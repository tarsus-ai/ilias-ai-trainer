<?php

namespace TarsusIlias\Output;

use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Support\Ilias;

/**
 * Shapes the brain's feed, practice and learn items for the browser:
 * Markdown rendered to safe HTML, labels localised, and nothing passed on
 * that the learner should not see before answering.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Shaper
{
    public static function taxonomy(string $level): string
    {
        switch (strtoupper($level)) {
            case 'WISSEN':
                return Ilias::txt('taxonomy_knowledge');
            case 'VERSTEHEN':
                return Ilias::txt('taxonomy_understanding');
            case 'ANWENDEN':
                return Ilias::txt('taxonomy_application');
            default:
                return '';
        }
    }

    public static function taxonomyIcon(string $level): string
    {
        switch (strtoupper($level)) {
            case 'WISSEN':
                return 'taxonomy_knowledge';
            case 'VERSTEHEN':
                return 'taxonomy_understanding';
            case 'ANWENDEN':
                return 'taxonomy_application';
            default:
                return '';
        }
    }

    public static function difficulty(int $grade): string
    {
        switch ($grade) {
            case 1:
                return Ilias::txt('difficulty_easy');
            case 2:
                return Ilias::txt('difficulty_medium');
            case 3:
                return Ilias::txt('difficulty_hard');
            default:
                return '';
        }
    }

    /**
     * Localise a choice value: true/false widgets answer with booleans.
     */
    public static function choice(string $value, string $widgettype): string
    {
        if ($widgettype === 'trueFalseQuestionWidget') {
            if (strcasecmp($value, 'true') === 0) {
                return Ilias::txt('choice_true');
            }
            if (strcasecmp($value, 'false') === 0) {
                return Ilias::txt('choice_false');
            }
        }
        return $value;
    }

    public static function isSupportedType(string $widgettype): bool
    {
        return in_array($widgettype, ClientInterface::QUESTION_WIDGET_TYPES, true);
    }

    /**
     * @param array<int, mixed> $answers
     * @return array<int, array{variable: string, answer: string, iscorrect: bool, clarification: string}>
     */
    public static function answers(array $answers): array
    {
        $out = [];
        foreach ($answers as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $out[] = [
                'variable' => (string) ($entry['variable'] ?? ''),
                'answer' => (string) ($entry['answer'] ?? ''),
                'iscorrect' => !empty($entry['iscorrect']),
                'clarification' => Markdown::render((string) ($entry['clarification'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * The question table of a task: rows of cells, a cell to fill in
     * marked 'ask'.
     *
     * @param array<int, mixed> $rows
     * @return array<int, array<int, array{value: string, ask: bool}>>
     */
    public static function cells(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = is_array($cell)
                    ? ['value' => (string) ($cell['value'] ?? ''), 'ask' => !empty($cell['ask'])]
                    : ['value' => (string) $cell, 'ask' => false];
            }
            $out[] = $cells;
        }
        return $out;
    }

    /**
     * Table rows as lists of cell values (the learner's filled-in table).
     *
     * @param array<int, mixed> $rows
     * @return array<int, string[]>
     */
    public static function values(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = array_values(array_map(static fn($cell): string => is_array($cell) ? (string) ($cell['value'] ?? '') : (string) $cell, $row));
            }
        }
        return $out;
    }

    /**
     * The model table with a verdict per cell: 1 right, -1 wrong, 0 given.
     *
     * @param array<int, mixed> $rows
     * @return array<int, array<int, array{value: string, verdict: int}>>
     */
    public static function verdicts(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = [
                    'value' => (string) (is_array($cell) ? ($cell['value'] ?? '') : $cell),
                    'verdict' => max(-1, min(1, (int) (is_array($cell) ? ($cell['verdict'] ?? 0) : 0))),
                ];
            }
            $out[] = $cells;
        }
        return $out;
    }

    /**
     * @param array<int, mixed> $choices
     * @return array<int, array{value: string, label: string}>
     */
    public static function choices(array $choices, string $type): array
    {
        $out = [];
        foreach ($choices as $choice) {
            $out[] = ['value' => (string) $choice, 'label' => self::choice((string) $choice, $type)];
        }
        return $out;
    }

    /**
     * A freshly generated question widget (no verdict).
     *
     * @param array<string, mixed> $widget
     * @return array<string, mixed>
     */
    public static function widget(array $widget): array
    {
        $type = (string) ($widget['type'] ?? '');
        return [
            'id' => (string) ($widget['id'] ?? ''),
            'type' => $type,
            'question' => Markdown::render((string) ($widget['question'] ?? '')),
            'choices' => self::choices((array) ($widget['choices'] ?? []), $type),
            'taxonomy' => self::taxonomy((string) ($widget['taxonomylevel'] ?? '')),
            'difficulty' => self::difficulty((int) ($widget['difficultylevel'] ?? 0)),
            'difficultylevel' => (int) ($widget['difficultylevel'] ?? 0),
            'variables' => array_values(array_map('strval', (array) ($widget['variables'] ?? []))),
            'table' => self::cells((array) ($widget['table'] ?? [])),
            'columns' => array_values(array_map('strval', (array) ($widget['columns'] ?? []))),
        ];
    }

    /**
     * A stored question - from the feed or inside a learning stack - with
     * its verdict when answered.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function practiceItem(array $item): array
    {
        $shaped = self::widget($item);
        return ['kind' => 'practice'] + $shaped + [
            'answered' => !empty($item['answered']),
            'correct' => (bool) ($item['correct'] ?? false),
            'score' => ($item['score'] ?? null) === null ? -1 : (float) $item['score'],
            'feedback' => Markdown::render((string) ($item['feedback'] ?? '')),
            'useranswer' => (string) ($item['useranswer'] ?? ''),
            'useranswers' => self::answers((array) ($item['useranswers'] ?? [])),
            'answers' => self::answers((array) ($item['answers'] ?? [])),
            'usertable' => self::values((array) ($item['usertable'] ?? [])),
            'answertable' => self::verdicts((array) ($item['answertable'] ?? [])),
        ];
    }

    /**
     * A learn item: objectives, stack, motivation, completion or message.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function learnItem(array $item): array
    {
        $kind = (string) ($item['kind'] ?? '');
        if ($kind === 'complete' || $kind === 'motivation' || $kind === 'message') {
            return [
                'kind' => $kind,
                'id' => (string) ($item['id'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'text' => Markdown::render((string) ($item['text'] ?? '')),
            ];
        }
        if ($kind === 'objectives') {
            $objectives = [];
            foreach ((array) ($item['objectives'] ?? []) as $objective) {
                if (!is_array($objective)) {
                    continue;
                }
                $objectives[] = [
                    'id' => (string) ($objective['id'] ?? ''),
                    'objective' => (string) ($objective['objective'] ?? ''),
                    'taxonomy' => self::taxonomy((string) ($objective['taxonomylevel'] ?? '')),
                    'done' => !empty($objective['done']),
                ];
            }
            return [
                'kind' => 'objectives',
                'id' => (string) ($item['id'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'message' => Markdown::render((string) ($item['message'] ?? '')),
                'objectives' => $objectives,
            ];
        }
        $slides = [];
        foreach ((array) ($item['slides'] ?? []) as $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $isquestion = (($slide['kind'] ?? 'text') === 'question') && is_array($slide['question'] ?? null);
            // An interactive page (ai-driven interaction) is passed through
            // untouched: the browser shows it in a sandboxed frame, never
            // in the page itself.
            $ispage = !$isquestion && ($slide['kind'] ?? 'text') === 'html' && trim((string) ($slide['html'] ?? '')) !== '';
            $level = (string) ($isquestion ? ($slide['question']['taxonomylevel'] ?? '') : ($slide['taxonomylevel'] ?? ''));
            $out = [
                'id' => (string) ($slide['id'] ?? ''),
                'kind' => $isquestion ? 'question' : ($ispage ? 'html' : 'text'),
                'taxonomy' => self::taxonomy($level),
                'taxonomyicon' => self::taxonomyIcon($level),
                'title' => (string) ($slide['title'] ?? ''),
                'text' => $ispage ? '' : Markdown::render((string) ($slide['text'] ?? '')),
                'html' => $ispage ? (string) $slide['html'] : '',
                'image' => $ispage ? '' : Media::image((string) ($slide['image'] ?? '')),
                'imageposition' => in_array($slide['imageposition'] ?? '', ['top', 'left', 'right'], true) ? (string) $slide['imageposition'] : 'right',
                // Narration of the card (a data URI checked by the client).
                'audio' => $ispage ? '' : (strncmp((string) ($slide['audio'] ?? ''), 'data:audio/', 11) === 0 ? (string) $slide['audio'] : ''),
            ];
            if ($isquestion) {
                $out['question'] = self::practiceItem($slide['question']);
            }
            $slides[] = $out;
        }
        return [
            'kind' => 'stack',
            'id' => (string) ($item['id'] ?? ''),
            'slides' => $slides,
        ];
    }

    /**
     * One feed item of any kind.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    public static function feedItem(array $item): ?array
    {
        $kind = (string) ($item['kind'] ?? '');
        switch ($kind) {
            case 'message':
                return [
                    'kind' => 'message',
                    'title' => (string) ($item['title'] ?? ''),
                    'text' => Markdown::render((string) ($item['text'] ?? '')),
                ];
            case 'exchange':
                return [
                    'kind' => 'exchange',
                    'id' => (string) ($item['id'] ?? ''),
                    'state' => (string) ($item['state'] ?? ''),
                    'query' => (string) ($item['query'] ?? ''),
                    'answer' => Markdown::render((string) ($item['answer'] ?? '')),
                    'html' => (string) ($item['html'] ?? ''),
                    'sources' => !empty($item['sources']),
                ];
            case 'practice':
                return self::practiceItem($item);
            case 'objectives':
            case 'stack':
            case 'complete':
            case 'motivation':
                return self::learnItem($item);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $progress
     * @return array<string, float>
     */
    public static function progress(array $progress): array
    {
        return [
            'earnedxp' => (float) ($progress['earnedxp'] ?? 0),
            'totalxp' => (float) ($progress['totalxp'] ?? 0),
            'earnedunits' => (float) ($progress['earnedunits'] ?? 0),
            'totalunits' => (float) ($progress['totalunits'] ?? 0),
            'practiceprogress' => (float) ($progress['practiceprogress'] ?? 0),
            'learnprogress' => (float) ($progress['learnprogress'] ?? 0),
            'progress' => (float) ($progress['progress'] ?? 0),
        ];
    }
}
