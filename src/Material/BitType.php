<?php

namespace TarsusIlias\Material;

/**
 * The four bit types the brain ingests: it tracks text, links, documents
 * and audio/video differently.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class BitType
{
    public const TEXT = 'text';
    public const URL = 'url';
    public const DOCUMENT = 'document';
    public const MEDIA = 'media';

    /** @var string[] */
    public const ALL = [self::TEXT, self::URL, self::DOCUMENT, self::MEDIA];

    /**
     * Classify a file by its MIME type.
     */
    public static function forMime(string $mimetype): string
    {
        $mimetype = strtolower($mimetype);
        if (strncmp($mimetype, 'audio/', 6) === 0 || strncmp($mimetype, 'video/', 6) === 0) {
            return self::MEDIA;
        }
        return self::DOCUMENT;
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
