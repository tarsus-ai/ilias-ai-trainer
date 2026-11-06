<?php

namespace TarsusIlias\Output;

use TarsusIlias\Index\Status;

/**
 * Maps an index status to its presentation: badge and available actions.
 *
 * Single source of truth for the server-rendered material list and its
 * live updates, so the two never drift.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Presenter
{
    /**
     * Seconds after which a resource still awaiting the brain's
     * confirmation counts as stuck and may be removed by the teacher.
     */
    public const STUCK_AFTER = 600;

    /** @var array<string, string> Status => badge modifier. */
    private const BADGES = [
        Status::NOT_INDEXED => 'neutral',
        Status::PENDING => 'warning',
        Status::PENDING_CONFIRMATION => 'warning',
        Status::PENDING_REINDEX => 'warning',
        Status::PENDING_DELETION => 'muted',
        Status::INDEXED => 'success',
        Status::ERROR => 'danger',
    ];

    public static function badgeClass(string $status): string
    {
        return 'tarsus-badge tarsus-badge--' . (self::BADGES[$status] ?? 'neutral');
    }

    /**
     * Whether a call is pending for the resource.
     */
    public static function isInflight(string $status): bool
    {
        // pendingreindex is not in flight: it is a stable "content changed"
        // state that waits for the teacher (or the cron job).
        return in_array($status, [Status::PENDING, Status::PENDING_CONFIRMATION, Status::PENDING_DELETION], true);
    }

    /**
     * @return string[] Subset of index, reindex, remove, retry.
     */
    public static function actionsFor(string $status, int $age = 0): array
    {
        switch ($status) {
            case Status::NOT_INDEXED:
                return ['index'];
            case Status::INDEXED:
            case Status::PENDING_REINDEX:
                return ['reindex', 'remove'];
            case Status::ERROR:
                return ['retry', 'remove'];
            case Status::PENDING_CONFIRMATION:
                // The brain never answered: after a while the teacher may
                // give up on the upload and untrack it.
                return $age >= self::STUCK_AFTER ? ['remove'] : [];
            default:
                return [];
        }
    }

    /**
     * The full presentational payload of one bit.
     *
     * @return array<string, mixed>
     */
    public static function present(string $resourceid, string $status, int $timemodified = 0): array
    {
        $age = $timemodified > 0 ? max(0, time() - $timemodified) : 0;
        $actions = self::actionsFor($status, $age);
        return [
            'resourceid' => $resourceid,
            'status' => $status,
            'statuslabel' => Status::label($status),
            'badgeclass' => self::badgeClass($status),
            'inflight' => self::isInflight($status),
            'canindex' => in_array('index', $actions, true),
            'canreindex' => in_array('reindex', $actions, true),
            'canremove' => in_array('remove', $actions, true),
            'canretry' => in_array('retry', $actions, true),
            // Only content the brain holds may be put in an object's scope.
            'caninclude' => $status === Status::INDEXED,
        ];
    }
}
