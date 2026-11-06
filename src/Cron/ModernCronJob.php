<?php

namespace TarsusIlias\Cron;

/*
 * The cron job for ILIAS 11 and later (ILIAS\Cron\CronJob). Declared only
 * where that API exists, so loading this file on another release is
 * harmless.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */

if (class_exists('ILIAS\\Cron\\CronJob') && enum_exists('ILIAS\\Cron\\Job\\Schedule\\JobScheduleType')) {
    class ModernCronJob extends \ILIAS\Cron\CronJob
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

        public function getDefaultScheduleType(): \ILIAS\Cron\Job\Schedule\JobScheduleType
        {
            return \ILIAS\Cron\Job\Schedule\JobScheduleType::IN_MINUTES;
        }

        public function getDefaultScheduleValue(): ?int
        {
            return 15;
        }

        public function run(): \ILIAS\Cron\Job\JobResult
        {
            $outcome = Sweep::run();
            $result = new \ILIAS\Cron\Job\JobResult();
            if (!$outcome['ok']) {
                $result->setStatus(\ILIAS\Cron\Job\JobResult::STATUS_FAIL);
            } elseif ($outcome['acted']) {
                $result->setStatus(\ILIAS\Cron\Job\JobResult::STATUS_OK);
            } else {
                $result->setStatus(\ILIAS\Cron\Job\JobResult::STATUS_NO_ACTION);
            }
            $result->setMessage($outcome['message']);
            return $result;
        }
    }
}
