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
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function table(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'position' => (string) ($row['position'] ?? ''),
                'rate' => (string) ($row['rate'] ?? ''),
                'amount' => (string) ($row['amount'] ?? ''),
                'askrate' => !empty($row['askrate']),
                'askamount' => !empty($row['askamount']),
                'rateverdict' => (int) ($row['rateverdict'] ?? 0),
                'amountverdict' => (int) ($row['amountverdict'] ?? 0),
            ];
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
            'table' => self::table((array) ($widget['table'] ?? [])),
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
            'usertable' => self::table((array) ($item['usertable'] ?? [])),
            'answertable' => self::table((array) ($item['answertable'] ?? [])),
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
                'image' => $ispage ? '' : self::image((string) ($slide['image'] ?? '')),
                'imageposition' => ($slide['imageposition'] ?? 'right') === 'left' ? 'left' : 'right',
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
                    'query' => (string) ($item['query'] ?? ''),
                    'answer' => Markdown::render((string) ($item['answer'] ?? '')),
                    'html' => (string) ($item['html'] ?? ''),
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

    /**
     * Only image data URIs and https URLs reach an <img>.
     */
    private static function image(string $value): string
    {
        if (preg_match('~^data:image/(svg\+xml|png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$~', $value)) {
            return $value;
        }
        if (preg_match('~^https://[^\s"\'<>]+$~', $value)) {
            return $value;
        }
        return '';
    }
}
