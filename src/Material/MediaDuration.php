<?php

namespace TarsusIlias\Material;

/**
 * How long an audio or video file plays.
 *
 * Indexing audio or video counts one AI interaction per started two hours
 * of material, so the length of a recording decides what its upload costs.
 * ILIAS ships getID3, which reads the length of every common format; when
 * it is missing, the two containers that state their length in a fixed
 * place - WAV and the MPEG-4 family (mp4, m4a, m4v, mov) - are read
 * directly. A length that cannot be determined is null, and the upload
 * then counts as one block.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class MediaDuration
{
    /** Atoms a file of the MPEG-4 family may start with. */
    private const MP4_LEADING_ATOMS = ['ftyp', 'moov', 'mdat', 'free', 'skip', 'wide', 'pnot'];

    /**
     * The playing time of a file in seconds, null when it cannot be read.
     */
    public static function seconds(string $path): ?float
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $seconds = self::viaGetId3($path) ?? self::wave($path) ?? self::mpeg4($path);
        return ($seconds !== null && $seconds > 0 && is_finite($seconds)) ? $seconds : null;
    }

    private static function viaGetId3(string $path): ?float
    {
        if (!class_exists('getID3')) {
            return null;
        }
        try {
            $info = (new \getID3())->analyze($path);
        } catch (\Throwable $e) {
            return null;
        }
        $seconds = is_array($info) ? ($info['playtime_seconds'] ?? null) : null;
        return is_numeric($seconds) && (float) $seconds > 0 ? (float) $seconds : null;
    }

    /**
     * A RIFF/WAVE file: the size of the data chunk over the byte rate of
     * the format chunk.
     */
    private static function wave(string $path): ?float
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $head = (string) fread($handle, 12);
            if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
                return null;
            }
            $filesize = (int) filesize($path);
            $byterate = 0;
            $offset = 12;
            while ($offset + 8 <= $filesize) {
                fseek($handle, $offset);
                $chunk = (string) fread($handle, 8);
                if (strlen($chunk) < 8) {
                    break;
                }
                $id = substr($chunk, 0, 4);
                $size = (int) unpack('V', substr($chunk, 4, 4))[1];
                if ($id === 'fmt ') {
                    $format = (string) fread($handle, 16);
                    if (strlen($format) < 16) {
                        return null;
                    }
                    $byterate = (int) unpack('V', substr($format, 8, 4))[1];
                } elseif ($id === 'data') {
                    if ($byterate <= 0) {
                        return null;
                    }
                    // A streamed file leaves the size open: the data runs
                    // to the end of the file.
                    $available = $filesize - $offset - 8;
                    if ($size <= 0 || $size > $available) {
                        $size = $available;
                    }
                    return $size > 0 ? $size / $byterate : null;
                }
                // Chunks are padded to an even length.
                $offset += 8 + $size + ($size % 2);
            }
            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * A file of the MPEG-4 family: duration over time scale in the movie
     * header (moov > mvhd), wherever in the file the movie atom sits.
     */
    private static function mpeg4(string $path): ?float
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $filesize = (int) filesize($path);
            $first = self::atom($handle, 0, $filesize);
            if ($first === null || !in_array($first['type'], self::MP4_LEADING_ATOMS, true)) {
                return null;
            }
            $movie = self::find($handle, 'moov', 0, $filesize);
            if ($movie === null) {
                return null;
            }
            $header = self::find($handle, 'mvhd', $movie['body'], $movie['end']);
            if ($header === null) {
                return null;
            }
            fseek($handle, $header['body']);
            $body = (string) fread($handle, 32);
            if (strlen($body) < 20) {
                return null;
            }
            if (ord($body[0]) === 1) {
                // Version 1: 64-bit times.
                if (strlen($body) < 32) {
                    return null;
                }
                $timescale = (int) unpack('N', substr($body, 20, 4))[1];
                $parts = unpack('Nhigh/Nlow', substr($body, 24, 8));
                $duration = (float) $parts['high'] * 4294967296.0 + (float) $parts['low'];
            } else {
                $timescale = (int) unpack('N', substr($body, 12, 4))[1];
                $duration = (float) unpack('N', substr($body, 16, 4))[1];
            }
            return ($timescale > 0 && $duration > 0) ? $duration / $timescale : null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The first atom of a type between two offsets.
     *
     * @param resource $handle
     * @return array{type: string, body: int, end: int}|null
     */
    private static function find($handle, string $type, int $start, int $end): ?array
    {
        $offset = $start;
        // A sane file has a handful of atoms per level.
        for ($guard = 0; $guard < 10000 && $offset + 8 <= $end; $guard++) {
            $atom = self::atom($handle, $offset, $end);
            if ($atom === null) {
                return null;
            }
            if ($atom['type'] === $type) {
                return $atom;
            }
            $offset = $atom['end'];
        }
        return null;
    }

    /**
     * Read the atom header at an offset.
     *
     * @param resource $handle
     * @return array{type: string, body: int, end: int}|null
     */
    private static function atom($handle, int $offset, int $limit): ?array
    {
        if ($offset + 8 > $limit) {
            return null;
        }
        fseek($handle, $offset);
        $head = (string) fread($handle, 8);
        if (strlen($head) < 8) {
            return null;
        }
        $size = (float) unpack('N', substr($head, 0, 4))[1];
        $type = substr($head, 4, 4);
        $headersize = 8;
        if ($size === 1.0) {
            // A 64-bit size follows the type.
            $extended = (string) fread($handle, 8);
            if (strlen($extended) < 8) {
                return null;
            }
            $parts = unpack('Nhigh/Nlow', $extended);
            $size = (float) $parts['high'] * 4294967296.0 + (float) $parts['low'];
            $headersize = 16;
        } elseif ($size === 0.0) {
            // The atom runs to the end of its container.
            $size = (float) ($limit - $offset);
        }
        if ($size < $headersize || $offset + $size > $limit) {
            return null;
        }
        return ['type' => $type, 'body' => $offset + $headersize, 'end' => (int) ($offset + $size)];
    }
}
