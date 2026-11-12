<?php

namespace TarsusIlias\Index;

use TarsusIlias\Client\TransportException;
use TarsusIlias\Client\ConfigurationException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Material\Resolver;
use TarsusIlias\Support\Ilias;

/**
 * Keeps the brain in step with the repository.
 *
 * Content leaving ILIAS must leave the brain with it, and indexed content
 * that changes should reach the brain without anyone pressing Re-index.
 * ILIAS raises no event a repository plugin could listen to for either, so
 * the reconciler compares what each course holds now with what the index
 * map records for it - on the teacher's material page and from the cron
 * job - and acts on the difference:
 *
 * - a course or group that is gone (deleted or in the trash): everything
 *   filed under it is untracked in one call;
 * - material that vanished (deleted, trashed, moved out, link removed):
 *   untracked;
 * - indexed material whose content changed: marked "content changed" and,
 *   within a budget, re-sent.
 *
 * A deletion that cannot be delivered stays recorded as pendingdeletion,
 * the durable record a later run (or the admin's deletions page) retries.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
class Reconciler
{
    /** Pending deletions one sweep attempts. */
    public const SWEEP_LIMIT = 20;

    private Repository $repo;
    private Resolver $resolver;
    private Indexer $indexer;
    private Tracker $tracker;

    /** Whether this request has already swept. */
    private static bool $swept = false;

    public function __construct(?Repository $repo = null, ?Resolver $resolver = null, ?Tracker $tracker = null)
    {
        $this->repo = $repo ?? new Repository();
        $this->resolver = $resolver ?? new Resolver();
        $this->tracker = $tracker ?? new Tracker($this->repo, null, null, $this->resolver);
        $this->indexer = new Indexer($this->resolver, $this->repo, $this->tracker);
    }

    /**
     * Reconcile one container.
     *
     * @param int  $uploadbudget How many changed bits to re-send right away.
     * @param bool $deliver      Whether to call the brain now; when false only
     *                           the local map is updated and the deliveries
     *                           wait for the next sweep.
     * @param \TarsusIlias\Material\Material[]|null $materials The container's
     *                           current material, when the caller has it.
     * @return array{withdrawn: int, changed: int, uploaded: int, failed: int, gone: bool}
     */
    public function reconcileContainer(int $containerref, int $uploadbudget = 0, bool $deliver = true, ?array $materials = null): array
    {
        $summary = ['withdrawn' => 0, 'changed' => 0, 'uploaded' => 0, 'failed' => 0, 'gone' => false];
        $rows = $this->repo->getByContainer($containerref);
        if ($rows === []) {
            return $summary;
        }

        if (!Ilias::refAlive($containerref)) {
            $summary['gone'] = true;
            if ($deliver) {
                $summary['withdrawn'] = $this->withdrawContainer($containerref, $rows) ? count($rows) : 0;
            }
            return $summary;
        }

        $current = [];
        foreach ($materials ?? $this->resolver->forContainer($containerref) as $material) {
            $current[$material->resourceid] = $material;
        }

        $doomed = [];
        $changed = [];
        foreach ($rows as $resourceid => $row) {
            $material = $current[$resourceid] ?? null;
            if ($material === null) {
                if ($row->status === Status::NOT_INDEXED) {
                    // Never reached the brain: just forget it.
                    $this->repo->delete($resourceid);
                    Inclusions::forgetResource($resourceid);
                    continue;
                }
                if ($row->status === Status::PENDING_DELETION) {
                    $doomed[] = $row;
                    continue;
                }
                if (Status::canTransition($row->status, Status::PENDING_DELETION)) {
                    $this->repo->setStatus($resourceid, Status::PENDING_DELETION);
                    $doomed[] = $this->repo->get($resourceid);
                }
                continue;
            }
            if ($row->srcref !== $material->srcref) {
                $this->repo->touchLocation($resourceid, $material->srcref);
            }
            if ($row->status === Status::INDEXED && $row->contenthash !== $material->contentHash()) {
                $this->repo->setStatus($resourceid, Status::PENDING_REINDEX);
                $changed[] = $resourceid;
            } elseif ($row->status === Status::PENDING_REINDEX) {
                $changed[] = $resourceid;
            }
        }

        if ($doomed !== [] && $deliver) {
            if ($this->deliver(fn() => $this->tracker->processDeletions(array_values(array_filter($doomed))))) {
                $summary['withdrawn'] = count($doomed);
            } else {
                $summary['failed'] += count($doomed);
            }
        }

        $summary['changed'] = count($changed);
        foreach (array_slice($changed, 0, $deliver ? max(0, $uploadbudget) : 0) as $resourceid) {
            try {
                $this->indexer->executeNow($resourceid);
                $summary['uploaded']++;
            } catch (BlockedException | TransportException | ConfigurationException $e) {
                // executeNow() reverted the row to "indexed", which is true
                // of the brain but hides that ILIAS has moved on: restore the
                // content-changed status so the teacher sees Re-index.
                $this->indexer->reindexResource($resourceid);
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * Reconcile every container with index rows (cron).
     *
     * @return array{containers: int, withdrawn: int, changed: int, uploaded: int, failed: int, confirmed: int}
     */
    public function reconcileAll(int $uploadbudget): array
    {
        $total = ['containers' => 0, 'withdrawn' => 0, 'changed' => 0, 'uploaded' => 0, 'failed' => 0, 'confirmed' => 0];
        foreach ($this->repo->containers() as $containerref) {
            $total['containers']++;
            $remaining = max(0, $uploadbudget - $total['uploaded']);
            $summary = $this->reconcileContainer($containerref, $remaining);
            foreach (['withdrawn', 'changed', 'uploaded', 'failed'] as $key) {
                $total[$key] += $summary[$key];
            }
        }

        // Rows still awaiting the brain's confirmation: ask in batches.
        $waiting = array_map(static fn($row) => $row->resourceid, $this->repo->getByStatus(Status::PENDING_CONFIRMATION));
        if ($waiting !== []) {
            try {
                $total['confirmed'] = count((new StatusSync($this->repo))->refresh(array_slice($waiting, 0, 200)));
            } catch (BlockedException | TransportException | ConfigurationException $e) {
                $total['failed']++;
            }
        }

        $this->sweep(true);
        return $total;
    }

    /**
     * Retry outstanding deletions, at most once per request unless forced.
     *
     * @return int How many rows were cleared.
     */
    public function sweep(bool $force = false): int
    {
        if (self::$swept && !$force) {
            return 0;
        }
        self::$swept = true;

        $rows = array_slice($this->repo->getByStatus(Status::PENDING_DELETION), 0, self::SWEEP_LIMIT);
        if ($rows === []) {
            return 0;
        }

        // Rows of a container that is gone go in one asset call.
        $bycontainer = [];
        foreach ($rows as $row) {
            $bycontainer[$row->containerref][$row->resourceid] = $row;
        }
        $cleared = 0;
        foreach ($bycontainer as $containerref => $containerrows) {
            if (!Ilias::refAlive((int) $containerref)) {
                if ($this->withdrawContainer((int) $containerref, $containerrows)) {
                    $cleared += count($containerrows);
                }
                continue;
            }
            if ($this->deliver(fn() => $this->tracker->processDeletions(array_values($containerrows)))) {
                $cleared += count($containerrows);
            }
        }
        return $cleared;
    }

    /**
     * The rows still awaiting removal from the brain.
     *
     * @return \stdClass[]
     */
    public function outstanding(): array
    {
        return $this->repo->getByStatus(Status::PENDING_DELETION);
    }

    /**
     * Untrack a whole container and forget its rows.
     *
     * @param array<string, \stdClass> $rows
     */
    private function withdrawContainer(int $containerref, array $rows): bool
    {
        foreach ($rows as $resourceid => $row) {
            if ($row->status !== Status::PENDING_DELETION && Status::canTransition($row->status, Status::PENDING_DELETION)) {
                $this->repo->setStatus($resourceid, Status::PENDING_DELETION);
            }
        }
        if (!$this->deliver(fn() => $this->tracker->untrackContainer($containerref))) {
            return false;
        }
        foreach (array_keys($rows) as $resourceid) {
            $this->repo->delete($resourceid);
            Inclusions::forgetResource($resourceid);
        }
        return true;
    }

    /**
     * Run an outbound call, never letting its failure escape.
     */
    private function deliver(callable $call): bool
    {
        try {
            $call();
            return true;
        } catch (BlockedException | TransportException | ConfigurationException $e) {
            Ilias::log('could not deliver a deletion: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Allow this request to sweep again (tests).
     */
    public static function resetSweep(): void
    {
        self::$swept = false;
    }
}
