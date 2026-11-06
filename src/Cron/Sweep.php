<?php

namespace TarsusIlias\Cron;

use TarsusIlias\Index\Reconciler;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;

/**
 * What the cron job does, independent of the ILIAS release's cron API:
 * reconcile every course with indexed material against the brain.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Sweep
{
    public const JOB_ID = 'xtar_sync';

    /** Changed bits one run re-sends at most. */
    public const UPLOAD_BUDGET = 10;

    /**
     * @return array{ok: bool, acted: bool, message: string}
     */
    public static function run(): array
    {
        if (Config::killswitch()) {
            return ['ok' => true, 'acted' => false, 'message' => 'Kill switch engaged: nothing sent.'];
        }
        try {
            $summary = (new Reconciler())->reconcileAll(self::UPLOAD_BUDGET);
        } catch (\Throwable $e) {
            Ilias::log('cron run failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'acted' => false, 'message' => substr($e->getMessage(), 0, 250)];
        }
        $acted = ($summary['withdrawn'] + $summary['uploaded'] + $summary['confirmed']) > 0;
        $message = sprintf(
            '%d course(s) checked: %d withdrawn, %d changed, %d re-sent, %d confirmed, %d failed.',
            $summary['containers'],
            $summary['withdrawn'],
            $summary['changed'],
            $summary['uploaded'],
            $summary['confirmed'],
            $summary['failed']
        );
        return ['ok' => $summary['failed'] === 0, 'acted' => $acted, 'message' => $message];
    }
}
