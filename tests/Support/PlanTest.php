<?php

declare(strict_types=1);

namespace TarsusIlias\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use TarsusIlias\Support\Plan;
use TarsusIlias\Tests\PluginTestCase;

/**
 * The package matrix of the partner agreement ("Funktionen je Paket"):
 * a customer reaches only the functions of the booked package, Pay as you
 * grow has access to everything, each package contains the one before it.
 */
final class PlanTest extends PluginTestCase
{
    /**
     * The agreement's table, row by row: feature => [Ask, Test, Build, PAYG].
     *
     * @return array<string, array{string, bool, bool, bool, bool}>
     */
    public static function agreementTable(): array
    {
        return [
            'Fragen stellen' => [Plan::FEATURE_ASK, true, true, true, true],
            'Wissen testen' => [Plan::FEATURE_TEST, false, true, true, true],
            'Wissen aufbauen' => [Plan::FEATURE_BUILD, false, false, true, true],
            'Audio-Erklaerer' => [Plan::FEATURE_AUDIO_EXPLAINER, false, false, true, true],
            'Spracheingabe' => [Plan::FEATURE_VOICE_INPUT, false, false, true, true],
            'Agent-Modus' => [Plan::FEATURE_AI_DRIVEN, false, false, true, true],
        ];
    }

    #[DataProvider('agreementTable')]
    public function testFeatureMatrixFollowsTheAgreement(string $feature, bool $ask, bool $test, bool $build, bool $payg): void
    {
        $this->assertSame($ask, Plan::allows($feature, Plan::ASK), 'Fragen stellen / ' . $feature);
        $this->assertSame($test, Plan::allows($feature, Plan::PRACTICE), 'Wissen testen / ' . $feature);
        $this->assertSame($build, Plan::allows($feature, Plan::LEARN), 'Wissen aufbauen / ' . $feature);
        $this->assertSame($payg, Plan::allows($feature, Plan::PAYG), 'Pay-As-You-Grow / ' . $feature);
    }

    public function testEveryPackageContainsTheOneBefore(): void
    {
        $ask = Plan::features(Plan::ASK);
        $test = Plan::features(Plan::PRACTICE);
        $build = Plan::features(Plan::LEARN);
        $this->assertSame([], array_diff($ask, $test), 'Knowledge Test contains Ask Question');
        $this->assertSame([], array_diff($test, $build), 'Build Knowledge contains Knowledge Test');
        $this->assertSame(Plan::ALL_FEATURES, Plan::features(Plan::PAYG), 'Pay as you grow has access to everything');
        $this->assertSame(Plan::ALL_FEATURES, $build, 'Build Knowledge has access to everything');
    }

    public function testModesDeriveFromTheMatrix(): void
    {
        $this->assertSame(['ask'], Plan::modes(Plan::ASK));
        $this->assertSame(['ask', 'test'], Plan::modes(Plan::PRACTICE));
        $this->assertSame(['ask', 'test', 'build'], Plan::modes(Plan::LEARN));
        $this->assertSame(['ask', 'test', 'build'], Plan::modes(Plan::PAYG));
        $this->assertFalse(Plan::allowsMode('build', Plan::PRACTICE), 'who pays for Wissen testen must not reach Wissen aufbauen');
        $this->assertFalse(Plan::allowsMode('test', Plan::ASK), 'who pays for Fragen stellen must not reach Wissen testen');
    }

    public function testNamedAccessorsMatchTheMatrix(): void
    {
        foreach (Plan::ALL as $plan) {
            $this->assertSame(Plan::allows(Plan::FEATURE_AUDIO_EXPLAINER, $plan), Plan::allowsAudio($plan), $plan);
            $this->assertSame(Plan::allows(Plan::FEATURE_VOICE_INPUT, $plan), Plan::allowsVoiceInput($plan), $plan);
            $this->assertSame(Plan::allows(Plan::FEATURE_AI_DRIVEN, $plan), Plan::allowsAiDriven($plan), $plan);
        }
    }

    public function testUnknownFeaturesAndPackagesOpenNoDoor(): void
    {
        $this->assertFalse(Plan::allows('standard_narration', Plan::PAYG), 'a feature outside the table is not granted');
        $this->assertFalse(Plan::allowsMode('ai_driven', Plan::PAYG), 'allowsMode answers for modes only');
        $this->assertSame(Plan::FEATURES[Plan::LEARN], Plan::features('enterprise'), 'an unknown package behaves like the default');
    }

    public function testTheConfiguredPackageDecidesTheInteractionType(): void
    {
        $this->configure(['plan' => Plan::PRACTICE]);
        $this->assertSame(Plan::INTERACTION_TRAINER, Plan::interactionType(Plan::INTERACTION_AI), 'Knowledge Test never sends ai-driven');
        $this->configure(['plan' => Plan::LEARN]);
        $this->assertSame(Plan::INTERACTION_AI, Plan::interactionType(Plan::INTERACTION_AI));
        $this->assertSame(Plan::INTERACTION_TRAINER, Plan::interactionType('anything else'));
    }

    public function testCurrentPackageAndItsFallback(): void
    {
        $this->assertSame(Plan::LEARN, Plan::current(), 'Build Knowledge until the partner sets another');
        $this->assertFalse(Plan::isConfigured());
        $this->configure(['plan' => 'gold']);
        $this->assertSame(Plan::LEARN, Plan::current(), 'an unknown stored value falls back');
        $this->assertFalse(Plan::isConfigured());
        $this->configure(['plan' => Plan::ASK]);
        $this->assertSame(Plan::ASK, Plan::current());
        $this->assertTrue(Plan::isConfigured());
    }

    public function testAnUnsetPackageOnALiveConnectionNeedsConfiguration(): void
    {
        $this->configure(['mockmode' => '1']);
        $this->assertFalse(Plan::needsConfiguration(), 'mock mode reaches no brain');
        $this->configure(['mockmode' => '0', 'api_key' => '']);
        $this->assertFalse(Plan::needsConfiguration(), 'no key, no live connection');
        $this->configure(['api_key' => 'k']);
        $this->assertTrue(Plan::needsConfiguration(), 'a live connection without a package must be flagged');
        $this->configure(['plan' => Plan::PRACTICE]);
        $this->assertFalse(Plan::needsConfiguration());
    }

    public function testIncludedAmountAndPrice(): void
    {
        $this->configure(['plan' => Plan::LEARN]);
        $this->assertSame(100000, Plan::includedCredits(), '100.000 KI-Interaktionen pro Monat');
        $this->assertSame(0.32, Plan::pricePerRequest(), '0,32 EUR je Interaktion');
        $this->assertSame('EUR', Plan::currency());
        $this->configure(['plan_credits' => '250000', 'plan_price' => '0,25', 'plan_currency' => 'CHF']);
        $this->assertSame(250000, Plan::includedCredits(), 'custom agreements change the figures');
        $this->assertSame(0.25, Plan::pricePerRequest(), 'a German decimal comma is understood');
        $this->assertSame('CHF', Plan::currency());
        $this->configure(['plan' => Plan::PAYG]);
        $this->assertSame(0, Plan::includedCredits(), 'Pay as you grow includes nothing');
        $this->assertSame(0.25, Plan::pricePerRequest(), 'and charges every interaction');
    }

    public function testALicenseKeyMeansTarsusBills(): void
    {
        $this->assertFalse(Plan::billedByTarsus());
        $this->configure(['license_key' => 'LK-1']);
        $this->assertTrue(Plan::billedByTarsus(), 'Kunden mit License-Key rechnet Tarsus ab, nicht der Partner');
        $this->configure(['license_key' => '   ']);
        $this->assertFalse(Plan::billedByTarsus(), 'blank is no key');
    }
}
