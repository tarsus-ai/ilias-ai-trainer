<?php

/**
 * The length of a recording decides what indexing it counts.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Material\MediaDuration;
use TarsusIlias\Support\Interactions;

/**
 * @param float|null $actual
 */
function near(float $expected, $actual, string $what = ''): void
{
    ok($actual !== null && abs($expected - $actual) < 0.01, $what . ' expected ' . $expected . ' s, got ' . var_export($actual, true));
}

test('a WAV file states its length through data size and byte rate', function () {
    near(1.0, MediaDuration::seconds(World::tempFile(Media::wav(1))), '1 s');
    near(90.5, MediaDuration::seconds(World::tempFile(Media::wav(90.5))), '90.5 s');
    near(7200.0, MediaDuration::seconds(World::tempFile(Media::wav(7200))), '2 h');
    near(10800.0, MediaDuration::seconds(World::tempFile(Media::wav(10800))), '3 h');
});

test('a streamed WAV with an open data size runs to the end of the file', function () {
    $wav = Media::wav(30);
    // Overwrite the size of the data chunk (the last four bytes of the 44-byte header).
    $open = substr($wav, 0, 40) . pack('V', 0xFFFFFFFF) . substr($wav, 44);
    near(30.0, MediaDuration::seconds(World::tempFile($open)));
    $zero = substr($wav, 0, 40) . pack('V', 0) . substr($wav, 44);
    near(30.0, MediaDuration::seconds(World::tempFile($zero)));
});

test('an MPEG-4 file states its length in the movie header', function () {
    near(12.5, MediaDuration::seconds(World::tempFile(Media::mp4(12.5))), '32-bit times');
    near(10800.0, MediaDuration::seconds(World::tempFile(Media::mp4(10800))), '3 h');
    near(10800.0, MediaDuration::seconds(World::tempFile(Media::mp4(10800, 1))), '64-bit times');
    near(7260.0, MediaDuration::seconds(World::tempFile(Media::mp4(7260, 0, true))), 'movie atom after the media data');
    near(7260.0, MediaDuration::seconds(World::tempFile(Media::mp4(7260, 1, true))), '64-bit times, movie atom last');
    near(3600.0, MediaDuration::seconds(World::tempFile(Media::mp4(3600, 0, false, 90000))), 'another time scale');
});

test('a length that cannot be read is unknown, never a guess', function () {
    same(null, MediaDuration::seconds(''));
    same(null, MediaDuration::seconds('/nonexistent/file.mp4'));
    same(null, MediaDuration::seconds(World::tempFile('')));
    same(null, MediaDuration::seconds(World::tempFile('just some text, not media at all')));
    same(null, MediaDuration::seconds(World::tempFile(random_bytes(4096))));
    same(null, MediaDuration::seconds(World::tempFile("ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x00", 200))), 'MP3 needs getID3');
    // Truncated files.
    same(null, MediaDuration::seconds(World::tempFile(substr(Media::wav(10), 0, 20))));
    same(null, MediaDuration::seconds(World::tempFile(substr(Media::mp4(10), 0, 40))));
    // A movie header without a time scale.
    $broken = str_replace(pack('N', 1000) . pack('N', 5000), pack('N', 0) . pack('N', 5000), Media::mp4(5));
    same(null, MediaDuration::seconds(World::tempFile($broken)));
    same(null, MediaDuration::seconds(sys_get_temp_dir()), 'a directory');
});

test('hostile atom sizes do not hang or crash the reader', function () {
    $atom = static fn(string $type, string $body): string => pack('N', 8 + strlen($body)) . $type . $body;
    // An atom claiming to be larger than the file.
    $huge = $atom('ftyp', 'isom') . pack('N', 0x7FFFFFFF) . 'moov' . 'xxxx';
    same(null, MediaDuration::seconds(World::tempFile($huge)));
    // An atom smaller than its own header.
    $tiny = $atom('ftyp', 'isom') . pack('N', 4) . 'moov';
    same(null, MediaDuration::seconds(World::tempFile($tiny)));
    // Thousands of empty atoms before nothing.
    same(null, MediaDuration::seconds(World::tempFile($atom('ftyp', 'isom') . str_repeat($atom('free', ''), 20000))));
});

test('the length turns into started two-hour blocks', function () {
    $blocks = static fn(string $content): int => Interactions::mediaBlocks(MediaDuration::seconds(World::tempFile($content)));
    same(1, $blocks(Media::wav(60)));
    same(1, $blocks(Media::mp4(7200)));
    same(2, $blocks(Media::mp4(7201)));
    same(2, $blocks(Media::wav(3 * 3600)));
    same(3, $blocks(Media::mp4(5 * 3600, 1, true)));
    same(1, $blocks('not media'), 'unknown length counts one block');
});
