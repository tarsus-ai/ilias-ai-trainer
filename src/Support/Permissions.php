<?php

namespace TarsusIlias\Support;

/**
 * Sensible default permissions for the new object type, so a Tarsus object
 * works in a course the moment it is created: members read it, tutors and
 * course administrators manage it and may create one.
 *
 * ILIAS gives a plugin type no permissions in any role template; without
 * these defaults an administrator would have to edit every role template
 * and every existing course by hand. The defaults are written once, on the
 * first activation, into the standard role templates, into the local roles
 * of existing courses and groups, and onto Tarsus objects that already
 * exist. Administrators may change them afterwards like any other
 * permission; the plugin never writes them again.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Permissions
{
    private const FLAG = 'permissions_seeded';

    /** @var array<string, array<string, string[]>> Role template => object type => operations. */
    private const DEFAULTS = [
        'il_crs_member' => ['xtar' => ['visible', 'read']],
        'il_crs_tutor' => ['xtar' => ['visible', 'read', 'write', 'copy'], 'crs' => ['create_xtar']],
        'il_crs_admin' => ['xtar' => ['visible', 'read', 'write', 'delete', 'edit_permission', 'copy'], 'crs' => ['create_xtar']],
        'il_grp_member' => ['xtar' => ['visible', 'read']],
        'il_grp_admin' => ['xtar' => ['visible', 'read', 'write', 'delete', 'edit_permission', 'copy'], 'grp' => ['create_xtar']],
    ];

    /**
     * Seed the defaults once.
     */
    public static function seedDefaults(): void
    {
        if (Config::fresh(self::FLAG) === '1') {
            return;
        }
        try {
            self::seedTemplates();
            self::seedLocalRoles();
            self::seedExistingObjects();
        } catch (\Throwable $e) {
            Ilias::log('could not seed default permissions: ' . $e->getMessage(), 'error');
        }
        Config::set(self::FLAG, '1');
    }

    /**
     * Operation ids by name, read from the database rather than through
     * ilRbacReview::_getOperationIdByName(): that cache is filled earlier in
     * the activation request, before ILIAS inserts the plugin's create_xtar
     * operation, and would report the new operation as unknown.
     *
     * @param string[] $names
     * @return int[]
     */
    private static function opIds(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $db = Ilias::db();
        $res = $db->query('SELECT ops_id FROM rbac_operations WHERE ' . $db->in('operation', $names, false, 'text'));
        $ids = [];
        while ($row = $db->fetchAssoc($res)) {
            $ids[] = (int) $row['ops_id'];
        }
        return $ids;
    }

    /**
     * The global role templates: what new courses and groups start with.
     */
    private static function seedTemplates(): void
    {
        $db = Ilias::db();
        $review = Ilias::dic()->rbac()->review();
        $admin = Ilias::dic()->rbac()->admin();
        foreach (self::DEFAULTS as $title => $types) {
            $res = $db->queryF("SELECT obj_id FROM object_data WHERE type = 'rolt' AND title = %s", ['text'], [$title]);
            $row = $db->fetchAssoc($res);
            if (!$row) {
                continue;
            }
            $roltid = (int) $row['obj_id'];
            foreach ($types as $type => $names) {
                $wanted = self::opIds($names);
                if ($type === Ilias::PLUGIN_ID) {
                    if ($review->getOperationsOfRole($roltid, $type, ROLE_FOLDER_ID) !== []) {
                        continue;
                    }
                }
                $admin->setRolePermission($roltid, $type, $wanted, ROLE_FOLDER_ID);
            }
        }
    }

    /**
     * The local roles of existing courses and groups.
     */
    private static function seedLocalRoles(): void
    {
        $db = Ilias::db();
        $review = Ilias::dic()->rbac()->review();
        $admin = Ilias::dic()->rbac()->admin();

        foreach (self::DEFAULTS as $template => $types) {
            $res = $db->queryF(
                "SELECT obj_id, title FROM object_data WHERE type = 'role' AND title LIKE %s",
                ['text'],
                [$template . '\_%']
            );
            while ($row = $db->fetchAssoc($res)) {
                if (!preg_match('/^' . preg_quote($template, '/') . '_(\d+)$/', (string) $row['title'], $m)) {
                    continue;
                }
                $roleid = (int) $row['obj_id'];
                $ref = (int) $m[1];
                if (!Ilias::refAlive($ref)) {
                    continue;
                }
                foreach ($types as $type => $names) {
                    $wanted = self::opIds($names);
                    if ($type === Ilias::PLUGIN_ID) {
                        // What new Tarsus objects in this container get.
                        if ($review->getOperationsOfRole($roleid, $type, $ref) === []) {
                            $admin->setRolePermission($roleid, $type, $wanted, $ref);
                        }
                        continue;
                    }
                    // Creating Tarsus objects in this container.
                    $admin->setRolePermission($roleid, $type, $wanted, $ref);
                    $current = $review->getRoleOperationsOnObject($roleid, $ref);
                    $merged = array_values(array_unique(array_merge($current, $wanted)));
                    if (count($merged) !== count($current)) {
                        $admin->grantPermission($roleid, $merged, $ref);
                    }
                }
            }
        }
    }

    /**
     * Tarsus objects that exist already: give them what their container's
     * roles now say, like a freshly created object.
     */
    private static function seedExistingObjects(): void
    {
        $db = Ilias::db();
        $tree = Ilias::tree();
        $res = $db->queryF(
            'SELECT r.ref_id FROM object_data d JOIN object_reference r ON r.obj_id = d.obj_id WHERE d.type = %s AND r.deleted IS NULL',
            ['text'],
            [Ilias::PLUGIN_ID]
        );
        while ($row = $db->fetchAssoc($res)) {
            $ref = (int) $row['ref_id'];
            if (!Ilias::refAlive($ref)) {
                continue;
            }
            $parent = (int) $tree->getParentId($ref);
            if ($parent <= 0) {
                continue;
            }
            try {
                // Not through the object factory: during the activation
                // request the object definition does not know the type yet.
                $object = new \ilObjTarsus($ref);
                $object->setPermissions($parent);
            } catch (\Throwable $e) {
                Ilias::log('could not refresh permissions of ref ' . $ref . ': ' . $e->getMessage());
            }
        }
    }
}
