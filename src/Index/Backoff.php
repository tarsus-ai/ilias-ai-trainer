<?php

namespace TarsusIlias\Index;

/**
 * Capped exponential backoff for retries: 120 -> 240 -> 480 -> 960 s,
 * four attempts at most, with optional jitter so a batch does not retry in
 * lockstep.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Backoff
{
    public const RETRY_LIMIT = 4;

    public const BASE_SECONDS = 120;

    public static function delay(int $attempt, bool $jitter = false): int
    {
        $attempt = max(1, $attempt);
        $delay = self::BASE_SECONDS * (2 ** ($attempt - 1));

        if ($jitter) {
            $spread = (int) round($delay * 0.1);
            $delay += random_int(-$spread, $spread);
        }

        return max(0, (int) $delay);
    }

    public static function exhausted(int $attempts): bool
    {
        return $attempts >= self::RETRY_LIMIT;
    }
}
