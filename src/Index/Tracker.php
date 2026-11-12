<?php

namespace TarsusIlias\Index;

use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Guardrail\Pipeline;
use TarsusIlias\Material\Resolver;
use TarsusIlias\Material\ResourceId;
use TarsusIlias\Support\Interactions;
use TarsusIlias\Support\UsageMeter;

/**
 * Runs one unit of indexation work against the brain, synchronously.
 *
 * Calls go through the guardrails pipeline and advance the index state
 * machine. Failures are not absorbed: a guardrail block or transport error
 * propagates to the caller, which decides how to present it and how to
 * revert the row (Indexer::executeNow()).
 *
 * Indexing is an AI interaction per upload - audio and video one per
 * started two hours - so uploads are metered and held by the monthly
 * limit like any other charged call. Withdrawing, checking and deleting
 * cost nothing.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class Tracker
{
    private Repository $repo;
    private Pipeline $pipeline;
    private UsageMeter $usage;
    private Resolver $resolver;
    private ClientInterface $client;

    public function __construct(
        ?Repository $repo = null,
        ?Pipeline $pipeline = null,
        ?UsageMeter $usage = null,
        ?Resolver $resolver = null,
        ?ClientInterface $client = null
    ) {
        $this->repo = $repo ?? new Repository();
        $this->pipeline = $pipeline ?? new Pipeline();
        $this->usage = $usage ?? new UsageMeter();
        $this->resolver = $resolver ?? new Resolver();
        $this->client = $client ?? ClientFactory::getClient();
    }

    /**
     * Route a row to the handler for its status.
     *
     * @throws BlockedException|TransportException
     */
    public function process(\stdClass $row): void
    {
        switch ($row->status) {
            case Status::PENDING:
            case Status::PENDING_REINDEX:
                $this->processUpload($row);
                break;
            case Status::PENDING_CONFIRMATION:
                $this->processConfirmation($row);
                break;
            case Status::PENDING_DELETION:
                $this->processDeletions([$row]);
                break;
        }
    }

    /**
     * Upload (or re-upload) a resource. Tracking the same id twice does not
     * replace the previous bits - the brain keeps both - so the upload is
     * always preceded by an untrack, which clears whatever the brain still
     * holds under this id. Untracking an unknown id is a no-op.
     */
    private function processUpload(\stdClass $row): void
    {
        // Re-extract from current state so the upload carries what is there now.
        $material = $this->resolver->resolveByResourceId($row->resourceid);
        if ($material === null) {
            throw new TransportException(0, 'the material no longer exists');
        }
        $payload = $this->resolver->payload($material);
        $hash = $material->contentHash();
        // What the upload will count at least; a long recording counts more
        // and the client reports the real figure.
        $cost = Interactions::forTracking((string) ($payload['type'] ?? ''), (string) ($payload['mimetype'] ?? ''));

        // The monthly limit is asked before the old version is withdrawn:
        // an upload it refuses must leave what the brain holds untouched.
        $this->pipeline->execute(fn() => $this->client->untrackResource([$row->resourceid]), $cost);

        // The brain now holds nothing under this id. Record that before the
        // upload: should it fail, the row must not keep claiming the
        // previous version is live.
        if ($row->status === Status::PENDING_REINDEX) {
            $this->repo->setStatus($row->resourceid, Status::PENDING);
        }

        $result = $this->pipeline->execute(fn() => $this->client->trackResource($payload), $cost);

        $this->usage->record($row->containerref, 0, 0, 'track', (int) ($result['credits'] ?? $cost));

        $bitref = (string) ($result['bitref'] ?? '');
        if (($result['status'] ?? '') === Status::INDEXED) {
            $this->repo->markIndexed($row->resourceid, $bitref, $hash);
        } else {
            // Persist the hash of what was actually sent: confirmation
            // compares against this, not against the source by then.
            $this->repo->markUploaded($row->resourceid, $hash);
        }
    }

    /**
     * Ask the brain whether an upload has been ingested.
     */
    private function processConfirmation(\stdClass $row): void
    {
        $result = $this->pipeline->execute(fn() => $this->client->getResourceStatus($row->resourceid));

        if (($result['status'] ?? '') === Status::INDEXED) {
            $this->repo->markIndexed($row->resourceid, (string) ($result['bitref'] ?? $row->bitref), $row->contenthash);
        } elseif (($result['status'] ?? '') === Status::NOT_INDEXED) {
            if (Status::canTransition($row->status, Status::NOT_INDEXED)) {
                $this->repo->setStatus($row->resourceid, Status::NOT_INDEXED);
            }
        }
        // Still ingesting: leave the row awaiting confirmation.
    }

    /**
     * Untrack every row of a set in a single call and forget them.
     *
     * @param \stdClass[] $rows Rows marked for deletion.
     * @throws BlockedException|TransportException
     */
    public function processDeletions(array $rows): void
    {
        $resourceids = [];
        foreach ($rows as $row) {
            if ($row !== null) {
                $resourceids[] = $row->resourceid;
            }
        }
        if ($resourceids === []) {
            return;
        }

        $this->pipeline->execute(fn() => $this->client->untrackResource($resourceids));

        foreach ($resourceids as $resourceid) {
            $this->repo->delete($resourceid);
            Inclusions::forgetResource($resourceid);
        }
    }

    /**
     * Remove everything the brain holds for one container, in one call.
     *
     * @throws BlockedException|TransportException
     */
    public function untrackContainer(int $containerref): void
    {
        $assetid = ResourceId::assetId($containerref);
        $this->pipeline->execute(fn() => $this->client->untrackAssets([$assetid]));
    }
}
