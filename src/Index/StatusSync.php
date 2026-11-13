<?php

namespace TarsusIlias\Index;

use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Guardrail\Pipeline;

/**
 * Reconciles a set of local index rows against the brain in one batch call.
 *
 * The brain reports up to ClientInterface::BATCH_LIMIT resources per call,
 * which is why a page of the teacher's material list holds the same
 * number: one page costs exactly one call. Only the local index map is
 * written; the brain is never mutated here.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
class StatusSync
{
    /**
     * Statuses worth asking the brain about. Rows with queued local work
     * (pending, pendingreindex, pendingdeletion) are deliberately absent:
     * the brain's answer describes the previous content, and adopting it
     * would cancel the queued work.
     */
    private const SYNCABLE = [
        Status::NOT_INDEXED,
        Status::PENDING_CONFIRMATION,
        Status::INDEXED,
        Status::ERROR,
    ];

    private Repository $repo;
    private Pipeline $pipeline;
    private ClientInterface $client;

    public function __construct(?Repository $repo = null, ?Pipeline $pipeline = null, ?ClientInterface $client = null)
    {
        $this->repo = $repo ?? new Repository();
        $this->pipeline = $pipeline ?? new Pipeline();
        $this->client = $client ?? ClientFactory::getClient();
    }

    /**
     * Ask the brain about the given resources and apply the answer locally.
     *
     * @param string[] $resourceids
     * @return array<string, string> Resource ids whose status changed => new status.
     * @throws BlockedException|TransportException
     */
    public function refresh(array $resourceids): array
    {
        $rows = [];
        foreach ($this->repo->getMany($resourceids) as $resourceid => $row) {
            if (in_array($row->status, self::SYNCABLE, true)) {
                $rows[$resourceid] = $row;
            }
        }
        if ($rows === []) {
            return [];
        }

        $changed = [];
        foreach (array_chunk(array_keys($rows), ClientInterface::BATCH_LIMIT) as $chunk) {
            $checked = $this->pipeline->execute(fn() => $this->client->checkTrackedResources($chunk));
            foreach ($chunk as $resourceid) {
                $row = $rows[$resourceid];
                $reported = $checked[$resourceid]['status'] ?? null;
                if ($reported === null || $reported === $row->status) {
                    continue;
                }
                if ($this->apply($row, (string) $reported)) {
                    $changed[$resourceid] = (string) $reported;
                }
            }
        }

        return $changed;
    }

    private function apply(\stdClass $row, string $reported): bool
    {
        if (!Status::isValid($reported) || !Status::canTransition($row->status, $reported)) {
            return false;
        }

        if ($reported === Status::INDEXED) {
            // The brain confirmed the content that was uploaded, whose hash
            // is already on the row.
            $this->repo->markIndexed($row->resourceid, $row->bitref, $row->contenthash);
            return true;
        }

        $this->repo->setStatus($row->resourceid, $reported);
        return true;
    }
}
