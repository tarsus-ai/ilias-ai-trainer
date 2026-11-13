<?php

namespace TarsusIlias\Support;

/**
 * The customer's Tarsus package and what it includes.
 *
 * Four packages exist, and each contains the one before it: Ask Question
 * (ASK) answers questions from the documents; Knowledge Test (PRACTICE)
 * adds adaptive quizzes; Build Knowledge (LEARN) adds the full coach with
 * the audio explainer, voice input and the agent (AI-driven) mode; Pay as
 * you grow (PAYG) has access to everything. The matrix in {@see FEATURES}
 * is the table "Funktionen je Paket" of the Tarsus partner agreement
 * (tarsus.ai/impressum/partnervereinbarung, 12 November 2026); the partner
 * has to enforce the booked package in the integration, so everything the
 * plugin offers goes through {@see allows()}.
 *
 * The subscription packages include a monthly amount of AI interactions
 * (100,000 by default) that does not carry over; interactions beyond it
 * are charged per interaction (0.32 EUR flat for partners). Pay as you
 * grow includes nothing: every interaction is charged. Custom agreements
 * change the figures, so the partner sets them in the plugin configuration.
 * A customer with a Tarsus license key is billed by Tarsus directly.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Plan
{
    /** Build Knowledge subscription: every feature. */
    public const LEARN = 'learn';
    /** Knowledge Test subscription: Ask and Test, trainer-driven only. */
    public const PRACTICE = 'practice';
    /** Ask Question subscription: Ask only, trainer-driven only. */
    public const ASK = 'ask';
    /** Pay as you grow: every feature, every interaction charged. */
    public const PAYG = 'payg';

    public const ALL = [self::LEARN, self::PRACTICE, self::ASK, self::PAYG];

    /** Ask a question: the AI answers from the documents. */
    public const FEATURE_ASK = 'ask';
    /** Test knowledge: the AI creates and marks quizzes. */
    public const FEATURE_TEST = 'test';
    /** Build knowledge: the coach explains step by step. */
    public const FEATURE_BUILD = 'build';
    /** The audio explainer of the coach (Generate Stack Audio Explainer). */
    public const FEATURE_AUDIO_EXPLAINER = 'audio_explainer';
    /** Questions or answers spoken into the coach (audio_content). */
    public const FEATURE_VOICE_INPUT = 'voice_input';
    /** Agent mode: the AI-driven interaction type. */
    public const FEATURE_AI_DRIVEN = 'ai_driven';

    /** Every feature a package can include, in the agreement's order. */
    public const ALL_FEATURES = [
        self::FEATURE_ASK,
        self::FEATURE_TEST,
        self::FEATURE_BUILD,
        self::FEATURE_AUDIO_EXPLAINER,
        self::FEATURE_VOICE_INPUT,
        self::FEATURE_AI_DRIVEN,
    ];

    /**
     * "Funktionen je Paket": what each package includes. Standard narration
     * of text (Audio Generation) is not part of the table and stays
     * available everywhere; the plugin does not use it.
     *
     * @var array<string, string[]>
     */
    public const FEATURES = [
        self::ASK => [self::FEATURE_ASK],
        self::PRACTICE => [self::FEATURE_ASK, self::FEATURE_TEST],
        self::LEARN => self::ALL_FEATURES,
        self::PAYG => self::ALL_FEATURES,
    ];

    /** AI interactions a subscription includes per month unless agreed otherwise. */
    public const DEFAULT_CREDITS = 100000;
    /** Price of one interaction beyond the included amount unless agreed otherwise. */
    public const DEFAULT_PRICE = 0.32;
    public const DEFAULT_CURRENCY = 'EUR';

    /** The teacher writes the didactics; the brain answers in text and pictures. */
    public const INTERACTION_TRAINER = 'trainer-driven';
    /** The brain may answer with interactive HTML pages (animations, simulations). */
    public const INTERACTION_AI = 'ai-driven';
    public const INTERACTIONS = [self::INTERACTION_TRAINER, self::INTERACTION_AI];

    /**
     * The configured package; Build Knowledge until the partner sets another.
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

    /**
     * Whether the package still has to be set although a live connection
     * exists: the agreement asks the partner to enforce the booked
     * package, and until it is set every feature is reachable.
     */
    public static function needsConfiguration(): bool
    {
        return !self::isConfigured() && !Config::mockMode() && Config::apiKey() !== '';
    }

    public static function label(?string $plan = null): string
    {
        return Ilias::txt('plan_' . ($plan ?? self::current()));
    }

    /**
     * The features a package includes.
     *
     * @return string[]
     */
    public static function features(?string $plan = null): array
    {
        return self::FEATURES[$plan ?? self::current()] ?? self::FEATURES[self::LEARN];
    }

    /**
     * Whether a package includes a feature. Unknown features are never
     * included: a typo cannot open a door.
     */
    public static function allows(string $feature, ?string $plan = null): bool
    {
        return in_array($feature, self::features($plan), true);
    }

    /**
     * The modes a package includes, in switcher order.
     *
     * @return string[]
     */
    public static function modes(?string $plan = null): array
    {
        return array_values(array_filter(
            ['ask', 'test', 'build'],
            static fn(string $mode): bool => self::allows($mode, $plan)
        ));
    }

    public static function allowsMode(string $mode, ?string $plan = null): bool
    {
        return in_array($mode, ['ask', 'test', 'build'], true) && self::allows($mode, $plan);
    }

    /**
     * The audio explainer of learning units: Build Knowledge and Pay as
     * you grow.
     */
    public static function allowsAudio(?string $plan = null): bool
    {
        return self::allows(self::FEATURE_AUDIO_EXPLAINER, $plan);
    }

    /**
     * Spoken questions and answers: Build Knowledge and Pay as you grow.
     */
    public static function allowsVoiceInput(?string $plan = null): bool
    {
        return self::allows(self::FEATURE_VOICE_INPUT, $plan);
    }

    /**
     * AI-driven interaction (interactive HTML answers and units): Build
     * Knowledge and Pay as you grow.
     */
    public static function allowsAiDriven(?string $plan = null): bool
    {
        return self::allows(self::FEATURE_AI_DRIVEN, $plan);
    }

    /**
     * The interaction type that may actually be sent for a wanted one: the
     * package decides, not the object's setting.
     */
    public static function interactionType(string $wanted): string
    {
        return ($wanted === self::INTERACTION_AI && self::allowsAiDriven()) ? self::INTERACTION_AI : self::INTERACTION_TRAINER;
    }

    public static function isPayAsYouGrow(): bool
    {
        return self::current() === self::PAYG;
    }

    /**
     * Whether Tarsus bills this customer directly: an existing Tarsus
     * customer carries a license key, and the partner's prices and cost
     * estimates do not apply.
     */
    public static function billedByTarsus(): bool
    {
        return Config::licenseKey() !== '';
    }

    /**
     * AI interactions the package includes per calendar month; none for
     * Pay as you grow.
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
     * Price of one interaction beyond the included amount (or of every
     * interaction on Pay as you grow).
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
     * Money formatted for the interface language.
     */
    public static function money(float $amount): string
    {
        $decimal = Ilias::userLanguage() === 'de' ? ',' : '.';
        $thousands = Ilias::userLanguage() === 'de' ? '.' : ',';
        return number_format($amount, 2, $decimal, $thousands) . ' ' . self::currency();
    }
}
