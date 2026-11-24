<?php

namespace TarsusIlias\Service;

use TarsusIlias\Client\TransportException;
use TarsusIlias\Support\Ilias;

/**
 * One clear message for every way a call to Tarsus can fail, in the
 * user's language. Tarsus explains a refusal in an upper-case sentence
 * (the whole body of a 400); the known sentences get their own message,
 * everything else a message by status. The details always go to the ILIAS
 * log, never the request (it carries the API key).
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Messages
{
    /** Tarsus's sentences that mean the connection itself is wrong (key, brain). */
    private const ACCESS = [
        'UNKNOWN OR INACTIVE API KEY',
        'UNAVAILABLE API KEY',
        'UNAVAILABLE BRAIN ID',
        'BRAIN IS EITHER INEXISTENT',
        'BRAIN MEMORY DOES NOT EXIST',
    ];

    /**
     * @param string $context What was asked for: ask, voice, practice, check, learn, audio, discuss, references, statistics.
     */
    public static function forTransport(TransportException $e, string $context = ''): string
    {
        Ilias::log($context . ': ' . $e->getMessage() . ($e->body !== '' ? ' - ' . $e->body : ''));

        if ($e->httpstatus === 0) {
            return Ilias::txt('err_connection');
        }
        foreach (self::ACCESS as $sentence) {
            if ($e->says($sentence)) {
                return Ilias::txt('err_access');
            }
        }
        if ($e->says('NO BITS WERE FOUND')) {
            return Ilias::txt('err_notindexed');
        }
        if ($e->says('NO SPEECH RECOGNIZED')) {
            return Ilias::txt('err_audio_nospeech');
        }
        if ($e->says('UNSUPPORTED OR INVALID AUDIO FORMAT') || $e->says('UNAVAILABLE AUDIO')) {
            return Ilias::txt('err_audio_format');
        }
        if ($e->says('UNABLE TO TRANSCRIBE AUDIO')) {
            return Ilias::txt('err_audio_transcribe');
        }
        if ($e->says('VOICE AGENT IS NOT SET UP') || ($context === 'discuss' && $e->httpstatus === 503)) {
            return Ilias::txt('err_live_unavailable');
        }
        if ($e->says('UNKNOWN VOICE ALIAS')) {
            return Ilias::txt('err_voice');
        }
        if ($e->says('STACK IS UNAVAILABLE')) {
            return Ilias::txt('err_stack');
        }
        if ($e->says('UNABLE TO EVALUATE')) {
            return Ilias::txt('err_evaluate');
        }
        if ($e->httpstatus === 413) {
            return Ilias::txt('err_toolarge');
        }
        if ($e->httpstatus === 503) {
            return Ilias::txt('err_busy');
        }
        if ($e->httpstatus >= 500 || $e->httpstatus < 400) {
            return Ilias::txt('err_server');
        }
        return Ilias::txt('err_request');
    }
}
