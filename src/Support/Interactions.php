<?php

namespace TarsusIlias\Support;

use TarsusIlias\Activity\Didactics;
use TarsusIlias\Material\BitType;

/**
 * How Tarsus counts AI interactions - the unit the packages include and
 * the price applies to.
 *
 * This is the table "KI-Interaktionen über die API" of the partner
 * agreement (tarsus.ai/impressum/partnervereinbarung, 12 November 2026;
 * the public version is tarsus.ai/ki-interaktionen):
 *
 * - Ground rule: every request in which the AI answers, creates, marks,
 *   voices or indexes something is one interaction.
 * - Audio plus another AI step counts twice: a spoken question is turned
 *   into text and then answered - two interactions. The audio explainer
 *   is the exception and counts once.
 * - "Think longer" (think_longer) doubles any request: a question counts
 *   2, a spoken question 4.
 * - Marking a single-choice or true/false answer counts nothing.
 * - Indexing text, a table, a question, a URL, a document or an image is
 *   one interaction per request; audio and video count one per started
 *   two hours.
 * - Managing keys and brains, reading the usage, and retrieving, listing,
 *   checking, releasing, editing or deleting content count nothing.
 *
 * The plugin records these figures in its ledger for every call it makes
 * and uses them to estimate a call before it is sent (the monthly limit)
 * and to price the month (the status page). What the brain actually
 * charged is fetched separately and wins when it is higher.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Interactions
{
    /** Seconds of audio or video that count as one interaction when indexing. */
    public const MEDIA_SLICE = 7200;

    /** The one interaction every answering, creating, marking or voicing request costs. */
    public const ONE = 1;

    /**
     * Generating something - an answer (Generate Next Widgets with query),
     * the next learning step, a quiz question, an agent-mode step.
     *
     * @param bool $audioinput  The request carries an audio file (audio_content).
     * @param bool $thinklonger The request asks the brain to think longer.
     */
    public static function forGenerate(bool $audioinput = false, bool $thinklonger = false): int
    {
        return self::multiply($audioinput ? 2 : self::ONE, $thinklonger);
    }

    /**
     * Marking a learner's answer. Single choice and true/false are
     * corrected without the AI and cost nothing; open questions, rule of
     * three, spreadsheet calculations and anything else cost one - or two
     * when the answer was spoken.
     */
    public static function forEvaluation(string $widgettype, bool $audioinput = false, bool $thinklonger = false): int
    {
        if (self::isFreeEvaluation($widgettype)) {
            return 0;
        }
        return self::multiply($audioinput ? 2 : self::ONE, $thinklonger);
    }

    /**
     * Whether a widget type is marked without an AI interaction.
     */
    public static function isFreeEvaluation(string $widgettype): bool
    {
        return $widgettype === Didactics::TYPE_SINGLECHOICE || $widgettype === Didactics::TYPE_TRUEFALSE;
    }

    /**
     * The audio explainer of a learning unit: one interaction, even though
     * it is audio plus an AI step.
     */
    public static function forAudioExplainer(bool $thinklonger = false): int
    {
        return self::multiply(self::ONE, $thinklonger);
    }

    /**
     * Reading a text aloud with the standard voice (Audio Generation).
     */
    public static function forNarration(): int
    {
        return self::ONE;
    }

    /**
     * Indexing a resource. Text, table, question, URL, document and image
     * cost one per request; audio and video one per started two hours of
     * running time (one when the running time is unknown).
     *
     * @param string $type    A {@see BitType}; audio and video are BitType::MEDIA.
     * @param int    $seconds Running time of a media file, 0 when unknown.
     */
    public static function forIndexing(string $type, int $seconds = 0): int
    {
        if ($type === BitType::MEDIA) {
            return max(1, (int) ceil(max(0, $seconds) / self::MEDIA_SLICE));
        }
        return self::ONE;
    }

    /**
     * Management and retrieval: keys, brains, usage, listing, checking,
     * releasing, editing, deleting, reading the feed back, forgetting a
     * conversation.
     */
    public static function forManagement(): int
    {
        return 0;
    }

    /**
     * Searching the knowledge (Search Bits): one interaction.
     */
    public static function forSearch(): int
    {
        return self::ONE;
    }

    private static function multiply(int $count, bool $thinklonger): int
    {
        return $thinklonger ? $count * 2 : $count;
    }
}
