<?php

namespace TarsusIlias\Support;

/**
 * The customer's Tarsus package and what it includes.
 *
 * Four packages exist, each containing the one before it: "Ask questions"
 * (ASK), "Test knowledge" (PRACTICE) and "Build knowledge" (LEARN), plus
 * Pay-As-You-Grow, which has access to everything. FEATURES is the
 * "Functions per package" table Tarsus agrees with its partners, row by
 * row. An integration has to enforce the booked package technically, so
 * nothing outside it may be reachable: every check in the plugin - the
 * object settings, the learner's mode switch and the server-side
 * endpoints - asks this class.
 *
 * The three packages include a monthly amount of AI interactions (100,000
 * by default) that does not carry over; interactions beyond it are charged
 * at a flat price each (0.32 EUR by default). Pay-As-You-Grow includes
 * none: every interaction is charged. What counts as an AI interaction is
 * defined in Interactions. Custom agreements change the figures, so
 * lern.link sets them in the plugin configuration. Config keys and method
 * names still say "credits": AI credits and AI interactions are two names
 * for the same unit.
 *
 * A customer identified by a License-Key instead of a brain ID is an
 * existing Tarsus customer, whom Tarsus bills directly instead of the
 * partner.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Plan
{
    /** Package "Build knowledge": every function. */
    public const LEARN = 'learn';
    /** Package "Test knowledge": Ask and Test. */
    public const PRACTICE = 'practice';
    /** Package "Ask questions": Ask only. */
    public const ASK = 'ask';
    /** Pay-As-You-Grow: every function, every interaction charged. */
    public const PAYG = 'payg';

    public const ALL = [self::LEARN, self::PRACTICE, self::ASK, self::PAYG];

    /** Ask questions: the AI answers questions from the documents. */
    public const FEATURE_ASK = 'ask';
    /** Test knowledge: the AI creates and evaluates quizzes. */
    public const FEATURE_TEST = 'test';
    /** Build knowledge: the learning coach explains step by step. */
    public const FEATURE_BUILD = 'build';
    /** Audio explainer in the learning coach (narrated learning units). */
    public const FEATURE_AUDIO = 'audio_explainer';
    /** Voice input in the learning coach (questions and answers by audio). */
    public const FEATURE_VOICE = 'voice_input';
    /** Agent mode: the AI-driven interaction type. */
    public const FEATURE_AGENT = 'agent_mode';

    /**
     * Functions per package: feature => the packages that include it.
     * Standard narration (Audio Generation) is not a package feature and
     * not restricted here; the plugin does not use it.
     *
     * @var array<string, string[]>
     */
    public const FEATURES = [
        self::FEATURE_ASK => [self::ASK, self::PRACTICE, self::LEARN, self::PAYG],
        self::FEATURE_TEST => [self::PRACTICE, self::LEARN, self::PAYG],
        self::FEATURE_BUILD => [self::LEARN, self::PAYG],
        self::FEATURE_AUDIO => [self::LEARN, self::PAYG],
        self::FEATURE_VOICE => [self::LEARN, self::PAYG],
        self::FEATURE_AGENT => [self::LEARN, self::PAYG],
    ];

    /** The three modes of an object; each is the package feature of the same name. */
    public const MODES = [self::FEATURE_ASK, self::FEATURE_TEST, self::FEATURE_BUILD];

    /** AI interactions a package includes per month unless agreed otherwise. */
    public const DEFAULT_CREDITS = 100000;
    /** Price of one AI interaction beyond the included ones unless agreed otherwise. */
    public const DEFAULT_PRICE = 0.32;
    public const DEFAULT_CURRENCY = 'EUR';

    /** The teacher writes the didactics; the brain answers in text and pictures. */
    public const INTERACTION_TRAINER = 'trainer-driven';
    /** Agent mode: the brain may answer with interactive HTML pages (animations, simulations). */
    public const INTERACTION_AI = 'ai-driven';
    public const INTERACTIONS = [self::INTERACTION_TRAINER, self::INTERACTION_AI];

    /** The usage is billed through the partner. */
    public const BILLED_BY_PARTNER = 'partner';
    /** Tarsus bills the customer directly (License-Key). */
    public const BILLED_BY_TARSUS = 'tarsus';

    /**
     * The configured package; "Build knowledge" until lern.link sets another.
     */
    public static function current(): string
    {
        $plan = (string) Config::get('plan', '');
        return in_array($plan, self::ALL, true) ? $plan : self::LEARN;
    }

    public static function isConfigured(): bool
    {
        return in_array((string) Config::get('plan', ''), self::ALL, true);
    }

    public static function label(?string $plan = null): string
    {
        return Ilias::txt('plan_' . ($plan ?? self::current()));
    }

    /**
     * Whether a package includes a feature. An unknown feature or package
     * includes nothing.
     */
    public static function allows(string $feature, ?string $plan = null): bool
    {
        return in_array($plan ?? self::current(), self::FEATURES[$feature] ?? [], true);
    }

    /**
     * The modes a package includes, each package adding one to the last.
     *
     * @return string[]
     */
    public static function modes(?string $plan = null): array
    {
        $plan = $plan ?? self::current();
        return array_values(array_filter(self::MODES, static fn(string $mode): bool => self::allows($mode, $plan)));
    }

    public static function allowsMode(string $mode, ?string $plan = null): bool
    {
        return in_array($mode, self::MODES, true) && self::allows($mode, $plan);
    }

    /**
     * The audio explainer (narration of learning units): "Build knowledge"
     * and Pay-As-You-Grow.
     */
    public static function allowsAudio(?string $plan = null): bool
    {
        return self::allows(self::FEATURE_AUDIO, $plan);
    }

    /**
     * Voice input (a question or an answer spoken instead of typed):
     * "Build knowledge" and Pay-As-You-Grow.
     */
    public static function allowsVoiceInput(?string $plan = null): bool
    {
        return self::allows(self::FEATURE_VOICE, $plan);
    }

    /**
     * Agent mode, the AI-driven interaction type (interactive HTML answers
     * and units): "Build knowledge" and Pay-As-You-Grow.
     */
    public static function allowsAiDriven(?string $plan = null): bool
    {
        return self::allows(self::FEATURE_AGENT, $plan);
    }

    /**
     * The interaction type that may actually be sent for a wanted one: the
     * package decides, not the object's setting.
     */
    public static function interactionType(string $wanted, ?string $plan = null): string
    {
        return ($wanted === self::INTERACTION_AI && self::allowsAiDriven($plan)) ? self::INTERACTION_AI : self::INTERACTION_TRAINER;
    }

    public static function isPayAsYouGrow(): bool
    {
        return self::current() === self::PAYG;
    }

    /**
     * AI interactions the package includes per calendar month; none for
     * Pay-As-You-Grow.
     */
    public static function includedCredits(): int
    {
        if (self::isPayAsYouGrow()) {
            return 0;
        }
        $value = Config::get('plan_credits');
        return ($value === null || $value === '') ? self::DEFAULT_CREDITS : max(0, (int) $value);
    }

    /**
     * Price of one AI interaction beyond the included ones (or of every
     * interaction on Pay-As-You-Grow).
     */
    public static function pricePerRequest(): float
    {
        $value = Config::get('plan_price');
        return ($value === null || $value === '') ? self::DEFAULT_PRICE : max(0.0, (float) str_replace(',', '.', $value));
    }

    public static function currency(): string
    {
        $value = trim((string) Config::get('plan_currency', ''));
        return $value !== '' ? $value : self::DEFAULT_CURRENCY;
    }

    /**
     * Who bills the AI interactions of this installation: the partner,
     * unless the customer is identified by a License-Key - those customers
     * are billed by Tarsus directly.
     */
    public static function billedBy(): string
    {
        return Config::usesLicenseKey() ? self::BILLED_BY_TARSUS : self::BILLED_BY_PARTNER;
    }

    /**
     * Money formatted for the interface language.
     */
    public static function money(float $amount): string
    {
        $decimal = Ilias::userLanguage() === 'de' ? ',' : '.';
        $thousands = Ilias::userLanguage() === 'de' ? '.' : ',';
        return number_format($amount, 2, $decimal, $thousands) . ' ' . self::currency();
    }
}
