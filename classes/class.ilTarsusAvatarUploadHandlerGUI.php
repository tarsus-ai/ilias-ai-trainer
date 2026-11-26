<?php

/**
 * Receives the assistant's picture from the file field of the plugin
 * configuration (ILIAS's UI framework uploads files before the form is
 * sent) and keeps it in ILIAS's resource storage until the form is saved.
 * Saving reads it and removes it; pictures uploaded but never saved are
 * removed the next time the configuration opens.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 *
 * @ilCtrl_isCalledBy ilTarsusAvatarUploadHandlerGUI: ilTarsusConfigGUI
 */

require_once __DIR__ . '/../src/autoload.php';

use ILIAS\FileUpload\Handler\AbstractCtrlAwareIRSSUploadHandler;
use ILIAS\FileUpload\Handler\HandlerResult;
use ILIAS\ResourceStorage\Stakeholder\ResourceStakeholder;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;

class ilTarsusAvatarUploadHandlerGUI extends AbstractCtrlAwareIRSSUploadHandler
{
    /** Configuration key of the uploads not saved yet (identifiers, comma-separated). */
    private const PENDING = 'robot_avatar_pending';

    protected function getStakeholder(): ResourceStakeholder
    {
        return new ilTarsusAvatarStakeholder();
    }

    /**
     * @return string[]
     */
    protected function getClassPath(): array
    {
        return [ilAdministrationGUI::class, ilObjComponentSettingsGUI::class, ilTarsusConfigGUI::class, self::class];
    }

    protected function getUploadResult(): HandlerResult
    {
        $result = parent::getUploadResult();
        if ($result->getStatus() === HandlerResult::STATUS_OK && $result->getFileIdentifier() !== '') {
            $pending = self::pending();
            $pending[] = $result->getFileIdentifier();
            Config::set(self::PENDING, implode(',', array_unique($pending)));
        }
        return $result;
    }

    /**
     * The picture uploaded under an identifier, or null when there is none.
     */
    public static function read(string $identifier): ?string
    {
        $irss = Ilias::dic()->resourceStorage();
        $rid = $irss->manage()->find($identifier);
        if ($rid === null) {
            return null;
        }
        return (string) $irss->consume()->stream($rid)->getStream()->getContents();
    }

    /**
     * Remove every upload not saved yet from the resource storage.
     */
    public static function discardPending(): void
    {
        $pending = self::pending();
        if ($pending === []) {
            return;
        }
        Config::set(self::PENDING, '');
        $irss = Ilias::dic()->resourceStorage();
        foreach ($pending as $identifier) {
            try {
                $rid = $irss->manage()->find($identifier);
                if ($rid !== null) {
                    $irss->manage()->remove($rid, new ilTarsusAvatarStakeholder());
                }
            } catch (Throwable $e) {
                Ilias::log('removing an uploaded picture: ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * @return string[]
     */
    private static function pending(): array
    {
        return array_values(array_filter(
            explode(',', (string) Config::get(self::PENDING, '')),
            static fn(string $identifier): bool => $identifier !== ''
        ));
    }
}
