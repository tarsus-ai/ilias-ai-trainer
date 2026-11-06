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
use TarsusIlias\Output\Markdown;
use TarsusIlias\Output\Shaper;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Plan;
use TarsusIlias\Support\UsageMeter;
use TarsusIlias\Support\Voices;

/**
 * The learner's side of a Tarsus object: the interleaved feed and the
 * three modes (Ask a question, Test knowledge, Build knowledge).
 *
 * Every call checks the mode switch and the scope first - an empty scope
 * never reaches the brain - then goes through the guardrails pipeline,
 * records the brain's figures for the learning progress and meters the
 * credits. Results are plain arrays with a 'status' (success, noscope,
 * disabled, blocked, error) and a user-facing 'error'.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class LearnerApi
{
    /** Widgets one feed call reads back (the page size Tarsus recommends). */
    public const FEED_PAGE_SIZE = 10;

    /** Longest question accepted, in characters. */
    public const MAX_QUERY = 4000;

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
     * Run a guarded brain call and map its failures to results.
     *
     * @param callable(): array<string, mixed> $call
     * @param callable(array<string, mixed>): array<string, mixed> $then
     * @return array<string, mixed>
     */
    private function guarded(callable $call, int $credits, callable $then): array
    {
        try {
            $response = $this->pipeline->execute($call, $credits);
        } catch (BlockedException $e) {
            return self::result('blocked', Ilias::txt('guardrail_blocked_' . $e->reason));
        } catch (TransportException $e) {
            Ilias::log($e->getMessage());
            return self::result('error', Ilias::txt('transport_error'));
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
            0,
            function (array $response): array {
                Scoring::record($this->objid, $this->userId(), (array) ($response['progress'] ?? []), false);
                $items = [];
                foreach ((array) ($response['items'] ?? []) as $item) {
                    if (is_array($item) && ($shaped = Shaper::feedItem($item)) !== null) {
                        $items[] = $shaped;
                    }
                }
                return self::result('success', '', [
                    'items' => $items,
                    // A full page means an older one may follow.
                    'hasmore' => (int) ($response['rawcount'] ?? 0) >= self::FEED_PAGE_SIZE,
                    'progress' => Shaper::progress((array) ($response['progress'] ?? [])),
                ]);
            }
        );
    }

    // ------------------------------------------------------------------
    // Ask a question
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function ask(string $query, string $replyto = ''): array
    {
        $query = trim($query);
        if ($query === '') {
            return self::result('error', Ilias::txt('empty_query'));
        }
        if (mb_strlen($query) > self::MAX_QUERY) {
            $query = mb_substr($query, 0, self::MAX_QUERY);
        }
        if (!$this->settings->askenabled || !Plan::allowsMode('ask')) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'));
        }
        // The answer may only be grounded in what the teacher included -
        // and of that, only what the brain holds right now.
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'));
        }

        $params = $this->params($allowed) + ['query' => $query];
        $replyto = trim($replyto);
        if ($replyto !== '' && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $replyto)) {
            // "Ask about this": the brain grounds the answer in that widget.
            $params['replytowidget'] = $replyto;
        }

        return $this->guarded(
            fn() => $this->client->generateWidgets($params),
            6,
            function (array $response): array {
                $this->usage->record($this->containerref, $this->objid, $this->userId(), 'chat_ask', (int) ($response['credits'] ?? 6));
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
                return self::result('success', '', [
                    'answer' => Markdown::render((string) ($response['answer'] ?? '')),
                    // An interactive page (ai-driven interaction), shown in
                    // a sandboxed frame.
                    'html' => (string) ($response['html'] ?? ''),
                    'messages' => $messages,
                ]);
            }
        );
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
            6,
            function (array $response): array {
                $widgets = array_values(array_filter(
                    (array) ($response['widgets'] ?? []),
                    static fn($widget): bool => is_array($widget) && (string) ($widget['id'] ?? '') !== ''
                ));
                if ($widgets === []) {
                    return self::result('error', Ilias::txt('practice_nowidget'));
                }
                Scoring::record($this->objid, $this->userId(), (array) ($response['progress'] ?? []));
                $this->usage->record($this->containerref, $this->objid, $this->userId(), 'practice_next', (int) ($response['credits'] ?? 6));
                return self::result('success', '', [
                    'widgets' => array_map([Shaper::class, 'widget'], $widgets),
                    'progress' => Shaper::progress((array) ($response['progress'] ?? [])),
                ]);
            }
        );
    }

    /**
     * Evaluate one answer. The payload carries widgetid, widgettype, state
     * ('practice' or 'learn'), and the answer: 'answer' (single-answer
     * types), 'answers' (rule of three) or 'table' + 'columns'
     * (spreadsheet calculation).
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
        if ($widgetid === '' || strlen($widgetid) > 128 || !Shaper::isSupportedType($widgettype)) {
            return self::result('error', Ilias::txt('invalid_request'));
        }

        $answer = trim((string) ($payload['answer'] ?? ''));
        $useranswers = [];
        $usertable = [];
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
            foreach ((array) ($payload['table'] ?? []) as $row) {
                $position = trim((string) ($row['position'] ?? ''));
                $rate = trim((string) ($row['rate'] ?? ''));
                $amount = trim((string) ($row['amount'] ?? ''));
                if ($position === '' || $rate === '?' || $amount === '?') {
                    return self::result('error', Ilias::txt('empty_answer'));
                }
                $usertable[] = ['position' => $position, 'rate' => $rate, 'amount' => $amount];
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

        $columns = array_values(array_map(static fn($c): string => trim((string) $c), (array) ($payload['columns'] ?? [])));
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
            6,
            function (array $response) use ($widgettype, $state): array {
                Scoring::record($this->objid, $this->userId(), (array) ($response['progress'] ?? []));
                $this->usage->record($this->containerref, $this->objid, $this->userId(),
                    $state === 'learn' ? 'learn_check' : 'practice_eval', (int) ($response['credits'] ?? 6));

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
                    'usertable' => Shaper::table((array) ($response['usertable'] ?? [])),
                    'answertable' => Shaper::table((array) ($response['answertable'] ?? [])),
                    'progress' => Shaper::progress((array) ($response['progress'] ?? [])),
                ]);
            }
        );
    }

    // ------------------------------------------------------------------
    // Build knowledge
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function learnNext(): array
    {
        if (!$this->settings->buildenabled || !Plan::allowsMode('build')) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'), ['items' => [], 'complete' => false]);
        }
        $allowed = $this->scope();
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'), ['items' => [], 'complete' => false]);
        }
        $params = $this->params($allowed);

        return $this->guarded(
            fn() => $this->client->generateLearnWidget($params),
            6,
            function (array $response): array {
                $items = array_values(array_filter((array) ($response['items'] ?? []), 'is_array'));
                if ($items === []) {
                    return self::result('error', Ilias::txt('learn_nowidget'), ['items' => [], 'complete' => false]);
                }
                Scoring::record($this->objid, $this->userId(), (array) ($response['progress'] ?? []));
                $this->usage->record($this->containerref, $this->objid, $this->userId(), 'learn_next', (int) ($response['credits'] ?? 6));
                // Only the brain's own farewell ends the course: the learn
                // progress may reach 100 while objectives are still open.
                $done = in_array('complete', array_column($items, 'kind'), true);
                return self::result('success', '', [
                    'items' => array_map([Shaper::class, 'learnItem'], $items),
                    'progress' => Shaper::progress((array) ($response['progress'] ?? [])),
                    'complete' => $done,
                ]);
            }
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
        if ($stackid === '' || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $stackid)) {
            return self::result('error', Ilias::txt('invalid_request'));
        }
        if (!$this->settings->buildenabled || !Plan::allowsMode('build')) {
            return self::result('disabled', Ilias::txt('chat_modedisabled'));
        }
        if (!Plan::allowsAudio()) {
            // Narration is part of Build Knowledge and Pay as you grow.
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
            6,
            function (array $response): array {
                if (($response['audio'] ?? '') === '') {
                    return self::result('error', Ilias::txt('learn_audio_unavailable'));
                }
                $this->usage->record($this->containerref, $this->objid, $this->userId(), 'learn_audio', (int) ($response['credits'] ?? 6));
                $mimetype = (string) ($response['mimetype'] ?? 'audio/wav');
                return self::result('success', '', [
                    'audio' => 'data:' . $mimetype . ';base64,' . preg_replace('/\s+/', '', (string) $response['audio']),
                    'mimetype' => $mimetype,
                    'voice' => (string) ($response['voice'] ?? ''),
                ]);
            }
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
        $userid = \TarsusIlias\Support\Pseudonymizer::pseudonymize($this->userId());

        return $this->guarded(
            function () use ($contextid, $userid): array {
                $this->client->resetConversation($contextid, $userid);
                return ['credits' => 0];
            },
            0,
            function (array $response): array {
                Scoring::clear($this->objid, $this->userId());
                return self::result('success');
            }
        );
    }
}
