<?php

namespace TarsusIlias\Activity;

use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Plan;

/**
 * The settings of one Tarsus object: availability, the didactic AI
 * settings grouped by mode, and the completion threshold.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Settings
{
    public const TABLE = 'rep_robj_xtar_data';

    /** Default completion threshold in percent. */
    public const DEFAULT_COMPLETION = 80;

    public int $objid = 0;
    public bool $online = false;
    public int $conversationstyle = 3;
    public int $dqr = 6;
    public string $industry = '';
    /** @var int[] */
    public array $taxonomylevels = [1, 2, 3];
    /** @var array<int, string[]> */
    public array $questiontypes = [];
    public string $operators = Didactics::OPERATORS_STANDARD;
    public bool $anticheat = false;
    public float $learntempo = Didactics::LEARN_TEMPO_DEFAULT;
    public bool $askenabled = true;
    public bool $testenabled = true;
    public bool $buildenabled = true;
    public bool $gifenabled = false;
    public string $learningmethod = Didactics::METHOD_MICROLEARNING;
    /** trainer-driven (text and pictures) or ai-driven (interactive pages). */
    public string $interactiontype = Plan::INTERACTION_TRAINER;
    /** Completion threshold in percent; 0 switches the rule off. */
    public int $completionprogress = self::DEFAULT_COMPLETION;

    public function __construct(int $objid = 0)
    {
        $this->objid = $objid;
        $this->questiontypes = Didactics::defaultQuestionTypes();
    }

    public static function load(int $objid): self
    {
        $settings = new self($objid);
        $db = Ilias::db();
        $res = $db->queryF('SELECT * FROM ' . self::TABLE . ' WHERE obj_id = %s', ['integer'], [$objid]);
        $row = $db->fetchAssoc($res);
        if (!$row) {
            return $settings;
        }
        $settings->online = !empty($row['is_online']);
        $settings->conversationstyle = self::within((int) $row['conv_style'], Didactics::STYLES, 3);
        $settings->dqr = self::within((int) $row['dqr'], Didactics::DQR_LEVELS, 6);
        $settings->industry = (string) ($row['industry'] ?? '');
        $settings->taxonomylevels = Didactics::decodeTaxonomyLevels((string) ($row['tax_levels'] ?? ''));
        $settings->questiontypes = Didactics::decodeQuestionTypes((string) ($row['q_types'] ?? ''));
        $settings->operators = Didactics::operators((string) ($row['operators'] ?? ''));
        $settings->anticheat = !empty($row['anticheat']);
        $settings->learntempo = Didactics::learnTempo($row['learn_tempo'] ?? null);
        $settings->askenabled = !empty($row['ask_on']);
        $settings->testenabled = !empty($row['test_on']);
        $settings->buildenabled = !empty($row['build_on']);
        $settings->gifenabled = !empty($row['gif_on']);
        $settings->learningmethod = Didactics::learningMethod((string) ($row['learn_method'] ?? ''));
        $settings->interactiontype = self::interaction((string) ($row['interaction'] ?? ''));
        $settings->completionprogress = max(0, min(100, (int) ($row['compl_progress'] ?? self::DEFAULT_COMPLETION)));
        return $settings;
    }

    public function save(): void
    {
        $db = Ilias::db();
        $db->replace(self::TABLE, ['obj_id' => ['integer', $this->objid]], [
            'is_online' => ['integer', $this->online ? 1 : 0],
            'conv_style' => ['integer', self::within($this->conversationstyle, Didactics::STYLES, 3)],
            'dqr' => ['integer', self::within($this->dqr, Didactics::DQR_LEVELS, 6)],
            'industry' => ['text', Config::bmp(mb_substr(trim($this->industry), 0, 255))],
            'tax_levels' => ['text', Didactics::encodeTaxonomyLevels($this->taxonomylevels) ?: '1,2,3'],
            'q_types' => ['text', Didactics::encodeQuestionTypes($this->questiontypes)],
            'operators' => ['text', Didactics::operators($this->operators)],
            'anticheat' => ['integer', $this->anticheat ? 1 : 0],
            'learn_tempo' => ['float', Didactics::learnTempo($this->learntempo)],
            'ask_on' => ['integer', $this->askenabled ? 1 : 0],
            'test_on' => ['integer', $this->testenabled ? 1 : 0],
            'build_on' => ['integer', $this->buildenabled ? 1 : 0],
            'gif_on' => ['integer', $this->gifenabled ? 1 : 0],
            'learn_method' => ['text', Didactics::learningMethod($this->learningmethod)],
            'interaction' => ['text', self::interaction($this->interactiontype)],
            'compl_progress' => ['integer', max(0, min(100, $this->completionprogress))],
        ]);
    }

    /**
     * A valid interaction type; trainer-driven for anything else.
     */
    public static function interaction(string $value): string
    {
        return in_array($value, Plan::INTERACTIONS, true) ? $value : Plan::INTERACTION_TRAINER;
    }

    /**
     * The interaction type that is actually in effect: the object's wish,
     * bounded by the plan.
     */
    public function effectiveInteraction(): string
    {
        return Plan::interactionType($this->interactiontype);
    }

    /**
     * Whether a mode is switched on here and included in the plan.
     */
    public function modeActive(string $mode): bool
    {
        return $this->modeEnabled($mode) && Plan::allowsMode($mode);
    }

    /**
     * The modes the plan allows this object to offer, in switcher order.
     *
     * @return string[]
     */
    public function availableModes(): array
    {
        return array_values(array_filter($this->modes(), static fn(string $mode): bool => Plan::allowsMode($mode)));
    }

    public static function delete(int $objid): void
    {
        Ilias::db()->manipulateF('DELETE FROM ' . self::TABLE . ' WHERE obj_id = %s', ['integer'], [$objid]);
    }

    /**
     * A copy of these settings for another object.
     */
    public function copyTo(int $objid): self
    {
        $copy = clone $this;
        $copy->objid = $objid;
        return $copy;
    }

    public function modeEnabled(string $mode): bool
    {
        switch ($mode) {
            case 'ask':
                return $this->askenabled;
            case 'test':
                return $this->testenabled;
            case 'build':
                return $this->buildenabled;
        }
        return false;
    }

    /**
     * The enabled modes in switcher order.
     *
     * @return string[]
     */
    public function modes(): array
    {
        return array_values(array_filter(Didactics::MODES, fn(string $mode): bool => $this->modeEnabled($mode)));
    }

    /**
     * Whether the online flag of an object is set (cheap, for access checks).
     */
    public static function isOnline(int $objid): bool
    {
        $db = Ilias::db();
        $res = $db->queryF('SELECT is_online FROM ' . self::TABLE . ' WHERE obj_id = %s', ['integer'], [$objid]);
        $row = $db->fetchAssoc($res);
        return $row !== null && $row !== false && !empty($row['is_online']);
    }

    /**
     * @param int[] $allowed
     */
    private static function within(int $value, array $allowed, int $default): int
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
