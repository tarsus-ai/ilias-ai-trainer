<?php

namespace TarsusIlias\Cron;

/**
 * Builds the cron job object for the running ILIAS release.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Jobs
{
    /**
     * @return object|null An ILIAS\Cron\CronJob (ILIAS 11+) or ilCronJob (ILIAS 9/10).
     */
    public static function create(): ?object
    {
        require_once __DIR__ . '/ModernCronJob.php';
        if (class_exists(ModernCronJob::class, false)) {
            return new ModernCronJob();
        }
        require_once __DIR__ . '/LegacyCronJob.php';
        if (class_exists(LegacyCronJob::class, false)) {
            return new LegacyCronJob();
        }
        return null;
    }
}
