<?php

namespace TarsusIlias\Index;

use TarsusIlias\Support\Ilias;

/**
 * Which indexed resources a teacher included in which Tarsus object.
 *
 * Different Tarsus objects in one course can cover different subsets of
 * the course's indexed material.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Inclusions
{
    public const TABLE = 'rep_robj_xtar_inc';

    /**
     * @return string[] The resource ids included in an object.
     */
    public static function forObject(int $objid): array
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT resource_id FROM ' . self::TABLE . ' WHERE obj_id = %s ORDER BY created', ['integer'], [$objid]);
        $ids = [];
        while ($row = $db->fetchAssoc($res)) {
            $ids[] = (string) $row['resource_id'];
        }
        return $ids;
    }

    public static function include(int $objid, string $resourceid): void
    {
        $db = Ilias::db();
        $db->replace(
            self::TABLE,
            ['obj_id' => ['integer', $objid], 'resource_id' => ['text', $resourceid]],
            ['created' => ['integer', time()]]
        );
    }

    public static function exclude(int $objid, string $resourceid): void
    {
        Ilias::db()->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE obj_id = %s AND resource_id = %s',
            ['integer', 'text'],
            [$objid, $resourceid]
        );
    }

    /**
     * A resource that left the brain leaves every object's scope with it:
     * keeping the choice would silently resurrect it on a later re-index.
     */
    public static function forgetResource(string $resourceid): void
    {
        Ilias::db()->manipulateF('DELETE FROM ' . self::TABLE . ' WHERE resource_id = %s', ['text'], [$resourceid]);
    }

    public static function forgetObject(int $objid): void
    {
        Ilias::db()->manipulateF('DELETE FROM ' . self::TABLE . ' WHERE obj_id = %s', ['integer'], [$objid]);
    }

    public static function count(int $objid): int
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT COUNT(*) cnt FROM ' . self::TABLE . ' WHERE obj_id = %s', ['integer'], [$objid]);
        $row = $db->fetchAssoc($res);
        return (int) ($row['cnt'] ?? 0);
    }
}
