<?php

/**
 * The HTTP client counts the AI interactions of what it actually sent;
 * the mock client reports what the same request would have counted.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Client\Credential;
use TarsusIlias\Client\MockClient;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Support\Interactions;

/** What every call of everyCall() counts, by the agreement's table. */
const EXPECTED_COUNTS = [
    'retrieveFeed' => 0,                                              // reading the history
    'generateWidgets' => 1,                                           // a question answered
    'generatePracticeWidget' => 1,                                    // quiz questions, per request
    'generateLearnWidget' => 1,                                       // the next learning step
    'evaluatePracticeAnswer:singleChoiceQuestionWidget' => 0,         // automatic correction
    'evaluatePracticeAnswer:trueFalseQuestionWidget' => 0,            // automatic correction
    'evaluatePracticeAnswer:openQuestionWidget' => 1,                 // free answer, with feedback
    'evaluatePracticeAnswer:crossMultiplicationQuestionWidget' => 1,  // calculation, with feedback
    'evaluatePracticeAnswer:tradeCostingQuestionWidget' => 1,         // calculation, with feedback
    'generateStackAudio' => 1,                                        // the audio explainer
    'trackResource:text' => 1,                                        // indexing, per request
    'trackResource:url' => 1,
    'trackResource:document' => 1,
    'trackResource:audio' => 1,                                       // 30 seconds: one block
    'trackResource:video' => 1,
    'getUsage' => 0,                                                  // querying the usage
];

test('the HTTP client reports the agreement\'s count for every call', function () {
    $results = everyCall(client());
    foreach (EXPECTED_COUNTS as $call => $expected) {
        same($expected, $results[$call]['credits'] ?? null, $call);
    }
});

test('the mock client reports the same counts as the HTTP client', function () {
    $mock = new MockClient();
    $http = everyCall(client());
    $all = [1 => array_keys(Interactions::EVALUATE), 2 => array_keys(Interactions::EVALUATE), 3 => array_keys(Interactions::EVALUATE)];

    same($http['retrieveFeed']['credits'], $mock->retrieveFeed(conversation(['offset' => 0, 'pagesize' => 10]))['credits'], 'feed');
    same($http['generateWidgets']['credits'], $mock->generateWidgets(conversation(['query' => 'q']))['credits'], 'question');
    same($http['generateLearnWidget']['credits'], $mock->generateLearnWidget(conversation())['credits'], 'learning step');
    same($http['generateStackAudio']['credits'], $mock->generateStackAudio(conversation(['stackid' => 's']))['credits'], 'audio explainer');
    same($http['getUsage']['credits'], $mock->getUsage(0, time())['credits'], 'usage');
    foreach (['text' => ['text', ''], 'url' => ['url', ''], 'document' => ['document', 'application/pdf'],
        'audio' => ['media', 'audio/wav'], 'video' => ['media', 'video/mp4']] as $name => [$type, $mimetype]) {
        same($http['trackResource:' . $name]['credits'], $mock->trackResource(['type' => $type, 'mimetype' => $mimetype, 'resource_id' => 'r'])['credits'], 'indexing ' . $name);
    }

    // The mock evaluates the questions it generated itself: three requests
    // bring all five question types.
    $widgets = [];
    for ($round = 0; $round < 3; $round++) {
        $generated = $mock->generatePracticeWidget(conversation(['questiontypes' => $all]));
        same($http['generatePracticeWidget']['credits'], $generated['credits'], 'quiz questions');
        $widgets = array_merge($widgets, $generated['widgets']);
    }
    $seen = [];
    foreach ($widgets as $widget) {
        $params = conversation(['widgetid' => $widget['id'], 'widgettype' => $widget['type'], 'state' => 'practice',
            'useranswer' => 'Mars', 'useranswers' => [], 'usertable' => [], 'columns' => $widget['columns']]);
        foreach ($widget['variables'] as $variable) {
            $params['useranswers'][] = ['variable' => $variable, 'answer' => '1'];
        }
        foreach ($widget['table'] as $row) {
            $params['usertable'][] = ['position' => $row['position'], 'rate' => $row['askrate'] ? '1' : $row['rate'], 'amount' => $row['askamount'] ? '1' : $row['amount']];
        }
        $evaluated = $mock->evaluatePracticeAnswer($params);
        same($http['evaluatePracticeAnswer:' . $widget['type']]['credits'], $evaluated['credits'], 'evaluating ' . $widget['type']);
        $seen[$widget['type']] = true;
    }
    same(5, count($seen), 'all five question types were evaluated');
});

test('each answer type is evaluated on its own endpoint', function () {
    $client = client();
    foreach (Interactions::EVALUATE as $type => $path) {
        TarsusServer::clear();
        $result = $client->evaluatePracticeAnswer(answer($type));
        $request = TarsusServer::only();
        same($path, $request['path'], $type);
        same(Interactions::count($path), $result['credits'], $type);
    }
});

test('agent mode counts once per request, like any other', function () {
    $client = client();
    $result = $client->generateWidgets(conversation(['query' => 'Show me', 'interactiontype' => 'ai-driven']));
    same('ai-driven', TarsusServer::only()['fields']['interaction_type']);
    ok($result['html'] !== '', 'the answer is an interactive page');
    same(1, $result['credits']);

    TarsusServer::clear();
    $result = $client->generateLearnWidget(conversation(['interactiontype' => 'ai-driven']));
    same('ai-driven', TarsusServer::only()['fields']['interaction_type']);
    same(1, $result['credits']);
});

test('one generate request counts once, however many questions it brings', function () {
    $result = client()->generatePracticeWidget(conversation());
    same(2, count($result['widgets']), 'two questions came back');
    same(1, $result['credits']);
    same(1, count(TarsusServer::requests()));
});

test('indexing a recording counts once per started two hours', function () {
    $client = client();
    $cases = [
        ['audio/wav', Media::wav(600), 1, 'ten minutes of audio'],
        ['audio/wav', Media::wav(7200), 1, 'exactly two hours of audio'],
        ['audio/wav', Media::wav(7201), 2, 'two hours and a second of audio'],
        ['video/mp4', Media::mp4(3 * 3600), 2, 'a three-hour video'],
        ['video/mp4', Media::mp4(4 * 3600 + 1, 1, true), 3, 'a video of four hours and a second'],
        ['video/quicktime', Media::mp4(9 * 3600), 5, 'nine hours'],
        ['audio/mpeg', 'not really an mp3', 1, 'a recording whose length cannot be read'],
    ];
    foreach ($cases as [$mimetype, $content, $expected, $what]) {
        TarsusServer::clear();
        $result = $client->trackResource(payload('media', $mimetype, $content));
        same($expected, $result['credits'], $what);
        $request = TarsusServer::only();
        $audio = strncmp($mimetype, 'audio/', 6) === 0;
        same('/api/v1/brain/access/track/' . ($audio ? 'audio' : 'video') . '-bits', $request['path'], $what);
        same(strlen($content), $request['files'][$audio ? 'audio_content' : 'video_content']['size'], $what . ': the whole file was uploaded');
        same(sha1($content), $request['files'][$audio ? 'audio_content' : 'video_content']['sha1'], $what);
    }
});

test('documents, links and text count once whatever their size', function () {
    $client = client();
    same(1, $client->trackResource(payload('document', 'application/pdf', str_repeat('x', 200000)))['credits']);
    same('/api/v1/brain/access/track/document-bits', TarsusServer::only()['path']);
    TarsusServer::clear();
    // A document that happens to look like a long recording is still a document.
    same(1, $client->trackResource(payload('document', 'application/octet-stream', Media::mp4(9 * 3600)))['credits']);
    TarsusServer::clear();
    same(1, $client->trackResource(payload('url'))['credits']);
    same('/api/v1/brain/access/track/url-bits', TarsusServer::only()['path']);
    TarsusServer::clear();
    same(1, $client->trackResource(payload('text'))['credits']);
    same('/api/v1/brain/access/track/text-bits', TarsusServer::only()['path']);
});

test('a failed request counts nothing', function () {
    $client = client();
    foreach ([500, 502, 429, 401, 404] as $status) {
        TarsusServer::arrange(['fail' => [Interactions::GENERATE => ['status' => $status, 'body' => 'no']]]);
        $error = throws(TransportException::class, function () use ($client) {
            $client->generateWidgets(conversation(['query' => 'q']));
        });
        same($status, $error->httpstatus);
    }
    // The next successful call starts from zero.
    TarsusServer::arrange([]);
    same(1, $client->generateWidgets(conversation(['query' => 'q']))['credits']);
});

test('a brain that cannot build a question is "no question", and free', function () {
    TarsusServer::arrange(['fail' => [Interactions::GENERATE => ['status' => 500, 'body' => 'UNABLE TO CREATE WIDGETS for this material']]]);
    $client = client();
    same(['widgets' => [], 'credits' => 0], array_intersect_key($client->generatePracticeWidget(conversation()), ['widgets' => 1, 'credits' => 1]));
    same(['items' => [], 'credits' => 0], array_intersect_key($client->generateLearnWidget(conversation()), ['items' => 1, 'credits' => 1]));
});

test('a malformed answer and an upload the brain did not receive count nothing', function () {
    $client = client();
    TarsusServer::arrange(['raw' => [Interactions::GENERATE => '<html>not json</html>']]);
    throws(TransportException::class, function () use ($client) {
        $client->generateWidgets(conversation(['query' => 'q']));
    });
    TarsusServer::arrange(['received' => false]);
    throws(TransportException::class, function () use ($client) {
        $client->trackResource(payload('text'));
    });
    TarsusServer::arrange([]);
    same(1, $client->trackResource(payload('text'))['credits'], 'the count starts afresh');
});

test('counts do not leak from one call into the next', function () {
    $client = client();
    same(1, $client->generateWidgets(conversation(['query' => 'q']))['credits']);
    same(0, $client->retrieveFeed(conversation())['credits']);
    same(1, $client->generateStackAudio(conversation(['stackid' => 's']))['credits']);
    $client->untrackResource(['r1']);
    $client->checkTrackedResources(['r1']);
    $client->listBrains();
    same(0, $client->getUsage(0, time())['credits']);
    same(1, $client->generateWidgets(conversation(['query' => 'q']))['credits']);
    same(0, $client->evaluatePracticeAnswer(answer('singleChoiceQuestionWidget'))['credits']);
    same(1, $client->evaluatePracticeAnswer(answer('openQuestionWidget'))['credits']);
});

test('counting is the same for a License-Key customer', function () {
    $results = everyCall(client(Credential::license('LK-1')));
    foreach (EXPECTED_COUNTS as $call => $expected) {
        same($expected, $results[$call]['credits'] ?? null, $call);
    }
});

test('withdrawing, checking and listing are sent but never counted', function () {
    $client = client();
    same(['r1' => 'removed', 'r2' => 'removed'], $client->untrackResource(['r1', 'r2']));
    same(['a1' => 'removed'], $client->untrackAssets(['a1']));
    same('indexed', $client->getResourceStatus('r1')['status']);
    same(2, count($client->checkTrackedResources(['r1', 'r2'])));
    same(2, count($client->listVoices()));
    same(27, $client->getUsage(0, time())['total'], 'the brain\'s own figure is passed on untouched');
    foreach (TarsusServer::requests() as $request) {
        same(0, Interactions::forRequest($request['path'], $request['fields']), $request['path']);
    }
});

test('what went over the wire adds up to what the client reported', function () {
    $results = everyCall(client());
    $reported = 0;
    foreach ($results as $result) {
        $reported += is_array($result) ? (int) ($result['credits'] ?? 0) : 0;
    }
    $wire = 0;
    foreach (TarsusServer::requests() as $request) {
        // A recording's length is not on the wire; every upload here is short.
        $wire += Interactions::forRequest($request['path'], $request['fields'] + $request['files']);
    }
    same($reported, $wire);
    same(array_sum(EXPECTED_COUNTS), $reported);
});
