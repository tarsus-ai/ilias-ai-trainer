<?php

/**
 * "Enforce packages technically": a customer reaches only the functions
 * that were paid for. Checked where it counts - on the server, with the
 * live client, by what does and does not reach the Tarsus API.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Service\LearnerApi;
use TarsusIlias\Support\Interactions;
use TarsusIlias\Support\Plan;

/**
 * The learner's calls: name => [feature of the agreement's table that the
 * call needs, endpoint it reaches, the call].
 *
 * @return array<string, array{0: string, 1: string, 2: callable(LearnerApi): array}>
 */
function learnerCalls(): array
{
    return [
        'ask a question' => [Plan::FEATURE_ASK, Interactions::GENERATE,
            static fn(LearnerApi $api): array => $api->ask('What is welding?')],
        'next quiz question' => [Plan::FEATURE_TEST, Interactions::GENERATE,
            static fn(LearnerApi $api): array => $api->practiceNext()],
        'evaluate a quiz answer' => [Plan::FEATURE_TEST, Interactions::EVALUATE['openQuestionWidget'],
            static fn(LearnerApi $api): array => $api->practiceCheck(['widgetid' => 'w-open-1', 'widgettype' => 'openQuestionWidget', 'answer' => 'Welding', 'state' => 'practice'])],
        'correct a single-choice quiz answer' => [Plan::FEATURE_TEST, Interactions::EVALUATE['singleChoiceQuestionWidget'],
            static fn(LearnerApi $api): array => $api->practiceCheck(['widgetid' => 'w-sc-1', 'widgettype' => 'singleChoiceQuestionWidget', 'answer' => 'Welding', 'state' => 'practice'])],
        'next learning step' => [Plan::FEATURE_BUILD, Interactions::GENERATE,
            static fn(LearnerApi $api): array => $api->learnNext()],
        'answer the check question of a learning unit' => [Plan::FEATURE_BUILD, Interactions::EVALUATE['singleChoiceQuestionWidget'],
            static fn(LearnerApi $api): array => $api->practiceCheck(['widgetid' => 's-q', 'widgettype' => 'singleChoiceQuestionWidget', 'answer' => 'Welding', 'state' => 'learn'])],
        'audio explainer' => [Plan::FEATURE_AUDIO, Interactions::AUDIO_EXPLAINER,
            static fn(LearnerApi $api): array => $api->stackAudio('stack-1')],
    ];
}

test('the server lets through exactly what the booked package includes', function () {
    foreach (PACKAGE_COLUMNS as $column => $plan) {
        foreach (learnerCalls() as $name => [$feature, $endpoint, $call]) {
            World::reset();
            World::live();
            World::plan($plan);
            $api = World::learner();
            $result = $call($api);
            $what = $name . ' with ' . $plan;

            if (FUNCTIONS_PER_PACKAGE[$feature][$column]) {
                same('success', $result['status'], $what . ': ' . $result['error']);
                same($endpoint, TarsusServer::only($endpoint)['path'], $what);
                same(1, count(World::ledgerRows()), $what . ' is in the ledger');
            } else {
                same('disabled', $result['status'], $what);
                same('This function is not part of the booked package.', $result['error'], $what);
                same([], TarsusServer::requests(), $what . ': nothing reached the Tarsus API');
                same([], World::ledgerRows(), $what . ': nothing was counted');
            }
        }
    }
});

test('the same holds in mock mode', function () {
    foreach (PACKAGE_COLUMNS as $column => $plan) {
        foreach (learnerCalls() as $name => [$feature, $endpoint, $call]) {
            if (strpos($name, 'answer') !== false || strpos($name, 'audio') !== false) {
                // Answers need a question the mock generated; covered below.
                continue;
            }
            World::reset();
            World::plan($plan);
            $result = $call(World::learner());
            same(FUNCTIONS_PER_PACKAGE[$feature][$column] ? 'success' : 'disabled', $result['status'], $name . ' with ' . $plan);
        }
    }
});

test('agent mode reaches the API only where the package includes it', function () {
    foreach (PACKAGE_COLUMNS as $column => $plan) {
        World::reset();
        World::live();
        World::plan($plan);
        // The teacher chose the AI-driven interaction type (perhaps before a downgrade).
        $api = World::learner(['interactiontype' => Plan::INTERACTION_AI]);
        $expected = FUNCTIONS_PER_PACKAGE[Plan::FEATURE_AGENT][$column] ? 'ai-driven' : 'trainer-driven';

        $api->ask('Show me how an arc forms');
        same($expected, TarsusServer::only()['fields']['interaction_type'], 'question with ' . $plan);

        if (FUNCTIONS_PER_PACKAGE[Plan::FEATURE_TEST][$column]) {
            TarsusServer::clear();
            $api->practiceNext();
            same($expected, TarsusServer::only()['fields']['interaction_type'], 'quiz with ' . $plan);
        }
        if (FUNCTIONS_PER_PACKAGE[Plan::FEATURE_BUILD][$column]) {
            TarsusServer::clear();
            $api->learnNext();
            same($expected, TarsusServer::only()['fields']['interaction_type'], 'learning step with ' . $plan);
        }
    }
});

test('without agent mode chosen, the trainer-driven type is sent in every package', function () {
    foreach (PACKAGE_COLUMNS as $plan) {
        World::reset();
        World::live();
        World::plan($plan);
        World::learner()->ask('What is welding?');
        same('trainer-driven', TarsusServer::only()['fields']['interaction_type'], $plan);
    }
});

test('what the learner\'s screen is told follows the package', function () {
    foreach (PACKAGE_COLUMNS as $column => $plan) {
        World::reset();
        World::plan($plan);
        $settings = World::settings(['interactiontype' => Plan::INTERACTION_AI]);
        $expected = [];
        foreach (['build' => Plan::FEATURE_BUILD, 'test' => Plan::FEATURE_TEST, 'ask' => Plan::FEATURE_ASK] as $mode => $feature) {
            if (FUNCTIONS_PER_PACKAGE[$feature][$column]) {
                $expected[] = $mode;
            }
            same(FUNCTIONS_PER_PACKAGE[$feature][$column], $settings->modeActive($mode), $mode . ' active with ' . $plan);
        }
        same($expected, $settings->availableModes(), 'the mode switch with ' . $plan);
        same(FUNCTIONS_PER_PACKAGE[Plan::FEATURE_AUDIO][$column], Plan::allowsAudio(), 'the audio button with ' . $plan);
        same(FUNCTIONS_PER_PACKAGE[Plan::FEATURE_AGENT][$column], $settings->effectiveInteraction() === Plan::INTERACTION_AI, 'interactive pages with ' . $plan);
    }
});

test('a downgrade takes effect at once, an upgrade finds the teacher\'s choices intact', function () {
    World::live();
    World::plan(Plan::LEARN);
    $api = World::learner(['interactiontype' => Plan::INTERACTION_AI]);
    same('success', $api->learnNext()['status']);
    same('success', $api->stackAudio('stack-1')['status']);

    World::plan(Plan::ASK);
    TarsusServer::clear();
    same('disabled', $api->learnNext()['status']);
    same('disabled', $api->practiceNext()['status']);
    same('disabled', $api->stackAudio('stack-1')['status']);
    same([], TarsusServer::requests());
    same('success', $api->ask('Still allowed?')['status']);
    same('trainer-driven', TarsusServer::only()['fields']['interaction_type']);

    World::plan(Plan::PAYG);
    TarsusServer::clear();
    same('success', $api->learnNext()['status']);
    same('ai-driven', TarsusServer::only()['fields']['interaction_type'], 'the stored interaction type is back in force');
});

test('a mode the teacher switched off stays off in every package, with its own message', function () {
    foreach ([Plan::LEARN, Plan::PAYG] as $plan) {
        World::reset();
        World::live();
        World::plan($plan);
        $api = World::learner(['askenabled' => false, 'testenabled' => false, 'buildenabled' => false]);
        foreach (learnerCalls() as $name => [$feature, $endpoint, $call]) {
            $result = $call($api);
            same('disabled', $result['status'], $name . ' with ' . $plan);
            same('This mode is switched off in this object.', $result['error'], $name . ' with ' . $plan);
        }
        same([], TarsusServer::requests());
    }
});

test('the package is asked before the object\'s own switch', function () {
    World::live();
    World::plan(Plan::ASK);
    // Switched on by the teacher, but not booked.
    $api = World::learner(['testenabled' => true, 'buildenabled' => true]);
    same('This function is not part of the booked package.', $api->practiceNext()['error']);
    same('This function is not part of the booked package.', $api->learnNext()['error']);
    // Booked, but switched off by the teacher.
    $api = World::learner(['askenabled' => false], false);
    same('This mode is switched off in this object.', $api->ask('q')['error']);
});

test('the refusal keeps the shape the screen expects', function () {
    World::plan(Plan::ASK);
    $api = World::learner();
    $refused = $api->learnNext();
    same(['items' => [], 'complete' => false, 'status' => 'disabled'], array_intersect_key($refused, ['items' => 1, 'complete' => 1, 'status' => 1]));
    foreach ([$api->practiceNext(), $api->stackAudio('stack-1'), $api->practiceCheck(['widgetid' => 'w', 'widgettype' => 'openQuestionWidget', 'answer' => 'a'])] as $result) {
        same(['status', 'error'], array_keys($result));
    }
});

test('an unset package offers everything and is reported as not set', function () {
    World::live();
    not(Plan::isConfigured());
    $api = World::learner();
    foreach (learnerCalls() as $name => [$feature, $endpoint, $call]) {
        same('success', $call($api)['status'], $name);
    }
});

test('reading the history is possible in every package and costs nothing', function () {
    foreach (PACKAGE_COLUMNS as $plan) {
        World::reset();
        World::live();
        World::plan($plan);
        $result = World::learner()->feed(0);
        same('success', $result['status'], $plan);
        same(Interactions::FEED, TarsusServer::only()['path']);
        same([], World::ledgerRows(), $plan);
    }
});
