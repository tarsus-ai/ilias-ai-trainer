<?php

/**
 * The monthly limit and the cost estimate work on AI interactions: the
 * partner manages them and answers for every cost - except for customers
 * with a License-Key, whom Tarsus bills.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Support\Budget;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Plan;

test('the limit defaults to what the package includes', function () {
    foreach ([Plan::ASK, Plan::PRACTICE, Plan::LEARN] as $plan) {
        World::plan($plan);
        same(100000, Budget::limit(), $plan);
    }
    World::plan(Plan::PAYG);
    same(100000, Budget::limit(), 'Pay-As-You-Grow includes nothing, so the default amount bounds it');
    World::plan(Plan::LEARN);
    World::configure(['plan_credits' => '5000']);
    same(5000, Budget::limit());
    World::configure(['monthly_limit' => '300']);
    same(300, Budget::limit(), 'an explicit limit wins');
    World::configure(['monthly_limit' => '0']);
    same(0, Budget::limit());
    not(Budget::hasLimit(), '0 means no limit');
    not(Budget::exhausted());
    not(Budget::wouldExceed(1000000));
});

test('usage is the sum of this month\'s AI interactions', function () {
    World::ledger('chat_ask', 1);
    World::ledger('practice_eval', 0);
    World::ledger('track', 3);
    // Last month does not count.
    World::ledger('chat_ask', 1, Budget::monthStart() - 1);
    World::ledger('chat_ask', 1, Budget::monthStart() - 40 * 86400);
    same(4, Budget::localCredits());
    same(2, Budget::localRequests());
    same(4, Budget::used());
    same(99996, Budget::remaining());
    // The first second of the month counts.
    World::ledger('chat_ask', 1, Budget::monthStart());
    same(5, Budget::used());
});

test('once the limit is reached, counted requests are refused and free ones are not', function () {
    World::live();
    World::plan(Plan::LEARN);
    World::configure(['monthly_limit' => '3']);
    $api = World::learner();

    same('success', $api->ask('one')['status']);
    same('success', $api->practiceNext()['status']);
    same('success', $api->learnNext()['status']);
    same(3, Budget::used());
    ok(Budget::exhausted());

    TarsusServer::clear();
    foreach ([
        'a question' => $api->ask('four'),
        'a quiz question' => $api->practiceNext(),
        'a learning step' => $api->learnNext(),
        'the audio explainer' => $api->stackAudio('stack-1'),
        'a free answer' => $api->practiceCheck(['widgetid' => 'w-open-1', 'widgettype' => 'openQuestionWidget', 'answer' => 'By melting']),
    ] as $what => $result) {
        same('blocked', $result['status'], $what);
        contains('used up', $result['error'], $what);
    }
    same([], TarsusServer::requests('/api/t0/activity/generate'), 'no counted request left the server');
    same([], TarsusServer::requests('/evaluate/'), 'no evaluation left the server');

    // What costs nothing stays possible: the history and automatic correction.
    same('success', $api->feed(0)['status']);
    same('success', $api->practiceCheck(['widgetid' => 'w-sc-1', 'widgettype' => 'singleChoiceQuestionWidget', 'answer' => 'Welding'])['status']);
    same('success', $api->practiceCheck(['widgetid' => 'w-tf-1', 'widgettype' => 'trueFalseQuestionWidget', 'answer' => 'true'])['status']);
    same(3, Budget::used(), 'and it added nothing');

    // Raising the limit takes effect at once.
    World::configure(['monthly_limit' => '4']);
    same('success', $api->ask('four')['status']);
    same('blocked', $api->ask('five')['status']);
});

test('the limit counts interactions, not requests', function () {
    World::configure(['monthly_limit' => '10']);
    World::ledger('track', 9);        // one upload of an eighteen-hour recording
    same(1, Budget::localRequests());
    same(9, Budget::used());
    not(Budget::wouldExceed(1));
    ok(Budget::wouldExceed(2));
    World::ledger('practice_eval', 0);
    World::ledger('practice_eval', 0);
    same(9, Budget::used(), 'free calls never move the meter');
});

test('warnings go out at 50, 75, 90 and 100 percent, once each', function () {
    World::configure(['monthly_limit' => '20', 'notify_emails' => "owner@example.org, second@example.org"]);
    $api = World::learner();
    $ask = static function (int $times) use ($api): void {
        for ($i = 0; $i < $times; $i++) {
            same('success', $api->ask('q')['status']);
        }
    };

    $ask(9);
    same(0, count(World::$mails), 'below half: silence');
    same(null, Budget::banner());

    $ask(1);    // 10 of 20
    same(1, count(World::$mails));
    same(['owner@example.org', 'second@example.org'], World::$mails[0]['to']);
    contains('50 % of the monthly AI interactions used', World::$mails[0]['subject']);
    contains('10 of 20', World::$mails[0]['body']);
    contains('Package & AI interactions', World::$mails[0]['body']);
    same('info', Budget::banner()['type']);
    contains('50 % of this month\'s AI interactions are used (10 of 20)', Budget::banner()['text']);

    $ask(4);    // 14: still below 75
    same(1, count(World::$mails));
    $ask(1);    // 15 of 20
    same(2, count(World::$mails));
    contains('75 %', World::$mails[1]['subject']);
    same('warning', Budget::banner()['type']);
    $ask(3);    // 18 of 20
    same(3, count(World::$mails));
    contains('90 %', World::$mails[2]['subject']);
    $ask(2);    // 20 of 20
    same(4, count(World::$mails));
    contains('are used up', World::$mails[3]['subject']);
    same('failure', Budget::banner()['type']);
    contains('used up (20 of 20)', Budget::banner()['text']);

    same('blocked', $api->ask('q')['status']);
    same(4, count(World::$mails), 'no mail twice');
});

test('free calls and failed calls raise no warning', function () {
    World::configure(['monthly_limit' => '2']);
    World::ledger('chat_ask', 0);
    (new \TarsusIlias\Support\UsageMeter())->record(World::COURSE, World::OBJECT, 6, 'practice_eval', 0);
    same(0, count(World::$mails));
    (new \TarsusIlias\Support\UsageMeter())->record(World::COURSE, World::OBJECT, 6, 'chat_ask', 1);
    same(1, count(World::$mails), '1 of 2 is half');
});

test('without recipients the installation\'s administrator is warned', function () {
    World::configure(['monthly_limit' => '2']);
    World::ledger('chat_ask', 1);
    Budget::checkThresholds();
    same(['admin@example.org'], World::$mails[0]['to']);
});

test('the brain\'s own figure counts when it is the larger one', function () {
    World::live();
    World::configure(['monthly_limit' => '30']);
    World::ledger('chat_ask', 5);
    same(5, Budget::used());

    // The fake API reports 27 for the brain this month (6 + 21 + 0).
    $remote = Budget::refreshRemote(true);
    same(27, $remote['credits']);
    same(27, Budget::used(), 'other systems on the same brain are included');
    same('critical', Budget::level());
    same(3, Budget::remaining());
    ok(Budget::wouldExceed(4));
    not(Budget::wouldExceed(3));

    World::ledger('chat_ask', 40);
    same(45, Budget::used(), 'and the local ledger when that is larger');
    same(['answeredQuery', 'questionGeneration', 'indexedText'], array_column($remote['items'], 'key'));
});

test('an unreachable usage endpoint keeps the last figure', function () {
    World::live();
    same(27, Budget::refreshRemote(true)['credits']);
    TarsusServer::arrange(['fail' => ['/api/v1/brain/awareness/get/usage' => ['status' => 500, 'body' => 'down']]]);
    same(27, Budget::refreshRemote(true)['credits']);
    same(27, Budget::used());
    ok(count(World::$log) > 0, 'the failure is logged');
});

test('the usage endpoint is asked with the customer\'s credential and costs nothing', function () {
    World::live();
    World::configure(['credential' => 'license', 'license_key' => 'LK-7']);
    Budget::refreshRemote(true);
    $request = TarsusServer::only('get/usage');
    same('LK-7', $request['fields']['license_key']);
    not(isset($request['fields']['brain_id']));
    same((string) Budget::monthStart(), $request['fields']['start_timestamp']);
    same([], World::ledgerRows());
});

test('overage: interactions beyond the package at the flat price', function () {
    World::plan(Plan::PRACTICE);
    World::configure(['plan_credits' => '10']);
    World::ledger('chat_ask', 1);
    same(['requests' => 1, 'interactions' => 1, 'included' => 10, 'charged' => 0, 'cost' => 0.0, 'billedby' => 'partner'], Budget::overage());

    World::ledger('track', 9);
    same(0, Budget::overage()['charged'], 'exactly the included amount');
    World::ledger('chat_ask', 1);
    World::ledger('chat_ask', 1);
    World::ledger('practice_eval', 0);
    $overage = Budget::overage();
    same(12, $overage['interactions']);
    same(2, $overage['charged']);
    same(0.64, $overage['cost'], '2 × 0.32 EUR');
    same(4, $overage['requests'], 'the upload of a long recording is one request');
});

test('overage counts interactions, not requests: a long recording is charged by its blocks', function () {
    World::plan(Plan::LEARN);
    World::configure(['plan_credits' => '2']);
    World::ledger('track', 5);   // one request, five two-hour blocks
    $overage = Budget::overage();
    same(1, $overage['requests']);
    same(3, $overage['charged']);
    same(0.96, $overage['cost']);
});

test('on Pay-As-You-Grow every interaction is charged', function () {
    World::plan(Plan::PAYG);
    same(0, Budget::overage()['charged']);
    World::ledger('chat_ask', 1);
    World::ledger('learn_next', 1);
    World::ledger('practice_eval', 0);
    World::ledger('track', 2);
    $overage = Budget::overage();
    same(0, $overage['included']);
    same(4, $overage['charged']);
    same(1.28, $overage['cost'], '4 × 0.32 EUR');
    World::configure(['plan_price' => '0.50']);
    same(2.0, Budget::overage()['cost'], 'a custom price');
});

test('the default figures of the agreement: 100,000 included, 0.32 EUR beyond', function () {
    World::plan(Plan::ASK);
    World::ledger('chat_ask', 100000);
    same(0, Budget::overage()['charged']);
    World::ledger('chat_ask', 250);
    $overage = Budget::overage();
    same(250, $overage['charged']);
    same(80.0, $overage['cost'], '250 × 0.32 EUR');
});

test('a License-Key customer is billed by Tarsus: no cost at the partner\'s price', function () {
    World::plan(Plan::PAYG);
    World::ledger('chat_ask', 5);
    same(1.6, Budget::overage()['cost']);
    same('partner', Budget::overage()['billedby']);

    World::configure(['credential' => 'license', 'license_key' => 'LK-1']);
    $overage = Budget::overage();
    same('tarsus', $overage['billedby']);
    same(0.0, $overage['cost']);
    same(5, $overage['charged'], 'the usage itself is still reported');
    same(5, $overage['interactions']);
});

test('the limit and its warnings stay in force for a License-Key customer', function () {
    World::configure(['credential' => 'license', 'license_key' => 'LK-1', 'monthly_limit' => '2']);
    $api = World::learner();
    same('success', $api->ask('one')['status']);
    same('success', $api->ask('two')['status']);
    same('blocked', $api->ask('three')['status']);
    same(2, count(World::$mails), 'half and full');
});

test('the brain figure of another month is ignored', function () {
    World::configure(['usage_remote' => json_encode(['month' => '2001-01', 'credits' => 99999, 'fetched' => time(), 'items' => []])]);
    same(null, Budget::remote());
    same(0, Budget::used());
    World::configure(['usage_remote' => json_encode(['month' => Budget::monthKey(), 'credits' => 12, 'fetched' => time(), 'items' => []])]);
    same(12, Budget::used());
    World::configure(['usage_remote' => 'garbage']);
    same(null, Budget::remote());
});

test('the calendar month runs from the first midnight to the next', function () {
    $noon = mktime(12, 0, 0, 2, 15, 2026);
    same(mktime(0, 0, 0, 2, 1, 2026), Budget::monthStart($noon));
    same(mktime(0, 0, 0, 3, 1, 2026), Budget::nextMonthStart($noon));
    same('2026-02', Budget::monthKey($noon));
    $silvester = mktime(23, 59, 59, 12, 31, 2026);
    same(mktime(0, 0, 0, 12, 1, 2026), Budget::monthStart($silvester));
    same(mktime(0, 0, 0, 1, 1, 2027), Budget::nextMonthStart($silvester));
    same(mktime(0, 0, 0, 1, 1, 2027), Budget::monthStart(mktime(0, 0, 0, 1, 1, 2027)));
});

test('severity follows the share of the limit used', function () {
    World::configure(['monthly_limit' => '100']);
    $levels = [0 => 'ok', 49 => 'ok', 50 => 'notice', 74 => 'notice', 75 => 'warning', 89 => 'warning', 90 => 'critical', 99 => 'critical', 100 => 'exhausted', 250 => 'exhausted'];
    $used = 0;
    foreach ($levels as $target => $level) {
        World::ledger('chat_ask', $target - $used);
        $used = $target;
        same($level, Budget::level(), $target . ' of 100');
    }
    same(250.0, Budget::percent());
    same(0, Budget::remaining());
});

test('lowering the limit below what is used blocks at once and warns', function () {
    World::ledger('chat_ask', 50);
    $api = World::learner();
    same('success', $api->ask('q')['status']);
    Config::set('monthly_limit', '40');
    Budget::resetWarnings();
    Budget::checkThresholds();
    same(1, count(World::$mails));
    contains('are used up', World::$mails[0]['subject']);
    same('blocked', $api->ask('q')['status']);
});
