<?php

namespace TarsusIlias\Client;

use TarsusIlias\Index\Status;
use TarsusIlias\Material\BitType;
use TarsusIlias\Output\Media;
use TarsusIlias\Support\Ilias;

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
 * What a call costs is not estimated here: Tarsus records it in the
 * brain's usage, which is the only figure the plugin shows and limits by.
 *
 * Widgets are read by their type, as Tarsus sends them; a page is taken
 * from data.code of a genericWebWidget or genericLiveWidget and nowhere
 * else. All transport and protocol failures surface as TransportException
 * so the guardrails pipeline stays in control; HTTP 409 (a widget sent
 * again in Build knowledge) surfaces as ReplayException.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
class HttpClient implements ClientInterface
{
    /** Common prefix of every brain endpoint. */
    private const API_PREFIX = '/api/v1/brain/';

    /** An AI-made HTML page (ai-driven interaction); the page is in data.code. */
    public const WEB_WIDGET = 'genericWebWidget';

    /** The live interaction (state discuss); its page is in data.code. */
    public const LIVE_WIDGET = 'genericLiveWidget';

    /** Learn-mode widgets that carry a message, the learning objectives or the farewell. */
    private const LEARN_MESSAGE_TYPES = [
        'learningObjectivesWidget', 'courseCompleteWidget', 'successWidget', 'skipContentWidget', 'motivationWidget',
    ];

    /** Thinking levels Tarsus accepts (1 fastest .. 4 most thorough) and its default. */
    public const THINKING_LEVELS = [1, 2, 3, 4];
    public const THINKING_LEVEL_DEFAULT = 4;

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

        // Ingestion is asynchronous: receipt is not indexation.
        return [
            'status' => Status::PENDING_CONFIRMATION,
            'bitref' => '',
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
            $item = self::feedItem($widget);
            if ($item !== null) {
                $items[] = $item;
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
            'visual_accessibility' => !empty($params['visualaccessibility']) ? 'true' : 'false',
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
            // What is spoken, word for word.
            'text' => self::str($body['activity']['audio']['text'] ?? ''),
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

        // The brain's own figure, kept as it is: a credit can be a fraction
        // (thinking level 3 costs 1.57).
        $items = [];
        $total = 0.0;
        foreach ((array) ($body['awareness']['usage'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $credits = is_numeric($entry['aiCredits'] ?? null) ? round((float) $entry['aiCredits'], 2) : 0.0;
            $total += $credits;
            $items[] = [
                'key' => self::str($entry['key'] ?? ''),
                'name' => self::str($entry['name'] ?? ''),
                'service' => self::str($entry['service'] ?? ''),
                'credits' => $credits,
            ];
        }

        return ['total' => round($total, 2), 'items' => $items];
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
        // text unless a recording is sent. full_brain_access must be an
        // explicit 'false': that is what makes resource_ids the retrieval
        // boundary; 'true' searches the whole shared brain regardless of
        // scope.
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
            // 1 (fastest) .. 4 (most thorough); Tarsus charges by it.
            'thinking_level' => (string) self::thinkingLevel($params),
            // The same content for a learner who sees the screen and one
            // who hears it.
            'visual_accessibility' => !empty($params['visualaccessibility']) ? 'true' : 'false',
            'resource_ids' => json_encode(array_values($params['resourceids'] ?? [])),
        ];
    }

    /**
     * The thinking level to send: one of 1-4, Tarsus's default otherwise.
     *
     * @param array<string, mixed> $params
     */
    public static function thinkingLevel(array $params): int
    {
        $level = (int) ($params['thinkinglevel'] ?? self::THINKING_LEVEL_DEFAULT);
        return in_array($level, self::THINKING_LEVELS, true) ? $level : self::THINKING_LEVEL_DEFAULT;
    }

    private static function interactionType(array $params): string
    {
        $type = (string) ($params['interactiontype'] ?? '');
        return $type === 'ai-driven' ? 'ai-driven' : 'trainer-driven';
    }

    /**
     * The page of a genericWebWidget or genericLiveWidget: data.code, the
     * whole HTML page with its pictures. Answers Tarsus saved before it
     * sent data.code fall back to data.answer. Other widgets carry no
     * page - their data.code is something else (a diagram's source, an
     * image id).
     *
     * @param array<string, mixed> $bit
     */
    private static function pageOf(array $bit): string
    {
        $type = (string) ($bit['type'] ?? '');
        if ($type !== self::WEB_WIDGET && $type !== self::LIVE_WIDGET) {
            return '';
        }
        $data = (array) ($bit['data'] ?? []);
        $page = self::str($data['code'] ?? '');
        if (trim($page) === '' && $type === self::WEB_WIDGET) {
            $page = self::str($data['answer'] ?? '');
        }
        return trim($page) === '' ? '' : $page;
    }

    /**
     * One saved widget as an item for the browser, read by its type; null
     * for what the feed does not show.
     *
     * @param array<string, mixed> $widget
     * @return array<string, mixed>|null
     */
    private static function feedItem(array $widget): ?array
    {
        if (isset($widget['stackedWidgets']) && is_array($widget['stackedWidgets'])) {
            return self::learnItem($widget);
        }
        $bit = (array) ($widget['bit'] ?? []);
        $type = (string) ($bit['type'] ?? '');
        $data = (array) ($bit['data'] ?? []);
        if (in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
            return self::questionItem($widget, $type, $data);
        }
        if (in_array($type, self::LEARN_MESSAGE_TYPES, true)) {
            return self::learnItem($widget);
        }
        $page = self::pageOf($bit);
        if ($page !== '' && self::str($widget['state'] ?? '') === 'learn') {
            // A learning page outside its stack: a one-page unit.
            return self::pageStack($widget, $bit, $data, $page);
        }
        if ($page !== '' || $type === 'answerWidget' || isset($data['answer'])) {
            return self::exchangeItem($widget, $bit, $data, $page);
        }
        $text = self::str($bit['text'] ?? '');
        return $text === '' ? null : ['kind' => 'message', 'title' => self::str($bit['title'] ?? ''), 'text' => $text];
    }

    /**
     * A question and its answer (Ask a question, or an answer given during
     * a live interaction). The question is the learner's text - for a
     * spoken question the text Tarsus made of the recording.
     *
     * @param array<string, mixed> $widget
     * @param array<string, mixed> $bit
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function exchangeItem(array $widget, array $bit, array $data, string $page): array
    {
        return [
            'kind' => 'exchange',
            'id' => self::str($widget['_id'] ?? ''),
            'state' => self::str($widget['state'] ?? ''),
            'query' => self::str($data['question'] ?? ($bit['text'] ?? '')),
            'answer' => $page === '' ? self::str($data['answer'] ?? '') : '',
            'html' => $page,
            // Sources exist when the answer names the bits it came from.
            'sources' => is_array($data['bitIds'] ?? null) && $data['bitIds'] !== [],
        ];
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
        // "Ask about this": the question refers to one learning unit (its
        // stack) or to one card or answer (its widget). Tarsus narrows the
        // conversation it answers from to that; both together would narrow
        // it to their intersection, so one is sent.
        $replystack = trim((string) ($params['replytostack'] ?? ''));
        $replywidget = trim((string) ($params['replytowidget'] ?? ''));
        if ($replystack !== '') {
            $fields['reply_to_stack'] = $replystack;
        } elseif ($replywidget !== '') {
            $fields['reply_to_widget'] = $replywidget;
        }

        // A spoken question: the recording travels as audio_content and
        // Tarsus turns it into text before answering.
        $audiofile = (string) ($params['audiofile'] ?? '');
        if ($audiofile !== '') {
            $fields['input_modality'] = 'audio';
            $fields['query'] = '';
            $fields['audio_content'] = new \CURLFile(
                $audiofile,
                (string) ($params['audiomimetype'] ?? 'application/octet-stream'),
                (string) ($params['audioname'] ?? 'question.webm')
            );
        }

        $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields, $audiofile !== '');

        // The answer is an answerWidget (data.answer, Markdown) or, with
        // ai-driven interaction, a genericWebWidget (its page in data.code).
        // Other widgets - the welcome on a conversation's first call - carry
        // a message in bit.text.
        $answer = '';
        $html = '';
        $question = '';
        $widgetid = '';
        $sources = false;
        $messages = [];
        foreach ((array) ($body['activity']['widgets'] ?? []) as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $bit = (array) ($widget['bit'] ?? []);
            $type = (string) ($bit['type'] ?? '');
            $data = (array) ($bit['data'] ?? []);
            $page = self::pageOf($bit);
            if ($page !== '' || $type === 'answerWidget' || isset($data['answer'])) {
                $item = self::exchangeItem($widget, $bit, $data, $page);
                $html = $item['html'];
                $answer = $item['answer'];
                $question = $item['query'];
                $widgetid = $item['id'];
                $sources = $item['sources'];
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
            'question' => $question,
            'widgetid' => $widgetid,
            'sources' => $sources,
            'messages' => $messages,
            'progress' => self::extractProgress((array) ($body['activity'] ?? []), []),
        ];
    }

    /**
     * Start a live interaction (state discuss): Tarsus answers with one
     * genericLiveWidget whose page, in data.code, holds the whole call.
     * The widget is not saved by Tarsus; what the coach answers during the
     * call is, and shows in the feed afterwards.
     *
     * @param array<string, mixed> $params
     * @return array{page: string, progress: array<string, float>}
     */
    public function startDiscuss(array $params): array
    {
        $fields = $this->generationFields($params, 'discuss') + ['query' => ''];
        $industry = trim((string) ($params['industry'] ?? ''));
        if ($industry !== '') {
            $fields['industry'] = $industry;
        }
        $voice = trim((string) ($params['voice'] ?? ''));
        if ($voice !== '') {
            $fields['voice'] = $voice;
        }
        foreach (['primarycolor' => 'primary_color', 'secondarycolor' => 'secondary_color', 'robotlogo' => 'robot_logo'] as $key => $field) {
            $value = trim((string) ($params[$key] ?? ''));
            if ($value !== '') {
                $fields[$field] = $value;
            }
        }

        $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields);

        $page = '';
        foreach ((array) ($body['activity']['widgets'] ?? []) as $widget) {
            if (is_array($widget)) {
                $page = self::pageOf((array) ($widget['bit'] ?? []));
                if ($page !== '') {
                    break;
                }
            }
        }
        if ($page === '') {
            throw new TransportException(502, 'the live interaction came without its page');
        }
        return ['page' => $page, 'progress' => self::extractProgress((array) ($body['activity'] ?? []), [])];
    }

    /**
     * The bits an answer was made from (its sources), in the order they
     * stand in their material. Tarsus returns at most 32.
     *
     * @param array<string, mixed> $params contextid, userid, state, widgetid
     * @return array<int, array<string, mixed>>
     */
    public function getReferences(array $params): array
    {
        $body = $this->send('POST', '/api/t0/activity/retrieve/widget/references', [
            'brain_id' => $this->brain(),
            'context_id' => (string) ($params['contextid'] ?? ''),
            'context_type' => 'tarsus_activity',
            'user_id' => (string) ($params['userid'] ?? ''),
            'state' => (string) ($params['state'] ?? 'ask'),
            'widget_id' => (string) ($params['widgetid'] ?? ''),
        ]);

        $bits = [];
        foreach ((array) ($body['access']['bits'] ?? []) as $bit) {
            if (is_array($bit)) {
                $bits[] = self::referenceItem($bit);
            }
        }
        usort($bits, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        return $bits;
    }

    /**
     * One source bit as the browser shows it: its type, its source and the
     * content that type carries (text; a table's rows, the first being the
     * header; a picture; the transcript of audio and video).
     *
     * @param array<string, mixed> $bit
     * @return array<string, mixed>
     */
    private static function referenceItem(array $bit): array
    {
        // A bit is either flat or a vector-store point with the bit in its payload.
        if (isset($bit['payload']) && is_array($bit['payload'])) {
            $bit = $bit['payload'] + ['id' => $bit['id'] ?? ''];
        }
        $metadata = (array) ($bit['metadata'] ?? []);
        $data = $bit['data'] ?? null;
        $type = strtolower(self::str($bit['type'] ?? 'text'));
        $text = self::str($bit['text'] ?? '');
        $rows = [];
        $image = '';
        if ($type === 'table' && is_array($data)) {
            foreach ($data as $row) {
                $cells = is_array($row) ? ($row['data'] ?? $row) : null;
                if (is_array($cells)) {
                    $rows[] = array_values(array_map([self::class, 'str'], $cells));
                }
            }
        } elseif ($type === 'image' && is_array($data)) {
            $image = Media::imageFromBase64(self::str($data['content'] ?? ''), self::str($data['extension'] ?? ''));
            if ($image === '') {
                $image = Media::image(self::str($bit['url'] ?? ''));
            }
            if (trim($text) === '@BLANK') {
                // Tarsus marks a picture without a description this way.
                $text = '';
            }
        } elseif ($type === 'question' && is_array($data)) {
            $question = self::str($data['question'] ?? '');
            $answer = $data['answer'] ?? '';
            if ($question !== '') {
                $text = $question . (is_bool($answer) ? '' : (self::str($answer) !== '' ? "\n\n" . self::str($answer) : ''));
            }
        }
        return [
            'id' => self::str($bit['id'] ?? ''),
            'type' => $type,
            'title' => self::str($bit['title'] ?? ''),
            'subtitle' => self::str($bit['subtitle'] ?? ''),
            'text' => $text,
            'rows' => $rows,
            'image' => $image,
            'sourceid' => self::str($metadata['sourceId'] ?? ''),
            'assetid' => self::str($metadata['assetId'] ?? ''),
            'sourcetype' => self::str($metadata['sourceType'] ?? ''),
            'link' => self::str($metadata['sourceShopURL'] ?? ''),
            'position' => is_numeric($metadata['bitPositionInSource'] ?? null) ? (float) $metadata['bitPositionInSource'] : 0.0,
        ];
    }

    /**
     * Every learner's figures in one context, as Tarsus last stored them.
     *
     * @param string[] $userids Pseudonymised user ids.
     * @return array<string, array<string, float>> User id => figures.
     */
    public function getContextStatistics(string $contextid, array $userids): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('strval', $userids), 'strlen'))), 200) as $chunk) {
            $parameters = [];
            foreach ($chunk as $userid) {
                $parameters[] = ['context_id' => $contextid, 'context_type' => 'tarsus_activity', 'state' => 'learn', 'user_id' => $userid];
            }
            $body = $this->send('POST', '/api/t0/activity/retrieve/context/statistics', [
                'brain_id' => $this->brain(),
                'statistics_parameters' => (string) json_encode($parameters),
            ]);
            foreach ((array) ($body['activity']['statistics'] ?? []) as $entry) {
                if (!is_array($entry) || self::str($entry['userId'] ?? '') === '') {
                    continue;
                }
                $result[self::str($entry['userId'])] = self::extractProgress($entry, []);
            }
        }
        return $result;
    }

    /**
     * How a learner's experience points and learning units grew: points
     * {x: Unix seconds, y: percent}, oldest first.
     *
     * @return array{xp: array<int, array{x: int, y: float}>, units: array<int, array{x: int, y: float}>}
     */
    public function getUserAnalytics(string $contextid, string $userid, int $start, int $end): array
    {
        $body = $this->send('POST', '/api/t0/activity/retrieve/user/analytics', [
            'brain_id' => $this->brain(),
            'context_id' => $contextid,
            'context_type' => 'tarsus_activity',
            'user_id' => $userid,
            'start_timestamp' => (string) max(0, $start),
            'end_timestamp' => (string) max($start, $end),
        ]);
        $analytics = (array) ($body['activity']['analytics'] ?? []);
        $points = static function ($series): array {
            $out = [];
            foreach ((array) $series as $point) {
                if (is_array($point) && is_numeric($point['x'] ?? null) && is_numeric($point['y'] ?? null)) {
                    $out[] = ['x' => (int) $point['x'], 'y' => round(max(0.0, min(100.0, (float) $point['y'])), 2)];
                }
            }
            return $out;
        };
        return ['xp' => $points($analytics['experiencePoints'] ?? []), 'units' => $points($analytics['learningUnits'] ?? [])];
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
            if ($e->httpstatus === 500 && $e->says('UNABLE TO CREATE WIDGETS')) {
                return ['widgets' => [], 'progress' => self::extractProgress([], [])];
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
            $columns = self::tableColumns((array) ($data['questionTable'] ?? []));
            $widgets[] = [
                'id' => self::str($candidate['_id'] ?? ''),
                'type' => $type,
                'question' => self::str($data['question'] ?? ($bit['text'] ?? '')),
                'choices' => $choices,
                'variables' => self::variablesOf($data),
                'table' => self::questionTable($data, $columns),
                'columns' => $columns,
                'taxonomylevel' => self::str($data['taxonomyLevel'] ?? ''),
                'difficultylevel' => (int) ($data['difficultyLevel'] ?? 0),
            ];
            $widgetdata = $data;
        }

        return [
            'widgets' => $widgets,
            'progress' => self::extractProgress((array) ($body['activity'] ?? []), $widgetdata),
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

        $replay = false;
        try {
            $body = $this->send('POST', '/api/t0/activity/generate/next/widgets', $fields);
        } catch (TransportException $e) {
            if ($e->httpstatus === 500 && $e->says('UNABLE TO CREATE WIDGETS')) {
                return ['items' => [], 'progress' => self::extractProgress([], []), 'replay' => false];
            }
            throw $e;
        } catch (ReplayException $e) {
            // HTTP 409: Tarsus sends a widget again - the course-complete
            // card once the path is finished, or the learn question still
            // waiting for an answer. Shown like any other; nothing charged.
            $replay = true;
            $body = ['activity' => ['widgets' => [$e->widget]]];
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
            // A widget sent again (409) carries no figures.
            'progress' => $replay ? [] : self::extractProgress((array) ($body['activity'] ?? []), []),
            'replay' => $replay,
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
        $columns = self::tableColumns((array) ($data['questionTable'] ?? []));

        return [
            'kind' => 'practice',
            'id' => self::str($widget['_id'] ?? ''),
            'type' => $type,
            'question' => self::str($data['question'] ?? ($bit['text'] ?? '')),
            'choices' => $choices,
            'variables' => self::variablesOf($data),
            'table' => self::questionTable($data, $columns),
            'columns' => $columns,
            'taxonomylevel' => self::str($data['taxonomyLevel'] ?? ''),
            'difficultylevel' => (int) ($data['difficultyLevel'] ?? 0),
            'answered' => $answered,
            'score' => $answered ? $score : null,
            // Tarsus marks out of 100; only full marks count as right.
            'correct' => $answered ? ($score >= 100) : null,
            'feedback' => $answered ? self::str($evaluation['feedback'] ?? '') : '',
            'useranswer' => $answered ? self::choiceText($evaluation['userAnswer'] ?? '') : '',
            'useranswers' => $answered ? self::variableAnswers($evaluation['userVariables'] ?? []) : [],
            'answer' => $answered ? self::choiceText($evaluation['answer'] ?? ($data['answer'] ?? '')) : '',
            'answers' => $answered ? self::variableVerdicts($data) : [],
            'usertable' => $answered ? self::tableValues($evaluation['userTable'] ?? [], $columns) : [],
            'answertable' => $answered ? self::tableVerdicts($data, $columns) : [],
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
                if (is_array($slide) && ($shaped = self::slideOf($slide)) !== null) {
                    $slides[] = $shaped;
                }
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
        $page = self::pageOf($bit);
        if ($page !== '') {
            // A page outside a stack shows as a one-page unit.
            return self::pageStack($widget, $bit, $data, $page);
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
        if (in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
            // A learn question on its own (Tarsus sends it again while it
            // waits for an answer): a one-card unit.
            $slide = self::slideOf($widget);
            return [
                'kind' => 'stack',
                'id' => self::str($widget['stackId'] ?? ($widget['_id'] ?? '')),
                'slides' => $slide === null ? [] : [$slide],
            ];
        }

        return null;
    }

    /**
     * One card of a learning unit, by its widget type: a question, an
     * AI-made page, or a card with text, picture (a diagram, a picture
     * from the material, a GIF) and narration.
     *
     * @param array<string, mixed> $slide
     * @return array<string, mixed>|null
     */
    private static function slideOf(array $slide): ?array
    {
        $bit = (array) ($slide['bit'] ?? []);
        $data = (array) ($bit['data'] ?? []);
        $type = (string) ($bit['type'] ?? '');
        $id = self::str($slide['_id'] ?? '');
        $level = self::str($data['taxonomyLevel'] ?? '');
        $title = self::str($data['title'] ?? ($bit['title'] ?? ''));

        if (in_array($type, self::QUESTION_WIDGET_TYPES, true)) {
            // The unit's check question. Answering it with state 'learn'
            // earns learning units.
            return [
                'id' => $id,
                'kind' => 'question',
                'title' => '',
                'text' => '',
                'image' => '',
                'imageposition' => 'right',
                'audio' => '',
                'question' => self::questionItem($slide, $type, $data),
            ];
        }
        $page = self::pageOf($bit);
        if ($page !== '') {
            return [
                'id' => $id,
                'kind' => 'html',
                'taxonomylevel' => $level,
                'title' => $title,
                'text' => '',
                'html' => $page,
                'image' => '',
                'imageposition' => 'right',
                'audio' => '',
            ];
        }
        $text = self::str($data['text'] ?? ($bit['text'] ?? ''));
        [$image, $position] = self::imageOf($data);
        $audio = Media::audio(self::str($data['audioBytes'] ?? ''), self::str($data['mimeType'] ?? ''));
        if ($text === '' && $title === '' && $image === '' && $audio === '') {
            return null;
        }
        return [
            'id' => $id,
            'kind' => 'text',
            'taxonomylevel' => $level,
            'title' => $title,
            'text' => $text,
            'image' => $image,
            'imageposition' => $position,
            'audio' => $audio,
        ];
    }

    /**
     * The picture of a card and where it goes: Tarsus sends it in
     * data.image.imageBytes (a data URI, or a link for a GIF; empty when
     * no GIF was found) and its place in data.image.imagePosition: "top"
     * above the text, "left" or "right" beside it.
     *
     * @param array<string, mixed> $data
     * @return array{0: string, 1: string}
     */
    private static function imageOf(array $data): array
    {
        $image = $data['image'] ?? null;
        if (!is_array($image)) {
            return ['', 'right'];
        }
        $position = strtolower(trim(self::str($image['imagePosition'] ?? ($data['imagePosition'] ?? ''))));
        return [
            Media::image(self::str($image['imageBytes'] ?? '')),
            in_array($position, ['top', 'left', 'right'], true) ? $position : 'right',
        ];
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
                'audio' => '',
            ]],
        ];
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
            // A spreadsheet calculation (and an accounting task, which comes
            // as one) is answered as the whole table: the given cells echoed,
            // the '?' cells filled, under the column names the question came
            // with - Tarsus names them per language and per task.
            $columns = array_values(array_map('strval', (array) ($params['columns'] ?? [])));
            $rows = [];
            foreach ((array) $params['usertable'] as $row) {
                $cells = array_values(array_map([self::class, 'str'], (array) $row));
                $object = [];
                foreach ($columns as $i => $column) {
                    $object[$column] = $cells[$i] ?? '';
                }
                $rows[] = (object) $object;
            }
            $fields['user_answers'] = json_encode($rows);
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
            // Tarsus marks out of 100; only full marks count as right.
            $score = (float) ($evaluation['score'] ?? 0);
            $correct = $score >= 100;
            $feedback = self::str($evaluation['feedback'] ?? $feedback);
            $answer = self::str($evaluation['answer'] ?? $answer);
        }
        $columns = self::tableColumns((array) ($data['questionTable'] ?? ($evaluation['questionTable'] ?? [])));

        return [
            'correct' => $correct,
            'score' => $score,
            'feedback' => $feedback,
            'answer' => self::choiceText($answer),
            'choices' => $choices,
            'answers' => self::variableVerdicts($data),
            'usertable' => self::tableValues($evaluation['userTable'] ?? [], $columns),
            'answertable' => self::tableVerdicts($data, $columns),
            'progress' => self::extractProgress((array) ($body['activity'] ?? []), $data),
        ];
    }

    /**
     * The column names of a task table, in Tarsus's order. Tarsus names
     * them per language and per task (a costing task: position, rate,
     * amount; an accounting task: its own columns); the hidden
     * clarification of the model rows is not a column.
     *
     * @param array<int|string, mixed> $rows
     * @return string[]
     */
    private static function tableColumns(array $rows): array
    {
        foreach ($rows as $row) {
            if (is_array($row)) {
                return array_values(array_map('strval', array_filter(
                    array_keys($row),
                    static fn($key): bool => strcasecmp((string) $key, 'clarification') !== 0
                )));
            }
        }
        return [];
    }

    /**
     * The question table of a task: rows of cells under the columns, a
     * '?' cell being one the learner fills in.
     *
     * @param array<string, mixed> $data
     * @param string[] $columns
     * @return array<int, array<int, array{value: string, ask: bool}>>
     */
    private static function questionTable(array $data, array $columns): array
    {
        $rows = [];
        foreach (self::tableValues($data['questionTable'] ?? [], $columns) as $values) {
            $rows[] = array_map(static fn(string $value): array => ['value' => $value, 'ask' => trim($value) === '?'], $values);
        }
        return $rows;
    }

    /**
     * Table rows as lists of cell values under the given columns.
     *
     * @param mixed $entries
     * @param string[] $columns
     * @return array<int, string[]>
     */
    private static function tableValues($entries, array $columns): array
    {
        $rows = [];
        foreach ((array) $entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $values = [];
            foreach ($columns as $column) {
                $values[] = trim(self::str($entry[$column] ?? ''));
            }
            $rows[] = $values;
        }
        return $rows;
    }

    /**
     * The model table with a verdict per cell: evaluationMask has one row
     * per table row, 1 for a right cell, -1 for a wrong one, 0 for a given
     * one.
     *
     * @param array<string, mixed> $data
     * @param string[] $columns
     * @return array<int, array<int, array{value: string, verdict: int}>>
     */
    private static function tableVerdicts(array $data, array $columns): array
    {
        $evaluation = (array) ($data['evaluation'] ?? []);
        $mask = array_values(array_filter((array) ($evaluation['evaluationMask'] ?? []), 'is_array'));
        $rows = [];
        foreach (self::tableValues($evaluation['answerTable'] ?? ($data['answerTable'] ?? []), $columns) as $i => $values) {
            $cells = [];
            foreach ($values as $j => $value) {
                $verdict = $mask[$i][$columns[$j]] ?? 0;
                $cells[] = ['value' => $value, 'verdict' => max(-1, min(1, (int) $verdict))];
            }
            $rows[] = $cells;
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

        if ($httpcode === 409) {
            // Build knowledge sends a widget again instead of a new one.
            $decoded = json_decode((string) $response, true);
            $widget = is_array($decoded) ? ($decoded['replay']['widget'] ?? null) : null;
            if (is_array($widget)) {
                throw new ReplayException($widget);
            }
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
