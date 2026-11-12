<?php

namespace TarsusIlias\Client;

use TarsusIlias\Index\Status;
use TarsusIlias\Material\BitType;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Interactions;

/**
 * Real Tarsus brain HTTP client.
 *
 * Speaks the /api/v1/brain/access/* surface (track/{text,url,document,
 * audio,video}-bits, untrack/multiple-resources, untrack/multiple-assets,
 * check/tracked-resources, awareness/list/*) and the /api/t0/activity/*
 * surface (generate, evaluate, retrieve, reset, audio). Every call is
 * form-data with api_key + brain_id, or - for an existing Tarsus customer -
 * api_key + license_key with no brain_id; the brain ingests
 * asynchronously, so track only ever confirms receipt and the batch check
 * drives the state machine afterwards.
 *
 * The 'credits' figure of every result is the number of AI interactions
 * the call counts for under the partner agreement ({@see Interactions});
 * the API itself returns no cost per call.
 *
 * All transport and protocol failures surface as TransportException so the
 * guardrails pipeline and the retry budget stay in control.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class HttpClient implements ClientInterface
{
    /** Common prefix of every brain endpoint. */
    private const API_PREFIX = '/api/v1/brain/';

    /** Seconds allowed for a plain JSON call. */
    private const TIMEOUT = 60;

    /** Seconds allowed for a call that uploads a file. */
    private const UPLOAD_TIMEOUT = 300;

    /** Seconds allowed to establish the connection. */
    private const CONNECT_TIMEOUT = 10;

    /**
     * The form field that carries the license key of an existing Tarsus
     * customer. It travels with every call next to api_key and in place of
     * brain_id, so that Tarsus recognises the customer and bills them
     * directly.
     */
    public const LICENSE_FIELD = 'license_key';

    protected string $baseurl;
    protected string $apikey;
    protected string $brainid;
    protected string $licensekey;

    public function __construct(string $baseurl, string $apikey, string $brainid, string $licensekey = '')
    {
        $this->baseurl = $baseurl;
        $this->apikey = $apikey;
        $this->brainid = $brainid;
        $this->licensekey = $licensekey;
    }

    public function trackResource(array $resource): array
    {
        [$path, $params, $tempfile] = $this->buildTrackRequest($resource);

        try {
            $body = $this->send('POST', $path, $params, $tempfile !== null);
        } finally {
            if ($tempfile !== null && file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }

        // A missing ack is tolerated; only an explicit false means the brain
        // never got the bytes. HTTP 2xx already carries the real signal.
        if (isset($body['access']['received']) && $body['access']['received'] === false) {
            throw new TransportException(0, 'brain did not acknowledge receipt of the resource');
        }

        // Ingestion is asynchronous: receipt is not indexation. Indexing
        // counts one interaction per request, media one per started two
        // hours.
        return [
            'status' => Status::PENDING_CONFIRMATION,
            'bitref' => '',
            'credits' => Interactions::forIndexing((string) ($resource['type'] ?? BitType::TEXT), (int) ($resource['duration'] ?? 0)),
        ];
    }

    public function retrieveFeed(array $params): array
    {
        $body = $this->send('POST', '/api/t0/activity/retrieve/next/widgets', [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'user_id' => (string) ($params['userid'] ?? ''),
            'state' => 'ask',
            'offset' => (string) (int) ($params['offset'] ?? 0),
            'page_size' => (string) (int) ($params['pagesize'] ?? 50),
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
            'taxonomy_levels' => json_encode(array_values($params['taxonomylevels'] ?? [])),
            // Selects WHICH page a long feed yields - the newest page_size
            // widgets, not the oldest - without affecting order; offset then
            // counts from the newest end.
            'newest_first' => 'true',
        ]);

        // Widgets arrive oldest first and the feed mixes every state
        // regardless of the 'state' parameter - which is exactly what the
        // interleaved activity feed wants.
        $items = [];
        $widgets = $body['activity']['widgets'] ?? [];
        if (!is_array($widgets)) {
            $widgets = [];
        }
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $learn = self::learnItem($widget);
            if ($learn !== null) {
                $items[] = $learn;
                continue;
            }
            $bit = (array) ($widget['bit'] ?? []);
            $type = (string) ($bit['type'] ?? '');
            $data = (array) ($bit['data'] ?? []);

            if (in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
                $items[] = self::questionItem($widget, $type, $data);
                continue;
            }

            $page = self::htmlOf($bit, $data);
            $question = self::str($data['question'] ?? '');
            if ($page !== '' && $question !== '') {
                $items[] = ['kind' => 'exchange', 'query' => $question, 'answer' => '', 'html' => $page];
                continue;
            }
            if ($page !== '') {
                $items[] = self::pageStack($widget, $bit, $data, $page);
                continue;
            }

            $answer = self::str($data['answer'] ?? '');
            if ($answer !== '') {
                $items[] = [
                    'kind' => 'exchange',
                    'query' => $question,
                    'answer' => $answer,
                ];
                continue;
            }

            $text = self::str($bit['text'] ?? '');
            if ($text !== '') {
                $items[] = ['kind' => 'message', 'title' => self::str($bit['title'] ?? ''), 'text' => $text];
            }
        }

        // The brain counts page_size in single widgets: a learning stack
        // weighs as many as it has slides, and a stack may even straddle
        // two pages. Counting slides keeps the page-size test honest; the
        // reader merges the straddling halves by stack id.
        $rawcount = 0;
        foreach ($widgets as $widget) {
            $rawcount += (is_array($widget) && isset($widget['stackedWidgets']) && is_array($widget['stackedWidgets']))
                ? max(1, count($widget['stackedWidgets'])) : 1;
        }

        return [
            'items' => $items,
            'rawcount' => $rawcount,
            'progress' => self::extractProgress((array) ($body['activity'] ?? []), []),
            'credits' => 0,
        ];
    }

    public function generateStackAudio(array $params): array
    {
        $body = $this->send('POST', '/api/t0/activity/generate/audio/explainer/stack', [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'context_name' => (string) ($params['contextname'] ?? ''),
            'context_description' => (string) ($params['contextdescription'] ?? ''),
            'robot_id' => (string) ($params['robotid'] ?? ''),
            'robot_name' => (string) ($params['robotname'] ?? ''),
            'robot_description' => (string) ($params['robotdescription'] ?? ''),
            'user_id' => (string) ($params['userid'] ?? ''),
            'user_name' => (string) ($params['username'] ?? ''),
            'user_language' => (string) ($params['userlanguage'] ?? 'de'),
            'state' => 'learn',
            'voice' => (string) ($params['voice'] ?? 'Daniel'),
            'stack_id' => (string) ($params['stackid'] ?? ''),
        ], false, self::UPLOAD_TIMEOUT);

        $data = (array) ($body['activity']['audio']['data'] ?? []);
        $bytes = self::str($data['audioBytes'] ?? '');
        $mimetype = strtolower(self::str($data['mimeType'] ?? 'audio/wav'));
        if (!preg_match('~^audio/[a-z0-9.+-]+$~', $mimetype)) {
            $mimetype = 'audio/wav';
        }

        return [
            'audio' => preg_match('~^[A-Za-z0-9+/=\s]+$~', $bytes) ? $bytes : '',
            'mimetype' => $mimetype,
            'voice' => self::str($data['assistant']['alias'] ?? ($params['voice'] ?? '')),
            'credits' => Interactions::forAudioExplainer(),
        ];
    }

    public function resetConversation(string $contextid, string $userid): void
    {
        $this->send('DELETE', '/api/t0/activity/reset/user/widgets', [
            'brain_id' => $this->brain(),
            'context_id' => $contextid,
            'context_type' => 'tarsus_activity',
            'user_id' => $userid,
        ]);
    }

    public function untrackResource(array $resourceids): array
    {
        $result = [];

        foreach ($this->chunk($resourceids) as $chunk) {
            $body = $this->send('DELETE', 'access/untrack/multiple-resources', [
                'brain_id' => $this->brain(),
                'resource_ids' => json_encode(array_values($chunk)),
            ]);

            $untracked = $body['access']['untracked']['resource_ids'] ?? [];
            $untracked = is_array($untracked) ? array_flip(array_map('strval', $untracked)) : [];

            foreach ($chunk as $resourceid) {
                $result[$resourceid] = isset($untracked[$resourceid]) ? 'removed' : 'unknown';
            }
        }

        return $result;
    }

    public function untrackAssets(array $assetids): array
    {
        $result = [];

        foreach ($this->chunk($assetids) as $chunk) {
            $body = $this->send('DELETE', 'access/untrack/multiple-assets', [
                'brain_id' => $this->brain(),
                'asset_ids' => json_encode(array_values($chunk)),
            ]);

            $untracked = $body['access']['untracked']['asset_ids'] ?? [];
            $untracked = is_array($untracked) ? array_flip(array_map('strval', $untracked)) : [];

            foreach ($chunk as $assetid) {
                $result[$assetid] = isset($untracked[$assetid]) ? 'removed' : 'unknown';
            }
        }

        return $result;
    }

    public function getResourceStatus(string $resourceid): array
    {
        // The plural endpoint reports a brain-side status; the singular one does not.
        $checked = $this->checkTrackedResources([$resourceid]);

        return $checked[$resourceid]
            ?? ['status' => Status::PENDING_CONFIRMATION, 'tracked' => false, 'brainstatus' => ''];
    }

    public function checkTrackedResources(array $resourceids): array
    {
        $result = [];

        foreach ($this->chunk($resourceids) as $chunk) {
            $body = $this->send('GET', 'access/check/tracked-resources', [
                'brain_id' => $this->brain(),
                'resource_ids' => json_encode(array_values($chunk)),
            ]);

            foreach ((array) ($body['access'] ?? []) as $entry) {
                if (!is_array($entry) || !isset($entry['resourceId'])) {
                    continue;
                }
                $result[(string) $entry['resourceId']] = [
                    'status' => $this->mapStatus($entry),
                    'tracked' => !empty($entry['tracked']),
                    'brainstatus' => self::str($entry['status'] ?? ''),
                ];
            }
        }

        return $result;
    }

    public function listBrains(): array
    {
        $body = $this->send('GET', 'awareness/list/memories', []);

        $brains = [];
        foreach ((array) ($body['awareness']['brain_ids'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['_id'])) {
                continue;
            }
            $brains[] = [
                'brainid' => (string) $entry['_id'],
                'active' => !empty($entry['active']),
                'language' => self::str($entry['brainLanguage'] ?? ''),
                'tag' => self::str($entry['brainTag'] ?? ''),
                'timecreated' => (int) ($entry['brainCreatedDateTime'] ?? 0),
            ];
        }

        return $brains;
    }

    public function getUsage(int $start, int $end): array
    {
        $body = $this->send('GET', 'awareness/get/usage', [
            'brain_id' => $this->brain(),
            'start_timestamp' => (string) max(0, $start),
            'end_timestamp' => (string) max($start, $end),
        ]);

        $items = [];
        $total = 0;
        foreach ((array) ($body['awareness']['usage'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $credits = (int) round((float) ($entry['aiCredits'] ?? 0));
            $total += $credits;
            $items[] = [
                'key' => self::str($entry['key'] ?? ''),
                'name' => self::str($entry['name'] ?? ''),
                'service' => self::str($entry['service'] ?? ''),
                'credits' => $credits,
            ];
        }

        return ['total' => $total, 'items' => $items, 'credits' => 0];
    }

    public function listVoices(): array
    {
        $body = $this->send('GET', 'awareness/list/clone-voices', ['brain_id' => $this->brain()]);

        $voices = [];
        foreach ((array) ($body['assistance']['voices'] ?? []) as $entry) {
            if (!is_array($entry) || empty($entry['assistant'])) {
                continue;
            }
            $voices[] = [
                'alias' => (string) $entry['assistant'],
                'country' => self::str($entry['country'] ?? ''),
                'gender' => strtolower(self::str($entry['gender'] ?? '')),
            ];
        }

        return $voices;
    }

    /**
     * The fields every t0 generation call shares.
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function generationFields(array $params, string $state): array
    {
        // context_type is a constant, default_robot stays true, input is
        // always text. full_brain_access must be an explicit 'false': that
        // is what makes resource_ids the retrieval boundary; 'true' searches
        // the whole shared brain regardless of scope.
        return [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'context_name' => (string) ($params['contextname'] ?? ''),
            'context_description' => (string) ($params['contextdescription'] ?? ''),
            'robot_id' => (string) ($params['robotid'] ?? ''),
            'robot_name' => (string) ($params['robotname'] ?? ''),
            'robot_description' => (string) ($params['robotdescription'] ?? ''),
            'user_id' => (string) ($params['userid'] ?? ''),
            'user_name' => (string) ($params['username'] ?? ''),
            'user_language' => (string) ($params['userlanguage'] ?? 'de'),
            'state' => $state,
            'default_robot' => 'true',
            'input_modality' => 'text',
            'conversation_style' => (string) (int) ($params['conversationstyle'] ?? 3),
            'dqr' => (string) (int) ($params['dqr'] ?? 6),
            'full_text_search' => !empty($params['fulltextsearch']) ? 'true' : 'false',
            'full_brain_access' => 'false',
            'operators' => self::operators($params),
            'anti_cheat' => !empty($params['anticheat']) ? 'true' : 'false',
            'learn_tempo' => self::learnTempo($params),
            // trainer-driven: text and pictures; ai-driven: the brain may
            // answer with interactive HTML pages (plan permitting).
            'interaction_type' => self::interactionType($params),
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
        ];
    }

    private static function interactionType(array $params): string
    {
        $type = (string) ($params['interactiontype'] ?? '');
        return $type === 'ai-driven' ? 'ai-driven' : 'trainer-driven';
    }

    /**
     * The interactive HTML page a widget carries, '' when it carries none.
     *
     * With ai-driven interaction the brain answers with HTML pages (HTML,
     * CSS and script: animations, simulations, small tools). The field
     * that holds the page is not fixed yet, so a few names are accepted,
     * and a text or answer that is itself a document counts too. Plain
     * Markdown with the odd inline tag is not a page.
     *
     * @param array<string, mixed> $bit
     * @param array<string, mixed> $data
     */
    private static function htmlOf(array $bit, array $data): string
    {
        foreach (['html', 'htmlContent', 'html_content', 'htmlPage', 'page', 'interactiveContent', 'interactive_content'] as $key) {
            foreach ([$data, $bit] as $source) {
                $value = $source[$key] ?? null;
                if (is_string($value) && trim($value) !== '' && self::looksLikePage($value)) {
                    return $value;
                }
            }
        }
        $type = strtolower((string) ($bit['type'] ?? ''));
        $pagelike = strpos($type, 'html') !== false || strpos($type, 'interactive') !== false || strpos($type, 'animation') !== false;
        foreach (['answer', 'text', 'content'] as $key) {
            foreach ([$data, $bit] as $source) {
                $value = $source[$key] ?? null;
                if (is_string($value) && trim($value) !== '' && ($pagelike || self::looksLikePage($value))) {
                    return $value;
                }
            }
        }
        return '';
    }

    /**
     * Whether a string is an HTML page rather than Markdown.
     */
    public static function looksLikePage(string $value): bool
    {
        $head = ltrim(substr($value, 0, 200));
        if (stripos($head, '<!doctype') === 0 || stripos($head, '<html') === 0) {
            return true;
        }
        return (bool) preg_match('~<(script|style|svg|canvas|input|button|form|select|textarea|video|iframe|body|head)\b~i', $value);
    }

    public function generateWidgets(array $params): array
    {
        $fields = $this->generationFields($params, 'ask') + [
            'query' => (string) ($params['query'] ?? ''),
        ];

        $industry = trim((string) ($params['industry'] ?? ''));
        if ($industry !== '') {
            // An unset domain is omitted rather than sent empty.
            $fields['industry'] = $industry;
        }
        $replyto = trim((string) ($params['replytowidget'] ?? ''));
        if ($replyto !== '') {
            // "Ask about this": anchor the question on this widget rather
            // than on the conversation's tail.
            $fields['reply_to_widget'] = $replyto;
        }

        $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields);

        // An answerWidget carries the answer in bit.data.answer (its
        // bit.text merely echoes the question); the informational widgets -
        // the welcome on a conversation's first call, and whatever Tarsus
        // adds later - carry their message in bit.text with no data.answer.
        // Collect both rather than matching widget types by name, so new
        // informational widgets surface instead of vanishing.
        $answer = '';
        $html = '';
        $messages = [];
        foreach ((array) ($body['activity']['widgets'] ?? []) as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $bit = (array) ($widget['bit'] ?? []);
            $data = (array) ($bit['data'] ?? []);
            $page = self::htmlOf($bit, $data);
            if ($page !== '') {
                // AI-driven interaction: the answer is an interactive page.
                $html = $page;
                continue;
            }
            $answertext = self::str($data['answer'] ?? '');
            if ($answertext !== '') {
                $answer = $answertext;
                continue;
            }
            $text = self::str($bit['text'] ?? '');
            if ($text !== '') {
                // The welcome carries the activity's name as its title.
                $messages[] = ['title' => self::str($bit['title'] ?? ''), 'text' => $text];
            }
        }

        return [
            'answer' => $answer,
            'html' => $html,
            'messages' => $messages,
            // The response carries no cost figure; the agreement counts one
            // interaction per answered question (input is always text here).
            'credits' => Interactions::forGenerate(),
        ];
    }

    public function generatePracticeWidget(array $params): array
    {
        // Same call as the chat, but state 'practice' makes the brain
        // generate the next question instead of answering one. The query is
        // required by the endpoint and stays empty here; question_types
        // must carry all three grades with at least one type each.
        $fields = $this->generationFields($params, 'practice') + [
            'taxonomy_levels' => json_encode(array_values($params['taxonomylevels'] ?? [])),
            'question_types' => self::encodeQuestionTypes((array) ($params['questiontypes'] ?? [])),
            'query' => '',
        ];

        $industry = trim((string) ($params['industry'] ?? ''));
        if ($industry !== '') {
            $fields['industry'] = $industry;
        }

        try {
            $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields);
        } catch (TransportException $e) {
            // When no question of the requested types can be built from the
            // material, the brain answers 500 with this message rather than
            // an empty list. That is "no question", not a transport failure.
            if ($e->httpstatus === 500 && stripos($e->body, 'UNABLE TO CREATE WIDGETS') !== false) {
                return ['widgets' => [], 'progress' => self::extractProgress([], []), 'credits' => 0];
            }
            throw $e;
        }

        // One call may generate several questions (usually two); every
        // widget of a supported question type is returned, in the order
        // Tarsus sent them. Correctness information (isCorrect,
        // clarifications, the model answer) stays server-side - it must
        // never reach the learner's browser before evaluation.
        $widgets = [];
        $widgetdata = [];
        foreach ((array) ($body['activity']['widgets'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $bit = (array) ($candidate['bit'] ?? []);
            $type = (string) ($bit['type'] ?? '');
            if (!in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
                continue;
            }
            $data = (array) ($bit['data'] ?? []);
            $choices = [];
            foreach ((array) ($data['choices'] ?? []) as $choice) {
                $choices[] = self::choiceText(is_array($choice) ? ($choice['choice'] ?? '') : $choice);
            }
            $widgets[] = [
                'id' => self::str($candidate['_id'] ?? ''),
                'type' => $type,
                'question' => self::str($data['question'] ?? ($bit['text'] ?? '')),
                'choices' => $choices,
                'variables' => self::variablesOf($data),
                'table' => self::questionTable($data),
                'columns' => self::tableColumns((array) ($data['questionTable'] ?? [])),
                'taxonomylevel' => self::str($data['taxonomyLevel'] ?? ''),
                'difficultylevel' => (int) ($data['difficultyLevel'] ?? 0),
            ];
            $widgetdata = $data;
        }

        return [
            'widgets' => $widgets,
            'progress' => self::extractProgress((array) ($body['activity'] ?? []), $widgetdata),
            'credits' => Interactions::forGenerate(),
        ];
    }

    public function generateLearnWidget(array $params): array
    {
        // Same call as the practice, with state 'learn': the brain first
        // sends the learning objectives, then one micro-learning stack per
        // call. learning_method picks the didactic concept, gif allows
        // animated illustrations.
        $fields = $this->generationFields($params, 'learn') + [
            'learning_method' => (string) ($params['learningmethod'] ?? 'micro-learning'),
            'gif' => !empty($params['gif']) ? 'true' : 'false',
            'taxonomy_levels' => json_encode(array_values($params['taxonomylevels'] ?? [])),
            'question_types' => self::encodeQuestionTypes((array) ($params['questiontypes'] ?? [])),
            'query' => '',
        ];

        $industry = trim((string) ($params['industry'] ?? ''));
        if ($industry !== '') {
            $fields['industry'] = $industry;
        }

        try {
            $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields);
        } catch (TransportException $e) {
            if ($e->httpstatus === 500 && stripos($e->body, 'UNABLE TO CREATE WIDGETS') !== false) {
                return ['items' => [], 'progress' => self::extractProgress([], []), 'credits' => 0];
            }
            throw $e;
        }

        $items = [];
        foreach ((array) ($body['activity']['widgets'] ?? []) as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $item = self::learnItem($widget);
            if ($item === null) {
                // Anything else with a text is a plain message, such as the
                // step-transition note between two objectives.
                $bit = (array) ($widget['bit'] ?? []);
                $text = self::str($bit['text'] ?? '');
                $item = $text !== ''
                    ? ['kind' => 'message', 'title' => self::str($bit['title'] ?? ''), 'text' => $text] : null;
            }
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'items' => $items,
            'progress' => self::extractProgress((array) ($body['activity'] ?? []), []),
            'credits' => Interactions::forGenerate(),
        ];
    }

    /**
     * Shape one stored question widget - in the feed or inside a learning
     * stack - as a 'practice' item, answered ones carrying their verdict
     * from data.evaluation, unanswered ones with correctness stripped.
     *
     * @param array<string, mixed> $widget
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function questionItem(array $widget, string $type, array $data): array
    {
        $bit = (array) ($widget['bit'] ?? []);
        $choices = [];
        foreach ((array) ($data['choices'] ?? []) as $choice) {
            $choices[] = self::choiceText(is_array($choice) ? ($choice['choice'] ?? '') : $choice);
        }
        $evaluation = (array) ($data['evaluation'] ?? []);
        $answered = !empty($evaluation['evaluated']);
        $score = (float) ($evaluation['score'] ?? 0);

        return [
            'kind' => 'practice',
            'id' => self::str($widget['_id'] ?? ''),
            'type' => $type,
            'question' => self::str($data['question'] ?? ($bit['text'] ?? '')),
            'choices' => $choices,
            'variables' => self::variablesOf($data),
            'table' => self::questionTable($data),
            'columns' => self::tableColumns((array) ($data['questionTable'] ?? [])),
            'taxonomylevel' => self::str($data['taxonomyLevel'] ?? ''),
            'difficultylevel' => (int) ($data['difficultyLevel'] ?? 0),
            'answered' => $answered,
            'score' => $answered ? $score : null,
            'correct' => $answered ? ($score >= 50) : null,
            'feedback' => $answered ? self::str($evaluation['feedback'] ?? '') : '',
            'useranswer' => $answered ? self::choiceText($evaluation['userAnswer'] ?? '') : '',
            'useranswers' => $answered ? self::variableAnswers($evaluation['userVariables'] ?? []) : [],
            'answer' => $answered ? self::choiceText($evaluation['answer'] ?? ($data['answer'] ?? '')) : '',
            'answers' => $answered ? self::variableVerdicts($data) : [],
            'usertable' => $answered ? self::tableRows($evaluation['userTable'] ?? []) : [],
            'answertable' => $answered ? self::tableVerdicts($data) : [],
        ];
    }

    /**
     * Read one learn-mode widget: the learning objectives, a stack, the
     * motivation between two objectives or the farewell.
     *
     * @param array<string, mixed> $widget
     * @return array<string, mixed>|null
     */
    private static function learnItem(array $widget): ?array
    {
        if (isset($widget['stackedWidgets']) && is_array($widget['stackedWidgets'])) {
            $slides = [];
            foreach ($widget['stackedWidgets'] as $slide) {
                if (!is_array($slide)) {
                    continue;
                }
                $bit = (array) ($slide['bit'] ?? []);
                $data = (array) ($bit['data'] ?? []);
                $type = (string) ($bit['type'] ?? '');
                if (in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
                    // The unit's check question (usually the last slide).
                    // Answering it with state 'learn' earns learning units.
                    $slides[] = [
                        'id' => self::str($slide['_id'] ?? ''),
                        'kind' => 'question',
                        'title' => '',
                        'text' => '',
                        'image' => '',
                        'imageposition' => 'right',
                        'question' => self::questionItem($slide, $type, $data),
                    ];
                    continue;
                }
                $page = self::htmlOf($bit, $data);
                if ($page !== '') {
                    // An interactive slide (ai-driven interaction).
                    $slides[] = [
                        'id' => self::str($slide['_id'] ?? ''),
                        'kind' => 'html',
                        'taxonomylevel' => self::str($data['taxonomyLevel'] ?? ''),
                        'title' => self::str($data['title'] ?? ($bit['title'] ?? '')),
                        'text' => '',
                        'html' => $page,
                        'image' => '',
                        'imageposition' => 'right',
                    ];
                    continue;
                }
                $text = self::str($data['text'] ?? ($bit['text'] ?? ''));
                $title = self::str($data['title'] ?? ($bit['title'] ?? ''));
                if ($text === '' && $title === '') {
                    continue;
                }
                $image = (array) ($data['image'] ?? []);
                $position = strtolower(self::str($image['imagePosition'] ?? ($data['imagePosition'] ?? 'right')));
                $slides[] = [
                    'id' => self::str($slide['_id'] ?? ''),
                    'kind' => 'text',
                    'taxonomylevel' => self::str($data['taxonomyLevel'] ?? ''),
                    'title' => $title,
                    'text' => $text,
                    'image' => self::imageSource(self::str($image['imageBytes'] ?? ($image['url'] ?? ($image['imageUrl'] ?? '')))),
                    'imageposition' => $position === 'left' ? 'left' : 'right',
                ];
            }
            return [
                'kind' => 'stack',
                'id' => self::str($widget['stackId'] ?? ''),
                'slides' => $slides,
            ];
        }

        $bit = (array) ($widget['bit'] ?? []);
        $type = (string) ($bit['type'] ?? '');
        $data = (array) ($bit['data'] ?? []);
        if (!in_array($type, self::QUESTION_WIDGET_TYPES, true) && !isset($data['answer'])) {
            $page = self::htmlOf($bit, $data);
            if ($page !== '') {
                // A page outside a stack shows as a one-slide unit.
                return self::pageStack($widget, $bit, $data, $page);
            }
        }
        if ($type === 'courseCompleteWidget' || $type === 'successWidget') {
            // The brain's farewell once every objective is done.
            return [
                'kind' => 'complete',
                'id' => self::str($widget['_id'] ?? ''),
                'title' => self::str($bit['title'] ?? ''),
                'text' => self::str($bit['text'] ?? ''),
            ];
        }
        if ($type === 'skipContentWidget' || $type === 'motivationWidget') {
            // The encouragement between two learning objectives.
            return [
                'kind' => 'motivation',
                'id' => self::str($widget['_id'] ?? ''),
                'title' => self::str($bit['title'] ?? ''),
                'text' => self::str($bit['text'] ?? ''),
            ];
        }
        if ($type === 'learningObjectivesWidget') {
            $data = (array) ($bit['data'] ?? []);
            $objectives = [];
            foreach ((array) ($data['learningObjectives'] ?? []) as $objective) {
                if (!is_array($objective)) {
                    continue;
                }
                $objectives[] = [
                    'id' => self::str($objective['_id'] ?? ''),
                    'objective' => self::str($objective['objective'] ?? ''),
                    'taxonomylevel' => self::str($objective['taxonomyLevel'] ?? ''),
                    'done' => !empty($objective['done']),
                ];
            }
            return [
                'kind' => 'objectives',
                'id' => self::str($widget['_id'] ?? ''),
                'title' => self::str($data['title'] ?? ($bit['title'] ?? '')),
                'message' => self::str($data['message'] ?? ($bit['text'] ?? '')),
                'objectives' => $objectives,
            ];
        }

        return null;
    }

    /**
     * A standalone interactive page as a one-slide stack.
     *
     * @param array<string, mixed> $widget
     * @param array<string, mixed> $bit
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function pageStack(array $widget, array $bit, array $data, string $page): array
    {
        $id = self::str($widget['stackId'] ?? ($widget['_id'] ?? ''));
        return [
            'kind' => 'stack',
            'id' => $id,
            'slides' => [[
                'id' => self::str($widget['_id'] ?? $id),
                'kind' => 'html',
                'taxonomylevel' => self::str($data['taxonomyLevel'] ?? ''),
                'title' => self::str($data['title'] ?? ($bit['title'] ?? '')),
                'text' => '',
                'html' => $page,
                'image' => '',
                'imageposition' => 'right',
            ]],
        ];
    }

    /**
     * Accept an illustration as a base64 image data URI (generated
     * flowcharts) or an https image URL (animated GIFs).
     */
    private static function imageSource(string $value): string
    {
        if (preg_match('~^data:image/(svg\+xml|png|jpeg|gif|webp);base64,[A-Za-z0-9+/=\s]+$~', $value)) {
            return (string) preg_replace('/\s+/', '', $value);
        }
        if (preg_match('~^https://[^\s"\'<>]+$~', $value)) {
            return $value;
        }
        return '';
    }

    public function evaluatePracticeAnswer(array $params): array
    {
        $paths = [
            'singleChoiceQuestionWidget' => '/api/t0/activity/evaluate/single-choice-question/widget',
            'trueFalseQuestionWidget' => '/api/t0/activity/evaluate/true-false-question/widget',
            'openQuestionWidget' => '/api/t0/activity/evaluate/open-question/widget',
            'crossMultiplicationQuestionWidget' => '/api/t0/activity/evaluate/cross-multiplication-question/widget',
            'tradeCostingQuestionWidget' => '/api/t0/activity/evaluate/trade-costing-question/widget',
        ];
        $type = (string) ($params['widgettype'] ?? '');
        if (!isset($paths[$type])) {
            throw new \InvalidArgumentException('Unsupported practice widget type: ' . $type);
        }

        // The endpoints take slightly different subsets; the superset is
        // sent to all of them.
        $fields = [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'context_name' => (string) ($params['contextname'] ?? ''),
            'context_description' => (string) ($params['contextdescription'] ?? ''),
            'robot_id' => (string) ($params['robotid'] ?? ''),
            'robot_name' => (string) ($params['robotname'] ?? ''),
            'robot_description' => (string) ($params['robotdescription'] ?? ''),
            'user_id' => (string) ($params['userid'] ?? ''),
            'user_name' => (string) ($params['username'] ?? ''),
            'user_language' => (string) ($params['userlanguage'] ?? 'de'),
            // 'learn' evaluates a learning unit's check question and earns
            // learning units; 'practice' earns experience points.
            'state' => (($params['state'] ?? '') === 'learn') ? 'learn' : 'practice',
            'input_modality' => 'text',
            'learn_tempo' => self::learnTempo($params),
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
            'taxonomy_levels' => json_encode(array_values($params['taxonomylevels'] ?? [])),
            'widget_id' => (string) ($params['widgetid'] ?? ''),
            'user_answer' => (string) ($params['useranswer'] ?? ''),
        ];
        if (!empty($params['useranswers'])) {
            // A rule-of-three question is answered per variable; the brain
            // accepts exactly {variable, answer} pairs.
            $fields['user_answers'] = json_encode(array_map(
                static fn(array $entry): array => ['variable' => $entry['variable'], 'answer' => $entry['answer']],
                self::variableAnswers($params['useranswers'])
            ));
        } elseif (!empty($params['usertable'])) {
            // A spreadsheet calculation is answered as the whole table, the
            // given cells echoed and the '?' cells filled. The column names
            // are localised by the brain per user language, so the names
            // the question came with travel back with the answer.
            $columns = array_values(array_map('strval', (array) ($params['columns'] ?? [])));
            if (count($columns) !== 3) {
                $columns = ['Position', 'Satz', 'Betrag'];
            }
            $fields['user_answers'] = json_encode(array_map(
                static fn(array $row): array => [
                    $columns[0] => $row['position'], $columns[1] => $row['rate'], $columns[2] => $row['amount'],
                ],
                self::tableRows($params['usertable'])
            ));
        }

        $body = $this->send('POST', $paths[$type], $fields);

        $data = (array) ($body['activity']['widget']['bit']['data'] ?? []);

        // The verdict lives in data.evaluation: score 0-100, the feedback
        // sentence, the model answer and the user's answer. Matching the
        // learner's pick against the choices is the fallback when the
        // evaluation block is missing.
        $useranswer = self::normaliseAnswer((string) ($params['useranswer'] ?? ''));
        $choices = [];
        $correct = null;
        $feedback = '';
        foreach ((array) ($data['choices'] ?? []) as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $text = self::choiceText($choice['choice'] ?? '');
            $ischoicecorrect = !empty($choice['isCorrect']);
            $choices[] = [
                'text' => $text,
                'iscorrect' => $ischoicecorrect,
                'clarification' => self::str($choice['clarification'] ?? ''),
            ];
            if (self::normaliseAnswer($text) === $useranswer) {
                $correct = $ischoicecorrect;
                $feedback = self::str($choice['clarification'] ?? '');
            }
        }

        $evaluation = (array) ($data['evaluation'] ?? []);
        $answer = self::str($data['answer'] ?? '');
        $score = null;
        if (!empty($evaluation['evaluated'])) {
            $score = (float) ($evaluation['score'] ?? 0);
            $correct = $score >= 50;
            $feedback = self::str($evaluation['feedback'] ?? $feedback);
            $answer = self::str($evaluation['answer'] ?? $answer);
        }

        return [
            'correct' => $correct,
            'score' => $score,
            'feedback' => $feedback,
            'answer' => self::choiceText($answer),
            'choices' => $choices,
            'answers' => self::variableVerdicts($data),
            'usertable' => self::tableRows($evaluation['userTable'] ?? []),
            'answertable' => self::tableVerdicts($data),
            'progress' => self::extractProgress((array) ($body['activity'] ?? []), $data),
            // Single choice and true/false are corrected without the AI.
            'credits' => Interactions::forEvaluation($type),
        ];
    }

    /**
     * The question table of a spreadsheet calculation, '?' marking the
     * cells to answer.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{position: string, rate: string, amount: string, askrate: bool, askamount: bool}>
     */
    private static function questionTable(array $data): array
    {
        $rows = [];
        foreach (self::tableRows($data['questionTable'] ?? []) as $row) {
            $row['askrate'] = $row['rate'] === '?';
            $row['askamount'] = $row['amount'] === '?';
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The column names of an API costing table: position, rate, amount.
     * The brain localises them per user language; known names are matched,
     * anything else falls back to column order.
     *
     * @param array<int|string, mixed> $rows
     * @return string[]
     */
    private static function tableColumns(array $rows): array
    {
        $first = null;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $first = $row;
                break;
            }
        }
        if ($first === null) {
            return ['Position', 'Satz', 'Betrag'];
        }
        $keys = array_values(array_filter(
            array_keys($first),
            static fn($key) => strcasecmp((string) $key, 'clarification') !== 0
        ));
        $pick = static function (string $pattern, int $index) use ($keys): string {
            foreach ($keys as $key) {
                if (preg_match($pattern, (string) $key)) {
                    return (string) $key;
                }
            }
            return (string) ($keys[$index] ?? '');
        };
        return [
            $pick('/^position$/i', 0),
            $pick('/^(rate|satz|prozentsatz|taux|tasa|tasso)$/i', 1),
            $pick('/^(amount|betrag|montant|importe|importo)$/i', 2),
        ];
    }

    /**
     * Normalise costing table rows from either side of the wire.
     *
     * @param mixed $entries
     * @return array<int, array{position: string, rate: string, amount: string}>
     */
    private static function tableRows($entries): array
    {
        $entries = array_values(array_filter((array) $entries, 'is_array'));
        [$poskey, $ratekey, $amountkey] = self::tableColumns($entries);
        $rows = [];
        foreach ($entries as $entry) {
            $position = trim(self::str($entry['position'] ?? ($entry[$poskey] ?? '')));
            if ($position === '') {
                continue;
            }
            $rows[] = [
                'position' => $position,
                'rate' => trim(self::str($entry['rate'] ?? ($entry[$ratekey] ?? ''))),
                'amount' => trim(self::str($entry['amount'] ?? ($entry[$amountkey] ?? ''))),
            ];
        }
        return $rows;
    }

    /**
     * The model table of a spreadsheet calculation with per-cell verdicts:
     * evaluationMask holds one row per table row, 1 for a correct cell, -1
     * for a wrong one, 0 for a given one.
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private static function tableVerdicts(array $data): array
    {
        $evaluation = (array) ($data['evaluation'] ?? []);
        $mask = array_values(array_filter((array) ($evaluation['evaluationMask'] ?? []), 'is_array'));
        $modelrows = (array) ($evaluation['answerTable'] ?? ($data['answerTable'] ?? []));
        [, $ratekey, $amountkey] = self::tableColumns($mask !== [] ? $mask : $modelrows);
        $rows = [];
        foreach (self::tableRows($modelrows) as $i => $row) {
            $cell = $mask[$i] ?? [];
            $row['rateverdict'] = max(-1, min(1, (int) ($cell[$ratekey] ?? 0)));
            $row['amountverdict'] = max(-1, min(1, (int) ($cell[$amountkey] ?? 0)));
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The model answers of a rule-of-three question with their verdicts.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{variable: string, answer: string, iscorrect: bool, clarification: string}>
     */
    private static function variableVerdicts(array $data): array
    {
        $evaluation = (array) ($data['evaluation'] ?? []);
        $verdicts = [];
        foreach ((array) ($evaluation['evaluationMask'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['variable'])) {
                $verdicts[(string) $entry['variable']] = (float) ($entry['evaluation'] ?? 0) > 0;
            }
        }
        $answers = self::variableAnswers($evaluation['answerVariables'] ?? ($data['answers'] ?? []));
        foreach ($answers as &$answer) {
            $answer['iscorrect'] = $verdicts[$answer['variable']] ?? false;
        }
        unset($answer);
        return $answers;
    }

    /**
     * The variable names a rule-of-three question asks for, in order.
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    private static function variablesOf(array $data): array
    {
        $variables = [];
        foreach ((array) ($data['answers'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $variable = trim(self::str($entry['variable'] ?? ''));
            if ($variable !== '') {
                $variables[] = $variable;
            }
        }
        return $variables;
    }

    /**
     * Normalise a per-variable answer list from either side of the wire.
     *
     * @param mixed $entries
     * @return array<int, array{variable: string, answer: string, iscorrect: bool, clarification: string}>
     */
    private static function variableAnswers($entries): array
    {
        $answers = [];
        foreach ((array) $entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $variable = trim(self::str($entry['variable'] ?? ''));
            if ($variable === '') {
                continue;
            }
            $answers[] = [
                'variable' => $variable,
                'answer' => self::str($entry['answer'] ?? ''),
                'iscorrect' => !empty($entry['isCorrect'] ?? ($entry['iscorrect'] ?? false)),
                'clarification' => self::str($entry['clarification'] ?? ''),
            ];
        }
        return $answers;
    }

    /**
     * The operator taxonomy to send: 'standard' or 'ihk'.
     *
     * @param array<string, mixed> $params
     */
    private static function operators(array $params): string
    {
        $value = strtolower(trim((string) ($params['operators'] ?? '')));
        return in_array($value, ['standard', 'ihk'], true) ? $value : 'standard';
    }

    /**
     * The learning pace to send, within the brain's 0.1-2 range.
     *
     * @param array<string, mixed> $params
     */
    private static function learnTempo(array $params): string
    {
        $value = $params['learntempo'] ?? null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '1';
        }
        $pace = max(0.1, min(2.0, round((float) $value, 2)));
        return rtrim(rtrim(number_format($pace, 2, '.', ''), '0'), '.');
    }

    /**
     * Encode the question types map with string grade keys, as the API expects.
     *
     * @param array<int|string, mixed> $map
     */
    private static function encodeQuestionTypes(array $map): string
    {
        $encoded = [];
        foreach ($map as $grade => $types) {
            $encoded[(string) (int) $grade] = array_values((array) $types);
        }
        return (string) json_encode((object) $encoded);
    }

    /**
     * Render a choice value as text: true/false widgets use booleans.
     *
     * @param mixed $choice
     */
    private static function choiceText($choice): string
    {
        if (is_bool($choice)) {
            return $choice ? 'true' : 'false';
        }
        return self::str($choice);
    }

    /**
     * Compare answers leniently: case and surrounding space do not matter.
     */
    private static function normaliseAnswer(string $answer): string
    {
        return mb_strtolower(trim($answer), 'UTF-8');
    }

    /**
     * A scalar as text; arrays and objects become ''.
     *
     * @param mixed $value
     */
    private static function str($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return '';
    }

    /**
     * Read the experience/progress figures from a t0 response. Generation
     * carries them inside the widget data, evaluation at the activity
     * level; the widget data wins where both are present.
     *
     * @param array<string, mixed> $activity
     * @param array<string, mixed> $widgetdata
     * @return array<string, float>
     */
    private static function extractProgress(array $activity, array $widgetdata): array
    {
        $pick = static function (string $key) use ($activity, $widgetdata): float {
            $value = $widgetdata[$key] ?? ($activity[$key] ?? 0);
            return is_numeric($value) ? (float) $value : 0.0;
        };
        return [
            'earnedxp' => $pick('earnedExperiencePoints'),
            'totalxp' => $pick('totalExperiencePoints'),
            // The learn mode keeps its own tally, learning units, beside
            // the practice mode's experience points.
            'earnedunits' => $pick('earnedLearningUnits'),
            'totalunits' => $pick('totalLearningUnits'),
            // Three progress figures: practice and learn each have their
            // own, and 'progress' is the overall one (their average).
            'practiceprogress' => $pick('practiceProgress'),
            'learnprogress' => $pick('learnProgress'),
            'progress' => $pick('progress'),
        ];
    }

    /**
     * Translate a brain status entry into the local state-machine
     * vocabulary. The brain reports done, pending and failed; "failed"
     * means the resource is simply not in the brain. An unrecognised value
     * is treated as "still working", never as success.
     *
     * @param array<string, mixed> $entry
     */
    private function mapStatus(array $entry): string
    {
        switch (strtolower(self::str($entry['status'] ?? ''))) {
            case 'done':
                return Status::INDEXED;
            case 'failed':
                return Status::NOT_INDEXED;
            default:
                return Status::PENDING_CONFIRMATION;
        }
    }

    /**
     * Pick the endpoint and form fields for one material payload.
     *
     * @param array<string, mixed> $resource
     * @return array{0: string, 1: array<string, mixed>, 2: ?string} Path, form fields, temp file to clean up.
     */
    private function buildTrackRequest(array $resource): array
    {
        $title = (string) ($resource['title'] ?? '');
        $type = (string) ($resource['type'] ?? BitType::TEXT);

        $common = [
            'brain_id' => $this->brain(),
            'resource_id' => (string) ($resource['resource_id'] ?? ''),
            'asset_id' => (string) ($resource['context_id'] ?? ''),
        ];

        if ($type === BitType::URL) {
            return ['access/track/url-bits', $common + ['url' => (string) ($resource['url'] ?? '')], null];
        }

        if ($type === BitType::DOCUMENT || $type === BitType::MEDIA) {
            return $this->buildFileTrackRequest($resource, $common, $title, $type);
        }

        return [
            'access/track/text-bits',
            $common + ['text' => (string) ($resource['text'] ?? ''), 'title' => $title],
            null,
        ];
    }

    /**
     * Build the multipart request for a document or media bit, copying the
     * file out of the ILIAS resource storage into a temporary file.
     *
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $common
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function buildFileTrackRequest(array $resource, array $common, string $title, string $type): array
    {
        $temppath = self::copyToTemp((string) ($resource['rid'] ?? ''));
        if ($temppath === null) {
            throw new TransportException(0, 'stored file for the resource no longer exists');
        }

        $mimetype = (string) ($resource['mimetype'] ?? 'application/octet-stream');
        $filename = (string) ($resource['filename'] ?? $title);
        $curlfile = new \CURLFile($temppath, $mimetype !== '' ? $mimetype : 'application/octet-stream', $filename);

        if ($type === BitType::DOCUMENT) {
            return ['access/track/document-bits', $common + ['document' => $curlfile], $temppath];
        }

        $isaudio = strncmp($mimetype, 'audio/', 6) === 0;

        return [
            $isaudio ? 'access/track/audio-bits' : 'access/track/video-bits',
            $common + ['title' => $title, ($isaudio ? 'audio_content' : 'video_content') => $curlfile],
            $temppath,
        ];
    }

    /**
     * Copy a resource-storage file into a temporary file.
     *
     * @return string|null The temporary path, or null when the file is gone.
     */
    public static function copyToTemp(string $rid): ?string
    {
        if ($rid === '' || $rid === '-') {
            return null;
        }
        try {
            $storage = Ilias::dic()->resourceStorage();
            $identification = $storage->manage()->find($rid);
            if ($identification === null) {
                return null;
            }
            $stream = $storage->consume()->stream($identification)->getStream();
            $temp = tempnam(sys_get_temp_dir(), 'xtar');
            if ($temp === false) {
                return null;
            }
            $out = fopen($temp, 'wb');
            if ($out === false) {
                return null;
            }
            $stream->rewind();
            while (!$stream->eof()) {
                $chunk = $stream->read(1048576);
                if ($chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
            }
            fclose($out);
            $stream->close();
            return $temp;
        } catch (\Throwable $e) {
            Ilias::log('could not read file ' . $rid . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The configured brain id, or fail loudly. A license-key customer has
     * none: Tarsus resolves it from the license key, and
     * {@see requestFields()} leaves the field out. Listing brains
     * deliberately does not go through here: an admin must be able to
     * discover the available brains before one has been chosen.
     */
    private function brain(): string
    {
        $brainid = trim($this->brainid);
        if ($brainid === '' && trim($this->licensekey) === '') {
            throw new ConfigurationException('err_nobrainselected');
        }
        return $brainid;
    }

    /**
     * Split ids into wire-sized chunks, dropping empties and duplicates.
     *
     * @param string[] $resourceids
     * @return array<int, string[]>
     */
    private function chunk(array $resourceids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $resourceids), 'strlen')));

        return $ids === [] ? [] : array_chunk($ids, self::BATCH_LIMIT);
    }

    /**
     * Perform one form-data call against the brain and decode its JSON
     * body. The API takes form-data even for GET and DELETE, so the method
     * is set via CURLOPT_CUSTOMREQUEST while the fields travel in the body,
     * exactly as "curl --request GET --form" does.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $params, bool $uploading = false, ?int $timeout = null): array
    {
        $baseurl = rtrim(trim($this->baseurl), '/');

        if ($baseurl === '' || trim($this->apikey) === '') {
            throw new ConfigurationException('err_notconfigured');
        }

        $params = $this->requestFields($params);

        $url = (strncmp($path, '/', 1) === 0)
            ? $baseurl . $path
            : $baseurl . self::API_PREFIX . $path;

        $curl = curl_init($url);
        if ($curl === false) {
            throw new TransportException(0, 'could not initialise curl');
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $timeout ?? ($uploading ? self::UPLOAD_TIMEOUT : self::TIMEOUT),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Expect:'],
            CURLOPT_USERAGENT => 'Tarsus-ILIAS/' . Ilias::pluginVersion(),
        ]);
        self::applyProxy($curl);

        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $httpcode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($errno !== 0 || $response === false) {
            // Never echo the request: it carries the API key.
            throw new TransportException(0, 'connection failed calling ' . $path);
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            // The brain explains some refusals in the body; keep its start
            // (never the request, which carries the API key).
            throw new TransportException(
                $httpcode,
                'unexpected HTTP status calling ' . $path,
                trim(substr((string) $response, 0, 300))
            );
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new TransportException(0, 'malformed JSON response from ' . $path);
        }

        return $decoded;
    }

    /**
     * The form fields every call carries: the caller's fields plus the
     * credentials. Two forms exist and never mix: a partner customer sends
     * api_key and brain_id; an existing Tarsus customer sends api_key and
     * license_key, and no brain_id - the license key stands for the brain.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function requestFields(array $params): array
    {
        $params['api_key'] = $this->apikey;
        if (trim($this->licensekey) !== '') {
            $params[self::LICENSE_FIELD] = trim($this->licensekey);
            unset($params['brain_id']);
        } elseif (isset($params['brain_id']) && (string) $params['brain_id'] === '') {
            unset($params['brain_id']);
        }
        return $params;
    }

    /**
     * Route the call through the proxy configured in ILIAS, if any.
     *
     * @param \CurlHandle|resource $curl
     */
    private static function applyProxy($curl): void
    {
        try {
            if (class_exists('ilProxySettings')) {
                $proxy = \ilProxySettings::_getInstance();
                if ($proxy->isActive() && $proxy->getHost() !== '') {
                    curl_setopt($curl, CURLOPT_PROXY, $proxy->getHost());
                    if ((int) $proxy->getPort() > 0) {
                        curl_setopt($curl, CURLOPT_PROXYPORT, (int) $proxy->getPort());
                    }
                }
            }
        } catch (\Throwable $e) {
            // No proxy configuration available: connect directly.
        }
    }
}
