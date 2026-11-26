<?php

/**
 * The plugin's part in ILIAS's resource storage: pictures of the
 * assistant between their upload and the saving of the configuration.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

use ILIAS\ResourceStorage\Stakeholder\AbstractResourceStakeholder;

class ilTarsusAvatarStakeholder extends AbstractResourceStakeholder
{
    /**
     * ILIAS 9's interface asks for a constructor its base class lacks;
     * from ILIAS 10 on the base class has one, which notes the owner.
     */
    public function __construct()
    {
        $constructor = (new ReflectionClass(AbstractResourceStakeholder::class))->getConstructor();
        if ($constructor !== null && !$constructor->isAbstract()) {
            $constructor->invoke($this);
        }
    }

    public function getId(): string
    {
        return 'xtar_avatar';
    }

    public function getOwnerOfNewResources(): int
    {
        global $DIC;
        return (int) $DIC->user()->getId();
    }
}
