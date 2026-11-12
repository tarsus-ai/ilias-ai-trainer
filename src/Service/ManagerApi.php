<?php

namespace TarsusIlias\Service;

use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Client\ConfigurationException;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Index\Inclusions;
use TarsusIlias\Index\Indexer;
use TarsusIlias\Index\Reconciler;
use TarsusIlias\Index\Repository;
use TarsusIlias\Index\Status;
use TarsusIlias\Index\StatusSync;
use TarsusIlias\Material\Resolver;
use TarsusIlias\Material\ResourceId;
use TarsusIlias\Output\Presenter;
use TarsusIlias\Support\Ilias;

/**
 * The teacher's side: per-resource index actions, the inclusion switch and
 * the page sync, all for the course a Tarsus object lives in.
 *
 * Every resource id that arrives from the browser is checked to belong to
 * this object's course and to point at material the teacher can read -
 * isolation by id on the shared brain.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class ManagerApi
{
    /** @var string[] */
    public const ACTIONS = ['index', 'reindex', 'remove', 'retry'];

    private int $objid;
    private int $containerref;
    private Repository $repo;
    private Resolver $resolver;

    public function __construct(int $objid, int $containerref)
    {
        $this->objid = $objid;
        $this->containerref = $containerref;
        $this->repo = new Repository();
        $this->resolver = new Resolver();
    }

    /**
     * Whether a resource id from the browser may be acted on here.
     */
    private function allowed(string $resourceid): bool
    {
        $parts = ResourceId::parse($resourceid);
        if ($parts === null || !ResourceId::isLocal($resourceid) || $parts['containerref'] !== $this->containerref) {
            return false;
        }
        $row = $this->repo->get($resourceid);
        if ($row !== null && !Ilias::refAlive($parts['refid'])) {
            // Material that is gone may still be withdrawn.
            return true;
        }
        return $this->resolver->inScope($parts['refid'], $this->containerref)
            && Ilias::access()->checkAccess('read', '', $parts['refid']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $resourceid): array
    {
        $row = $this->repo->get($resourceid);
        $status = $row->status ?? Status::NOT_INDEXED;
        return Presenter::present($resourceid, $status, (int) ($row->timemodified ?? 0))
            + ['included' => in_array($resourceid, Inclusions::forObject($this->objid), true)];
    }

    /**
     * Apply one action to one resource, synchronously.
     *
     * @return array<string, mixed> {ok, error?, row}
     */
    public function action(string $resourceid, string $action): array
    {
        if (!in_array($action, self::ACTIONS, true) || !$this->allowed($resourceid)) {
            return ['ok' => false, 'error' => Ilias::txt('invalid_resource')];
        }

        $indexer = new Indexer($this->resolver, $this->repo);
        switch ($action) {
            case 'index':
                $marked = $indexer->indexResource($resourceid);
                break;
            case 'reindex':
                $marked = $indexer->reindexResource($resourceid);
                break;
            case 'remove':
                $marked = $indexer->removeResource($resourceid);
                break;
            default:
                $marked = $indexer->retryResource($resourceid);
                break;
        }
        if (!$marked) {
            return ['ok' => false, 'error' => Ilias::txt('action_failed'), 'row' => $this->present($resourceid)];
        }

        // The whole action runs in this request: allow a slow file upload
        // to finish (the client caps uploads at 300 seconds).
        if (function_exists('set_time_limit')) {
            @set_time_limit(330);
        }
        $error = '';
        try {
            $indexer->executeNow($resourceid);
        } catch (BlockedException $e) {
            $error = Ilias::txt('guardrail_blocked_' . $e->reason);
        } catch (TransportException $e) {
            Ilias::log($e->getMessage());
            $error = Ilias::txt('transport_error');
        } catch (ConfigurationException $e) {
            $error = Ilias::txt($e->langKey);
        }

        return ['ok' => $error === '', 'error' => $error, 'row' => $this->present($resourceid)];
    }

    /**
     * Include or exclude a resource from this object's scope.
     *
     * @return array<string, mixed>
     */
    public function setIncluded(string $resourceid, bool $included): array
    {
        if (!$this->allowed($resourceid)) {
            return ['ok' => false, 'error' => Ilias::txt('invalid_resource')];
        }
        if ($included) {
            // Only content the brain holds may be put in scope.
            $row = $this->repo->get($resourceid);
            if ($row === null || $row->status !== Status::INDEXED) {
                return ['ok' => false, 'error' => Ilias::txt('include_not_indexed'), 'row' => $this->present($resourceid)];
            }
            Inclusions::include($this->objid, $resourceid);
        } else {
            Inclusions::exclude($this->objid, $resourceid);
        }
        return ['ok' => true, 'error' => '', 'row' => $this->present($resourceid)];
    }

    /**
     * Reconcile one page of the list against the brain in a single call,
     * then finish deletions that could not be delivered earlier.
     *
     * @param string[] $resourceids
     * @return array<string, mixed>
     */
    public function sync(array $resourceids): array
    {
        $resourceids = array_values(array_unique(array_map('strval', $resourceids)));
        if (count($resourceids) > ClientInterface::BATCH_LIMIT) {
            return ['ok' => false, 'error' => Ilias::txt('too_many_resources', ['max' => ClientInterface::BATCH_LIMIT])];
        }
        foreach ($resourceids as $resourceid) {
            if (!$this->allowed($resourceid)) {
                return ['ok' => false, 'error' => Ilias::txt('invalid_resource')];
            }
        }

        $error = '';
        try {
            (new StatusSync($this->repo))->refresh($resourceids);
        } catch (BlockedException $e) {
            $error = Ilias::txt('guardrail_blocked_' . $e->reason);
        } catch (TransportException $e) {
            Ilias::log($e->getMessage());
            $error = Ilias::txt('transport_error');
        } catch (ConfigurationException $e) {
            $error = Ilias::txt($e->langKey);
        }

        // Deliveries that failed when content left the course have nowhere
        // else to be retried from; this is the moment to finish them.
        (new Reconciler($this->repo, $this->resolver))->sweep();

        $rows = [];
        foreach ($resourceids as $resourceid) {
            $rows[] = $this->present($resourceid);
        }
        return ['ok' => $error === '', 'error' => $error, 'rows' => $rows];
    }
}
