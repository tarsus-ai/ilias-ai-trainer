<?php

/**
 * Access rules of Tarsus objects: learners only see objects that are
 * online; everyone with write permission always does.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */

require_once __DIR__ . '/../src/autoload.php';

class ilObjTarsusAccess extends ilObjectPluginAccess
{
    public function _checkAccess(string $cmd, string $permission, int $ref_id, int $obj_id, ?int $user_id = null): bool
    {
        global $DIC;

        if ($user_id === null || $user_id === 0) {
            $user_id = (int) $DIC->user()->getId();
        }

        if (($permission === 'read' || $permission === 'visible')
            && !self::checkOnline($obj_id)
            && !$DIC->access()->checkAccessOfUser($user_id, 'write', '', $ref_id)) {
            return false;
        }

        return true;
    }

    public static function checkOnline(int $a_id): bool
    {
        return \TarsusIlias\Activity\Settings::isOnline($a_id);
    }
}
