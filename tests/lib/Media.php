<?php

/**
 * Tiny, valid-enough audio and video files of a chosen length: a WAV with
 * ten bytes per second and MPEG-4 files that consist of little more than
 * the movie header.
 *
 * @license GPL-3.0-or-later
 */

namespace TarsusIlias\Tests;

final class Media
{
    /**
     * A PCM WAV: mono, 8 bit, 10 Hz - ten bytes per second.
     */
    public static function wav(float $seconds): string
    {
        $data = str_repeat("\x80", (int) round($seconds * 10));
        $format = pack('vvVVvv', 1, 1, 10, 10, 1, 8);
        return 'RIFF' . pack('V', 4 + 8 + 16 + 8 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('V', 16) . $format
            . 'data' . pack('V', strlen($data)) . $data;
    }

    /**
     * An MPEG-4 file whose movie header states the length.
     *
     * @param int  $version   0: 32-bit times, 1: 64-bit times.
     * @param bool $movielast Whether the movie atom follows the media data
     *                        (a file not prepared for streaming).
     */
    public static function mp4(float $seconds, int $version = 0, bool $movielast = false, int $timescale = 1000): string
    {
        $duration = (int) round($seconds * $timescale);
        if ($version === 1) {
            $header = "\x01\x00\x00\x00" . pack('J', 0) . pack('J', 0) . pack('N', $timescale) . pack('J', $duration);
        } else {
            $header = "\x00\x00\x00\x00" . pack('N', 0) . pack('N', 0) . pack('N', $timescale) . pack('N', $duration);
        }
        $movie = self::atom('moov', self::atom('mvhd', $header . str_repeat("\x00", 80)) . self::atom('trak', 'xxxxxxxx'));
        $type = self::atom('ftyp', 'isom' . pack('N', 512) . 'isomiso2mp41');
        $data = self::atom('mdat', str_repeat("\x00", 64));
        return $type . ($movielast ? $data . self::atom('free', '') . $movie : $movie . $data);
    }

    private static function atom(string $type, string $body): string
    {
        return pack('N', 8 + strlen($body)) . $type . $body;
    }
}
