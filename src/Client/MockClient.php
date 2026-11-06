<?php

namespace TarsusIlias\Client;

use TarsusIlias\Support\Config;

/**
 * Deterministic, stateful mock Tarsus client.
 *
 * Serves every call in mock mode, so the whole plugin - indexation state
 * machine, guardrails and the three learner modes - can be tried out
 * without an API key, network traffic or credits. Unlike a fixture-only
 * mock it remembers each learner's conversation per activity (in the
 * plugin's own settings table), so reloading the page reopens the history,
 * answers earn experience points, units add up to completion and the
 * learning progress moves, exactly as with the real brain.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
class MockClient implements ClientInterface
{
    /** Experience points the practice mode offers in total. */
    private const XP_TOTAL = 12.0;

    /** Learning units the build mode offers in total. */
    private const UNITS_TOTAL = 9.0;

    /** Learning stacks until the course is complete. */
    private const STACKS = 3;

    protected bool $async;
    protected int $simulatestatus;

    /**
     * @param bool $async          When true, track returns 'pending-confirmation'
     *                             (asynchronous indexing); otherwise 'indexed'.
     * @param int  $simulatestatus When non-zero, every call throws a
     *                             TransportException with this HTTP status.
     */
    public function __construct(bool $async = true, int $simulatestatus = 0)
    {
        $this->async = $async;
        $this->simulatestatus = $simulatestatus;
    }

    protected function maybeFail(): void
    {
        if ($this->simulatestatus !== 0) {
            throw new TransportException($this->simulatestatus, 'mock simulated failure');
        }
    }

    // ------------------------------------------------------------------
    // Material
    // ------------------------------------------------------------------

    public function trackResource(array $resource): array
    {
        $this->maybeFail();

        $resourceid = (string) ($resource['resource_id'] ?? 'mock-resource');

        return [
            'status' => $this->async ? 'pending-confirmation' : 'indexed',
            'bitref' => 'mock-bit-' . sha1($resourceid),
            'credits' => 0,
        ];
    }

    public function untrackResource(array $resourceids): array
    {
        $this->maybeFail();

        $result = [];
        foreach ($resourceids as $resourceid) {
            $result[(string) $resourceid] = 'removed';
        }
        return $result;
    }

    public function untrackAssets(array $assetids): array
    {
        $this->maybeFail();

        $result = [];
        foreach ($assetids as $assetid) {
            $result[(string) $assetid] = 'removed';
        }
        return $result;
    }

    public function getResourceStatus(string $resourceid): array
    {
        $this->maybeFail();

        return [
            'status' => 'indexed',
            'bitref' => 'mock-bit-' . sha1($resourceid),
        ];
    }

    public function checkTrackedResources(array $resourceids): array
    {
        $this->maybeFail();

        $result = [];
        foreach (array_unique(array_map('strval', $resourceids)) as $resourceid) {
            $result[$resourceid] = [
                'status' => 'indexed',
                'tracked' => true,
                'brainstatus' => 'done',
            ];
        }
        return $result;
    }

    public function listVoices(): array
    {
        $this->maybeFail();

        return [
            ['alias' => 'Sara', 'country' => 'de-DE', 'gender' => 'female'],
            ['alias' => 'Philip', 'country' => 'de-DE', 'gender' => 'male'],
            ['alias' => 'Abigail', 'country' => 'en-US', 'gender' => 'female'],
            ['alias' => 'Daniel', 'country' => 'en-US', 'gender' => 'male'],
            ['alias' => 'Maria', 'country' => 'fr-FR', 'gender' => 'female'],
        ];
    }

    public function listBrains(): array
    {
        $this->maybeFail();

        return [
            [
                'brainid' => 'mock-brain-default',
                'active' => true,
                'language' => 'de',
                'tag' => 'default',
                'timecreated' => 1726722755,
            ],
            [
                'brainid' => 'mock-brain-retired',
                'active' => false,
                'language' => 'en',
                'tag' => 'default',
                'timecreated' => 1700000000,
            ],
            [
                'brainid' => 'deleted-17cff6a1-4850-4d71-987a-97ceea0e0cba-mock-brain-old',
                'active' => false,
                'language' => 'de',
                'tag' => 'default',
                'timecreated' => 1690000000,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Conversation
    // ------------------------------------------------------------------

    public function generateWidgets(array $params): array
    {
        $this->maybeFail();
        $state = $this->load($params);

        $messages = [];
        if (empty($state['welcomed'])) {
            $state['welcomed'] = true;
            $welcome = [
                'title' => (string) ($params['contextname'] ?? ''),
                'text' => self::de($params)
                    ? "**Willkommen!** Ich bin " . (string) ($params['robotname'] ?? 'Tarsus') . ". Stelle mir Fragen zu den Kursmaterialien, teste dein Wissen oder baue es Schritt für Schritt auf.\n\n_(Demo-Modus: die Antworten sind simuliert.)_"
                    : "**Welcome!** I am " . (string) ($params['robotname'] ?? 'Tarsus') . ". Ask me about the course material, test your knowledge or build it up step by step.\n\n_(Demo mode: answers are simulated.)_",
            ];
            $messages[] = $welcome;
            $state['feed'][] = ['kind' => 'message', 'title' => $welcome['title'], 'text' => $welcome['text']];
        }

        $query = trim((string) ($params['query'] ?? ''));
        $scope = count((array) ($params['resourceids'] ?? []));
        $reply = trim((string) ($params['replytowidget'] ?? ''));
        if (self::de($params)) {
            $answer = "Du hast gefragt: *" . $query . "*\n\n"
                . "Im Demo-Modus antworte ich mit einer simulierten Antwort. Mit dem echten Brain wäre sie in den "
                . "**" . $scope . "** einbezogenen Material(ien) verankert"
                . ($reply !== '' ? ", bezogen auf das gewählte Element" : '') . ".\n\n"
                . "| Einstellung | Wert |\n|---|---|\n"
                . "| Gesprächsstil | " . (int) ($params['conversationstyle'] ?? 3) . " |\n"
                . "| DQR-Niveau | " . (int) ($params['dqr'] ?? 6) . " |\n\n"
                . 'Eine Formel zur Probe: $a^2 + b^2 = c^2$';
        } else {
            $answer = "You asked: *" . $query . "*\n\n"
                . "In demo mode I reply with a simulated answer. With the real brain it would be grounded in the "
                . "**" . $scope . "** included material(s)"
                . ($reply !== '' ? ", focused on the element you picked" : '') . ".\n\n"
                . "| Setting | Value |\n|---|---|\n"
                . "| Conversation style | " . (int) ($params['conversationstyle'] ?? 3) . " |\n"
                . "| DQR level | " . (int) ($params['dqr'] ?? 6) . " |\n\n"
                . 'A formula for good measure: $a^2 + b^2 = c^2$';
        }
        $html = '';
        if (($params['interactiontype'] ?? '') === 'ai-driven') {
            // AI-driven interaction: the answer is an interactive page.
            $turn = (int) ($state['pageturn'] ?? 0);
            $state['pageturn'] = $turn + 1;
            $html = self::page(['speed', 'discharge', 'cylinder'][$turn % 3], self::de($params));
        }
        $state['feed'][] = ['kind' => 'exchange', 'query' => $query, 'answer' => $html === '' ? $answer : '', 'html' => $html];
        $this->save($params, $state);

        return [
            'answer' => $html === '' ? $answer : '',
            'html' => $html,
            'messages' => $messages,
            'credits' => 6,
        ];
    }

    /**
     * One of the bundled interactive demo pages, in the user's language.
     */
    private static function page(string $name, bool $de): string
    {
        $file = dirname(__DIR__, 2) . '/templates/mock/' . $name . '.html';
        $html = is_file($file) ? (string) file_get_contents($file) : '';
        return str_replace('{{lang}}', $de ? 'de' : 'en', $html);
    }

    public function generatePracticeWidget(array $params): array
    {
        $this->maybeFail();
        $state = $this->load($params);

        $allowed = [];
        foreach ((array) ($params['questiontypes'] ?? []) as $types) {
            foreach ((array) $types as $type) {
                $allowed[(string) $type] = true;
            }
        }
        $bank = self::questionBank(self::de($params));
        $widgets = [];
        $guard = 0;
        while (count($widgets) < 2 && $guard < 20) {
            $guard++;
            $index = (int) ($state['qcursor'] ?? 0) % count($bank);
            $state['qcursor'] = $index + 1;
            $question = $bank[$index];
            if ($allowed !== [] && !isset($allowed[$question['type']])) {
                continue;
            }
            $id = 'mock-q-' . substr(sha1(uniqid('', true)), 0, 10);
            $state['questions'][$id] = $question + ['state' => 'practice'];
            $widget = self::publicQuestion($id, $question);
            $widgets[] = $widget;
            $state['feed'][] = $widget + ['kind' => 'practice', 'answered' => false, 'score' => null,
                'correct' => null, 'feedback' => '', 'useranswer' => '', 'useranswers' => [], 'answer' => '',
                'answers' => [], 'usertable' => [], 'answertable' => []];
        }
        $this->save($params, $state);

        return [
            'widgets' => $widgets,
            'progress' => self::progressOf($state),
            'credits' => $widgets === [] ? 0 : 6,
        ];
    }

    public function generateLearnWidget(array $params): array
    {
        $this->maybeFail();
        $state = $this->load($params);
        $de = self::de($params);

        $items = [];
        $step = (int) ($state['lstep'] ?? 0);
        if ($step === 0) {
            $items[] = [
                'kind' => 'objectives',
                'id' => 'mock-objectives',
                'title' => $de ? 'Deine Lernziele' : 'Your learning goals',
                'message' => $de ? 'Wir bauen dein Wissen in **drei** kurzen Einheiten auf.' : 'We build your knowledge in **three** short units.',
                'objectives' => [
                    ['id' => 'g1', 'objective' => $de ? 'Grundbegriffe benennen' : 'Name the basic terms', 'taxonomylevel' => 'WISSEN', 'done' => false],
                    ['id' => 'g2', 'objective' => $de ? 'Zusammenhänge erklären' : 'Explain how things relate', 'taxonomylevel' => 'VERSTEHEN', 'done' => false],
                    ['id' => 'g3', 'objective' => $de ? 'Das Gelernte anwenden' : 'Apply what you learned', 'taxonomylevel' => 'ANWENDEN', 'done' => false],
                ],
            ];
        } elseif ($step > self::STACKS) {
            $items[] = [
                'kind' => 'complete',
                'id' => 'mock-complete',
                'title' => '',
                'text' => $de ? 'Du hast alle Lernziele dieser Demo **erreicht**.' : 'You have **reached** every goal of this demo.',
            ];
        } else {
            if ($step > 1) {
                $items[] = [
                    'kind' => 'motivation',
                    'id' => 'mock-motivation-' . $step,
                    'title' => '',
                    'text' => $de ? 'Stark - weiter zum nächsten Ziel!' : 'Great - on to the next goal!',
                ];
            }
            $items[] = $this->mockStack($state, $step, $de, ($params['interactiontype'] ?? '') === 'ai-driven');
        }
        if ($step > self::STACKS) {
            $state['lcomplete'] = true;
        }
        $state['lstep'] = $step + 1;
        foreach ($items as $item) {
            $state['feed'][] = $item;
        }
        $this->save($params, $state);

        return [
            'items' => $items,
            'progress' => self::progressOf($state),
            'credits' => 6,
        ];
    }

    /**
     * One learning stack: two text slides (one illustrated) and a check question.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function mockStack(array &$state, int $step, bool $de, bool $interactive = false): array
    {
        $stackid = 'mock-stack-' . $step . '-' . substr(sha1(uniqid('', true)), 0, 6);
        $svg = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 150"><rect width="240" height="150" rx="14" fill="#efeafb"/>'
            . '<circle cx="70" cy="75" r="34" fill="#8f88ec"/><rect x="122" y="48" width="86" height="14" rx="7" fill="#b2aef2"/>'
            . '<rect x="122" y="74" width="62" height="14" rx="7" fill="#cfcbf6"/><rect x="122" y="100" width="74" height="14" rx="7" fill="#dcd8f8"/></svg>'
        );
        $qid = 'mock-lq-' . substr(sha1(uniqid('', true)), 0, 10);
        $question = [
            'type' => 'singleChoiceQuestionWidget',
            'question' => $de ? 'Welche Aussage fasst Einheit ' . $step . ' richtig zusammen?' : 'Which statement sums up unit ' . $step . ' correctly?',
            'choices' => $de ? ['Kleine Schritte festigen Wissen', 'Wiederholung ist überflüssig'] : ['Small steps consolidate knowledge', 'Repetition is pointless'],
            'correct' => $de ? 'Kleine Schritte festigen Wissen' : 'Small steps consolidate knowledge',
            'taxonomylevel' => 'VERSTEHEN',
            'difficultylevel' => 1,
        ];
        $state['questions'][$qid] = $question + ['state' => 'learn'];

        $pages = [];
        if ($interactive) {
            // AI-driven interaction: one slide is an interactive page.
            $pages[] = ['id' => $stackid . '-p', 'kind' => 'html', 'taxonomylevel' => 'ANWENDEN',
                'title' => $de ? 'Probiere es aus' : 'Try it yourself', 'text' => '', 'image' => '', 'imageposition' => 'right',
                'html' => self::page(['cylinder', 'speed', 'discharge'][$step % 3], $de)];
        }

        return [
            'kind' => 'stack',
            'id' => $stackid,
            'slides' => array_merge([
                ['id' => $stackid . '-1', 'kind' => 'text', 'taxonomylevel' => 'WISSEN',
                    'title' => $de ? 'Einheit ' . $step . ': Das Wichtigste' : 'Unit ' . $step . ': the essentials',
                    'text' => $de ? "Micro-Learning teilt Wissen in **kurze Einheiten**.\n\n- eine Idee pro Folie\n- direkt anwendbar"
                        : "Micro-learning splits knowledge into **short units**.\n\n- one idea per slide\n- immediately applicable",
                    'image' => '', 'imageposition' => 'right'],
                ['id' => $stackid . '-2', 'kind' => 'text', 'taxonomylevel' => 'VERSTEHEN',
                    'title' => $de ? 'Warum es funktioniert' : 'Why it works',
                    'text' => $de ? "Kurze Einheiten passen in den Alltag und werden öfter wiederholt - so bleibt mehr hängen."
                        : "Short units fit into everyday work and get repeated more often - so more of it sticks.",
                    'image' => $svg, 'imageposition' => $step % 2 === 0 ? 'right' : 'left'],
            ], $pages, [
                ['id' => $qid, 'kind' => 'question', 'title' => '', 'text' => '', 'image' => '', 'imageposition' => 'right',
                    'question' => self::publicQuestion($qid, $question) + ['kind' => 'practice', 'answered' => false,
                        'score' => null, 'correct' => null, 'feedback' => '', 'useranswer' => '', 'useranswers' => [],
                        'answer' => '', 'answers' => [], 'usertable' => [], 'answertable' => []]],
            ]),
        ];
    }

    public function evaluatePracticeAnswer(array $params): array
    {
        $this->maybeFail();
        $state = $this->load($params);
        $de = self::de($params);

        $id = (string) ($params['widgetid'] ?? '');
        $question = $state['questions'][$id] ?? null;
        if (!is_array($question)) {
            // Unknown (e.g. after a reset): judge against the generic answer.
            $question = ['type' => (string) ($params['widgettype'] ?? ''), 'correct' => 'Alpha', 'choices' => [],
                'state' => (string) ($params['state'] ?? 'practice')];
        }
        $type = (string) $question['type'];
        $learn = ($params['state'] ?? '') === 'learn';

        $result = [
            'correct' => false,
            'score' => 0.0,
            'feedback' => '',
            'answer' => '',
            'choices' => [],
            'answers' => [],
            'usertable' => [],
            'answertable' => [],
        ];
        $stored = [];

        if ($type === 'crossMultiplicationQuestionWidget') {
            $given = [];
            foreach ((array) ($params['useranswers'] ?? []) as $entry) {
                $given[(string) ($entry['variable'] ?? '')] = trim((string) ($entry['answer'] ?? ''));
            }
            $right = 0;
            $answers = [];
            foreach ((array) $question['solution'] as $variable => $value) {
                $ok = self::sameNumber($given[$variable] ?? '', (string) $value);
                $right += $ok ? 1 : 0;
                $answers[] = ['variable' => $variable, 'answer' => (string) $value, 'iscorrect' => $ok, 'clarification' => ''];
            }
            $score = round($right / max(1, count($answers)) * 100, 2);
            $result['answers'] = $answers;
            $result['score'] = $score;
            $result['correct'] = $score >= 50;
            $result['feedback'] = $de ? $question['explain_de'] : $question['explain_en'];
            $stored = ['useranswers' => array_map(static fn($v, $k) => ['variable' => $k, 'answer' => $v], $given, array_keys($given)),
                'answers' => $answers];
        } elseif ($type === 'tradeCostingQuestionWidget') {
            $rows = [];
            foreach ((array) ($params['usertable'] ?? []) as $row) {
                $rows[(string) ($row['position'] ?? '')] = $row;
            }
            $right = 0;
            $asked = 0;
            $answertable = [];
            foreach ((array) $question['solution'] as $model) {
                $user = $rows[$model['position']] ?? [];
                $rv = 0;
                $av = 0;
                if ($model['askrate']) {
                    $asked++;
                    $rv = self::sameNumber((string) ($user['rate'] ?? ''), $model['rate']) ? 1 : -1;
                    $right += $rv > 0 ? 1 : 0;
                }
                if ($model['askamount']) {
                    $asked++;
                    $av = self::sameNumber((string) ($user['amount'] ?? ''), $model['amount']) ? 1 : -1;
                    $right += $av > 0 ? 1 : 0;
                }
                $answertable[] = ['position' => $model['position'], 'rate' => $model['rate'], 'amount' => $model['amount'],
                    'rateverdict' => $rv, 'amountverdict' => $av];
            }
            $score = round($right / max(1, $asked) * 100, 2);
            $result['score'] = $score;
            $result['correct'] = $score >= 50;
            $result['usertable'] = array_values(array_map(static fn($r) => [
                'position' => (string) ($r['position'] ?? ''), 'rate' => (string) ($r['rate'] ?? ''), 'amount' => (string) ($r['amount'] ?? ''),
            ], $rows));
            $result['answertable'] = $answertable;
            $result['feedback'] = $de ? $question['explain_de'] : $question['explain_en'];
            $stored = ['usertable' => $result['usertable'], 'answertable' => $answertable];
        } elseif ($type === 'openQuestionWidget') {
            $given = trim((string) ($params['useranswer'] ?? ''));
            $score = 15.0;
            foreach ((array) $question['keywords'] as $keyword) {
                if (stripos($given, $keyword) !== false) {
                    $score += 35.0;
                }
            }
            $score = min(100.0, $score + min(15.0, mb_strlen($given) / 10));
            $result['score'] = round($score, 2);
            $result['correct'] = $score >= 50;
            $result['answer'] = $de ? $question['model_de'] : $question['model_en'];
            $result['feedback'] = ($score >= 50 ? ($de ? '**Gut erklärt.** ' : '**Well explained.** ') : ($de ? '**Fast.** ' : '**Nearly.** '))
                . ($de ? $question['model_de'] : $question['model_en']);
            $stored = ['useranswer' => $given];
        } else {
            $given = trim((string) ($params['useranswer'] ?? ''));
            $correct = strcasecmp($given, (string) $question['correct']) === 0;
            $result['correct'] = $correct;
            $result['score'] = $correct ? 100.0 : 0.0;
            $result['answer'] = (string) $question['correct'];
            foreach ((array) ($question['choices'] ?? []) as $choice) {
                $result['choices'][] = ['text' => (string) $choice, 'iscorrect' => strcasecmp((string) $choice, (string) $question['correct']) === 0,
                    'clarification' => ''];
            }
            $result['feedback'] = $correct
                ? ($de ? '**Richtig!** Gut erkannt.' : '**Correct!** Well spotted.')
                : ($de ? '**Nicht ganz.** Richtig wäre: ' : '**Not quite.** The right answer is: ') . self::label((string) $question['correct'], $de);
            $stored = ['useranswer' => $given];
        }

        // Earn points once per question.
        if (empty($question['answered'])) {
            $fraction = ((float) $result['score']) / 100;
            if ($learn) {
                $state['units'] = min(self::UNITS_TOTAL, (float) ($state['units'] ?? 0) + 3 * $fraction);
            } else {
                $state['xp'] = min(self::XP_TOTAL, (float) ($state['xp'] ?? 0) + 2 * $fraction);
            }
            $state['questions'][$id]['answered'] = true;
        }

        // Write the verdict into the stored feed so a reload shows it.
        $verdict = $stored + ['answered' => true, 'score' => $result['score'], 'correct' => $result['correct'],
            'feedback' => $result['feedback'], 'answer' => $result['answer']];
        foreach ($state['feed'] as &$item) {
            if (($item['kind'] ?? '') === 'practice' && ($item['id'] ?? '') === $id) {
                $item = array_merge($item, $verdict);
            }
            if (($item['kind'] ?? '') === 'stack') {
                foreach ($item['slides'] as &$slide) {
                    if (($slide['kind'] ?? '') === 'question' && ($slide['id'] ?? '') === $id) {
                        $slide['question'] = array_merge($slide['question'], $verdict);
                    }
                }
                unset($slide);
            }
        }
        unset($item);
        $this->save($params, $state);

        return $result + ['progress' => self::progressOf($state), 'credits' => 6];
    }

    public function retrieveFeed(array $params): array
    {
        $this->maybeFail();
        $state = $this->load($params);

        // Count widgets as the brain does: a stack weighs one per slide.
        $feed = $state['feed'];
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $pagesize = max(1, (int) ($params['pagesize'] ?? 10));
        $weights = array_map(static fn($item) => ($item['kind'] ?? '') === 'stack' ? max(1, count($item['slides'] ?? [])) : 1, $feed);
        $total = array_sum($weights);
        // Newest page first: skip $offset widgets from the newest end.
        $end = $total - $offset;
        $start = max(0, $end - $pagesize);
        $page = [];
        $position = 0;
        $rawcount = 0;
        foreach ($feed as $index => $item) {
            $position += $weights[$index];
            // Each item belongs to the page holding its last widget, so a
            // stack never shows up on two pages.
            if ($position <= $start || $position > $end) {
                continue;
            }
            $page[] = $item;
            $rawcount += $weights[$index];
        }

        return [
            'items' => $page,
            'rawcount' => $end > 0 ? min($pagesize, $rawcount) : 0,
            'progress' => self::progressOf($state),
            'credits' => 0,
        ];
    }

    public function getUsage(int $start, int $end): array
    {
        $this->maybeFail();
        // The demo brain charges what the local ledger counted, split over
        // the services the real usage report names.
        $db = \TarsusIlias\Support\Ilias::db();
        $res = $db->queryF(
            'SELECT op_name, SUM(credits) credits FROM ' . \TarsusIlias\Support\UsageMeter::TABLE . ' WHERE created >= %s AND created <= %s GROUP BY op_name',
            ['integer', 'integer'],
            [$start, $end]
        );
        $services = [
            'answeredQuery' => ['Answered Search', 'search', 0],
            'questionGeneration' => ['Question Generation', 'assets', 0],
            'answerEvaluation' => ['Answer Evaluation', 'assets', 0],
            'bitsGeneration' => ['Nugget Generation', 'assets', 0],
            'audioGeneration' => ['Audio Generation', 'assets', 0],
        ];
        while ($row = $db->fetchAssoc($res)) {
            $op = (string) $row['op_name'];
            $credits = (int) $row['credits'];
            $key = 'questionGeneration';
            if (strpos($op, 'ask') !== false) {
                $key = 'answeredQuery';
            } elseif (strpos($op, 'eval') !== false || strpos($op, 'check') !== false) {
                $key = 'answerEvaluation';
            } elseif (strpos($op, 'audio') !== false) {
                $key = 'audioGeneration';
            } elseif (strpos($op, 'learn') !== false) {
                $key = 'bitsGeneration';
            }
            $services[$key][2] += $credits;
        }
        $items = [];
        $total = 0;
        foreach ($services as $key => [$name, $service, $credits]) {
            $items[] = ['key' => $key, 'name' => $name, 'service' => $service, 'credits' => $credits];
            $total += $credits;
        }
        return ['total' => $total, 'items' => $items, 'credits' => 0];
    }

    public function resetConversation(string $contextid, string $userid): void
    {
        $this->maybeFail();
        Config::set(self::key($contextid, $userid), null);
    }

    public function generateStackAudio(array $params): array
    {
        $this->maybeFail();

        return [
            'audio' => self::tone(),
            'mimetype' => 'audio/wav',
            'voice' => (string) ($params['voice'] ?? 'Daniel'),
            'credits' => 6,
        ];
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    private static function key(string $contextid, string $userid): string
    {
        return 'mock_' . sha1($contextid . '|' . $userid);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function load(array $params): array
    {
        $raw = Config::fresh(self::key((string) ($params['contextid'] ?? ''), (string) ($params['userid'] ?? '')));
        $state = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = [];
        }
        $state += ['feed' => [], 'questions' => [], 'xp' => 0.0, 'units' => 0.0, 'lstep' => 0, 'qcursor' => 0];
        return $state;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $state
     */
    private function save(array $params, array $state): void
    {
        // Keep the stored history bounded.
        if (count($state['feed']) > 300) {
            $state['feed'] = array_slice($state['feed'], -300);
        }
        Config::set(
            self::key((string) ($params['contextid'] ?? ''), (string) ($params['userid'] ?? '')),
            Config::bmp((string) json_encode($state, JSON_UNESCAPED_UNICODE))
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, float>
     */
    private static function progressOf(array $state): array
    {
        $xp = round((float) ($state['xp'] ?? 0), 2);
        $units = round((float) ($state['units'] ?? 0), 2);
        $practice = round($xp / self::XP_TOTAL * 100, 2);
        $learn = !empty($state['lcomplete']) ? 100.0 : round($units / self::UNITS_TOTAL * 100, 2);
        return [
            'earnedxp' => $xp,
            'totalxp' => self::XP_TOTAL,
            'earnedunits' => $units,
            'totalunits' => self::UNITS_TOTAL,
            'practiceprogress' => $practice,
            'learnprogress' => $learn,
            'progress' => round(($practice + $learn) / 2, 2),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function de(array $params): bool
    {
        return ($params['userlanguage'] ?? 'de') === 'de';
    }

    private static function label(string $value, bool $de): string
    {
        if ($value === 'true') {
            return $de ? 'Wahr' : 'True';
        }
        if ($value === 'false') {
            return $de ? 'Falsch' : 'False';
        }
        return $value;
    }

    private static function sameNumber(string $given, string $expected): bool
    {
        $normalise = static function (string $value): ?float {
            $value = str_replace([' ', '€', '%', "\u{00a0}"], '', $value);
            if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
                $value = str_replace(['.', ','], ['', '.'], $value);
            } else {
                $value = str_replace(',', '.', $value);
            }
            return is_numeric($value) ? (float) $value : null;
        };
        $a = $normalise($given);
        $b = $normalise($expected);
        return $a !== null && $b !== null && abs($a - $b) < 0.01;
    }

    /**
     * The learner-facing shape of a bank question (no solution).
     *
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    private static function publicQuestion(string $id, array $question): array
    {
        $table = [];
        foreach ((array) ($question['solution'] ?? []) as $row) {
            if (is_array($row) && isset($row['position'])) {
                $table[] = [
                    'position' => $row['position'],
                    'rate' => $row['askrate'] ? '?' : $row['rate'],
                    'amount' => $row['askamount'] ? '?' : $row['amount'],
                    'askrate' => $row['askrate'],
                    'askamount' => $row['askamount'],
                ];
            }
        }
        return [
            'id' => $id,
            'type' => (string) $question['type'],
            'question' => (string) $question['question'],
            'choices' => array_values((array) ($question['choices'] ?? [])),
            'variables' => $question['type'] === 'crossMultiplicationQuestionWidget' ? array_keys((array) $question['solution']) : [],
            'table' => $table,
            'columns' => $table !== [] ? ['Position', 'Satz', 'Betrag'] : [],
            'taxonomylevel' => (string) ($question['taxonomylevel'] ?? 'WISSEN'),
            'difficultylevel' => (int) ($question['difficultylevel'] ?? 1),
        ];
    }

    /**
     * A small bank covering every supported question type.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function questionBank(bool $de): array
    {
        return [
            [
                'type' => 'singleChoiceQuestionWidget',
                'question' => $de ? 'Welcher Planet wird als der **Rote Planet** bezeichnet?' : 'Which planet is known as the **Red Planet**?',
                'choices' => $de ? ['Venus', 'Mars', 'Jupiter', 'Merkur'] : ['Venus', 'Mars', 'Jupiter', 'Mercury'],
                'correct' => 'Mars',
                'taxonomylevel' => 'WISSEN',
                'difficultylevel' => 1,
            ],
            [
                'type' => 'trueFalseQuestionWidget',
                'question' => $de ? 'Wasser siedet auf Meereshöhe bei 100 °C.' : 'At sea level, water boils at 100 °C.',
                'choices' => ['true', 'false'],
                'correct' => 'true',
                'taxonomylevel' => 'WISSEN',
                'difficultylevel' => 1,
            ],
            [
                'type' => 'crossMultiplicationQuestionWidget',
                'question' => $de ? '3 Personen streichen einen Zaun in 6 Stunden. Wie lange brauchen 2 Personen (t2) und wie lange 9 Personen (t9)?'
                    : '3 people paint a fence in 6 hours. How long do 2 people need (t2), and how long 9 people (t9)?',
                'solution' => ['t2' => '9', 't9' => '2'],
                'explain_de' => 'Antiproportional: 3 · 6 = 18 Personenstunden, also t2 = 18 / 2 = 9 h und t9 = 18 / 9 = 2 h.',
                'explain_en' => 'Inversely proportional: 3 · 6 = 18 person-hours, so t2 = 18 / 2 = 9 h and t9 = 18 / 9 = 2 h.',
                'taxonomylevel' => 'ANWENDEN',
                'difficultylevel' => 2,
            ],
            [
                'type' => 'openQuestionWidget',
                'question' => $de ? 'Erkläre in ein bis zwei Sätzen, warum der Himmel blau erscheint.' : 'Explain in one or two sentences why the sky looks blue.',
                'keywords' => ['streu', 'scatter', 'rayleigh'],
                'model_de' => 'Kurzwelliges blaues Licht wird an den Luftmolekülen stärker **gestreut** als langwelliges rotes (Rayleigh-Streuung).',
                'model_en' => 'Short-wave blue light is **scattered** by air molecules much more than long-wave red light (Rayleigh scattering).',
                'taxonomylevel' => 'VERSTEHEN',
                'difficultylevel' => 3,
            ],
            [
                'type' => 'tradeCostingQuestionWidget',
                'question' => $de ? 'Berechne Rabatt und Zieleinkaufspreis.' : 'Work out the discount and the target purchase price.',
                'solution' => [
                    ['position' => $de ? 'Listeneinkaufspreis' : 'List price', 'rate' => '', 'amount' => '1000', 'askrate' => false, 'askamount' => false],
                    ['position' => $de ? '- Rabatt' : '- Discount', 'rate' => '10', 'amount' => '100', 'askrate' => false, 'askamount' => true],
                    ['position' => $de ? '= Zieleinkaufspreis' : '= Target price', 'rate' => '', 'amount' => '900', 'askrate' => false, 'askamount' => true],
                ],
                'explain_de' => '10 % von 1000 € sind 100 €; 1000 € - 100 € = 900 €.',
                'explain_en' => '10 % of 1000 is 100; 1000 - 100 = 900.',
                'taxonomylevel' => 'ANWENDEN',
                'difficultylevel' => 2,
            ],
            [
                'type' => 'singleChoiceQuestionWidget',
                'question' => $de ? 'Was ergibt $7 \\cdot 8$?' : 'What is $7 \\cdot 8$?',
                'choices' => ['54', '56', '64', '48'],
                'correct' => '56',
                'taxonomylevel' => 'WISSEN',
                'difficultylevel' => 1,
            ],
        ];
    }

    /**
     * A short, quiet two-tone chime as a base64 WAV, so the narration
     * control has something audible to play in demo mode.
     */
    private static function tone(): string
    {
        $rate = 16000;
        $samples = '';
        $length = (int) ($rate * 1.2);
        for ($i = 0; $i < $length; $i++) {
            $t = $i / $rate;
            $frequency = $t < 0.6 ? 523.25 : 659.25;
            $envelope = min(1.0, $i / 400) * max(0.0, 1 - ($t - ($t < 0.6 ? 0 : 0.6)) / 0.6);
            $value = (int) (sin(2 * M_PI * $frequency * $t) * 3000 * $envelope);
            $samples .= pack('v', $value & 0xffff);
        }
        $header = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'data' . pack('V', strlen($samples));
        return base64_encode($header . $samples);
    }
}
