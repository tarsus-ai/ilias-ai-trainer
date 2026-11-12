<?php

/**
 * The ledger holds, for every learner call, the AI interactions the
 * agreement counts for it.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Support\Budget;
use TarsusIlias\Support\Plan;
use TarsusIlias\Support\UsageMeter;

test('live: every learner call is entered with its count', function () {
    World::live();
    World::plan(Plan::LEARN);
    $api = World::learner();

    same('success', $api->ask('What is welding?')['status']);
    same('success', $api->practiceNext()['status']);
    same('success', $api->practiceCheck(['widgetid' => 'w-sc-1', 'widgettype' => 'singleChoiceQuestionWidget', 'answer' => 'Welding'])['status']);
    same('success', $api->practiceCheck(['widgetid' => 'w-tf-1', 'widgettype' => 'trueFalseQuestionWidget', 'answer' => 'true'])['status']);
    same('success', $api->practiceCheck(['widgetid' => 'w-open-1', 'widgettype' => 'openQuestionWidget', 'answer' => 'By melting'])['status']);
    same('success', $api->practiceCheck(['widgetid' => 'w-cm-1', 'widgettype' => 'crossMultiplicationQuestionWidget',
        'answers' => [['variable' => 't2', 'answer' => '9']]])['status']);
    same('success', $api->practiceCheck(['widgetid' => 'w-tc-1', 'widgettype' => 'tradeCostingQuestionWidget',
        'table' => [['position' => 'List price', 'rate' => '', 'amount' => '1000']], 'columns' => ['Position', 'Rate', 'Amount']])['status']);
    same('success', $api->learnNext()['status']);
    same('success', $api->practiceCheck(['widgetid' => 's-q', 'widgettype' => 'singleChoiceQuestionWidget', 'answer' => 'Welding', 'state' => 'learn'])['status']);
    same('success', $api->practiceCheck(['widgetid' => 's-o', 'widgettype' => 'openQuestionWidget', 'answer' => 'An arc', 'state' => 'learn'])['status']);
    same('success', $api->stackAudio('stack-1')['status']);
    same('success', $api->feed(0)['status']);

    same([
        ['chat_ask', 1],        // a question answered
        ['practice_next', 1],   // quiz questions created
        ['practice_eval', 0],   // single choice: corrected automatically
        ['practice_eval', 0],   // true/false: corrected automatically
        ['practice_eval', 1],   // free answer evaluated, with feedback
        ['practice_eval', 1],   // calculation evaluated (rule of three)
        ['practice_eval', 1],   // calculation evaluated (costing)
        ['learn_next', 1],      // next learning step
        ['learn_check', 0],     // check question, single choice
        ['learn_check', 1],     // check question, free answer
        ['learn_audio', 1],     // audio explainer
        // reading the history: no entry
    ], World::ledgerRows());
    same(8, Budget::localCredits());
    same(8, Budget::localRequests(), 'requests that counted');
    same(8, Budget::used());
});

test('mock mode: the demo counts exactly as the live system would', function () {
    World::plan(Plan::PAYG);
    $all = array_keys(\TarsusIlias\Support\Interactions::EVALUATE);
    $api = World::learner(['questiontypes' => [1 => $all, 2 => $all, 3 => $all]]);

    same('success', $api->ask('What is welding?')['status']);
    $expected = [['chat_ask', 1]];
    // Three rounds bring all five question types.
    for ($round = 0; $round < 3; $round++) {
        $next = $api->practiceNext();
        same('success', $next['status']);
        $expected[] = ['practice_next', 1];
        foreach ($next['widgets'] as $widget) {
            $payload = ['widgetid' => $widget['id'], 'widgettype' => $widget['type'], 'answer' => 'Mars', 'columns' => $widget['columns']];
            foreach ($widget['variables'] as $variable) {
                $payload['answers'][] = ['variable' => $variable, 'answer' => '1'];
            }
            foreach ($widget['table'] as $row) {
                $payload['table'][] = ['position' => $row['position'], 'rate' => $row['askrate'] ? '1' : $row['rate'], 'amount' => $row['askamount'] ? '1' : $row['amount']];
            }
            same('success', $api->practiceCheck($payload)['status'], $widget['type']);
            $free = in_array($widget['type'], ['singleChoiceQuestionWidget', 'trueFalseQuestionWidget'], true);
            $expected[] = ['practice_eval', $free ? 0 : 1];
        }
    }
    $learn = $api->learnNext();
    same('success', $learn['status']);
    $expected[] = ['learn_next', 1];
    same('success', $api->stackAudio('stack-1')['status']);
    $expected[] = ['learn_audio', 1];

    same($expected, World::ledgerRows());
    same(3, count(array_filter($expected, static fn(array $row): bool => $row === ['practice_eval', 0])), 'two single-choice and one true/false question were free');
    same(3, count(array_filter($expected, static fn(array $row): bool => $row === ['practice_eval', 1])), 'open, rule of three and costing counted');
});

test('what never reaches the brain is never counted', function () {
    World::live();
    $api = World::learner([], false);   // nothing in scope
    same('noscope', $api->ask('What is welding?')['status']);
    same('noscope', $api->practiceNext()['status']);
    same('noscope', $api->learnNext()['status']);
    same('noscope', $api->stackAudio('stack-1')['status']);
    same('noscope', $api->practiceCheck(['widgetid' => 'w', 'widgettype' => 'openQuestionWidget', 'answer' => 'a'])['status']);

    $api = World::learner();
    same('error', $api->ask('   ')['status'], 'an empty question');
    same('error', $api->practiceCheck(['widgetid' => '', 'widgettype' => 'openQuestionWidget', 'answer' => 'a'])['status'], 'no widget');
    same('error', $api->practiceCheck(['widgetid' => 'w', 'widgettype' => 'hologramWidget', 'answer' => 'a'])['status'], 'unknown type');
    same('error', $api->practiceCheck(['widgetid' => 'w', 'widgettype' => 'openQuestionWidget', 'answer' => ''])['status'], 'an empty answer');
    same('error', $api->stackAudio('../etc/passwd')['status'], 'a malformed stack id');

    same([], TarsusServer::requests());
    same([], World::ledgerRows());
});

test('a failed call is not counted', function () {
    World::live();
    $api = World::learner();
    TarsusServer::arrange(['fail' => ['/api/t0/activity/generate/next/widgets' => ['status' => 502, 'body' => 'bad gateway']]]);
    same('error', $api->ask('What is welding?')['status']);
    same('error', $api->practiceNext()['status']);
    same('error', $api->learnNext()['status']);
    same([], World::ledgerRows());

    TarsusServer::arrange(['fail' => ['/api/t0/activity/generate/next/widgets' => ['status' => 500, 'body' => 'UNABLE TO CREATE WIDGETS']]]);
    same('error', $api->practiceNext()['status'], 'no question could be built');
    same('error', $api->learnNext()['status']);
    same([], World::ledgerRows(), 'a request that produced nothing is not counted');
    same(0, Budget::used());
});

test('the ledger attributes usage to object, learner and day', function () {
    World::plan(Plan::LEARN);
    $api = World::learner();
    $api->ask('one');
    $api->ask('two');
    World::$user = new \ilObjUser(7, 'Grace Hopper', 'en');
    $api->ask('three');
    $api->practiceNext();

    $meter = new UsageMeter();
    same(2, $meter->activeUsers(0, World::OBJECT));
    $peruser = $meter->perUser(0, World::OBJECT);
    same([[6, 2, 2], [7, 2, 2]], array_map(static fn(array $row): array => [$row['usrid'], $row['calls'], $row['credits']], $peruser));
    $summary = $meter->summary(0, World::OBJECT);
    same(['calls' => 3, 'credits' => 3], $summary['chat_ask']);
    same(['calls' => 1, 'credits' => 1], $summary['practice_next']);
    $daily = $meter->daily(0, World::OBJECT);
    same(['calls' => 4, 'credits' => 4], $daily[date('Y-m-d')]);
    same([], $meter->summary(0, 999), 'another object has no usage');
});

test('free calls show as calls in the summary but not as counted requests', function () {
    World::plan(Plan::LEARN);
    World::ledger('practice_eval', 0);
    World::ledger('practice_eval', 0);
    World::ledger('practice_eval', 1);
    $meter = new UsageMeter();
    same(['calls' => 3, 'credits' => 1], $meter->summary(0)['practice_eval']);
    same(['calls' => 1, 'credits' => 1], $meter->daily(0)[date('Y-m-d')]);
    same(1, Budget::localRequests());
    same(1, Budget::localCredits());
});

test('a negative count can never enter the ledger', function () {
    (new UsageMeter())->record(World::COURSE, World::OBJECT, 6, 'chat_ask', -3);
    same([['chat_ask', 0]], World::ledgerRows());
});

test('resetting a conversation is free', function () {
    World::live();
    same('success', World::learner()->reset()['status']);
    same('/api/t0/activity/reset/user/widgets', TarsusServer::only()['path']);
    same([], World::ledgerRows());
});
