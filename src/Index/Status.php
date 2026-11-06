<?php

namespace TarsusIlias\Index;

use TarsusIlias\Support\Ilias;

/**
 * Indexation status vocabulary and the allowed state-machine transitions.
 *
 *   notindexed -> pending -> pendingconfirmation -> indexed
 * plus pendingreindex, pendingdeletion and error.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Status
{
    /** Tracked locally but not (yet) in the brain. */
    public const NOT_INDEXED = 'notindexed';

    /** Upload to the brain under way. */
    public const PENDING = 'pending';

    /** Uploaded; awaiting the brain's asynchronous confirmation. */
    public const PENDING_CONFIRMATION = 'pendingconfirmation';

    /** Live in the brain (content hash stored). */
    public const INDEXED = 'indexed';

    /** Content changed since it was indexed. */
    public const PENDING_REINDEX = 'pendingreindex';

    /** Marked for removal from the brain. */
    public const PENDING_DELETION = 'pendingdeletion';

    /** Upload/confirmation failed after the retry budget. */
    public const ERROR = 'error';

    /** @var string[] */
    public const ALL = [
        self::NOT_INDEXED,
        self::PENDING,
        self::PENDING_CONFIRMATION,
        self::INDEXED,
        self::PENDING_REINDEX,
        self::PENDING_DELETION,
        self::ERROR,
    ];

    /** @var array<string, string[]> Allowed transitions from each status. */
    private const TRANSITIONS = [
        self::NOT_INDEXED => [self::PENDING, self::PENDING_DELETION],
        // notindexed is the revert target when a synchronous upload fails:
        // with no background queue the row must never stay mid-flight.
        self::PENDING => [
            self::PENDING_CONFIRMATION,
            self::INDEXED,
            self::ERROR,
            self::PENDING_DELETION,
            self::NOT_INDEXED,
        ],
        // The brain is the source of truth: it may report a resource as
        // simply absent ("failed"), which drops the row to the start state.
        self::PENDING_CONFIRMATION => [
            self::INDEXED,
            self::PENDING,
            self::NOT_INDEXED,
            self::ERROR,
            self::PENDING_DELETION,
        ],
        self::INDEXED => [self::PENDING_REINDEX, self::NOT_INDEXED, self::PENDING_DELETION],
        // pending is reached mid-re-index: once the old bits are untracked
        // the brain holds nothing, which is exactly what pending means.
        self::PENDING_REINDEX => [
            self::PENDING,
            self::PENDING_CONFIRMATION,
            self::INDEXED,
            self::NOT_INDEXED,
            self::ERROR,
            self::PENDING_DELETION,
        ],
        // A pending deletion either completes, or reverts to indexed when
        // it could not be delivered.
        self::PENDING_DELETION => [self::NOT_INDEXED, self::INDEXED, self::ERROR],
        // From error a manual retry re-enters the pipeline, or the row is
        // dropped. The brain may also confirm the resource after the local
        // retry budget ran out (slow ingestion): that self-heals.
        self::ERROR => [
            self::PENDING,
            self::PENDING_REINDEX,
            self::PENDING_DELETION,
            self::NOT_INDEXED,
            self::INDEXED,
        ],
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /**
     * Whether a transition is allowed; a no-op transition always is.
     */
    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(string $status): string
    {
        return Ilias::txt('status_' . $status);
    }
}
