<?php

namespace TarsusIlias\Material;

use TarsusIlias\Support\Config;

/**
 * Builds and parses the namespaced resource id that addresses a bit:
 *
 *     {sitetoken}:{containerref}:{type}:{refid}:{bitkey}
 *
 * - sitetoken keeps two ILIAS installations that share a brain apart;
 * - containerref scopes content to one course (or group);
 * - type + refid locate the source object so the payload can be
 *   re-extracted at send time;
 * - bitkey distinguishes several bits of one object (each link of a web
 *   link list); single-bit objects use 'main'.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class ResourceId
{
    public const MAIN = 'main';

    private const SEP = ':';

    public static function build(int $containerref, string $type, int $refid, string $bitkey = self::MAIN): string
    {
        return implode(self::SEP, [
            Config::siteToken(),
            $containerref,
            $type,
            $refid,
            self::cleanBitkey($bitkey),
        ]);
    }

    /**
     * @return array{sitetoken: string, containerref: int, type: string, refid: int, bitkey: string}|null
     */
    public static function parse(string $resourceid): ?array
    {
        $parts = explode(self::SEP, $resourceid);
        if (count($parts) !== 5) {
            return null;
        }

        [$sitetoken, $containerref, $type, $refid, $bitkey] = $parts;
        if ($sitetoken === '' || $type === '' || $bitkey === ''
                || !ctype_digit($containerref) || !ctype_digit($refid)
                || !preg_match('/^[a-z]{2,8}$/', $type)) {
            return null;
        }

        return [
            'sitetoken' => $sitetoken,
            'containerref' => (int) $containerref,
            'type' => $type,
            'refid' => (int) $refid,
            'bitkey' => $bitkey,
        ];
    }

    /**
     * The brain-side asset id of a container: every bit is filed under its
     * course (or group), so removing a course costs one call.
     */
    public static function assetId(int $containerref): string
    {
        return Config::siteToken() . self::SEP . $containerref;
    }

    /**
     * Whether an id belongs to this installation.
     */
    public static function isLocal(string $resourceid): bool
    {
        $parts = self::parse($resourceid);
        return $parts !== null && $parts['sitetoken'] === Config::siteToken();
    }

    private static function cleanBitkey(string $bitkey): string
    {
        $clean = preg_replace('/[^a-z0-9_.-]/i', '', $bitkey);
        return ($clean === '' || $clean === null) ? self::MAIN : $clean;
    }
}
