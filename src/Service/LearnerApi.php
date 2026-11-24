<?php

namespace TarsusIlias\Service;

use TarsusIlias\Activity\Conversation;
use TarsusIlias\Activity\Didactics;
use TarsusIlias\Activity\Scope;
use TarsusIlias\Activity\Scoring;
use TarsusIlias\Activity\Settings;
use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Client\ConfigurationException;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Guardrail\Pipeline;
use TarsusIlias\Material\ResourceId;
use TarsusIlias\Output\Markdown;
use TarsusIlias\Output\Media;
use TarsusIlias\Output\Shaper;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Plan;
use TarsusIlias\Support\Pseudonymizer;
use TarsusIlias\Support\UsageMeter;
use TarsusIlias\Support\Voices;

/**
 * The learner's side of a Tarsus object: the interleaved feed and the four
 * modes (Ask a question - typed or spoken -, Test knowledge, Build
 * knowledge, Live interaction), the sources of an answer and the audio
 * explainer.
 *
 * Every call checks the package and the mode switch, then the scope - an
 * empty scope never reaches the brain - then goes through the guardrails
 * pipeline and records the brain's figures for the learning progress. What
 * a call costs is left to Tarsus (the brain's usage); the local ledger only
 * counts requests. Results are plain arrays with a 'status' (success,
 * noscope, disabled, blocked, error) and a user-facing 'error'.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class LearnerApi
{
    /** Widgets one feed call reads back (the page size Tarsus recommends). */
    public const FEED_PAGE_SIZE = 10;

    /** Longest question accepted, in characters. */
    public const MAX_QUERY = 4000;

    /** Characters an id from Tarsus (widget, stack) consists of. */
    private const ID_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';

    private int $objid;
    private string $title;
    private string $description;
    private Settings $settings;
    private int $containerref;
    private ClientInterface $client;
    private Pipeline $pipeline;
    private UsageMeter $usage;

    public function __construct(int $objid, string $title, string $description, Settings $settings, int $containerref)
    {
        $this->objid = $objid;
        $this->title = $title;
        $this->description = $description;
        $this->settings = $settings;
        $this->containerref = $containerref;
        $this->client = ClientFactory::getClient();
        $this->pipeline = new Pipeline();
        $this->usage = new UsageMeter();
    }

    private function userId(): int
    {
        return (int) Ilias::user()->getId();
    }

    /**
     * @return string[]
     */
    private function scope(): array
    {
        return Scope::allowedResourceIds($this->objid, $this->containerref);
    }

    /**
     * @param string[] $allowed
     * @return array<string, mixed>
     */
    private function params(array $allowed): array
    {
        return Conversation::params($this->objid, $this->title, $this->description, $this->settings, $allowed);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function result(string $status, string $error = '', array $extra = []): array
    {
        return $extra + ['status' => $status, 'error' => $error];
    }

    /**
     * Whether a value is an id as Tarsus issues them (widget, stack).
     */
    public static function isId(string $value): bool
    {
        $length = strlen($value);
        return $length > 0 && $length <= 100 && strspn($value, self::ID_CHARACTERS) === $length;
    }

    /**
     * Run a guarded brain call and map its failures to results.
     *
     * @param callable(): array<string, mixed> $call
     * @param bool $charged Whether the call may cost credits (refused once the month's limit is reached).
     * @param callable(array<string, mixed>): array<string, mixed> $then
     * @param string $context For the message and the log.
     * @return array<string, mixed>
     */
    private function guarded(callable $call, bool $charged, callable $then, string $context): array
    {
        try {
            $response = $this->pipeline->execute($call, $charged);
        } catch (BlockedException $e) {
            return self::result('blocked', Ilias::txt('guardrail_blocked_' . $e->reason));
        } catch (TransportException $e) {
            return self::result('error', Messages::forTransport($e, $context));
        } catch (ConfigurationException $e) {
            return self::result('error', Ilias::txt($e->langKey));
        }
        return $then(is_array($response) ? $response : []);
    }

    // ------------------------------------------------------------------
    // Feed
    // ------------------------------------------------------------------

    /**
     * One page of the activity feed and the experience figures. The newest
     * page comes first; older pages follow at multiples of the page size
     * as the learner scrolls up.
     *
     * @return array<string, mixed>
     */
    public function feed(int $offset): array
    {
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'), ['items' => [], 'hasmore' => false]);
        }
        $params = $this->params($allowed) + ['offset' => max(0, $offset), 'pagesize' => self::FEED_PAGE_SIZE];

        return $this->guarded(
            fn() => $this->client->retrieveFeed($params),
            false,
            function (array $response): array {
                Scoring::record($this->objid, $this->userId(), (array) ($response['progress'] ?? []), false);
                return self::result('success', '', self::feedPage($response) + [
                    'progress' => Shaper::progress((array) ($response['progress'] ?? [])),
                ]);
            },
            'feed'
        );
    }

    /**
     * The shaped items of one feed page and whether an older page may follow.
     *
     * @param array<string, mixed> $response
     * @return array{items: array<int, array<string, mixed>>, hasmore: bool}
     */
    public static function feedPage(array $response): array
    {
        $items = [];
        foreach ((array) ($response['items'] ?? []) as $item) {
            if (is_array($item) && ($shaped = Shaper::feedItem($item)) !== null) {
                $items[] = $shaped;
            }
        }
        // A full page means an older one may follow.
        return ['items' => $items, 'hasmore' => (int) ($response['rawcount'] ?? 0) >= self::FEED_PAGE_SIZE];
    }

    // ------------------------------------------------------------------
    // Ask a question
    // ------------------------------------------------------------------

    /**
     * Answer a typed question. $replywidget / $replystack anchor it on a
     * card or answer, or on a whole learning unit ("ask about this").
     *
     * @return array<string, mixed>
     */
    public function ask(string $query, string $replywidget = '', string $replystack = ''): array
    {
        $query = trim($query);
        if ($query === '') {
            return self::result('error', Ilias::txt('empty_query'));
        }
        if (mb_strlen($query) > self::MAX_QUERY) {
            $query = mb_substr($query, 0, self::MAX_QUERY);
        }
        return $this->answer(['query' => $query], $replywidget, $replystack, 'ask');
    }

    /**
     * Answer a spoken question: the recording goes to Tarsus, which turns
     * it into text and answers it. The text comes back as the question.
     *
     * @return array<string, mixed>
     */
    public function askVoice(string $file, string $mimetype, string $filename, string $replywidget = '', string $replystack = ''): array
    {
        if (!$this->settings->voiceInputActive()) {
            return self::result('disabled', Ilias::txt('voice_unavailable'));
        }
        if ($file === '' || !is_file($file) || filesize($file) === 0) {
            return self::result('error', Ilias::txt('err_audio_format'));
        }
        return $this->answer([
            'audiofile' => $file,
            'audiomimetype' => $mimetype !== '' ? $mimetype : 'application/octet-stream',
            'audioname' => $filename !== '' ? $filename : 'question.webm',
        ], $replywidget, $replystack, 'voice');
    }

    /**
     * @param array<string, mixed> $question query, or the recording
     * @return array<string, mixed>
     */
    private function answer(array $question, string $replywidget, string $replystack, string $context): array
    {
        if (!$this->settings->askenabled || !Plan::allowsMode('ask')) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'));
        }
        // The answer may only be grounded in what the teacher included -
        // and of that, only what the brain holds right now.
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'));
        }

        $params = $this->params($allowed) + $question;
        $replystack = trim($replystack);
        $replywidget = trim($replywidget);
        if ($replystack !== '' && self::isId($replystack)) {
            $params['replytostack'] = $replystack;
        } elseif ($replywidget !== '' && self::isId($replywidget)) {
            $params['replytowidget'] = $replywidget;
        }

        return $this->guarded(
            fn() => $this->client->generateWidgets($params),
            true,
            function (array $response) use ($context): array {
                $this->usage->record($this->containerref, $this->objid, $this->userId(), $context === 'voice' ? 'chat_voice' : 'chat_ask');
                Scoring::touch($this->objid, $this->userId());
                $messages = [];
                foreach ((array) ($response['messages'] ?? []) as $message) {
                    if (is_array($message) && trim((string) ($message['text'] ?? '')) !== '') {
                        $messages[] = [
                            'title' => (string) ($message['title'] ?? ''),
                            'text' => Markdown::render((string) $message['text']),
                        ];
                    }
                }
                $widgetid = (string) ($response['widgetid'] ?? '');
                return self::result('success', '', [
                    'answer' => Markdown::render((string) ($response['answer'] ?? '')),
                    // An interactive page (ai-driven interaction), shown in
                    // a sandboxed frame.
                    'html' => (string) ($response['html'] ?? ''),
                    // The question as Tarsus understood it: for a spoken
                    // question the text it made of the recording.
                    'question' => (string) ($response['question'] ?? ''),
                    'widgetid' => $widgetid,
                    'sources' => $this->settings->referencesenabled && !empty($response['sources']) && $widgetid !== '',
                    'messages' => $messages,
                ]);
            },
            $context
        );
    }

    // ------------------------------------------------------------------
    // Sources of an answer
    // ------------------------------------------------------------------

    /**
     * The sources of one answer: every bit Tarsus took it from, whatever
     * its type, with a link to the material in ILIAS where the learner may
     * open it.
     *
     * @return array<string, mixed>
     */
    public function references(string $widgetid, string $state): array
    {
        $widgetid = trim($widgetid);
        if (!self::isId($widgetid)) {
            return self::result('error', Ilias::txt('invalid_request'));
        }
        if (!$this->settings->referencesenabled) {
            return self::result('disabled', Ilias::txt('references_disabled'));
        }
        $state = in_array($state, ['ask', 'discuss', 'learn', 'practice'], true) ? $state : 'ask';
        $params = [
            'contextid' => Scope::conversationContextId($this->objid),
            'userid' => Pseudonymizer::pseudonymize($this->userId()),
            'state' => $state,
            'widgetid' => $widgetid,
        ];

        return $this->guarded(
            fn() => ['bits' => $this->client->getReferences($params)],
            false,
            function (array $response): array {
                $bits = [];
                foreach ((array) ($response['bits'] ?? []) as $bit) {
                    if (is_array($bit)) {
                        $bits[] = $this->shapeReference($bit);
                    }
                }
                return self::result('success', '', ['bits' => $bits]);
            },
            'references'
        );
    }

    /**
     * @param array<string, mixed> $bit
     * @return array<string, mixed>
     */
    private function shapeReference(array $bit): array
    {
        $rows = [];
        foreach ((array) ($bit['rows'] ?? []) as $row) {
            $rows[] = array_values(array_map('strval', (array) $row));
        }
        $link = '';
        $parsed = ResourceId::parse((string) ($bit['sourceid'] ?? ''));
        if ($parsed !== null && $parsed['refid'] > 0 && Ilias::refAlive($parsed['refid'])
            && Ilias::access()->checkAccess('read', '', $parsed['refid'])) {
            try {
                $link = \ilLink::_getLink($parsed['refid'], $parsed['type']);
            } catch (\Throwable $e) {
                $link = '';
            }
        }
        return [
            'type' => (string) ($bit['type'] ?? 'text'),
            'title' => (string) ($bit['title'] ?? ''),
            'subtitle' => (string) ($bit['subtitle'] ?? ''),
            'text' => Markdown::render((string) ($bit['text'] ?? '')),
            'rows' => $rows,
            'image' => Media::image((string) ($bit['image'] ?? '')),
            'link' => $link,
        ];
    }

    // ------------------------------------------------------------------
    // Test knowledge
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function practiceNext(): array
    {
        if (!$this->settings->testenabled || !Plan::allowsMode('test')) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'));
        }
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'));
        }
        $params = $this->params($allowed);

        return $this->guarded(
            fn() => $this->client->generatePracticeWidget($params),
            true,
            function (array $response): array {
                $widgets = array_values(array_filter(
                    (array) ($response['widgets'] ?? []),
                    static fn($widget): bool => is_array($widget) && (string) ($widget['id'] ?? '') !== ''
                ));
                if ($widgets === []) {
                    return self::result('error', Ilias::txt('practice_nowidget'));
                }
                Scoring::record($this->objid, $this->userId(), (array) ($response['progress'] ?? []));
                $this->usage->record($this->containerref, $this->objid, $this->userId(), 'practice_next');
                return self::result('success', '', [
                    'widgets' => array_map([Shaper::class, 'widget'], $widgets),
                    'progress' => Shaper::progress((array) ($response['progress'] ?? [])),
                ]);
            },
            'practice'
        );
    }

    /**
     * Evaluate one answer. The payload carries widgetid, widgettype, state
     * ('practice' or 'learn'), and the answer: 'answer' (single-answer
     * types), 'answers' (rule of three) or 'table' + 'columns' (a task
     * table: rows of cell values under the columns the question came with).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function practiceCheck(array $payload): array
    {
        $widgetid = trim((string) ($payload['widgetid'] ?? ''));
        $widgettype = (string) ($payload['widgettype'] ?? '');
        $state = (string) ($payload['state'] ?? 'practice');
        if (!in_array($state, ['practice', 'learn'], true)) {
            $state = 'practice';
        }
        if (!self::isId($widgetid) || !Shaper::isSupportedType($widgettype)) {
            return self::result('error', Ilias::txt('invalid_request'));
        }

        $answer = trim((string) ($payload['answer'] ?? ''));
        $useranswers = [];
        $usertable = [];
        $columns = array_values(array_map(static fn($c): string => trim((string) $c), (array) ($payload['columns'] ?? [])));
        if ($widgettype === Didactics::TYPE_CROSSMULTIPLICATION) {
            foreach ((array) ($payload['answers'] ?? []) as $entry) {
                $variable = trim((string) ($entry['variable'] ?? ''));
                $value = trim((string) ($entry['answer'] ?? ''));
                if ($variable === '' || $value === '') {
                    return self::result('error', Ilias::txt('empty_answer'));
                }
                $useranswers[] = ['variable' => $variable, 'answer' => $value];
            }
            if ($useranswers === []) {
                return self::result('error', Ilias::txt('empty_answer'));
            }
        } elseif ($widgettype === Didactics::TYPE_TRADECOSTING) {
            // The whole table travels; no asked cell may stay unanswered.
            if ($columns === []) {
                return self::result('error', Ilias::txt('invalid_request'));
            }
            foreach ((array) ($payload['table'] ?? []) as $row) {
                $cells = array_values(array_map(static fn($cell): string => trim((string) $cell), (array) $row));
                if (count($cells) !== count($columns) || in_array('?', $cells, true)) {
                    return self::result('error', Ilias::txt('empty_answer'));
                }
                $usertable[] = $cells;
            }
            if ($usertable === []) {
                return self::result('error', Ilias::txt('empty_answer'));
            }
        } elseif ($answer === '') {
            return self::result('error', Ilias::txt('empty_answer'));
        }

        // A learning unit's check question belongs to Build knowledge.
        $mode = $state === 'learn' ? 'build' : 'test';
        if (!$this->settings->modeEnabled($mode) || !Plan::allowsMode($mode)) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'));
        }
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'));
        }

        $params = $this->params($allowed) + [
            'widgetid' => $widgetid,
            'widgettype' => $widgettype,
            'useranswer' => mb_substr($answer, 0, self::MAX_QUERY),
            'useranswers' => $useranswers,
            'usertable' => $usertable,
            'columns' => $columns,
            'state' => $state,
        ];

        return $this->guarded(
            fn() => $this->client->evaluatePracticeAnswer($params),
            true,
            function (array $response) use ($widgettype, $state): array {
                Scoring::record($this->objid, $this->userId(), (array) ($response['progress'] ?? []));
                $this->usage->record($this->containerref, $this->objid, $this->userId(), $state === 'learn' ? 'learn_check' : 'practice_eval');

                $choices = [];
                foreach ((array) ($response['choices'] ?? []) as $choice) {
                    if (!is_array($choice)) {
                        continue;
                    }
                    $choices[] = [
                        'value' => (string) ($choice['text'] ?? ''),
                        'label' => Shaper::choice((string) ($choice['text'] ?? ''), $widgettype),
                        'iscorrect' => !empty($choice['iscorrect']),
                        'clarification' => Markdown::render((string) ($choice['clarification'] ?? '')),
                    ];
                }

                return self::result('success', '', [
                    'hasverdict' => ($response['correct'] ?? null) !== null,
                    'correct' => (bool) ($response['correct'] ?? false),
                    'score' => ($response['score'] ?? null) === null ? -1 : (float) $response['score'],
                    'feedback' => Markdown::render((string) ($response['feedback'] ?? '')),
                    'answer' => Markdown::render(Shaper::choice((string) ($response['answer'] ?? ''), $widgettype)),
                    'choices' => $choices,
                    'answers' => Shaper::answers((array) ($response['answers'] ?? [])),
                    'usertable' => Shaper::values((array) ($response['usertable'] ?? [])),
                    'answertable' => Shaper::verdicts((array) ($response['answertable'] ?? [])),
                    'progress' => Shaper::progress((array) ($response['progress'] ?? [])),
                ]);
            },
            'check'
        );
    }

    // ------------------------------------------------------------------
    // Build knowledge
    // ------------------------------------------------------------------

    /**
     * The next learning step. When Tarsus sends a widget again instead
     * (HTTP 409: the path is finished, or a learn question still waits
     * for its answer), that widget is shown; nothing is locked here.
     *
     * @return array<string, mixed>
     */
    public function learnNext(): array
    {
        if (!$this->settings->buildenabled || !Plan::allowsMode('build')) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'), ['items' => []]);
        }
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'), ['items' => []]);
        }
        $params = $this->params($allowed);

        return $this->guarded(
            fn() => $this->client->generateLearnWidget($params),
            true,
            function (array $response): array {
                $items = array_values(array_filter((array) ($response['items'] ?? []), 'is_array'));
                if ($items === []) {
                    return self::result('error', Ilias::txt('learn_nowidget'), ['items' => []]);
                }
                $replay = !empty($response['replay']);
                if (!$replay) {
                    Scoring::record($this->objid, $this->userId(), (array) ($response['progress'] ?? []));
                    $this->usage->record($this->containerref, $this->objid, $this->userId(), 'learn_next');
                }
                return self::result('success', '', [
                    'items' => array_map([Shaper::class, 'learnItem'], $items),
                    'progress' => $replay ? null : Shaper::progress((array) ($response['progress'] ?? [])),
                    'replay' => $replay,
                ]);
            },
            'learn'
        );
    }

    /**
     * Have the brain read a learning unit aloud.
     *
     * @return array<string, mixed>
     */
    public function stackAudio(string $stackid): array
    {
        $stackid = trim($stackid);
        if (!self::isId($stackid)) {
            return self::result('error', Ilias::txt('invalid_request'));
        }
        if (!$this->settings->buildenabled || !Plan::allowsMode('build')) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'));
        }
        if (!Plan::allowsAudio()) {
            // The audio explainer is part of Build Knowledge and Pay as you grow.
            return self::result('disabled', Ilias::txt('plan_feature_unavailable'));
        }
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'));
        }
        $params = $this->params($allowed) + ['stackid' => $stackid];
        $params['voice'] = Voices::choose((string) $params['userlanguage']);

        return $this->guarded(
            fn() => $this->client->generateStackAudio($params),
            true,
            function (array $response): array {
                $audio = Media::audio((string) ($response['audio'] ?? ''), (string) ($response['mimetype'] ?? ''));
                if ($audio === '') {
                    return self::result('error', Ilias::txt('learn_audio_unavailable'));
                }
                $this->usage->record($this->containerref, $this->objid, $this->userId(), 'learn_audio');
                return self::result('success', '', [
                    'audio' => $audio,
                    'voice' => (string) ($response['voice'] ?? ''),
                    'transcript' => (string) ($response['text'] ?? ''),
                ]);
            },
            'audio'
        );
    }

    // ------------------------------------------------------------------
    // Live interaction
    // ------------------------------------------------------------------

    /**
     * Start a live interaction: Tarsus returns the page of the call, which
     * the browser shows in a frame that may use the microphone. The call
     * talks to Tarsus directly; what the coach answers is saved by Tarsus
     * and shows in the feed afterwards.
     *
     * @return array<string, mixed>
     */
    public function discuss(): array
    {
        if (!$this->settings->discussenabled || !Plan::allowsMode('discuss')) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'));
        }
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'));
        }
        $params = $this->params($allowed);
        $params['voice'] = Voices::choose((string) $params['userlanguage']);
        $logo = Config::robotAvatar();
        if (strncasecmp($logo, 'https://', 8) === 0 || strncasecmp($logo, 'data:image/', 11) === 0) {
            $params['robotlogo'] = $logo;
        }

        return $this->guarded(
            fn() => $this->client->startDiscuss($params),
            true,
            function (array $response): array {
                $page = (string) ($response['page'] ?? '');
                if ($page === '') {
                    return self::result('error', Ilias::txt('err_live_unavailable'));
                }
                $this->usage->record($this->containerref, $this->objid, $this->userId(), 'discuss_start');
                Scoring::touch($this->objid, $this->userId());
                return self::result('success', '', ['page' => $page]);
            },
            'discuss'
        );
    }

    // ------------------------------------------------------------------
    // Reset
    // ------------------------------------------------------------------

    /**
     * Erase the current user's conversation: the brain forgets transcript
     * and dialogue memory, and the local figures go too.
     *
     * @return array<string, mixed>
     */
    public function reset(): array
    {
        $contextid = Scope::conversationContextId($this->objid);
        $userid = Pseudonymizer::pseudonymize($this->userId());

        try {
            $this->pipeline->execute(function () use ($contextid, $userid): array {
                $this->client->resetConversation($contextid, $userid);
                return [];
            });
        } catch (BlockedException $e) {
            return self::result('blocked', Ilias::txt('guardrail_blocked_' . $e->reason));
        } catch (TransportException $e) {
            // Nothing stored for this learner yet: there is nothing to erase.
            if (!$e->says('CONTEXT DOES NOT EXIST')) {
                return self::result('error', Messages::forTransport($e, 'reset'));
            }
        } catch (ConfigurationException $e) {
            return self::result('error', Ilias::txt($e->langKey));
        }
        Scoring::clear($this->objid, $this->userId());
        return self::result('success');
    }
}
