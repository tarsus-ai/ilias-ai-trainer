<?php

namespace TarsusIlias\Output;

/**
 * Pictures and sound exactly as Tarsus sends them, checked before they
 * reach the browser: base64 data URIs (generated diagrams, pictures from
 * the material, narration) and https links (animated GIFs). A value that
 * is neither is dropped; nothing is guessed or converted.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Media
{
    /** Image types a browser shows, with the spelling the data URI gets. */
    private const IMAGE_TYPES = [
        'png' => 'png',
        'jpeg' => 'jpeg',
        'jpg' => 'jpeg',
        'pjpeg' => 'jpeg',
        'gif' => 'gif',
        'webp' => 'webp',
        'svg+xml' => 'svg+xml',
        'svg' => 'svg+xml',
        'bmp' => 'bmp',
        'avif' => 'avif',
    ];

    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';

    /** Characters that never belong in a link put into an attribute. */
    private const LINK_FORBIDDEN = " \t\r\n\"'<>`\\";

    /**
     * A picture for an <img>: a base64 data URI of a known image type, or
     * an https link. '' when the value is neither (an empty GIF field, for
     * instance, means Tarsus found no GIF).
     */
    public static function image(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strncasecmp($value, 'https://', 8) === 0) {
            return strpbrk($value, self::LINK_FORBIDDEN) === false ? $value : '';
        }
        if (strncasecmp($value, 'data:image/', 11) !== 0) {
            return '';
        }
        $comma = strpos($value, ',');
        if ($comma === false) {
            return '';
        }
        $header = strtolower(substr($value, 11, $comma - 11));
        if (substr($header, -7) !== ';base64') {
            return '';
        }
        $subtype = substr($header, 0, -7);
        if (!isset(self::IMAGE_TYPES[$subtype])) {
            return '';
        }
        $payload = self::base64(substr($value, $comma + 1));
        return $payload === '' ? '' : 'data:image/' . self::IMAGE_TYPES[$subtype] . ';base64,' . $payload;
    }

    /**
     * A picture from raw base64 and its file extension, as image bits
     * carry them (data.content and data.extension). Tarsus stores SVG
     * pictures with the extension "svg" or "bin".
     */
    public static function imageFromBase64(string $content, string $extension): string
    {
        $extension = strtolower(trim($extension, ". \t"));
        if ($extension === 'bin') {
            $extension = 'svg';
        }
        if (!isset(self::IMAGE_TYPES[$extension])) {
            return '';
        }
        $payload = self::base64($content);
        return $payload === '' ? '' : 'data:image/' . self::IMAGE_TYPES[$extension] . ';base64,' . $payload;
    }

    /**
     * A sound as a data URI for an <audio>. Tarsus does not always say
     * which format it sends, so the format is read from the first bytes;
     * the declared type is used only when those are not recognised.
     */
    public static function audio(string $base64, string $declared = ''): string
    {
        $payload = self::base64($base64);
        if ($payload === '') {
            return '';
        }
        return 'data:' . self::audioType($payload, $declared) . ';base64,' . $payload;
    }

    private static function audioType(string $payload, string $declared): string
    {
        $head = base64_decode(substr($payload, 0, 16), true);
        if (is_string($head) && $head !== '') {
            if (strncmp($head, 'RIFF', 4) === 0) {
                return 'audio/wav';
            }
            if (strncmp($head, 'ID3', 3) === 0) {
                return 'audio/mpeg';
            }
            if (strncmp($head, 'OggS', 4) === 0) {
                return 'audio/ogg';
            }
            if (strncmp($head, 'fLaC', 4) === 0) {
                return 'audio/flac';
            }
            if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) {
                return 'audio/webm';
            }
            if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') {
                return 'audio/mp4';
            }
            if (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) {
                return 'audio/mpeg';
            }
        }
        $declared = strtolower(trim($declared));
        $subtype = substr($declared, 6);
        if (strncmp($declared, 'audio/', 6) === 0 && $subtype !== ''
            && strspn($subtype, 'abcdefghijklmnopqrstuvwxyz0123456789.+-') === strlen($subtype)) {
            return $declared;
        }
        return 'audio/wav';
    }

    /**
     * Base64 without line breaks or spaces; '' when anything else is in it.
     */
    private static function base64(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t", ' '], '', $value);
        if ($value === '' || strspn($value, self::BASE64_ALPHABET) !== strlen($value)) {
            return '';
        }
        return $value;
    }
}
