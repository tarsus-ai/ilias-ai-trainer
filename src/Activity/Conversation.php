<?php

namespace TarsusIlias\Activity;

use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Pseudonymizer;

/**
 * The shared identity of every t0 call: context, robot, user and the
 * object's didactic settings.
 *
 * The context is the Tarsus object (site-scoped id), the robot identity is
 * a global admin setting, and the user travels as pseudonym + display name
 * + interface language. Email addresses and logins never leave ILIAS.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Conversation
{
    /** @var string[] Languages the Tarsus t0 surface supports. */
    private const LANGUAGES = ['en', 'de', 'fr', 'ar', 'zh', 'it', 'es', 'he', 'ja', 'nl', 'tr', 'uk', 'ru'];

    /**
     * @param string[] $allowed Resource ids allowed to ground the call.
     * @return array<string, mixed>
     */
    public static function params(int $objid, string $title, string $description, Settings $settings, array $allowed): array
    {
        $name = trim($title);
        $description = trim(html_entity_decode(strip_tags($description), ENT_QUOTES, 'UTF-8'));
        if ($description === '') {
            // The brain requires a non-empty description.
            $description = $name;
        }

        $user = Ilias::user();

        return [
            'contextid' => Scope::conversationContextId($objid),
            'contextname' => $name,
            'contextdescription' => $description,
            'conversationstyle' => $settings->conversationstyle,
            'dqr' => $settings->dqr,
            'industry' => trim($settings->industry),
            // Full-text search stays off: semantic retrieval alone grounds
            // the answers.
            'fulltextsearch' => false,
            'taxonomylevels' => $settings->taxonomylevels,
            'questiontypes' => $settings->questiontypes,
            'operators' => $settings->operators,
            'gif' => $settings->gifenabled,
            'learningmethod' => $settings->learningmethod,
            'anticheat' => $settings->anticheat,
            'learntempo' => $settings->learntempo,
            'robotname' => Config::robotName(),
            'robotid' => Config::robotId(),
            'robotdescription' => Config::robotDescription(),
            'userid' => Pseudonymizer::pseudonymize((int) $user->getId()),
            'username' => trim((string) $user->getFullname()),
            'userlanguage' => self::language(),
            'resourceids' => $allowed,
        ];
    }

    /**
     * The user's interface language, reduced to what the brain supports.
     */
    public static function language(): string
    {
        $language = Ilias::userLanguage();
        return in_array($language, self::LANGUAGES, true) ? $language : 'de';
    }
}
