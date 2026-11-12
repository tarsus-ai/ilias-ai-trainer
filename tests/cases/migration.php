<?php

/**
 * Updating an installation of version 1.1.0: its ledger held an estimate
 * of six AI credits per request and nothing for indexing.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Support\Budget;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Plan;

/**
 * A world as version 1.1.0 left it: database steps 1 to 8 only.
 */
function world110(): void
{
    World::$db = new FakeDb();
    Config::reset();
    World::install(1, 8);
}

test('step 9 brings the 1.1.0 ledger to AI interactions', function () {
    world110();
    $lastmonth = Budget::monthStart() - 86400;
    // What 1.1.0 wrote: six credits for every request, nothing for uploads.
    foreach ([['chat_ask', 6], ['practice_next', 6], ['practice_eval', 6], ['learn_next', 6], ['learn_check', 6],
        ['learn_audio', 6], ['track', 0], ['track', 0], ['chat_ask', 6]] as [$operation, $credits]) {
        World::ledger($operation, $credits);
    }
    World::ledger('chat_ask', 6, $lastmonth);
    World::ledger('track', 0, $lastmonth);
    same(42, Budget::localCredits(), 'before: seven requests at six credits');

    World::install(9, 9);

    $credits = array_map('intval', array_column(World::$db->rows('SELECT credits FROM rep_robj_xtar_use ORDER BY id'), 'credits'));
    same(array_fill(0, 11, 1), $credits, 'every old request and every old upload is one interaction');
    same(9, Budget::localCredits(), 'this month: seven requests and two uploads, one each');
    same(9, Budget::localRequests());
    same(9, Budget::used());
});

test('step 9 keeps the configuration and lets the warnings start afresh', function () {
    world110();
    World::configure([
        'plan' => Plan::PRACTICE, 'plan_credits' => '50000', 'plan_price' => '0.32', 'monthly_limit' => '60',
        'api_key' => 'k', 'brain_id' => 'b', 'base_url' => 'https://api.example.org', 'mockmode' => '0',
        'usage_notified' => json_encode([Budget::monthKey() => [50, 75]]),
        'usage_remote' => json_encode(['month' => Budget::monthKey(), 'credits' => 7, 'fetched' => time(), 'items' => []]),
    ]);
    for ($i = 0; $i < 8; $i++) {
        World::ledger('chat_ask', 6);
    }
    same(48, Budget::used(), 'before: 80 % of the limit by the old estimate');

    World::install(9, 9);

    same(8, Budget::used(), 'after: eight interactions');
    same(null, Config::fresh('usage_notified'), 'the record of sent warnings is gone');
    same(Plan::PRACTICE, Plan::current());
    same(50000, Plan::includedCredits());
    same(60, Budget::limit());
    same('k', Config::apiKey());
    same('b', Config::brainId());
    not(Config::usesLicenseKey(), 'an updated installation keeps identifying the customer by brain ID');
    same(7, Budget::remote()['credits'], 'the brain\'s figure is kept');

    // Warnings fire on the corrected figures: 8 of 60 is below half.
    Budget::checkThresholds();
    same(0, count(World::$mails));
});

test('step 9 changes nothing on a fresh installation and leaves new entries alone', function () {
    // World::reset() ran every step on an empty database.
    same([], World::ledgerRows());
    World::ledger('track', 3);
    World::ledger('practice_eval', 0);
    World::ledger('chat_ask', 1);
    same([['track', 3], ['practice_eval', 0], ['chat_ask', 1]], World::ledgerRows(), 'new entries keep their counts');
});

test('step 9 may run late: entries of the new counting are never touched', function () {
    world110();
    // An installation that ran the new code for a while before the version
    // was bumped holds old and new entries side by side.
    $rows = [
        ['chat_ask', 6, 1],        // 1.1.0: a request at six credits
        ['track', 0, 1],           // 1.1.0: an upload at nothing
        ['practice_eval', 6, 1],   // 1.1.0
        ['chat_ask', 1, 1],        // new: a question
        ['practice_eval', 0, 0],   // new: a free correction stays free
        ['learn_check', 0, 0],     // new
        ['track', 1, 1],           // new: an upload
        ['track', 2, 2],           // new: a three-hour recording
        ['track', 6, 6],           // new: a twelve-hour recording keeps its six blocks
        ['chat_ask', 2, 2],        // new: should voice input or "think longer" ever be sent
        ['chat_ask', 4, 4],
    ];
    foreach ($rows as [$operation, $credits]) {
        World::ledger($operation, $credits);
    }
    World::install(9, 9);
    $after = World::ledgerRows();
    foreach ($rows as $index => [$operation, $before, $expected]) {
        same([$operation, $expected], $after[$index], $operation . ' at ' . $before);
    }
});

test('the steps are numbered without gaps and the last one is 9', function () {
    same(range(1, 9), array_keys(World::steps()));
});

test('applying the steps twice is harmless', function () {
    World::ledger('chat_ask', 1);
    World::install();   // ILIAS never does this, but a step must not break on existing tables
    same([['chat_ask', 1]], World::ledgerRows());
    ok(World::$db->tableColumnExists('rep_robj_xtar_data', 'interaction'));
});

test('the version, the changelog and the database notes agree', function () {
    $changelog = (string) file_get_contents(PLUGIN_ROOT . '/CHANGELOG.md');
    preg_match_all('/^## \\[(\\d+\\.\\d+\\.\\d+)\\]/m', $changelog, $matches);
    $released = $matches[1][0];
    $unreleased = strpos($changelog, '## [Unreleased]') !== false
        ? substr($changelog, (int) strpos($changelog, '## [Unreleased]'), (int) strpos($changelog, '## [' . $released . ']') - (int) strpos($changelog, '## [Unreleased]'))
        : '';
    if (Ilias::pluginVersion() === $released) {
        // Not released yet: the step is announced under Unreleased, and the
        // release that carries it has to bump the version for ILIAS to run it.
        contains('Step 9', $unreleased, 'the unreleased notes announce the database step');
        contains('version', $unreleased, 'and say that it needs a version bump');
    } else {
        ok(version_compare(Ilias::pluginVersion(), $released, '>=') && strpos($changelog, '## [' . Ilias::pluginVersion() . ']') !== false,
            'a bumped version has its changelog section');
        contains('Step 9', $changelog);
    }
    contains('[' . $released . ']: https://github.com/tarsus-ai/ilias-ai-trainer/releases/tag/v' . $released, $changelog);
});
