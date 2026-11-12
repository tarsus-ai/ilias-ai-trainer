<?php

namespace TarsusIlias\Activity;

/**
 * Didactic settings vocabulary: taxonomy levels, question types per
 * difficulty grade, operators, learning pace, modes and methods.
 *
 * The Test knowledge mode is steered by the enabled Bloom taxonomy levels
 * (1 Knowledge, 2 Understanding, 3 Application) and a map of difficulty
 * grade (1 Easy, 2 Medium, 3 Hard) to the question widget types the brain
 * may generate in that grade. Difficulty has no API parameter of its own;
 * the brain requires every grade to carry at least one type.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Didactics
{
    public const TYPE_SINGLECHOICE = 'singleChoiceQuestionWidget';
    public const TYPE_TRUEFALSE = 'trueFalseQuestionWidget';
    public const TYPE_OPEN = 'openQuestionWidget';
    public const TYPE_CROSSMULTIPLICATION = 'crossMultiplicationQuestionWidget';
    public const TYPE_TRADECOSTING = 'tradeCostingQuestionWidget';

    /**
     * Accounting exercise (Buchhaltung). A requestable type only: the brain
     * answers it with tradeCostingQuestionWidget tasks, so display and
     * evaluation are those of the spreadsheet calculation.
     */
    public const TYPE_ACCOUNTING = 'accountingWidget';

    /** @var string[] */
    public const TYPES = [
        self::TYPE_SINGLECHOICE,
        self::TYPE_TRUEFALSE,
        self::TYPE_OPEN,
        self::TYPE_CROSSMULTIPLICATION,
        self::TYPE_TRADECOSTING,
        self::TYPE_ACCOUNTING,
    ];

    /** @var int[] Difficulty grades (1 Easy, 2 Medium, 3 Hard). */
    public const GRADES = [1, 2, 3];

    /** @var int[] Bloom taxonomy levels (1 Knowledge, 2 Understanding, 3 Application). */
    public const TAXONOMY_LEVELS = [1, 2, 3];

    public const OPERATORS_STANDARD = 'standard';
    public const OPERATORS_IHK = 'ihk';

    /** @var string[] */
    public const OPERATORS = [self::OPERATORS_STANDARD, self::OPERATORS_IHK];

    /** @var float[] Learning pace steps; 1 is recommended. */
    public const LEARN_TEMPOS = [0.1, 0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0];

    public const LEARN_TEMPO_DEFAULT = 1.0;

    /** @var string[] Interaction modes, in switcher order. */
    public const MODES = ['build', 'test', 'ask'];

    public const METHOD_MICROLEARNING = 'micro-learning';

    /** @var string[] */
    public const LEARNING_METHODS = [self::METHOD_MICROLEARNING];

    /** @var int[] Conversation styles (1 Closed .. 5 Open). */
    public const STYLES = [1, 2, 3, 4, 5];

    /** @var int[] DQR levels. */
    public const DQR_LEVELS = [3, 4, 5, 6, 7];

    public static function learningMethod(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, self::LEARNING_METHODS, true) ? $value : self::METHOD_MICROLEARNING;
    }

    public static function operators(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, self::OPERATORS, true) ? $value : self::OPERATORS_STANDARD;
    }

    /**
     * @param mixed $value
     */
    public static function learnTempo($value): float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return self::LEARN_TEMPO_DEFAULT;
        }
        return max(0.1, min(2.0, round((float) $value, 2)));
    }

    /**
     * The language key of a question type's label.
     */
    public static function typeLabelKey(string $type): string
    {
        switch ($type) {
            case self::TYPE_SINGLECHOICE:
                return 'qtype_singlechoice';
            case self::TYPE_TRUEFALSE:
                return 'qtype_truefalse';
            case self::TYPE_OPEN:
                return 'qtype_open';
            case self::TYPE_CROSSMULTIPLICATION:
                return 'qtype_crossmultiplication';
            case self::TYPE_TRADECOSTING:
                return 'qtype_tradecosting';
            case self::TYPE_ACCOUNTING:
                return 'qtype_accounting';
            default:
                return 'qtype_unknown';
        }
    }

    /**
     * @return int[]
     */
    public static function defaultTaxonomyLevels(): array
    {
        return self::TAXONOMY_LEVELS;
    }

    /**
     * The default question types per grade: closed formats when easy,
     * everything in the middle, open questions only when hard.
     *
     * @return array<int, string[]>
     */
    public static function defaultQuestionTypes(): array
    {
        return [
            1 => [self::TYPE_SINGLECHOICE, self::TYPE_TRUEFALSE],
            2 => [self::TYPE_SINGLECHOICE, self::TYPE_TRUEFALSE, self::TYPE_OPEN],
            3 => [self::TYPE_OPEN],
        ];
    }

    /**
     * @param array<int|string, mixed> $levels
     */
    public static function encodeTaxonomyLevels(array $levels): string
    {
        $levels = array_values(array_unique(array_intersect(array_map('intval', $levels), self::TAXONOMY_LEVELS)));
        sort($levels);
        return implode(',', $levels);
    }

    /**
     * @return int[] At least one level; invalid values fall back to the defaults.
     */
    public static function decodeTaxonomyLevels(?string $encoded): array
    {
        $levels = array_values(array_unique(array_intersect(
            array_map('intval', explode(',', (string) $encoded)),
            self::TAXONOMY_LEVELS
        )));
        sort($levels);
        return $levels === [] ? self::defaultTaxonomyLevels() : $levels;
    }

    /**
     * @param array<int|string, mixed> $map Grade => question widget types.
     */
    public static function encodeQuestionTypes(array $map): string
    {
        $clean = [];
        foreach (self::GRADES as $grade) {
            $types = array_values(array_intersect((array) ($map[$grade] ?? []), self::TYPES));
            if ($types !== []) {
                $clean[(string) $grade] = $types;
            }
        }
        return (string) json_encode((object) $clean);
    }

    /**
     * Decode the stored map; a grade that ends up empty takes its default,
     * so the result is always complete and sendable.
     *
     * @return array<int, string[]>
     */
    public static function decodeQuestionTypes(?string $encoded): array
    {
        $decoded = json_decode((string) $encoded, true);
        $map = [];
        if (is_array($decoded)) {
            foreach ($decoded as $grade => $types) {
                $grade = (int) $grade;
                if (!in_array($grade, self::GRADES, true) || !is_array($types)) {
                    continue;
                }
                $types = array_values(array_intersect($types, self::TYPES));
                if ($types !== []) {
                    $map[$grade] = $types;
                }
            }
        }
        foreach (self::defaultQuestionTypes() as $grade => $types) {
            $map[$grade] = $map[$grade] ?? $types;
        }
        ksort($map);
        return $map;
    }

    /**
     * Format a pace for display: x0.25, x1, ...
     */
    public static function tempoLabel(float $tempo): string
    {
        return 'x' . rtrim(rtrim(number_format($tempo, 2, '.', ''), '0'), '.');
    }
}
