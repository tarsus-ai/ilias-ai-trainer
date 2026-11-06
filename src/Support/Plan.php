<?php

namespace TarsusIlias\Support;

/**
 * The customer's Tarsus subscription and what it includes.
 *
 * Four plans exist. Build Knowledge (LEARN) and Pay as you grow include
 * everything; Knowledge Test (PRACTICE) has no Build knowledge, no
 * narration and no AI-driven interaction; Ask Question (ASK) additionally
 * has no Test knowledge. The subscription plans include a monthly amount
 * of AI credits (100,000 by default) that does not carry over; requests
 * beyond it are charged per request (0.32 EUR by default). Pay as you grow
 * includes no credits: every request is charged. Custom agreements change
 * the figures, so lern.link sets them in the plugin configuration.
 *
 * @author  lern.link GmbH
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
    /** Pay as you grow: every feature, every request charged. */
    public const PAYG = 'payg';

    public const ALL = [self::LEARN, self::PRACTICE, self::ASK, self::PAYG];

    /** AI credits a subscription includes per month unless agreed otherwise. */
    public const DEFAULT_CREDITS = 100000;
    /** Price of one request beyond the included credits unless agreed otherwise. */
    public const DEFAULT_PRICE = 0.32;
    public const DEFAULT_CURRENCY = 'EUR';

    /** The teacher writes the didactics; the brain answers in text and pictures. */
    public const INTERACTION_TRAINER = 'trainer-driven';
    /** The brain may answer with interactive HTML pages (animations, simulations). */
    public const INTERACTION_AI = 'ai-driven';
    public const INTERACTIONS = [self::INTERACTION_TRAINER, self::INTERACTION_AI];

    /**
     * The configured plan; Build Knowledge until lern.link sets another.
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
     * The modes a plan includes, in switcher order.
     *
     * @return string[]
     */
    public static function modes(?string $plan = null): array
    {
        switch ($plan ?? self::current()) {
            case self::ASK:
                return ['ask'];
            case self::PRACTICE:
                return ['ask', 'test'];
            default:
                return ['ask', 'test', 'build'];
        }
    }

    public static function allowsMode(string $mode, ?string $plan = null): bool
    {
        return in_array($mode, self::modes($plan), true);
    }

    /**
     * Narration of learning units: Build Knowledge and Pay as you grow.
     */
    public static function allowsAudio(?string $plan = null): bool
    {
        $plan = $plan ?? self::current();
        return $plan === self::LEARN || $plan === self::PAYG;
    }

    /**
     * AI-driven interaction (interactive HTML answers and units): Build
     * Knowledge and Pay as you grow.
     */
    public static function allowsAiDriven(?string $plan = null): bool
    {
        $plan = $plan ?? self::current();
        return $plan === self::LEARN || $plan === self::PAYG;
    }

    /**
     * The interaction type that may actually be sent for a wanted one: the
     * plan decides, not the object's setting.
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
     * AI credits the plan includes per calendar month; none for Pay as you grow.
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
     * Price of one request beyond the included credits (or of every
     * request on Pay as you grow).
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
