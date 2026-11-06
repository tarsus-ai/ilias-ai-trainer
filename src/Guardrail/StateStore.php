<?php

namespace TarsusIlias\Guardrail;

use TarsusIlias\Support\Ilias;

/**
 * Persistent guardrail state with locked read-modify-write.
 *
 * The breaker, token bucket and budget counters are shared mutable state.
 * Concurrent requests and cron runs must not corrupt them, so every
 * mutation runs under a named database lock.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class StateStore
{
    public const TABLE = 'rep_robj_xtar_grd';

    /** Seconds to wait for a guardrail lock. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Fetch a guardrail row, creating it with defaults if absent.
     */
    public function get(string $name): \stdClass
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT * FROM ' . self::TABLE . ' WHERE g_name = %s', ['text'], [$name]);
        $row = $db->fetchAssoc($res);
        if (!$row) {
            $defaults = $this->defaultRow($name);
            $db->replace(self::TABLE, ['g_name' => ['text', $name]], $this->columns($defaults));
            return $defaults;
        }
        return (object) [
            'name' => (string) $row['g_name'],
            'state' => (string) $row['g_state'],
            'failurecount' => (int) $row['failures'],
            'opensince' => (int) $row['open_since'],
            'tokens' => (float) $row['tokens'],
            'lastrefill' => (int) $row['last_refill'],
            'creditsused' => (int) $row['credits_used'],
            'periodstart' => (int) $row['period_start'],
            'timemodified' => (int) $row['modified'],
        ];
    }

    /**
     * Read, mutate and persist a guardrail row under a lock. The callback
     * mutates the row in place; if it throws, nothing is written.
     *
     * @param callable(\stdClass): void $mutator
     */
    public function mutate(string $name, callable $mutator): \stdClass
    {
        $db = Ilias::db();
        $lock = 'xtar_' . substr(sha1((defined('CLIENT_ID') ? CLIENT_ID : '') . '|' . $name), 0, 40);
        $locked = $this->lock($db, $lock);

        try {
            $row = $this->get($name);
            $mutator($row);
            $row->timemodified = time();
            $db->replace(self::TABLE, ['g_name' => ['text', $name]], $this->columns($row));
            return $row;
        } finally {
            if ($locked) {
                $this->unlock($db, $lock);
            }
        }
    }

    private function lock(\ilDBInterface $db, string $name): bool
    {
        try {
            $res = $db->query('SELECT GET_LOCK(' . $db->quote($name, 'text') . ', ' . self::LOCK_TIMEOUT . ') AS got');
            $row = $db->fetchAssoc($res);
            return (int) ($row['got'] ?? 0) === 1;
        } catch (\Throwable $e) {
            // A database without named locks: carry on unlocked; the state
            // is advisory and a lost update only costs precision.
            return false;
        }
    }

    private function unlock(\ilDBInterface $db, string $name): void
    {
        try {
            $db->query('SELECT RELEASE_LOCK(' . $db->quote($name, 'text') . ') AS released');
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    private function columns(\stdClass $row): array
    {
        return [
            'g_state' => ['text', (string) $row->state],
            'failures' => ['integer', (int) $row->failurecount],
            'open_since' => ['integer', (int) $row->opensince],
            'tokens' => ['float', (float) $row->tokens],
            'last_refill' => ['integer', (int) $row->lastrefill],
            'credits_used' => ['integer', (int) $row->creditsused],
            'period_start' => ['integer', (int) $row->periodstart],
            'modified' => ['integer', (int) $row->timemodified],
        ];
    }

    private function defaultRow(string $name): \stdClass
    {
        return (object) [
            'name' => $name,
            'state' => 'closed',
            'failurecount' => 0,
            'opensince' => 0,
            'tokens' => 0.0,
            'lastrefill' => 0,
            'creditsused' => 0,
            'periodstart' => 0,
            'timemodified' => time(),
        ];
    }
}
