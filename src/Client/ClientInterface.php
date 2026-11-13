<?php

namespace TarsusIlias\Client;

/**
 * Stable Tarsus client contract.
 *
 * Everything outside the client layer depends on this interface, never on
 * a concrete client. A deterministic mock backs it in mock mode; the HTTP
 * client speaks to the live brain.
 *
 * The 'credits' figure every charged result carries is the number of AI
 * interactions the call counts for under the Tarsus partner agreement
 * ({@see \TarsusIlias\Support\Interactions}); it feeds the local ledger,
 * the monthly limit and the cost estimate.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
interface ClientInterface
{
    /** Maximum resource ids accepted by one batch status check. */
    public const BATCH_LIMIT = 10;

    /** Question widget types the practice mode supports. */
    public const QUESTION_WIDGET_TYPES = [
        'singleChoiceQuestionWidget',
        'trueFalseQuestionWidget',
        'openQuestionWidget',
        'crossMultiplicationQuestionWidget',
        'tradeCostingQuestionWidget',
    ];

    /**
     * Track (index) a resource into the brain.
     *
     * @param array<string, mixed> $resource Resource payload (type, resource_id, context_id, title, text|url|file).
     * @return array<string, mixed> Result with at least 'status' and optional 'bitref'.
     */
    public function trackResource(array $resource): array;

    /**
     * Untrack (remove) one or more resources from the brain.
     *
     * @param string[] $resourceids
     * @return array<string, string> Per-resource removal result.
     */
    public function untrackResource(array $resourceids): array;

    /**
     * Untrack every resource filed under the given assets (one asset is one
     * ILIAS course or group).
     *
     * @param string[] $assetids
     * @return array<string, string>
     */
    public function untrackAssets(array $assetids): array;

    /**
     * Brain-side status of one tracked resource.
     *
     * @return array<string, mixed> Result with at least 'status'.
     */
    public function getResourceStatus(string $resourceid): array;

    /**
     * Brain-side status of many resources, chunked by BATCH_LIMIT.
     *
     * @param string[] $resourceids
     * @return array<string, array{status: string, tracked: bool, brainstatus: string}>
     */
    public function checkTrackedResources(array $resourceids): array;

    /**
     * Every brain the configured API key can reach.
     *
     * @return array<int, array{brainid: string, active: bool, language: string, tag: string, timecreated: int}>
     */
    public function listBrains(): array;

    /**
     * The voices the brain can read aloud with.
     *
     * @return array<int, array{alias: string, country: string, gender: string}>
     */
    public function listVoices(): array;

    /**
     * What the brain charged between two Unix timestamps.
     *
     * @return array{total: int, items: array<int, array{key: string, name: string, service: string, credits: int}>, credits: int}
     */
    public function getUsage(int $start, int $end): array;

    /**
     * Ask the activity chat and return the assistant's answer.
     *
     * @param array<string, mixed> $params Conversation parameters incl. 'query'.
     * @return array{answer: string, messages: array<int, array{title: string, text: string}>, credits: int}
     */
    public function generateWidgets(array $params): array;

    /**
     * Generate the next practice question widgets (Test knowledge).
     *
     * @param array<string, mixed> $params
     * @return array{widgets: array, progress: array, credits: int}
     */
    public function generatePracticeWidget(array $params): array;

    /**
     * Generate the next learning unit (Build knowledge).
     *
     * @param array<string, mixed> $params
     * @return array{items: array, progress: array, credits: int}
     */
    public function generateLearnWidget(array $params): array;

    /**
     * Evaluate a learner's answer to a question widget.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function evaluatePracticeAnswer(array $params): array;

    /**
     * Read one page of the interleaved activity feed back from the brain.
     *
     * @param array<string, mixed> $params
     * @return array{items: array, rawcount: int, progress: array, credits: int}
     */
    public function retrieveFeed(array $params): array;

    /**
     * Erase one user's conversation in one activity context.
     */
    public function resetConversation(string $contextid, string $userid): void;

    /**
     * Have the brain read one learning unit (stack) aloud.
     *
     * @param array<string, mixed> $params
     * @return array{audio: string, mimetype: string, voice: string, credits: int}
     */
    public function generateStackAudio(array $params): array;
}
