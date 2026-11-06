<?php

/**
 * Tarsus for ILIAS - repository object plugin.
 *
 * An AI learning companion grounded in a course's own material: learners
 * ask questions, test and build their knowledge; teachers choose which
 * material the assistant may use.
 *
 * The plugin class also contributes the cron job that keeps the brain in
 * step with the repository. ILIAS 11 renamed the cron API, so the base
 * class picks the provider interface the running release knows.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */

require_once __DIR__ . '/../src/autoload.php';

if (interface_exists('ILIAS\\Cron\\Job\\JobProvider')) {
    /**
     * ILIAS 11 and later.
     */
    abstract class ilTarsusPluginBase extends ilRepositoryObjectPlugin implements \ILIAS\Cron\Job\JobProvider
    {
        public function getCronJobInstances(): array
        {
            $job = \TarsusIlias\Cron\Jobs::create();
            return $job === null ? [] : [$job];
        }

        public function getCronJobInstance(string $jobId): \ILIAS\Cron\CronJob
        {
            $job = \TarsusIlias\Cron\Jobs::create();
            if ($job === null || $job->getId() !== $jobId) {
                throw new OutOfBoundsException('Unknown cron job ' . $jobId);
            }
            return $job;
        }
    }
} elseif (interface_exists('ilCronJobProvider')) {
    /**
     * ILIAS 9 and 10.
     */
    abstract class ilTarsusPluginBase extends ilRepositoryObjectPlugin implements ilCronJobProvider
    {
        public function getCronJobInstances(): array
        {
            $job = \TarsusIlias\Cron\Jobs::create();
            return $job === null ? [] : [$job];
        }

        public function getCronJobInstance(string $jobId): ilCronJob
        {
            $job = \TarsusIlias\Cron\Jobs::create();
            if ($job === null || $job->getId() !== $jobId) {
                throw new OutOfBoundsException('Unknown cron job ' . $jobId);
            }
            return $job;
        }
    }
} else {
    /**
     * A release without plugin cron jobs.
     */
    abstract class ilTarsusPluginBase extends ilRepositoryObjectPlugin
    {
    }
}

class ilTarsusPlugin extends ilTarsusPluginBase
{
    public const ID = 'xtar';

    /** @var string[] The plugin's tables, for uninstall. */
    private const TABLES = [
        'rep_robj_xtar_data',
        'rep_robj_xtar_idx',
        'rep_robj_xtar_inc',
        'rep_robj_xtar_prg',
        'rep_robj_xtar_use',
        'rep_robj_xtar_grd',
        'rep_robj_xtar_cfg',
    ];

    protected function init(): void
    {
        \TarsusIlias\Support\Ilias::setPlugin($this);
    }

    public function getPluginName(): string
    {
        return 'Tarsus';
    }

    public function allowCopy(): bool
    {
        return true;
    }

    /**
     * On the first activation, give the new object type sensible default
     * permissions in the course and group role templates (and the roles of
     * existing courses and groups), so members can use a Tarsus object as
     * soon as a tutor creates one.
     */
    protected function afterActivation(): void
    {
        \TarsusIlias\Support\Permissions::seedDefaults();
    }

    protected function uninstallCustom(): void
    {
        $db = $this->db;
        foreach (self::TABLES as $table) {
            if ($db->tableExists($table)) {
                $db->dropTable($table, false);
            }
            if ($db->sequenceExists($table)) {
                $db->dropSequence($table);
            }
        }
        // The cron job's registration goes with the plugin.
        if ($db->tableExists('cron_job')) {
            $db->manipulateF('DELETE FROM cron_job WHERE job_id = %s', ['text'], [\TarsusIlias\Cron\Sweep::JOB_ID]);
        }
    }
}
