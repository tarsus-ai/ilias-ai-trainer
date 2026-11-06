<?php

namespace TarsusIlias\Activity;

use TarsusIlias\Support\Ilias;

/**
 * Turns the brain's figures into a score and a learning progress status.
 *
 * With every answer the brain reports the learner's experience points
 * (Test knowledge) and learning units (Build knowledge) with their totals,
 * plus three progress percentages. The latest figures are kept per learner
 * and object: the score is the share of what could be earned in the modes
 * the teacher switched on (0-100), and the learning progress compares the
 * matching progress figure with the teacher's completion threshold.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Scoring
{
    public const TABLE = 'rep_robj_xtar_prg';

    // ILIAS learning progress status values (ilLPStatus).
    public const LP_NOT_ATTEMPTED = 0;
    public const LP_IN_PROGRESS = 1;
    public const LP_COMPLETED = 2;

    /**
     * Store the figures the brain just reported and refresh the learning
     * progress when anything changed.
     *
     * @param array<string, mixed> $progress
     */
    public static function record(int $objid, int $userid, array $progress, bool $interaction = true): void
    {
        if ($progress === [] || $userid <= 0 || (defined('ANONYMOUS_USER_ID') && $userid === (int) ANONYMOUS_USER_ID)) {
            return;
        }
        $values = [
            'earned_xp' => round((float) ($progress['earnedxp'] ?? 0), 2),
            'total_xp' => round((float) ($progress['totalxp'] ?? 0), 2),
            'earned_units' => round((float) ($progress['earnedunits'] ?? 0), 2),
            'total_units' => round((float) ($progress['totalunits'] ?? 0), 2),
            'practice_prg' => round((float) ($progress['practiceprogress'] ?? 0), 2),
            'learn_prg' => round((float) ($progress['learnprogress'] ?? 0), 2),
            'overall_prg' => round((float) ($progress['progress'] ?? 0), 2),
        ];

        $existing = self::raw($objid, $userid);
        if ($existing === null && !$interaction) {
            // Merely reading the feed with nothing earned yet creates no
            // row: the learner still counts as "not attempted" until the
            // first question, answer or learning unit.
            $touched = false;
            foreach (['earned_xp', 'earned_units', 'practice_prg', 'learn_prg', 'overall_prg'] as $key) {
                if ($values[$key] > 0) {
                    $touched = true;
                }
            }
            if (!$touched) {
                return;
            }
        }
        if ($existing !== null) {
            $same = true;
            foreach ($values as $key => $value) {
                if (abs((float) $existing[$key] - $value) > 0.001) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return;
            }
        }

        $columns = [];
        foreach ($values as $key => $value) {
            $columns[$key] = ['float', $value];
        }
        $columns['modified'] = ['integer', time()];
        Ilias::db()->replace(
            self::TABLE,
            ['obj_id' => ['integer', $objid], 'usr_id' => ['integer', $userid]],
            $columns
        );

        self::pushLearningProgress($objid, $userid);
    }

    /**
     * Note that a learner interacted (asked a question) when the brain
     * reported no figures: the learner is then "in progress".
     */
    public static function touch(int $objid, int $userid): void
    {
        if ($userid <= 0 || (defined('ANONYMOUS_USER_ID') && $userid === (int) ANONYMOUS_USER_ID) || self::raw($objid, $userid) !== null) {
            return;
        }
        self::record($objid, $userid, ['earnedxp' => 0, 'totalxp' => 0], true);
    }

    /**
     * Forget a learner's figures (the conversation was erased).
     */
    public static function clear(int $objid, int $userid): void
    {
        Ilias::db()->manipulateF(
            'DELETE FROM ' . self::TABLE . ' WHERE obj_id = %s AND usr_id = %s',
            ['integer', 'integer'],
            [$objid, $userid]
        );
        self::pushLearningProgress($objid, $userid);
    }

    public static function forgetObject(int $objid): void
    {
        Ilias::db()->manipulateF('DELETE FROM ' . self::TABLE . ' WHERE obj_id = %s', ['integer'], [$objid]);
    }

    public static function row(int $objid, int $userid): ?\stdClass
    {
        $raw = self::raw($objid, $userid);
        return $raw === null ? null : self::map($raw);
    }

    /**
     * @return array<int, \stdClass> User id => figures.
     */
    public static function rows(int $objid): array
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT * FROM ' . self::TABLE . ' WHERE obj_id = %s', ['integer'], [$objid]);
        $rows = [];
        while ($row = $db->fetchAssoc($res)) {
            $rows[(int) $row['usr_id']] = self::map($row);
        }
        return $rows;
    }

    /**
     * The score (0-100): Test knowledge alone earned/total XP, Build
     * knowledge alone earned/total units, both their mean, neither (chat
     * only) no score.
     */
    public static function grade(Settings $settings, ?\stdClass $row): ?float
    {
        $test = $settings->testenabled;
        $build = $settings->buildenabled;
        if (!$test && !$build) {
            return null;
        }
        if ($row === null) {
            return 0.0;
        }
        $parts = [];
        if ($test) {
            $parts[] = self::share($row->earnedxp, $row->totalxp);
        }
        if ($build) {
            $parts[] = self::share($row->earnedunits, $row->totalunits);
        }
        return round(array_sum($parts) / count($parts), 2);
    }

    /**
     * The progress percentage (0-100) the completion rule looks at: Test
     * knowledge alone its practice progress, Build knowledge alone its
     * learning progress, both the brain's overall progress.
     */
    public static function progress(Settings $settings, ?\stdClass $row): float
    {
        if ($row === null) {
            return 0.0;
        }
        $test = $settings->testenabled;
        $build = $settings->buildenabled;
        if ($test && $build) {
            $value = $row->progress;
            if ($value <= 0) {
                $value = ($row->practiceprogress + $row->learnprogress) / 2;
            }
        } elseif ($build) {
            $value = $row->learnprogress;
        } elseif ($test) {
            $value = $row->practiceprogress;
        } else {
            $value = 0.0;
        }
        return round(max(0.0, min(100.0, $value)), 2);
    }

    /**
     * The learning progress status of a learner.
     */
    public static function lpStatus(Settings $settings, ?\stdClass $row): int
    {
        if ($row === null) {
            return self::LP_NOT_ATTEMPTED;
        }
        if ($settings->completionprogress > 0 && self::progress($settings, $row) >= $settings->completionprogress) {
            return self::LP_COMPLETED;
        }
        return self::LP_IN_PROGRESS;
    }

    private static function share(float $earned, float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }
        return max(0.0, min(100.0, $earned / $total * 100));
    }

    /**
     * Have ILIAS recompute the learner's learning progress status.
     */
    public static function pushLearningProgress(int $objid, int $userid): void
    {
        try {
            \ilLPStatusWrapper::_updateStatus($objid, $userid);
        } catch (\Throwable $e) {
            Ilias::log('could not update the learning progress: ' . $e->getMessage());
        }
    }

    /**
     * Refresh the learning progress of every learner of an object (after
     * the threshold or the modes changed).
     */
    public static function pushAll(int $objid): void
    {
        foreach (array_keys(self::rows($objid)) as $userid) {
            self::pushLearningProgress($objid, (int) $userid);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function raw(int $objid, int $userid): ?array
    {
        $db = Ilias::db();
        $res = $db->queryF(
            'SELECT * FROM ' . self::TABLE . ' WHERE obj_id = %s AND usr_id = %s',
            ['integer', 'integer'],
            [$objid, $userid]
        );
        $row = $db->fetchAssoc($res);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function map(array $row): \stdClass
    {
        return (object) [
            'userid' => (int) $row['usr_id'],
            'earnedxp' => (float) $row['earned_xp'],
            'totalxp' => (float) $row['total_xp'],
            'earnedunits' => (float) $row['earned_units'],
            'totalunits' => (float) $row['total_units'],
            'practiceprogress' => (float) $row['practice_prg'],
            'learnprogress' => (float) $row['learn_prg'],
            'progress' => (float) $row['overall_prg'],
            'timemodified' => (int) $row['modified'],
        ];
    }
}
