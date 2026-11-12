<?php

/**
 * Tarsus repository object: one AI learning companion in a course.
 *
 * Holds the didactic settings and reports the learning progress the
 * brain's figures add up to.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

require_once __DIR__ . '/../src/autoload.php';

use TarsusIlias\Activity\Scoring;
use TarsusIlias\Activity\Settings;
use TarsusIlias\Index\Inclusions;
use TarsusIlias\Material\ResourceId;

class ilObjTarsus extends ilObjectPlugin implements ilLPStatusPluginInterface
{
    protected ?Settings $settings = null;

    public function __construct(int $a_ref_id = 0)
    {
        parent::__construct($a_ref_id);
    }

    final protected function initType(): void
    {
        $this->setType(ilTarsusPlugin::ID);
    }

    protected function doCreate(bool $clone_mode = false): void
    {
        $this->settings = new Settings($this->getId());
        $this->settings->save();
    }

    protected function doRead(): void
    {
        $this->settings = Settings::load($this->getId());
    }

    protected function doUpdate(): void
    {
        $this->getSettings()->objid = $this->getId();
        $this->getSettings()->save();
    }

    protected function doDelete(): void
    {
        Settings::delete($this->getId());
        Inclusions::forgetObject($this->getId());
        Scoring::forgetObject($this->getId());
    }

    /**
     * Copy settings and inclusions. Inclusions point at material by its
     * course and object reference; when the material was copied along (a
     * course copy), they follow it, otherwise only those of the same
     * course stay. The copy starts with no learner history.
     */
    protected function doCloneObject($new_obj, $a_target_id, $a_copy_id = null): void
    {
        /** @var ilObjTarsus $new_obj */
        $settings = $this->getSettings()->copyTo($new_obj->getId());
        $settings->save();
        $new_obj->settings = $settings;

        $mappings = [];
        if ($a_copy_id) {
            try {
                $mappings = ilCopyWizardOptions::_getInstance((int) $a_copy_id)->getMappings();
            } catch (Throwable $e) {
                $mappings = [];
            }
        }
        $newcontainer = $new_obj->getRefId() > 0
            ? \TarsusIlias\Support\Ilias::scopeContainer($new_obj->getRefId())
            : 0;

        foreach (Inclusions::forObject($this->getId()) as $resourceid) {
            $parts = ResourceId::parse($resourceid);
            if ($parts === null || !ResourceId::isLocal($resourceid)) {
                continue;
            }
            $container = (int) ($mappings[$parts['containerref']] ?? $parts['containerref']);
            $refid = (int) ($mappings[$parts['refid']] ?? $parts['refid']);
            if ($newcontainer > 0 && $container !== $newcontainer) {
                continue;
            }
            Inclusions::include(
                $new_obj->getId(),
                ResourceId::build($container, $parts['type'], $refid, $parts['bitkey'])
            );
        }
    }

    public function getSettings(): Settings
    {
        if ($this->settings === null) {
            $this->settings = $this->getId() > 0 ? Settings::load($this->getId()) : new Settings();
        }
        return $this->settings;
    }

    public function isOnline(): bool
    {
        return $this->getSettings()->online;
    }

    /**
     * The course (or group) whose material the object uses.
     */
    public function getContainerRefId(): int
    {
        return $this->getRefId() > 0 ? \TarsusIlias\Support\Ilias::scopeContainer($this->getRefId()) : 0;
    }

    // ------------------------------------------------------------------
    // Learning progress
    // ------------------------------------------------------------------

    /**
     * @return int[]
     */
    public function getLPCompleted(): array
    {
        $settings = $this->getSettings();
        $ids = [];
        foreach (Scoring::rows($this->getId()) as $userid => $row) {
            if (Scoring::lpStatus($settings, $row) === Scoring::LP_COMPLETED) {
                $ids[] = (int) $userid;
            }
        }
        return $ids;
    }

    /**
     * @return int[]
     */
    public function getLPNotAttempted(): array
    {
        return [];
    }

    /**
     * @return int[]
     */
    public function getLPFailed(): array
    {
        return [];
    }

    /**
     * @return int[]
     */
    public function getLPInProgress(): array
    {
        $settings = $this->getSettings();
        $ids = [];
        foreach (Scoring::rows($this->getId()) as $userid => $row) {
            if (Scoring::lpStatus($settings, $row) === Scoring::LP_IN_PROGRESS) {
                $ids[] = (int) $userid;
            }
        }
        return $ids;
    }

    public function getLPStatusForUser(int $a_user_id): int
    {
        return Scoring::lpStatus($this->getSettings(), Scoring::row($this->getId(), $a_user_id));
    }

    /**
     * The learner's progress in percent (shown in the learning progress
     * tables).
     */
    public function getPercentageForUser(int $a_user_id): int
    {
        return (int) round(Scoring::progress($this->getSettings(), Scoring::row($this->getId(), $a_user_id)));
    }
}
