<?php

namespace TarsusIlias\Support;

use TarsusIlias\Client\ClientFactory;

/**
 * The brain's voices: a cached catalogue and the choice for a learner.
 *
 * The brain lists voices and demands one by name on every narration call.
 * The admin decides: one voice for everyone, or the first voice of the
 * learner's language with the configured voice as the fallback.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Voices
{
    public const MODE_FIXED = 'fixed';
    public const MODE_AUTO = 'auto';
    public const DEFAULT_VOICE = 'Daniel';

    /** Seconds the catalogue is kept before asking the brain again. */
    private const CACHE_TTL = 86400;

    /**
     * @return array<int, array{alias: string, country: string, gender: string}>
     */
    public static function catalogue(bool $refresh = false): array
    {
        $cached = json_decode((string) Config::get('voices_cache', ''), true);
        $fetched = (int) Config::get('voices_cachetime', '0');
        if (!$refresh && is_array($cached) && $fetched > time() - self::CACHE_TTL) {
            return $cached;
        }

        try {
            $voices = ClientFactory::getClient()->listVoices();
        } catch (\Throwable $e) {
            // An unreachable brain keeps the last known list, however old.
            return is_array($cached) ? $cached : [];
        }
        if ($voices !== []) {
            Config::set('voices_cache', (string) json_encode($voices));
            Config::set('voices_cachetime', (string) time());
        }
        return $voices;
    }

    /**
     * @return array<string, string> alias => "Alias (language, gender)".
     */
    public static function options(): array
    {
        $lng = Ilias::dic()->language();
        $options = [];
        foreach (self::catalogue() as $voice) {
            $code = strtolower(substr((string) $voice['country'], 0, 2));
            $language = $lng->txt('meta_l_' . $code);
            if ($language === '' || $language[0] === '-') {
                $language = (string) $voice['country'];
            }
            $gender = in_array($voice['gender'], ['female', 'male'], true)
                ? Ilias::txt('voice_' . $voice['gender']) : (string) $voice['gender'];
            $options[(string) $voice['alias']] = Ilias::txt('voice_label', [
                'alias' => (string) $voice['alias'], 'language' => $language, 'gender' => $gender,
            ]);
        }
        return $options;
    }

    /**
     * The voice to read to a learner with.
     */
    public static function choose(string $language): string
    {
        $fixed = trim((string) Config::get('voice', ''));
        if ($fixed === '') {
            $fixed = self::DEFAULT_VOICE;
        }
        if (Config::get('voice_mode', self::MODE_AUTO) === self::MODE_FIXED) {
            return $fixed;
        }

        $code = strtolower(substr($language, 0, 2));
        foreach (self::catalogue() as $voice) {
            if (strtolower(substr((string) $voice['country'], 0, 2)) === $code) {
                return (string) $voice['alias'];
            }
        }
        return $fixed;
    }
}
