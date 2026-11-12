<?php

/**
 * The counting rules of the partner agreement ("AI interactions via the
 * API"), row by row. The table below is the agreement's table: what the
 * AI does, the API requests by their names in the API documentation, and
 * what one request counts.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Support\Interactions;

/** Requests that count once. */
const AGREEMENT_ONE = [
    'Answer a question (learning coach Tarsus Zero)' => ['Generate Next Widgets'],
    'Create quiz questions: single choice, open or true/false' => [
        'Single Choice Questions Generation', 'Open Questions Generation', 'True-False Questions Generation',
        'Single Choice Questions Generation From Resource', 'Single Choice Questions Generation From Asset',
        'Open Questions Generation From Resource', 'Open Questions Generation From Asset',
        'True-False Questions Generation From Resource', 'True-False Questions Generation From Asset',
        'Anonymous Single Choice From URL', 'Anonymous Single Choice From Document',
    ],
    'Create wrong answer options' => ['Single Choice Distractor Generation'],
    'Analyse questions' => ['Analyse Questions'],
    'Evaluate a free answer, with feedback' => ['Evaluate Answer', 'Evaluate Open Question Widget'],
    'Evaluate several answers at once' => ['Evaluate Multiple Answers'],
    'Evaluate a calculation or a Socratic question, with feedback' => [
        'Evaluate Cross Multiplication Question Widget', 'Evaluate Trade Costing Question Widget', 'Evaluate Socratic Question Widgets',
    ],
    'Create learning content for objectives or for a question' => [
        'Bits Generation From Assets', 'Block Generation From Assets', 'Question to Bits Generation',
    ],
    'Check the objectives of a content' => ['Validate Asset Objectives'],
    'Summary from a URL or a document' => ['Anonymous Summary From URL', 'Anonymous Summary From Document'],
    'Narrate text (standard audio)' => ['Audio Generation'],
    'Search the knowledge' => ['Search Bits', 'Search User Bits'],
    'Index content: text, table, question, URL, document or image' => [
        'Track Text Bits', 'Track Table Bits', 'Track Table Data Bits', 'Track Question Bits', 'Track Open Question Bits',
        'Track Form Question Bits', 'Track True-False Question Bits', 'Track Url Bits', 'Track Document Bits',
        'Track Image Bits', 'Track Image Url Bits',
    ],
    'Audio explainer' => ['Generate Stack Audio Explainer'],
];

/** Requests that count once per started two hours. */
const AGREEMENT_MEDIA = ['Track Audio Bits', 'Track Video Bits', 'Track Video Url Bits'];

/** Requests the table lists with 0. */
const AGREEMENT_ZERO = [
    'Correct a single-choice or true/false answer automatically' => [
        'Evaluate Single Choice Question Widgets', 'Evaluate True False Question Widgets',
    ],
    'Retrieve learning analytics' => [
        'Monitor Cognitive Excellence', 'Monitor Knowledge Excellence', 'Monitor Knowledge Gaps Addressed',
        'Estimate Time Saved by Digital Clones', 'Get Hot Keywords',
    ],
];

/**
 * The endpoint of a request by its name in the API documentation.
 */
function endpoint(string $name): string
{
    $found = [];
    foreach (Interactions::REQUESTS as $path => $entry) {
        if ($entry[0] === $name) {
            $found[] = $path;
        }
    }
    same(1, count($found), 'request "' . $name . '" is listed once');
    return $found[0];
}

test('basic rule: every request in which the AI answers, creates, evaluates, narrates or indexes counts once', function () {
    foreach (AGREEMENT_ONE as $what => $names) {
        foreach ($names as $name) {
            same(1, Interactions::count(endpoint($name)), $what . ' - ' . $name);
        }
    }
});

test('automatic correction and learning analytics count nothing', function () {
    foreach (AGREEMENT_ZERO as $what => $names) {
        foreach ($names as $name) {
            same(0, Interactions::count(endpoint($name)), $what . ' - ' . $name);
        }
    }
});

test('no interaction: managing keys and brains, usage, retrieving, listing, checking, approving, editing, deleting', function () {
    $counted = array_merge(AGREEMENT_MEDIA, ...array_values(AGREEMENT_ONE));
    $free = [];
    foreach (Interactions::REQUESTS as $path => [$name, $rule]) {
        if (!in_array($name, $counted, true)) {
            same(0, Interactions::count($path), $name);
            $free[] = $name;
        }
    }
    foreach (['Generate API Key', 'Create Brain', 'Delete Brain', 'List Brains', 'List Clone Voices', 'Get Brain Usage',
        'Retrieve Next Widgets', 'Retrieve Resource Bits', 'Retrieve Search Queries', 'List Site Urls',
        'Check Tracked Resource', 'Check Tracked Resources', 'Check Tracked Asset', 'Check Tracked Assets',
        'Approve Searched Bit', 'Approve Search Argument', 'Edit Bit', 'Delete bit', 'Untrack Resource Bits',
        'Untrack Multiple Resources', 'Untrack Multiple Assets', 'Reset User Widgets'] as $name) {
        ok(in_array($name, $free, true), $name . ' is free');
    }
});

test('the table covers the API documentation: 79 requests, each classified once', function () {
    same(79, count(Interactions::REQUESTS));
    $names = array_column(array_values(Interactions::REQUESTS), 0);
    same(count($names), count(array_unique($names)), 'no name twice');

    $one = array_merge(...array_values(AGREEMENT_ONE));
    $zero = array_merge(...array_values(AGREEMENT_ZERO));
    foreach (Interactions::REQUESTS as $path => [$name, $rule]) {
        ok(in_array($rule, [Interactions::FREE, Interactions::ONE, Interactions::MEDIA], true), $name . ' has a rule');
        same('/api/', substr($path, 0, 5), $name . ' is keyed by its absolute endpoint');
        if (in_array($name, $one, true)) {
            same(Interactions::ONE, $rule, $name);
        } elseif (in_array($name, AGREEMENT_MEDIA, true)) {
            same(Interactions::MEDIA, $rule, $name);
        } else {
            same(Interactions::FREE, $rule, $name);
        }
    }
    // Every request the agreement names exists in the table.
    foreach (array_merge($one, $zero, AGREEMENT_MEDIA) as $name) {
        endpoint($name);
    }
    // 41 requests count once, 3 by length, 7 are listed with 0 and the
    // remaining 28 are management and retrieval.
    $others = array_filter(
        Interactions::REQUESTS,
        static fn(array $entry): bool => $entry[1] === Interactions::FREE && !in_array($entry[0], $zero, true)
    );
    same([41, 3, 7, 28], [count($one), count(AGREEMENT_MEDIA), count($zero), count($others)]);
});

test('a question, the next learning step and agent mode each count once per request', function () {
    same(1, Interactions::forRequest(Interactions::GENERATE, ['state' => 'ask', 'query' => 'What is welding?']), 'question');
    same(1, Interactions::forRequest(Interactions::GENERATE, ['state' => 'learn', 'query' => '']), 'next learning step');
    same(1, Interactions::forRequest(Interactions::GENERATE, ['state' => 'practice', 'query' => '']), 'quiz question');
    same(1, Interactions::forRequest(Interactions::GENERATE, ['state' => 'ask', 'query' => 'x', 'interaction_type' => 'ai-driven']), 'agent mode');
    same(1, Interactions::forRequest(Interactions::GENERATE, ['state' => 'learn', 'interaction_type' => 'ai-driven']), 'agent mode, learning step');
});

test('audio plus a further AI step counts double: voice input is 2', function () {
    $file = new \CURLFile(World::tempFile('audio'), 'audio/webm', 'question.webm');
    same(2, Interactions::forRequest(Interactions::GENERATE, ['state' => 'ask', 'audio_content' => $file, 'input_modality' => 'audio']), 'a question by voice');
    same(2, Interactions::forRequest('/api/t0/activity/evaluate/open-question/widget', ['audio_content' => $file]), 'an answer by voice');
    same(2, Interactions::count(Interactions::GENERATE, ['voice' => true]));
    same(2, Interactions::forEvaluation('openQuestionWidget', ['voice' => true]));
    // Without a recording the request is an ordinary one.
    same(1, Interactions::forRequest(Interactions::GENERATE, ['input_modality' => 'text']));
    same(1, Interactions::forRequest(Interactions::GENERATE, ['audio_content' => '']));
});

test('exception: the audio explainer counts as one interaction', function () {
    same(1, Interactions::count(Interactions::AUDIO_EXPLAINER));
    same(1, Interactions::forRequest(Interactions::AUDIO_EXPLAINER, ['voice' => 'Sara', 'stack_id' => 's1']));
    // "voice" is the narrator's name there, not spoken input.
    same(1, Interactions::count(Interactions::AUDIO_EXPLAINER, ['voice' => true]));
});

test('think longer doubles any request: a question counts 2, a question by voice 4', function () {
    same(2, Interactions::count(Interactions::GENERATE, ['think_longer' => true]), 'question');
    same(4, Interactions::count(Interactions::GENERATE, ['think_longer' => true, 'voice' => true]), 'question by voice');
    same(2, Interactions::forRequest(Interactions::GENERATE, ['query' => 'x', 'think_longer' => 'true']));
    $file = new \CURLFile(World::tempFile('audio'), 'audio/webm', 'q.webm');
    same(4, Interactions::forRequest(Interactions::GENERATE, ['think_longer' => 'true', 'audio_content' => $file]));
    same(2, Interactions::count(Interactions::AUDIO_EXPLAINER, ['think_longer' => true]), 'any request');
    same(2, Interactions::count('access/track/document-bits', ['think_longer' => true]), 'any request');
    same(4, Interactions::count('access/track/video-bits', ['think_longer' => true, 'duration' => 3 * 3600]), 'two blocks, doubled');
    same(0, Interactions::count('/api/t0/activity/evaluate/single-choice-question/widget', ['think_longer' => true]), 'nothing doubled is nothing');
});

test('the think_longer field is read as the API takes it', function () {
    foreach (['true', 'TRUE', ' true ', '1', 1, true, 'yes', 'on'] as $value) {
        same(2, Interactions::forRequest(Interactions::GENERATE, ['think_longer' => $value]), 'on: ' . var_export($value, true));
    }
    foreach (['false', 'FALSE', '0', 0, false, '', null, 'no', 'off', 2, [], 'maybe'] as $value) {
        same(1, Interactions::forRequest(Interactions::GENERATE, ['think_longer' => $value]), 'off: ' . var_export($value, true));
    }
});

test('indexing audio or video counts once per started two hours', function () {
    foreach (AGREEMENT_MEDIA as $name) {
        $path = endpoint($name);
        same(1, Interactions::count($path, ['duration' => 1]), $name . ': a second');
        same(1, Interactions::count($path, ['duration' => 7199.9]), $name . ': just under two hours');
        same(1, Interactions::count($path, ['duration' => 7200]), $name . ': exactly two hours');
        same(2, Interactions::count($path, ['duration' => 7200.1]), $name . ': the third hour has started');
        same(2, Interactions::count($path, ['duration' => 14400]), $name . ': exactly four hours');
        same(3, Interactions::count($path, ['duration' => 14401]), $name . ': the fifth hour has started');
        same(5, Interactions::count($path, ['duration' => 10 * 3600]), $name . ': ten hours');
        same(1, Interactions::count($path), $name . ': unknown length counts one block');
    }
    foreach ([null, 0, -5, 'abc', '', NAN] as $unknown) {
        same(1, Interactions::mediaBlocks($unknown), 'unknown length ' . var_export($unknown, true));
    }
    same(2, Interactions::mediaBlocks('9000'), 'a numeric string');
    // A document's length plays no role.
    same(1, Interactions::count('access/track/document-bits', ['duration' => 99999]));
});

test('indexing by bit type: text, link and document once, recordings by length', function () {
    same(1, Interactions::forTracking('text'));
    same(1, Interactions::forTracking('url'));
    same(1, Interactions::forTracking('document', 'application/pdf'));
    same(1, Interactions::forTracking('media', 'audio/mpeg'));
    same(1, Interactions::forTracking('media', 'video/mp4', 7200));
    same(2, Interactions::forTracking('media', 'video/mp4', 7201));
    same(3, Interactions::forTracking('media', 'audio/mpeg', 5 * 3600));
    same('/api/v1/brain/access/track/audio-bits', Interactions::trackPath('media', 'Audio/MPEG'));
    same('/api/v1/brain/access/track/video-bits', Interactions::trackPath('media', 'video/mp4'));
    same('/api/v1/brain/access/track/video-bits', Interactions::trackPath('media', ''));
    same('/api/v1/brain/access/track/text-bits', Interactions::trackPath('anything-else'));
});

test('evaluating by widget type: single choice and true/false free, the others once', function () {
    same(0, Interactions::forEvaluation('singleChoiceQuestionWidget'));
    same(0, Interactions::forEvaluation('trueFalseQuestionWidget'));
    same(1, Interactions::forEvaluation('openQuestionWidget'));
    same(1, Interactions::forEvaluation('crossMultiplicationQuestionWidget'));
    same(1, Interactions::forEvaluation('tradeCostingQuestionWidget'));
    $types = array_keys(Interactions::EVALUATE);
    sort($types);
    $supported = ClientInterface::QUESTION_WIDGET_TYPES;
    sort($supported);
    same($supported, $types, 'every question type the plugin supports has its evaluation endpoint');
    foreach (Interactions::EVALUATE as $type => $path) {
        ok(isset(Interactions::REQUESTS[$path]), $type . ' evaluates on a documented endpoint');
    }
});

test('a request the table does not know falls under the basic rule', function () {
    same(1, Interactions::count('/api/v9/brand/new/endpoint'));
    same(Interactions::ONE, Interactions::rule('/api/v9/brand/new/endpoint'));
    same('', Interactions::name('/api/v9/brand/new/endpoint'));
    same(1, Interactions::forEvaluation('holographicQuestionWidget'));
    same(2, Interactions::count('/api/v9/brand/new/endpoint', ['think_longer' => true]));
});

test('endpoints are recognised however they are written', function () {
    $expected = '/api/v1/brain/access/track/url-bits';
    foreach ([
        'access/track/url-bits',
        '/api/v1/brain/access/track/url-bits',
        '/api/v1/brain/access/track/url-bits/',
        ' access/track/url-bits ',
        'https://api.example.org/api/v1/brain/access/track/url-bits',
        'http://127.0.0.1:8080/api/v1/brain/access/track/url-bits?x=1',
        '/api/v1/brain/access/track/url-bits?brain_id=abc',
    ] as $written) {
        same($expected, Interactions::normalise($written), $written);
        same('Track Url Bits', Interactions::name($written), $written);
    }
    same(Interactions::GENERATE, Interactions::normalise('https://api.example.org' . Interactions::GENERATE));
    same('Generate Next Widgets', Interactions::name(Interactions::GENERATE));
    same('Retrieve Next Widgets', Interactions::name(Interactions::FEED));
    same(0, Interactions::count(Interactions::FEED));
});

test('the examples of the general terms hold', function () {
    // "Ask a question and get an AI answer" 1, "have an exercise created"
    // 1, "indexing of an audio file" 1, "slides for a learning objective in
    // Build knowledge" 1, "automatic correction of a single-choice question" 0.
    same(1, Interactions::count(Interactions::GENERATE));
    same(1, Interactions::forTracking('media', 'audio/mpeg', 600));
    same(0, Interactions::forEvaluation('singleChoiceQuestionWidget'));
});
