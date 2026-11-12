<?php

namespace TarsusIlias\Support;

/**
 * AI interactions as the Tarsus partner agreement counts them.
 *
 * The agreement ("AI interactions via the API") bills what the brain does
 * per request:
 *
 * - Basic rule: every request in which the AI answers, creates, evaluates,
 *   narrates or indexes something is one AI interaction.
 * - Audio plus a further AI step counts double: a question asked by voice
 *   is transcribed first and answered second - two interactions. The audio
 *   explainer is the exception and counts once.
 * - "Think longer" (think_longer) doubles any request: a question counts
 *   2, a question by voice 4.
 * - Indexing audio or video counts once per started two hours of material.
 * - No interaction: managing API keys and brains, querying the usage,
 *   retrieving, listing, checking, approving, editing or deleting content,
 *   the learning analytics, and the automatic correction of single-choice
 *   and true/false answers.
 *
 * REQUESTS holds every request of the API documentation under its endpoint
 * with the documentation's name and its rule, so a count is always derived
 * from the request that goes over the wire - the HTTP client counts what
 * it sends, the mock client what it would have sent. A request the table
 * does not know falls under the basic rule and counts once, so that an
 * unknown request is never taken for free.
 *
 * The ledger, the monthly limit and the cost estimate all work on these
 * figures. The code keeps calling them "credits" (the column, the config
 * keys): Tarsus uses AI credits and AI interactions as two names for the
 * same unit.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Interactions
{
    /** No AI interaction. */
    public const FREE = 'free';
    /** One AI interaction per request. */
    public const ONE = 'one';
    /** One AI interaction per started two hours of audio or video. */
    public const MEDIA = 'media';

    /** Seconds of audio or video one interaction covers when indexing. */
    public const MEDIA_BLOCK_SECONDS = 7200;

    /** Prefix of the brain endpoints the client addresses relatively. */
    public const BRAIN_PREFIX = '/api/v1/brain/';

    /** Generate Next Widgets: answers, practice questions, learning units. */
    public const GENERATE = '/api/t0/activity/generate/next/widgets';
    /** Generate Stack Audio Explainer. */
    public const AUDIO_EXPLAINER = '/api/t0/activity/generate/audio/explainer/stack';
    /** Retrieve Next Widgets (the feed). */
    public const FEED = '/api/t0/activity/retrieve/next/widgets';

    /**
     * The evaluation endpoint of each question widget type.
     *
     * @var array<string, string>
     */
    public const EVALUATE = [
        'singleChoiceQuestionWidget' => '/api/t0/activity/evaluate/single-choice-question/widget',
        'trueFalseQuestionWidget' => '/api/t0/activity/evaluate/true-false-question/widget',
        'openQuestionWidget' => '/api/t0/activity/evaluate/open-question/widget',
        'crossMultiplicationQuestionWidget' => '/api/t0/activity/evaluate/cross-multiplication-question/widget',
        'tradeCostingQuestionWidget' => '/api/t0/activity/evaluate/trade-costing-question/widget',
    ];

    /**
     * Requests that take spoken input (an audio file in audio_content).
     *
     * @var string[]
     */
    public const VOICE = [
        self::GENERATE,
        '/api/t0/activity/evaluate/open-question/widget',
    ];

    /**
     * Every request of the API documentation: endpoint => [name, rule].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const REQUESTS = [
        // Api: managing the API key.
        '/api/v1/auth/key/generate' => ['Generate API Key', self::FREE],

        // Awareness: managing brains, voices and the usage.
        '/api/v1/brain/awareness/create/memory' => ['Create Brain', self::FREE],
        '/api/v1/brain/awareness/delete/memory' => ['Delete Brain', self::FREE],
        '/api/v1/brain/awareness/list/memories' => ['List Brains', self::FREE],
        '/api/v1/brain/awareness/list/clone-voices' => ['List Clone Voices', self::FREE],
        '/api/v1/brain/awareness/get/usage' => ['Get Brain Usage', self::FREE],
        '/api/v1/brain/awareness/update/memory/index' => ['Update Brain Index', self::FREE],

        // Assistance: analysing, evaluating, generating questions, narrating.
        '/api/v1/brain/assistance/analyze/questions' => ['Analyse Questions', self::ONE],
        '/api/v1/brain/assistance/evaluate/answer' => ['Evaluate Answer', self::ONE],
        '/api/v1/brain/assistance/evaluate/multiple-answers' => ['Evaluate Multiple Answers', self::ONE],
        '/api/v1/brain/assistance/generate/mcq' => ['Single Choice Questions Generation', self::ONE],
        '/api/v1/brain/assistance/generate/oq' => ['Open Questions Generation', self::ONE],
        '/api/v1/brain/assistance/generate/tfq' => ['True-False Questions Generation', self::ONE],
        '/api/v1/brain/assistance/generate/audio' => ['Audio Generation', self::ONE],
        '/api/v1/brain/assistance/generate/mcq-distractors' => ['Single Choice Distractor Generation', self::ONE],

        // Access: indexing content.
        '/api/v1/brain/access/track/text-bits' => ['Track Text Bits', self::ONE],
        '/api/v1/brain/access/track/table-bits' => ['Track Table Bits', self::ONE],
        '/api/v1/brain/access/track/table-data-bits' => ['Track Table Data Bits', self::ONE],
        '/api/v1/brain/access/track/question-bits' => ['Track Question Bits', self::ONE],
        '/api/v1/brain/access/track/open-question-bits' => ['Track Open Question Bits', self::ONE],
        '/api/v1/brain/access/track/form-bits' => ['Track Form Question Bits', self::ONE],
        '/api/v1/brain/access/track/true-false-question-bits' => ['Track True-False Question Bits', self::ONE],
        '/api/v1/brain/access/track/url-bits' => ['Track Url Bits', self::ONE],
        '/api/v1/brain/access/track/document-bits' => ['Track Document Bits', self::ONE],
        '/api/v1/brain/access/track/image-bits' => ['Track Image Bits', self::ONE],
        '/api/v1/brain/access/track/image-url-bits' => ['Track Image Url Bits', self::ONE],
        '/api/v1/brain/access/track/audio-bits' => ['Track Audio Bits', self::MEDIA],
        '/api/v1/brain/access/track/video-bits' => ['Track Video Bits', self::MEDIA],
        '/api/v1/brain/access/track/video-url-bits' => ['Track Video Url Bits', self::MEDIA],

        // Access: approving, editing, deleting, retrieving, listing, checking.
        '/api/v1/brain/access/approve/searched-bit' => ['Approve Searched Bit', self::FREE],
        '/api/v1/brain/access/edit/bit' => ['Edit Bit', self::FREE],
        '/api/v1/brain/access/approve/search-argument' => ['Approve Search Argument', self::FREE],
        '/api/v1/brain/access/untrack/resource-bits' => ['Untrack Resource Bits', self::FREE],
        '/api/v1/brain/access/delete/bit' => ['Delete bit', self::FREE],
        '/api/v1/brain/access/untrack/multiple-assets' => ['Untrack Multiple Assets', self::FREE],
        '/api/v1/brain/access/untrack/multiple-resources' => ['Untrack Multiple Resources', self::FREE],
        '/api/v1/brain/access/retrieve/resource-bits' => ['Retrieve Resource Bits', self::FREE],
        '/api/v1/brain/access/retrieve/asset-resource-user-id' => ['Retrieve Asset Resource User Id', self::FREE],
        '/api/v1/brain/access/list/searches' => ['Retrieve Search Queries', self::FREE],
        '/api/v1/brain/access/list/grouped-searches' => ['Retrieve Grouped Search Queries', self::FREE],
        '/api/v1/brain/access/retrieve/selected-bits' => ['Retrieve Selected Bits', self::FREE],
        '/api/v1/brain/access/check/tracked-resource' => ['Check Tracked Resource', self::FREE],
        '/api/v1/brain/access/check/tracked-resources' => ['Check Tracked Resources', self::FREE],
        '/api/v1/brain/access/check/tracked-asset' => ['Check Tracked Asset', self::FREE],
        '/api/v1/brain/access/check/tracked-assets' => ['Check Tracked Assets', self::FREE],
        '/api/v1/brain/access/list/site-urls' => ['List Site Urls', self::FREE],

        // Access: searching the knowledge.
        '/api/v1/brain/access/search/bits' => ['Search Bits', self::ONE],
        '/api/v1/brain/access/search/user-bits' => ['Search User Bits', self::ONE],

        // Automation: questions, learning content and summaries from material.
        '/api/v1/brain/automation/generate/resource-mcq' => ['Single Choice Questions Generation From Resource', self::ONE],
        '/api/v1/brain/automation/generate/asset-mcq' => ['Single Choice Questions Generation From Asset', self::ONE],
        '/api/v1/brain/automation/generate/resource-oq' => ['Open Questions Generation From Resource', self::ONE],
        '/api/v1/brain/automation/generate/asset-oq' => ['Open Questions Generation From Asset', self::ONE],
        '/api/v1/brain/automation/generate/resource-tfq' => ['True-False Questions Generation From Resource', self::ONE],
        '/api/v1/brain/automation/generate/asset-tfq' => ['True-False Questions Generation From Asset', self::ONE],
        '/api/v1/brain/automation/validate/asset-objectives' => ['Validate Asset Objectives', self::ONE],
        '/api/v1/brain/automation/generate/url-mcq' => ['Anonymous Single Choice From URL', self::ONE],
        '/api/v1/brain/automation/generate/document-mcq' => ['Anonymous Single Choice From Document', self::ONE],
        '/api/v1/brain/automation/generate/url-summary' => ['Anonymous Summary From URL', self::ONE],
        '/api/v1/brain/automation/generate/document-summary' => ['Anonymous Summary From Document', self::ONE],
        '/api/v1/brain/automation/generate/question-bits' => ['Question to Bits Generation', self::ONE],
        '/api/v1/brain/automation/generate/asset-bits' => ['Bits Generation From Assets', self::ONE],
        '/api/v1/brain/automation/generate/asset-block' => ['Block Generation From Assets', self::ONE],

        // Advisory: learning analytics.
        '/api/v1/brain/advisory/monitor/cognitive-excellence' => ['Monitor Cognitive Excellence', self::FREE],
        '/api/v1/brain/advisory/monitor/knowledge-excellence' => ['Monitor Knowledge Excellence', self::FREE],
        '/api/v1/brain/advisory/monitor/knowledge-gaps-addressed' => ['Monitor Knowledge Gaps Addressed', self::FREE],
        '/api/v1/brain/advisory/estimate/time-saved-by-dc' => ['Estimate Time Saved by Digital Clones', self::FREE],
        '/api/v1/brain/advisory/get/hot-keywords' => ['Get Hot Keywords', self::FREE],

        // Tarsus Zero activity: the learning coach.
        self::FEED => ['Retrieve Next Widgets', self::FREE],
        '/api/t0/activity/retrieve/widget/references' => ['Retrieve Widget Reference Bits', self::FREE],
        self::GENERATE => ['Generate Next Widgets', self::ONE],
        '/api/t0/activity/retrieve/context/statistics' => ['Retrieve Context Statistics', self::FREE],
        '/api/t0/activity/evaluate/single-choice-question/widget' => ['Evaluate Single Choice Question Widgets', self::FREE],
        '/api/t0/activity/evaluate/socratic-question/widget' => ['Evaluate Socratic Question Widgets', self::ONE],
        '/api/t0/activity/evaluate/true-false-question/widget' => ['Evaluate True False Question Widgets', self::FREE],
        '/api/t0/activity/evaluate/open-question/widget' => ['Evaluate Open Question Widget', self::ONE],
        '/api/t0/activity/evaluate/cross-multiplication-question/widget' => ['Evaluate Cross Multiplication Question Widget', self::ONE],
        self::AUDIO_EXPLAINER => ['Generate Stack Audio Explainer', self::ONE],
        '/api/t0/activity/evaluate/trade-costing-question/widget' => ['Evaluate Trade Costing Question Widget', self::ONE],
        '/api/t0/activity/reset/user/widgets' => ['Reset User Widgets', self::FREE],
    ];

    /**
     * The AI interactions one request counts.
     *
     * @param string $path Endpoint, absolute ('/api/t0/...') or relative to
     *                     the brain prefix ('access/track/url-bits').
     * @param array{voice?: bool, think_longer?: bool, duration?: float|int|null} $options
     *        voice: the request carries spoken input; think_longer: the
     *        request asks the brain to think longer; duration: seconds of
     *        the audio or video being indexed (unknown counts one block).
     */
    public static function count(string $path, array $options = []): int
    {
        $path = self::normalise($path);
        $rule = self::rule($path);
        if ($rule === self::FREE) {
            return 0;
        }

        $count = $rule === self::MEDIA ? self::mediaBlocks($options['duration'] ?? null) : 1;
        if (!empty($options['voice']) && in_array($path, self::VOICE, true)) {
            // Transcribing the recording is an AI step of its own.
            $count++;
        }
        if (!empty($options['think_longer'])) {
            $count *= 2;
        }
        return $count;
    }

    /**
     * The AI interactions of a request as it is sent: the options are read
     * from its form fields.
     *
     * @param array<string, mixed> $fields The form fields of the request.
     * @param array{duration?: float|int|null} $hints What the fields do not say.
     */
    public static function forRequest(string $path, array $fields, array $hints = []): int
    {
        return self::count($path, [
            'voice' => self::hasFile($fields['audio_content'] ?? null),
            'think_longer' => self::flag($fields['think_longer'] ?? null),
            'duration' => $hints['duration'] ?? null,
        ]);
    }

    /**
     * Evaluating an answer to a question widget: free for the automatically
     * corrected types, one interaction for the others.
     *
     * @param array{voice?: bool, think_longer?: bool} $options
     */
    public static function forEvaluation(string $widgettype, array $options = []): int
    {
        $path = self::EVALUATE[$widgettype] ?? null;
        // An unknown widget type falls under the basic rule.
        return $path === null ? self::count('', $options) : self::count($path, $options);
    }

    /**
     * The track endpoint for a bit: text, link, document, audio or video.
     */
    public static function trackPath(string $bittype, string $mimetype = ''): string
    {
        switch ($bittype) {
            case 'url':
                return self::BRAIN_PREFIX . 'access/track/url-bits';
            case 'document':
                return self::BRAIN_PREFIX . 'access/track/document-bits';
            case 'media':
                return self::BRAIN_PREFIX . (strncmp(strtolower($mimetype), 'audio/', 6) === 0
                    ? 'access/track/audio-bits' : 'access/track/video-bits');
            default:
                return self::BRAIN_PREFIX . 'access/track/text-bits';
        }
    }

    /**
     * Indexing one bit: one interaction per request, audio and video one
     * per started two hours.
     *
     * @param float|int|null $duration Seconds of audio or video, null when unknown.
     */
    public static function forTracking(string $bittype, string $mimetype = '', $duration = null): int
    {
        return self::count(self::trackPath($bittype, $mimetype), ['duration' => $duration]);
    }

    /**
     * Started two-hour blocks of a recording; an unknown or empty length
     * counts as one block.
     *
     * @param float|int|null $seconds
     */
    public static function mediaBlocks($seconds): int
    {
        if ($seconds === null || !is_numeric($seconds) || (float) $seconds <= 0) {
            return 1;
        }
        return max(1, (int) ceil((float) $seconds / self::MEDIA_BLOCK_SECONDS));
    }

    /**
     * The rule of an endpoint; the basic rule for one the table does not know.
     */
    public static function rule(string $path): string
    {
        return self::REQUESTS[self::normalise($path)][1] ?? self::ONE;
    }

    /**
     * The request's name in the API documentation, '' when it is not listed.
     */
    public static function name(string $path): string
    {
        return self::REQUESTS[self::normalise($path)][0] ?? '';
    }

    /**
     * An endpoint as the table keys it: absolute, without host, query
     * string or trailing slash.
     */
    public static function normalise(string $path): string
    {
        $path = trim($path);
        $api = strpos($path, '/api/');
        if (preg_match('~^https?://~i', $path) && $api !== false) {
            $path = substr($path, $api);
        }
        $query = strpos($path, '?');
        if ($query !== false) {
            $path = substr($path, 0, $query);
        }
        if ($path !== '' && $path[0] !== '/') {
            $path = self::BRAIN_PREFIX . $path;
        }
        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }

    /**
     * Whether a form field switches something on: the API takes booleans
     * as the strings 'true' and 'false'.
     *
     * @param mixed $value
     */
    private static function flag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return is_string($value) && in_array(strtolower(trim($value)), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Whether a form field carries a file.
     *
     * @param mixed $value
     */
    private static function hasFile($value): bool
    {
        if (is_object($value)) {
            return true;
        }
        return is_string($value) && trim($value) !== '';
    }
}
