<?php

namespace TarsusIlias\Support;

/**
 * Site-wide plugin settings, stored in the plugin's own key/value table.
 *
 * One place to connect ILIAS to Tarsus, to record the customer's plan and
 * to bound traffic and credit spend. Operational switches (kill switch,
 * mock mode, limits) are read
 * fresh from the database on every call, bypassing the request cache, so
 * that flipping one mid-run - during a long cron run, say - takes effect
 * immediately.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Config
{
    public const TABLE = 'rep_robj_xtar_cfg';

    /** Default outbound calls per minute. */
    public const DEFAULT_RATE_LIMIT = 60;

    /** Default assistant name. */
    public const DEFAULT_ROBOT_NAME = 'Tarsus';

    /** @var array<string, ?string>|null Request cache of every stored value. */
    private static ?array $cache = null;

    /**
     * Read a setting (request-cached).
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        if (!array_key_exists($key, self::$cache) || self::$cache[$key] === null) {
            return $default;
        }
        return self::$cache[$key];
    }

    /**
     * Read a setting straight from the database, skipping the request cache.
     */
    public static function fresh(string $key, ?string $default = null): ?string
    {
        $db = Ilias::db();
        $res = $db->queryF(
            'SELECT cfg_value FROM ' . self::TABLE . ' WHERE cfg_key = %s',
            ['text'],
            [$key]
        );
        $row = $db->fetchAssoc($res);
        if (!$row || $row['cfg_value'] === null) {
            return $default;
        }
        return (string) $row['cfg_value'];
    }

    /**
     * Store a setting; null removes it.
     */
    public static function set(string $key, ?string $value): void
    {
        $db = Ilias::db();
        if ($value === null) {
            $db->manipulateF('DELETE FROM ' . self::TABLE . ' WHERE cfg_key = %s', ['text'], [$key]);
        } else {
            $db->replace(
                self::TABLE,
                ['cfg_key' => ['text', $key]],
                ['cfg_value' => ['clob', $value]]
            );
        }
        self::load();
        self::$cache[$key] = $value;
    }

    /**
     * Forget the request cache (tests, long cron runs).
     */
    public static function reset(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        $db = Ilias::db();
        $res = $db->query('SELECT cfg_key, cfg_value FROM ' . self::TABLE);
        while ($row = $db->fetchAssoc($res)) {
            self::$cache[(string) $row['cfg_key']] = $row['cfg_value'] === null ? null : (string) $row['cfg_value'];
        }
    }

    // ------------------------------------------------------------------
    // Typed accessors
    // ------------------------------------------------------------------

    public static function baseUrl(): string
    {
        return trim((string) self::get('base_url', ''));
    }

    public static function apiKey(): string
    {
        return trim((string) self::get('api_key', ''));
    }

    public static function brainId(): string
    {
        return trim((string) self::get('brain_id', ''));
    }

    /**
     * The Tarsus license key of an existing Tarsus customer. Such a
     * customer carries it in addition to the API key and in place of a
     * brain ID; the plugin sends it with every call, and Tarsus - not the
     * partner - bills the usage. Empty for partner customers.
     */
    public static function licenseKey(): string
    {
        return trim((string) self::get('license_key', ''));
    }

    /**
     * Whether the connection is complete: base URL, API key, and a brain
     * ID or a license key.
     */
    public static function isConnected(): bool
    {
        return self::baseUrl() !== '' && self::apiKey() !== '' && (self::brainId() !== '' || self::licenseKey() !== '');
    }

    /**
     * Mock mode defaults to on (fresh install), so no code path can reach a
     * live endpoint by accident.
     */
    public static function mockMode(): bool
    {
        $value = self::fresh('mockmode');
        return $value === null || $value === '1';
    }

    public static function killswitch(): bool
    {
        return self::fresh('killswitch') === '1';
    }

    public static function rateLimit(): int
    {
        $value = self::fresh('ratelimit');
        return $value === null || $value === '' ? self::DEFAULT_RATE_LIMIT : max(1, (int) $value);
    }

    public static function robotName(): string
    {
        $name = trim((string) self::get('robot_name', ''));
        return $name !== '' ? $name : self::DEFAULT_ROBOT_NAME;
    }

    public static function robotId(): string
    {
        $id = trim((string) self::get('robot_id', ''));
        return $id !== '' ? $id : self::robotName();
    }

    public static function robotDescription(): string
    {
        $description = trim((string) self::get('robot_description', ''));
        return $description !== '' ? $description : self::robotName();
    }

    /**
     * The uploaded assistant avatar as a data URI, or '' when none.
     */
    public static function robotAvatar(): string
    {
        $avatar = (string) self::get('robot_avatar', '');
        return preg_match('~^data:image/(png|jpeg|gif|webp|svg\+xml);base64,[A-Za-z0-9+/=]+$~', $avatar) ? $avatar : '';
    }

    /**
     * A short, stable token identifying this ILIAS installation (and
     * client), generated once. It keeps two installations that share one
     * brain apart.
     */
    public static function siteToken(): string
    {
        $token = (string) self::get('site_token', '');
        if (!preg_match('/^site[0-9a-f]{12}$/', $token)) {
            $token = 'site' . substr(bin2hex(random_bytes(16)), 0, 12);
            self::set('site_token', $token);
        }
        return $token;
    }

    /**
     * The HMAC secret for user pseudonyms, generated once. Rotating it
     * invalidates every conversation in the brain, so it is never rotated
     * implicitly.
     */
    public static function hmacSecret(): string
    {
        $secret = (string) self::get('hmac_secret', '');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            self::set('hmac_secret', $secret);
        }
        return $secret;
    }

    /**
     * Strip characters MySQL's three-byte utf8 cannot hold (emoji and other
     * supplementary-plane characters) before they reach a text column.
     */
    public static function bmp(string $value): string
    {
        return (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value);
    }
}
