<?php

namespace TarsusIlias\Index;

use TarsusIlias\Client\TransportException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Material\Material;
use TarsusIlias\Material\Resolver;
use TarsusIlias\Material\ResourceId;

/**
 * Turns teacher actions into indexation work.
 *
 * The decision half: resolve the material, compare its content hash with
 * the index map, and only mark a bit for an API call when it is new or its
 * content changed - an unchanged bit costs nothing. The marked work then
 * runs synchronously via executeNow(): there is no background queue, so a
 * failure reverts the row to its previous stable state and surfaces to
 * the caller.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
class Indexer
{
    private Resolver $resolver;
    private Repository $repo;
    private Tracker $tracker;

    public function __construct(?Resolver $resolver = null, ?Repository $repo = null, ?Tracker $tracker = null)
    {
        $this->resolver = $resolver ?? new Resolver();
        $this->repo = $repo ?? new Repository();
        $this->tracker = $tracker ?? new Tracker($this->repo, null, null, $this->resolver);
    }

    /**
     * Index one material by its resource id (opt-in per-item action).
     */
    public function indexResource(string $resourceid): bool
    {
        $material = $this->resolver->resolveByResourceId($resourceid);
        if ($material === null) {
            return false;
        }
        return $this->indexMaterial($material, false);
    }

    /**
     * Force a re-index of one resource.
     */
    public function reindexResource(string $resourceid): bool
    {
        $row = $this->repo->get($resourceid);
        if ($row === null) {
            // Not tracked yet: a re-index request degrades to a first index.
            return $this->indexResource($resourceid);
        }

        if ($row->status === Status::ERROR) {
            $this->repo->resetAttempts($resourceid);
        }

        if (!Status::canTransition($row->status, Status::PENDING_REINDEX)) {
            return false;
        }

        $this->repo->setStatus($resourceid, Status::PENDING_REINDEX);
        return true;
    }

    /**
     * Mark one resource for removal from the brain.
     */
    public function removeResource(string $resourceid): bool
    {
        $row = $this->repo->get($resourceid);
        if ($row === null || !Status::canTransition($row->status, Status::PENDING_DELETION)) {
            return false;
        }

        $this->repo->setStatus($resourceid, Status::PENDING_DELETION);
        return true;
    }

    /**
     * Retry one resource stuck in the error state.
     */
    public function retryResource(string $resourceid): bool
    {
        $row = $this->repo->get($resourceid);
        if ($row === null || $row->status !== Status::ERROR) {
            return false;
        }

        $this->repo->resetAttempts($resourceid);
        $this->repo->setStatus($resourceid, Status::PENDING);
        return true;
    }

    /**
     * Run the pending work of a resource now, reverting on failure. The row
     * was just marked (pending / pendingreindex / pendingdeletion); when a
     * guardrail or the transport refuses, it returns to its previous
     * stable state - there is no queue to pick it up later - and the
     * failure propagates so the caller can show it.
     *
     * @throws BlockedException|TransportException
     */
    public function executeNow(string $resourceid): void
    {
        $row = $this->repo->get($resourceid);
        if ($row === null) {
            return;
        }

        try {
            $this->tracker->process($row);
        } catch (BlockedException | TransportException $e) {
            // Re-read: a re-index advances the row to pending once the old
            // content is untracked, and the revert must act on where the
            // sequence actually failed.
            $this->revert($this->repo->get($resourceid) ?? $row);
            throw $e;
        }
    }

    private function revert(\stdClass $row): void
    {
        switch ($row->status) {
            case Status::PENDING:
                // A first upload failed: back to square one.
                $this->repo->setStatus($row->resourceid, Status::NOT_INDEXED);
                break;
            case Status::PENDING_REINDEX:
            case Status::PENDING_DELETION:
                // The old content is still live in the brain.
                $this->repo->setStatus($row->resourceid, Status::INDEXED);
                break;
        }
    }

    /**
     * Decide and mark one material.
     *
     * @param bool $onlytracked When true, skip materials that have no row yet.
     */
    public function indexMaterial(Material $material, bool $onlytracked = false): bool
    {
        $existing = $this->repo->get($material->resourceid);
        if ($onlytracked && $existing === null) {
            return false;
        }

        $row = $existing ?? $this->repo->ensure(
            $material->resourceid,
            $material->containerref,
            ResourceId::assetId($material->containerref),
            $material->srctype,
            $material->srcref
        );

        $target = $this->targetStatus($row, $material->contentHash());
        if ($target === null) {
            return false;
        }

        $this->repo->setStatus($material->resourceid, $target);
        return true;
    }

    private function targetStatus(\stdClass $row, string $hash): ?string
    {
        if ($row->status === Status::NOT_INDEXED || $row->status === Status::ERROR) {
            return Status::PENDING;
        }
        if ($row->status === Status::INDEXED && $row->contenthash !== $hash) {
            return Status::PENDING_REINDEX;
        }
        return null;
    }
}
