<?php

namespace TarsusIlias\Service;

use TarsusIlias\Activity\Didactics;
use TarsusIlias\Activity\Scoring;
use TarsusIlias\Activity\Settings;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Plan;

/**
 * The AI settings of one object for the teacher's settings panel, and the
 * one place where submitted settings are checked and applied - for the
 * panel and for the ILIAS settings tab alike.
 *
 * A setting outside the customer's package is shown, explained and locked;
 * a locked value posts nothing and the stored one is kept, so a package
 * upgrade later finds the teacher's choice intact.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class SettingsApi
{
    /** Conversation styles, 1 closed .. 5 open. */
    private const STYLES = ['closed', 'lexical', 'tutorial', 'socratic', 'open'];

    /** Thinking levels with their language keys. */
    private const THINKING = [1 => 'fast', 2 => 'slow', 3 => 'deep', 4 => 'intense'];

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    private static function t(string $key, array $params = []): string
    {
        return Ilias::txt($key, $params);
    }

    /**
     * The settings panel: sections of fields with their values, options,
     * help texts and package locks.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $s = $this->settings;
        $planlock = static fn(string $key): string => self::t($key, ['plan' => Plan::label()]);

        $modes = [];
        foreach (['ask', 'test', 'build', 'discuss'] as $mode) {
            $allowed = Plan::allowsMode($mode);
            $modes[] = [
                'name' => $mode . 'enabled',
                'type' => 'checkbox',
                'label' => self::t('mode_' . $mode),
                'help' => self::t($mode . 'enabled_help'),
                'value' => $s->modeEnabled($mode),
                'locked' => !$allowed,
                'lockedtext' => $allowed ? '' : $planlock('plan_mode_unavailable'),
            ];
        }

        $levels = [];
        foreach (self::THINKING as $level => $key) {
            $levels[] = ['value' => (string) $level, 'label' => self::t('thinking_' . $key), 'help' => self::t('thinking_' . $key . '_help')];
        }
        $styles = [];
        foreach (self::STYLES as $i => $key) {
            $styles[] = ['value' => (string) ($i + 1), 'label' => self::t('style_' . $key)];
        }
        $dqr = [];
        foreach (Didactics::DQR_LEVELS as $level) {
            $dqr[] = ['value' => (string) $level, 'label' => $level . ' – ' . self::t('dqr_level' . $level)];
        }
        $taxonomy = [];
        foreach ([1 => 'taxonomy_knowledge', 2 => 'taxonomy_understanding', 3 => 'taxonomy_application'] as $level => $key) {
            $taxonomy[] = ['value' => (string) $level, 'label' => self::t($key)];
        }
        $types = [];
        foreach (Didactics::TYPES as $type) {
            $types[] = ['value' => $type, 'label' => self::t(Didactics::typeLabelKey($type))];
        }
        $tempos = [];
        foreach (Didactics::LEARN_TEMPOS as $value) {
            $label = Didactics::tempoLabel($value);
            $tempos[] = [
                'value' => (string) $value,
                'label' => $value === Didactics::LEARN_TEMPO_DEFAULT ? str_replace('{label}', $label, self::t('learntempo_recommended')) : $label,
            ];
        }
        $methods = [];
        foreach (Didactics::LEARNING_METHODS as $value) {
            $methods[] = ['value' => $value, 'label' => self::t('learningmethod_' . str_replace('-', '', $value))];
        }

        $aiallowed = Plan::allowsAiDriven();
        $voiceallowed = Plan::allowsVoiceInput();
        $grades = [];
        foreach ([1 => 'difficulty_easy', 2 => 'difficulty_medium', 3 => 'difficulty_hard'] as $grade => $key) {
            $grades[] = [
                'name' => 'qtypes' . $grade,
                'type' => 'multi',
                'label' => str_replace('{grade}', self::t($key), self::t('questiontypes_grade')),
                'help' => $grade === 1 ? self::t('questiontypes_help') : '',
                'options' => $types,
                'value' => array_values($s->questiontypes[$grade] ?? []),
            ];
        }

        return [
            'sections' => [
                ['title' => self::t('settings_modes'), 'fields' => $modes],
                ['title' => self::t('settings_interaction'), 'help' => self::t('settings_interaction_desc'), 'fields' => [
                    [
                        'name' => 'interactiontype', 'type' => 'radio', 'label' => self::t('interactiontype'),
                        'options' => [
                            ['value' => Plan::INTERACTION_TRAINER, 'label' => self::t('interaction_trainer'), 'help' => self::t('interaction_trainer_help')],
                            ['value' => Plan::INTERACTION_AI, 'label' => self::t('interaction_ai'), 'help' => self::t('interaction_ai_help'),
                                'locked' => !$aiallowed, 'lockedtext' => $aiallowed ? '' : $planlock('plan_interaction_unavailable')],
                        ],
                        'value' => $s->interactiontype,
                    ],
                    [
                        'name' => 'voiceinput', 'type' => 'checkbox', 'label' => self::t('voiceinput'), 'help' => self::t('voiceinput_help'),
                        'value' => $s->voiceinput, 'locked' => !$voiceallowed, 'lockedtext' => $voiceallowed ? '' : $planlock('plan_feature_locked'),
                    ],
                    ['name' => 'referencesenabled', 'type' => 'checkbox', 'label' => self::t('referencesenabled'), 'help' => self::t('referencesenabled_help'), 'value' => $s->referencesenabled],
                    ['name' => 'visualaccessibility', 'type' => 'checkbox', 'label' => self::t('visualaccessibility'), 'help' => self::t('visualaccessibility_help'), 'value' => $s->visualaccessibility],
                ]],
                ['title' => self::t('thinkinglevel'), 'help' => self::t('thinkinglevel_help'), 'fields' => [
                    ['name' => 'thinkinglevel', 'type' => 'cards', 'label' => self::t('thinkinglevel'), 'options' => $levels, 'value' => (string) $s->thinkinglevel],
                ]],
                ['title' => self::t('settings_general'), 'fields' => [
                    ['name' => 'dqr', 'type' => 'select', 'label' => self::t('dqr'), 'help' => self::t('dqr_help'), 'options' => $dqr, 'value' => (string) $s->dqr],
                    ['name' => 'industry', 'type' => 'text', 'label' => self::t('industry'), 'help' => self::t('industry_help'), 'value' => $s->industry, 'maxlength' => 255],
                    ['name' => 'anticheat', 'type' => 'checkbox', 'label' => self::t('anticheat'), 'help' => self::t('anticheat_help'), 'value' => $s->anticheat],
                ]],
                ['title' => self::t('settings_ask'), 'fields' => [
                    ['name' => 'conversationstyle', 'type' => 'select', 'label' => self::t('conversationstyle'), 'help' => self::t('conversationstyle_help'), 'options' => $styles, 'value' => (string) $s->conversationstyle],
                ]],
                ['title' => self::t('settings_test'), 'fields' => array_merge([
                    ['name' => 'taxonomylevels', 'type' => 'multi', 'label' => self::t('taxonomylevels'), 'help' => self::t('taxonomylevels_help'), 'options' => $taxonomy,
                        'value' => array_map('strval', $s->taxonomylevels)],
                ], $grades, [
                    ['name' => 'operators', 'type' => 'radio', 'label' => self::t('operators'), 'help' => self::t('operators_help'), 'options' => [
                        ['value' => Didactics::OPERATORS_STANDARD, 'label' => self::t('operators_standard')],
                        ['value' => Didactics::OPERATORS_IHK, 'label' => self::t('operators_ihk')],
                    ], 'value' => $s->operators],
                    ['name' => 'learntempo', 'type' => 'select', 'label' => self::t('learntempo'), 'help' => self::t('learntempo_help'), 'options' => $tempos, 'value' => (string) $s->learntempo],
                ])],
                ['title' => self::t('settings_build'), 'fields' => [
                    ['name' => 'learningmethod', 'type' => 'select', 'label' => self::t('learningmethod'), 'help' => self::t('learningmethod_help'), 'options' => $methods, 'value' => $s->learningmethod],
                    ['name' => 'gifenabled', 'type' => 'checkbox', 'label' => self::t('gifenabled'), 'help' => self::t('gifenabled_help'), 'value' => $s->gifenabled],
                ]],
                ['title' => self::t('settings_lp'), 'fields' => [
                    ['name' => 'completionprogress', 'type' => 'number', 'label' => self::t('completionprogress'), 'help' => self::t('completionprogress_help'),
                        'value' => (string) $s->completionprogress, 'min' => 0, 'max' => 100, 'suffix' => '%'],
                ]],
            ],
        ];
    }

    /**
     * Check submitted values and apply them to the settings. Values not
     * submitted keep their stored value; locked ones always do.
     *
     * @param array<string, mixed> $values
     * @return array<string, string> Field name => error message; empty when applied.
     */
    public function apply(array $values): array
    {
        $s = $this->settings;
        $errors = [];
        $bool = static fn(string $name, bool $current): bool => array_key_exists($name, $values) ? !empty($values[$name]) : $current;

        $wanted = [];
        $anymode = false;
        foreach (['ask', 'test', 'build', 'discuss'] as $mode) {
            $wanted[$mode] = Plan::allowsMode($mode) ? $bool($mode . 'enabled', $s->modeEnabled($mode)) : $s->modeEnabled($mode);
            if ($wanted[$mode] && Plan::allowsMode($mode)) {
                $anymode = true;
            }
        }
        if (!$anymode) {
            $errors['askenabled'] = self::t('error_modesrequired');
        }

        $taxonomy = array_key_exists('taxonomylevels', $values)
            ? Didactics::encodeTaxonomyLevels((array) $values['taxonomylevels'])
            : Didactics::encodeTaxonomyLevels($s->taxonomylevels);
        if ($taxonomy === '') {
            $errors['taxonomylevels'] = self::t('error_taxonomyrequired');
        }
        $map = [];
        foreach (Didactics::GRADES as $grade) {
            $map[$grade] = array_key_exists('qtypes' . $grade, $values)
                ? array_values(array_intersect(array_map('strval', (array) $values['qtypes' . $grade]), Didactics::TYPES))
                : array_values($s->questiontypes[$grade] ?? []);
            if ($map[$grade] === []) {
                $errors['qtypes' . $grade] = self::t('error_questiontypesrequired');
            }
        }
        $completion = array_key_exists('completionprogress', $values) ? $values['completionprogress'] : $s->completionprogress;
        if (!is_numeric($completion) || (int) $completion < 0 || (int) $completion > 100) {
            $errors['completionprogress'] = self::t('error_completion');
        }
        if ($errors !== []) {
            return $errors;
        }

        $s->askenabled = $wanted['ask'];
        $s->testenabled = $wanted['test'];
        $s->buildenabled = $wanted['build'];
        $s->discussenabled = $wanted['discuss'];
        if (array_key_exists('interactiontype', $values)) {
            $interaction = Settings::interaction((string) $values['interactiontype']);
            // Agent mode outside the package keeps the stored choice.
            if (!($interaction === Plan::INTERACTION_AI && !Plan::allowsAiDriven())) {
                $s->interactiontype = $interaction;
            }
        }
        if (Plan::allowsVoiceInput()) {
            $s->voiceinput = $bool('voiceinput', $s->voiceinput);
        }
        $s->referencesenabled = $bool('referencesenabled', $s->referencesenabled);
        $s->visualaccessibility = $bool('visualaccessibility', $s->visualaccessibility);
        if (array_key_exists('thinkinglevel', $values) && in_array((int) $values['thinkinglevel'], Settings::THINKING_LEVELS, true)) {
            $s->thinkinglevel = (int) $values['thinkinglevel'];
        }
        if (array_key_exists('dqr', $values) && in_array((int) $values['dqr'], Didactics::DQR_LEVELS, true)) {
            $s->dqr = (int) $values['dqr'];
        }
        if (array_key_exists('industry', $values)) {
            $s->industry = mb_substr(trim((string) $values['industry']), 0, 255);
        }
        $s->anticheat = $bool('anticheat', $s->anticheat);
        if (array_key_exists('conversationstyle', $values) && in_array((int) $values['conversationstyle'], Didactics::STYLES, true)) {
            $s->conversationstyle = (int) $values['conversationstyle'];
        }
        $s->taxonomylevels = Didactics::decodeTaxonomyLevels($taxonomy);
        $s->questiontypes = $map;
        if (array_key_exists('operators', $values)) {
            $s->operators = Didactics::operators((string) $values['operators']);
        }
        if (array_key_exists('learntempo', $values)) {
            $s->learntempo = Didactics::learnTempo($values['learntempo']);
        }
        if (array_key_exists('learningmethod', $values)) {
            $s->learningmethod = Didactics::learningMethod((string) $values['learningmethod']);
        }
        $s->gifenabled = $bool('gifenabled', $s->gifenabled);
        $s->completionprogress = max(0, min(100, (int) $completion));
        return [];
    }

    /**
     * Apply and store the values sent from the settings panel.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function save(array $values): array
    {
        $errors = $this->apply($values);
        if ($errors !== []) {
            return ['status' => 'error', 'error' => self::t('form_invalid'), 'errors' => $errors];
        }
        $this->settings->save();
        // A changed threshold or mode changes every learner's status.
        Scoring::pushAll($this->settings->objid);
        return ['status' => 'success', 'error' => '', 'message' => self::t('settings_saved')];
    }
}
