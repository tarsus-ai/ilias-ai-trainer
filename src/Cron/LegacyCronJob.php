<?php

namespace TarsusIlias\Cron;

/*
 * The cron job for ILIAS 9 and 10 (ilCronJob). Declared only where that
 * API exists, so loading this file on another release is harmless.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */

if (class_exists('ilCronJob') && enum_exists('ILIAS\\Cron\\Schedule\\CronJobScheduleType')) {
    class LegacyCronJob extends \ilCronJob
    {
        public function getId(): string
        {
            return Sweep::JOB_ID;
        }

        public function getTitle(): string
        {
            return \TarsusIlias\Support\Ilias::txt('cron_title');
        }

        public function getDescription(): string
        {
            return \TarsusIlias\Support\Ilias::txt('cron_description');
        }

        public function hasAutoActivation(): bool
        {
            return true;
        }

        public function hasFlexibleSchedule(): bool
        {
            return true;
        }

        public function getDefaultScheduleType(): \ILIAS\Cron\Schedule\CronJobScheduleType
        {
            return \ILIAS\Cron\Schedule\CronJobScheduleType::SCHEDULE_TYPE_IN_MINUTES;
        }

        public function getDefaultScheduleValue(): ?int
        {
            return 15;
        }

        public function run(): \ilCronJobResult
        {
            $outcome = Sweep::run();
            $result = new \ilCronJobResult();
            if (!$outcome['ok']) {
                $result->setStatus(\ilCronJobResult::STATUS_FAIL);
            } elseif ($outcome['acted']) {
                $result->setStatus(\ilCronJobResult::STATUS_OK);
            } else {
                $result->setStatus(\ilCronJobResult::STATUS_NO_ACTION);
            }
            $result->setMessage($outcome['message']);
            return $result;
        }
    }
}
