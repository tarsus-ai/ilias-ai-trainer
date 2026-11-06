<?php

namespace TarsusIlias\Index;

use TarsusIlias\Support\Ilias;

/**
 * Typed access to the local index map: the source of truth for what the
 * brain holds of this installation's material.
 *
 * Status reads are database reads - zero API calls on render. Every
 * transition is validated against Status, so a row can only move through
 * legal states.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class Repository
{
    public const TABLE = 'rep_robj_xtar_idx';

    public function get(string $resourceid): ?\stdClass
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT * FROM ' . self::TABLE . ' WHERE resource_id = %s', ['text'], [$resourceid]);
        $row = $db->fetchAssoc($res);
        return $row ? self::map($row) : null;
    }

    /**
     * Ensure a row exists, creating it as notindexed (idempotent).
     */
    public function ensure(string $resourceid, int $containerref, string $assetid, string $srctype, int $srcref): \stdClass
    {
        $existing = $this->get($resourceid);
        if ($existing !== null) {
            return $existing;
        }

        $db = Ilias::db();
        $now = time();
        $db->insert(self::TABLE, [
            'id' => ['integer', $db->nextId(self::TABLE)],
            'resource_id' => ['text', $resourceid],
            'asset_id' => ['text', $assetid],
            'container_ref' => ['integer', $containerref],
            'src_type' => ['text', $srctype],
            'src_ref' => ['integer', $srcref],
            'content_hash' => ['text', null],
            'bit_ref' => ['text', null],
            'idx_status' => ['text', Status::NOT_INDEXED],
            'attempts' => ['integer', 0],
            'next_retry' => ['integer', 0],
            'created' => ['integer', $now],
            'modified' => ['integer', $now],
        ]);

        return $this->requireRow($resourceid);
    }

    /**
     * Move a row to a new status, validating the transition.
     */
    public function setStatus(string $resourceid, string $newstatus): void
    {
        $row = $this->requireRow($resourceid);

        if (!Status::canTransition($row->status, $newstatus)) {
            throw new \LogicException("Illegal index status transition {$row->status} -> {$newstatus} for {$resourceid}");
        }

        $this->write($resourceid, ['idx_status' => ['text', $newstatus]]);
    }

    /**
     * Mark a resource as live in the brain, storing its bit ref and hash.
     */
    public function markIndexed(string $resourceid, string $bitref, string $contenthash): void
    {
        $row = $this->requireRow($resourceid);
        if (!Status::canTransition($row->status, Status::INDEXED)) {
            throw new \LogicException("Illegal index status transition {$row->status} -> indexed for {$resourceid}");
        }
        $this->write($resourceid, [
            'idx_status' => ['text', Status::INDEXED],
            'bit_ref' => ['text', $bitref],
            'content_hash' => ['text', $contenthash],
            'attempts' => ['integer', 0],
            'next_retry' => ['integer', 0],
        ]);
    }

    /**
     * Record a successful upload: await confirmation, remembering the hash
     * of what was actually sent (a later confirmation must not recompute it
     * from current ILIAS state - the source may have changed meanwhile).
     */
    public function markUploaded(string $resourceid, string $contenthash): void
    {
        $row = $this->requireRow($resourceid);
        if (!Status::canTransition($row->status, Status::PENDING_CONFIRMATION)) {
            throw new \LogicException("Illegal index status transition {$row->status} -> pendingconfirmation for {$resourceid}");
        }
        $this->write($resourceid, [
            'idx_status' => ['text', Status::PENDING_CONFIRMATION],
            'content_hash' => ['text', $contenthash],
            'attempts' => ['integer', 0],
            'next_retry' => ['integer', 0],
        ]);
    }

    /**
     * Record a failed attempt: schedule the next retry, or fail to error.
     */
    public function recordFailure(string $resourceid): \stdClass
    {
        $row = $this->requireRow($resourceid);
        $attempts = $row->attempts + 1;
        $now = time();
        $values = ['attempts' => ['integer', $attempts]];

        if (Backoff::exhausted($attempts)) {
            if (Status::canTransition($row->status, Status::ERROR)) {
                $values['idx_status'] = ['text', Status::ERROR];
            }
            $values['next_retry'] = ['integer', 0];
        } else {
            $values['next_retry'] = ['integer', $now + Backoff::delay($attempts, true)];
        }

        $this->write($resourceid, $values);
        return $this->requireRow($resourceid);
    }

    public function resetAttempts(string $resourceid): void
    {
        $this->requireRow($resourceid);
        $this->write($resourceid, ['attempts' => ['integer', 0], 'next_retry' => ['integer', 0]]);
    }

    /**
     * Keep a row's location in step with its material (the container is
     * part of the id, the source ref is not when an object is moved).
     */
    public function touchLocation(string $resourceid, int $srcref): void
    {
        $db = Ilias::db();
        $db->update(self::TABLE, ['src_ref' => ['integer', $srcref]], ['resource_id' => ['text', $resourceid]]);
    }

    /**
     * @return \stdClass[]
     */
    public function getByStatus(string $status): array
    {
        return $this->select('idx_status = ' . Ilias::db()->quote($status, 'text'));
    }

    /**
     * @return \stdClass[] Every row of one container, keyed by resource id.
     */
    public function getByContainer(int $containerref): array
    {
        $rows = [];
        foreach ($this->select('container_ref = ' . Ilias::db()->quote($containerref, 'integer')) as $row) {
            $rows[$row->resourceid] = $row;
        }
        return $rows;
    }

    /**
     * @param string[] $resourceids
     * @return array<string, \stdClass> Keyed by resource id.
     */
    public function getMany(array $resourceids): array
    {
        $resourceids = array_values(array_unique(array_filter(array_map('strval', $resourceids), 'strlen')));
        if ($resourceids === []) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($resourceids, 200) as $chunk) {
            foreach ($this->select(Ilias::db()->in('resource_id', $chunk, false, 'text')) as $row) {
                $rows[$row->resourceid] = $row;
            }
        }
        return $rows;
    }

    /**
     * @return int[] The distinct containers with index rows.
     */
    public function containers(): array
    {
        $db = Ilias::db();
        $res = $db->query('SELECT DISTINCT container_ref FROM ' . self::TABLE);
        $refs = [];
        while ($row = $db->fetchAssoc($res)) {
            $refs[] = (int) $row['container_ref'];
        }
        return $refs;
    }

    /**
     * @return array<string, int> Status => count, over all rows.
     */
    public function countByStatus(): array
    {
        $db = Ilias::db();
        $res = $db->query('SELECT idx_status, COUNT(*) cnt FROM ' . self::TABLE . ' GROUP BY idx_status');
        $counts = [];
        while ($row = $db->fetchAssoc($res)) {
            $counts[(string) $row['idx_status']] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function delete(string $resourceid): void
    {
        Ilias::db()->manipulateF('DELETE FROM ' . self::TABLE . ' WHERE resource_id = %s', ['text'], [$resourceid]);
    }

    /**
     * @return \stdClass[]
     */
    private function select(string $where): array
    {
        $db = Ilias::db();
        $res = $db->query('SELECT * FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY id');
        $rows = [];
        while ($row = $db->fetchAssoc($res)) {
            $rows[] = self::map($row);
        }
        return $rows;
    }

    /**
     * @param array<string, array{0: string, 1: mixed}> $values
     */
    private function write(string $resourceid, array $values): void
    {
        $values['modified'] = ['integer', time()];
        Ilias::db()->update(self::TABLE, $values, ['resource_id' => ['text', $resourceid]]);
    }

    private function requireRow(string $resourceid): \stdClass
    {
        $row = $this->get($resourceid);
        if ($row === null) {
            throw new \LogicException("No index row for resource {$resourceid}");
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function map(array $row): \stdClass
    {
        return (object) [
            'id' => (int) $row['id'],
            'resourceid' => (string) $row['resource_id'],
            'assetid' => (string) $row['asset_id'],
            'containerref' => (int) $row['container_ref'],
            'srctype' => (string) $row['src_type'],
            'srcref' => (int) $row['src_ref'],
            'contenthash' => (string) ($row['content_hash'] ?? ''),
            'bitref' => (string) ($row['bit_ref'] ?? ''),
            'status' => (string) $row['idx_status'],
            'attempts' => (int) $row['attempts'],
            'nextretry' => (int) $row['next_retry'],
            'timecreated' => (int) $row['created'],
            'timemodified' => (int) $row['modified'],
        ];
    }
}
