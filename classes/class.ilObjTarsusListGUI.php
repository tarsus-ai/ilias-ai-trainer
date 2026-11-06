<?php

/**
 * How Tarsus objects appear in course and category listings: offline flag
 * for teachers, the learner's own progress for learners.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */

require_once __DIR__ . '/../src/autoload.php';

use TarsusIlias\Activity\Scoring;
use TarsusIlias\Activity\Settings;

class ilObjTarsusListGUI extends ilObjectPluginListGUI
{
    public function initType(): void
    {
        $this->setType(ilTarsusPlugin::ID);
    }

    public function getGuiClass(): string
    {
        return 'ilObjTarsusGUI';
    }

    public function initCommands(): array
    {
        return [
            [
                'permission' => 'read',
                'cmd' => 'showContent',
                'default' => true,
            ],
            [
                'permission' => 'write',
                'cmd' => 'showMaterials',
                'txt' => $this->txt('tab_materials'),
                'default' => false,
            ],
            [
                'permission' => 'write',
                'cmd' => 'editSettings',
                'txt' => $this->txt('tab_settings'),
                'default' => false,
            ],
        ];
    }

    public function getProperties(): array
    {
        global $DIC;

        $props = [];
        $objid = (int) $this->obj_id;

        if (!ilObjTarsusAccess::checkOnline($objid)) {
            $props[] = [
                'alert' => true,
                'property' => $this->txt('status'),
                'value' => $this->txt('offline'),
            ];
            return $props;
        }

        $userid = (int) $DIC->user()->getId();
        if ($userid > 0 && (!defined('ANONYMOUS_USER_ID') || $userid !== (int) ANONYMOUS_USER_ID)) {
            $row = Scoring::row($objid, $userid);
            if ($row !== null) {
                $settings = Settings::load($objid);
                if ($settings->testenabled || $settings->buildenabled) {
                    $props[] = [
                        'alert' => false,
                        'property' => $this->txt('your_progress'),
                        'value' => (int) round(Scoring::progress($settings, $row)) . ' %',
                    ];
                }
            }
        }

        return $props;
    }
}
