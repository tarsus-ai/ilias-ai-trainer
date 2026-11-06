<?php

namespace TarsusIlias\Support;

/**
 * Local usage ledger: credits and operations per container, object and
 * user. The shared brain's usage cannot be broken down by course, so this
 * ledger provides the attribution.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class UsageMeter
{
    public const TABLE = 'rep_robj_xtar_use';

    public function record(int $containerref, int $objid, int $userid, string $operation, int $credits = 0): void
    {
        $db = Ilias::db();
        $db->insert(self::TABLE, [
            'id' => ['integer', $db->nextId(self::TABLE)],
            'container_ref' => ['integer', $containerref],
            'obj_id' => ['integer', $objid],
            'usr_id' => ['integer', $userid],
            'op_name' => ['text', substr($operation, 0, 50)],
            'credits' => ['integer', $credits],
            'created' => ['integer', time()],
        ]);
    }

    /**
     * Credits and calls per operation since a point in time.
     *
     * @return array<string, array{calls: int, credits: int}>
     */
    public function summary(int $since, ?int $objid = null): array
    {
        $db = Ilias::db();
        $where = 'created >= ' . $db->quote($since, 'integer');
        if ($objid !== null) {
            $where .= ' AND obj_id = ' . $db->quote($objid, 'integer');
        }
        $res = $db->query(
            'SELECT op_name, COUNT(*) calls, SUM(credits) credits FROM ' . self::TABLE . ' WHERE ' . $where . ' GROUP BY op_name ORDER BY op_name'
        );
        $summary = [];
        while ($row = $db->fetchAssoc($res)) {
            $summary[(string) $row['op_name']] = ['calls' => (int) $row['calls'], 'credits' => (int) $row['credits']];
        }
        return $summary;
    }

    /**
     * Remove a user's ledger rows (the conversation was erased).
     */
    public function forgetUser(int $objid, int $userid): void
    {
        Ilias::db()->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE obj_id = %s AND usr_id = %s',
            ['integer', 'integer'],
            [$objid, $userid]
        );
    }
}
