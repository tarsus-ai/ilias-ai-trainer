<?php

/**
 * "Functions per package" of the partner agreement, cell by cell, and the
 * figures of the packages.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Support\Config;
use TarsusIlias\Support\Plan;

/**
 * The agreement's table. Columns: Ask questions, Test knowledge, Build
 * knowledge, Pay-As-You-Grow.
 */
const FUNCTIONS_PER_PACKAGE = [
    //                          ASK    PRACTICE LEARN  PAYG
    Plan::FEATURE_ASK   => [true,  true,  true,  true],   // Ask questions: the AI answers questions from the documents
    Plan::FEATURE_TEST  => [false, true,  true,  true],   // Test knowledge: the AI creates and evaluates quizzes
    Plan::FEATURE_BUILD => [false, false, true,  true],   // Build knowledge: the learning coach explains step by step
    Plan::FEATURE_AUDIO => [false, false, true,  true],   // Audio explainer in the learning coach
    Plan::FEATURE_VOICE => [false, false, true,  true],   // Voice input in the learning coach
    Plan::FEATURE_AGENT => [false, false, true,  true],   // Agent mode (AI-driven interaction type)
];

const PACKAGE_COLUMNS = [Plan::ASK, Plan::PRACTICE, Plan::LEARN, Plan::PAYG];

test('functions per package: every cell of the agreement table', function () {
    same(array_keys(FUNCTIONS_PER_PACKAGE), array_keys(Plan::FEATURES), 'the features are the rows of the table');
    foreach (FUNCTIONS_PER_PACKAGE as $feature => $row) {
        foreach (PACKAGE_COLUMNS as $column => $plan) {
            same($row[$column], Plan::allows($feature, $plan), $feature . ' in ' . $plan);
            // ... and for the configured package.
            World::plan($plan);
            same($row[$column], Plan::allows($feature), $feature . ' with ' . $plan . ' configured');
        }
    }
});

test('each package contains the one before it; Build knowledge and Pay-As-You-Grow have everything', function () {
    $count = static fn(string $plan): int => count(array_filter(
        array_keys(Plan::FEATURES),
        static fn(string $feature): bool => Plan::allows($feature, $plan)
    ));
    same([1, 2, 6, 6], array_map($count, PACKAGE_COLUMNS));
    foreach (array_keys(Plan::FEATURES) as $feature) {
        if (Plan::allows($feature, Plan::ASK)) {
            ok(Plan::allows($feature, Plan::PRACTICE), $feature . ': PRACTICE contains ASK');
        }
        if (Plan::allows($feature, Plan::PRACTICE)) {
            ok(Plan::allows($feature, Plan::LEARN), $feature . ': LEARN contains PRACTICE');
        }
        ok(Plan::allows($feature, Plan::LEARN), $feature . ' in Build knowledge');
        ok(Plan::allows($feature, Plan::PAYG), $feature . ' in Pay-As-You-Grow');
    }
});

test('modes follow the package', function () {
    same(['ask'], Plan::modes(Plan::ASK));
    same(['ask', 'test'], Plan::modes(Plan::PRACTICE));
    same(['ask', 'test', 'build'], Plan::modes(Plan::LEARN));
    same(['ask', 'test', 'build'], Plan::modes(Plan::PAYG));
    foreach (PACKAGE_COLUMNS as $plan) {
        World::plan($plan);
        same(Plan::modes($plan), Plan::modes(), $plan . ' configured');
        foreach (['ask', 'test', 'build'] as $mode) {
            same(in_array($mode, Plan::modes($plan), true), Plan::allowsMode($mode), $mode . ' with ' . $plan);
        }
    }
});

test('the named checks read the table', function () {
    foreach (PACKAGE_COLUMNS as $column => $plan) {
        same(FUNCTIONS_PER_PACKAGE[Plan::FEATURE_AUDIO][$column], Plan::allowsAudio($plan), 'audio explainer in ' . $plan);
        same(FUNCTIONS_PER_PACKAGE[Plan::FEATURE_VOICE][$column], Plan::allowsVoiceInput($plan), 'voice input in ' . $plan);
        same(FUNCTIONS_PER_PACKAGE[Plan::FEATURE_AGENT][$column], Plan::allowsAiDriven($plan), 'agent mode in ' . $plan);
    }
});

test('agent mode is only sent where the package includes it', function () {
    foreach (PACKAGE_COLUMNS as $plan) {
        World::plan($plan);
        $allowed = in_array($plan, [Plan::LEARN, Plan::PAYG], true);
        same($allowed ? Plan::INTERACTION_AI : Plan::INTERACTION_TRAINER, Plan::interactionType(Plan::INTERACTION_AI), $plan);
        same(Plan::INTERACTION_TRAINER, Plan::interactionType(Plan::INTERACTION_TRAINER), $plan);
        same(Plan::INTERACTION_TRAINER, Plan::interactionType('anything'), $plan);
    }
});

test('nothing is included in an unknown package, and an unknown feature is in none', function () {
    foreach (array_keys(Plan::FEATURES) as $feature) {
        not(Plan::allows($feature, 'enterprise'), $feature);
        not(Plan::allows($feature, ''), $feature);
    }
    foreach (PACKAGE_COLUMNS as $plan) {
        not(Plan::allows('teleportation', $plan));
        not(Plan::allowsMode('audio_explainer', $plan), 'a feature that is not a mode');
        not(Plan::allowsMode('', $plan));
    }
    same([], Plan::modes('enterprise'));
});

test('an unconfigured or invalid package setting means Build knowledge, and says so', function () {
    not(Plan::isConfigured());
    same(Plan::LEARN, Plan::current());
    World::configure(['plan' => 'gold']);
    not(Plan::isConfigured());
    same(Plan::LEARN, Plan::current());
    World::plan(Plan::ASK);
    ok(Plan::isConfigured());
    same(Plan::ASK, Plan::current());
});

test('packages include 100,000 AI interactions a month; Pay-As-You-Grow none', function () {
    same(100000, Plan::DEFAULT_CREDITS);
    foreach ([Plan::ASK, Plan::PRACTICE, Plan::LEARN] as $plan) {
        World::plan($plan);
        same(100000, Plan::includedCredits(), $plan);
        not(Plan::isPayAsYouGrow(), $plan);
    }
    World::plan(Plan::PAYG);
    same(0, Plan::includedCredits());
    ok(Plan::isPayAsYouGrow());
    // A custom agreement changes the amount - but never for Pay-As-You-Grow.
    World::configure(['plan_credits' => '250000']);
    same(0, Plan::includedCredits());
    World::plan(Plan::PRACTICE);
    same(250000, Plan::includedCredits());
    World::configure(['plan_credits' => '-5']);
    same(0, Plan::includedCredits(), 'never negative');
    World::configure(['plan_credits' => '']);
    same(100000, Plan::includedCredits(), 'empty means the default');
});

test('further AI interactions cost a flat 0.32 EUR each unless agreed otherwise', function () {
    same(0.32, Plan::pricePerRequest());
    same('EUR', Plan::currency());
    World::configure(['plan_price' => '0,25', 'plan_currency' => 'CHF']);
    same(0.25, Plan::pricePerRequest());
    same('CHF', Plan::currency());
    World::configure(['plan_price' => '-1']);
    same(0.0, Plan::pricePerRequest(), 'never negative');
    World::configure(['plan_price' => '', 'plan_currency' => '  ']);
    same(0.32, Plan::pricePerRequest());
    same('EUR', Plan::currency());
});

test('money is written the way the interface language writes it', function () {
    same('1,234.50 EUR', Plan::money(1234.5));
    World::$user->language = 'de';
    same('1.234,50 EUR', Plan::money(1234.5));
});

test('the partner is billed, unless the customer is identified by a License-Key', function () {
    same(Plan::BILLED_BY_PARTNER, Plan::billedBy());
    World::configure(['credential' => 'license', 'license_key' => 'LK-1']);
    same(Plan::BILLED_BY_TARSUS, Plan::billedBy());
    World::configure(['credential' => 'brain']);
    same(Plan::BILLED_BY_PARTNER, Plan::billedBy());
    // The License-Key lying around unused changes nothing.
    ok(Config::licenseKey() !== '');
    same(Plan::BILLED_BY_PARTNER, Plan::billedBy());
});

test('every package has a name in both languages', function () {
    foreach (['en', 'de'] as $language) {
        World::$user->language = $language;
        foreach (Plan::ALL as $plan) {
            $label = Plan::label($plan);
            ok($label !== '' && $label[0] !== '-', $plan . ' in ' . $language . ': ' . $label);
        }
    }
});
