<?php

namespace TarsusIlias\Activity;

use TarsusIlias\Index\Inclusions;
use TarsusIlias\Index\Repository;
use TarsusIlias\Index\Status;
use TarsusIlias\Support\Config;

/**
 * The set of resources allowed to ground a Tarsus object's conversation:
 * the teacher's inclusions, filtered down to what the brain actually holds
 * right now, in this object's course.
 *
 * Filtering at read time means a resource removed or replaced after being
 * included silently drops out of scope - no cleanup ordering to get wrong.
 * An empty scope must never reach the brain: it reads an empty list as
 * "search everything", which would cross course boundaries.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Scope
{
    /**
     * The brain-side conversation context id of a Tarsus object. Site
     * scoped, so two installations sharing a brain never share a
     * conversation.
     */
    public static function conversationContextId(int $objid): string
    {
        return Config::siteToken() . ':tarsus:' . $objid;
    }

    /**
     * @return string[] Resource ids allowed to ground the object's answers.
     */
    public static function allowedResourceIds(int $objid, int $containerref): array
    {
        $included = Inclusions::forObject($objid);
        if ($included === []) {
            return [];
        }
        $allowed = [];
        foreach ((new Repository())->getMany($included) as $resourceid => $row) {
            if ($row->status === Status::INDEXED && $row->containerref === $containerref) {
                $allowed[] = $resourceid;
            }
        }
        return $allowed;
    }
}
