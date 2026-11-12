<?php

/**
 * Shared helpers of the test cases.
 *
 * @license GPL-3.0-or-later
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Client\Credential;
use TarsusIlias\Client\HttpClient;
use TarsusIlias\Support\Interactions;

/**
 * The conversation parameters a t0 call carries, as Conversation::params()
 * builds them.
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function conversation(array $extra = []): array
{
    return $extra + [
        'contextid' => 'site0123456789ab:tarsus:210',
        'contextname' => 'Learning coach',
        'contextdescription' => 'About welding',
        'conversationstyle' => 3,
        'dqr' => 6,
        'industry' => '',
        'fulltextsearch' => false,
        'taxonomylevels' => [1, 2, 3],
        'questiontypes' => [1 => ['singleChoiceQuestionWidget'], 2 => ['openQuestionWidget'], 3 => ['trueFalseQuestionWidget']],
        'operators' => 'standard',
        'gif' => false,
        'learningmethod' => 'micro-learning',
        'anticheat' => false,
        'learntempo' => 1.0,
        'interactiontype' => 'trainer-driven',
        'robotname' => 'Tarsus',
        'robotid' => 'Tarsus',
        'robotdescription' => 'Tarsus',
        'userid' => 'pseudonym-of-ada',
        'username' => 'Ada Lovelace',
        'userlanguage' => 'en',
        'resourceids' => ['site0123456789ab:100:webr:120:link1'],
    ];
}

/**
 * The parameters of an answer to a question widget of a type.
 *
 * @return array<string, mixed>
 */
function answer(string $widgettype, string $state = 'practice'): array
{
    return conversation([
        'widgetid' => 'w-1',
        'widgettype' => $widgettype,
        'useranswer' => $widgettype === 'trueFalseQuestionWidget' ? 'true' : 'Welding',
        'useranswers' => $widgettype === 'crossMultiplicationQuestionWidget' ? [['variable' => 't2', 'answer' => '9']] : [],
        'usertable' => $widgettype === 'tradeCostingQuestionWidget' ? [['position' => 'List price', 'rate' => '', 'amount' => '1000']] : [],
        'columns' => $widgettype === 'tradeCostingQuestionWidget' ? ['Position', 'Rate', 'Amount'] : [],
        'state' => $state,
    ]);
}

/**
 * A track payload as Resolver::payload() builds it. Files are registered
 * in the fake resource storage.
 *
 * @return array<string, mixed>
 */
function payload(string $type, string $mimetype = '', string $content = 'content'): array
{
    $payload = [
        'resource_id' => 'site0123456789ab:100:file:130:main',
        'context_id' => 'site0123456789ab:100',
        'type' => $type,
        'title' => 'Material',
    ];
    if ($type === 'url') {
        $payload['url'] = 'https://example.org/welding';
    } elseif ($type === 'document' || $type === 'media') {
        $rid = 'rid-' . substr(sha1($content . $mimetype), 0, 8);
        World::$files[$rid] = ['path' => World::tempFile($content), 'mimetype' => $mimetype, 'title' => 'file', 'version' => 1];
        $payload += ['rid' => $rid, 'filename' => 'file.bin', 'mimetype' => $mimetype];
    } else {
        $payload['text'] = 'Welding joins metal parts.';
    }
    return $payload;
}

/**
 * A client talking to the fake Tarsus API.
 *
 * @param string|Credential $credential
 */
function client($credential = 'brain-test-1'): HttpClient
{
    return new HttpClient(TarsusServer::url(), 'test-api-key', $credential);
}

/**
 * Call every method of a client once, the way the plugin calls them.
 *
 * @return array<string, mixed> Method (with variant) => result.
 */
function everyCall(ClientInterface $client): array
{
    $results = [];
    $results['retrieveFeed'] = $client->retrieveFeed(conversation(['offset' => 0, 'pagesize' => 10]));
    $results['generateWidgets'] = $client->generateWidgets(conversation(['query' => 'What is welding?', 'replytowidget' => 'w-0', 'industry' => 'Metal']));
    $results['generatePracticeWidget'] = $client->generatePracticeWidget(conversation());
    $results['generateLearnWidget'] = $client->generateLearnWidget(conversation());
    foreach (array_keys(Interactions::EVALUATE) as $type) {
        $results['evaluatePracticeAnswer:' . $type] = $client->evaluatePracticeAnswer(answer($type));
    }
    $results['generateStackAudio'] = $client->generateStackAudio(conversation(['stackid' => 'stack-1', 'voice' => 'Sara']));
    $client->resetConversation('site0123456789ab:tarsus:210', 'pseudonym-of-ada');
    $results['trackResource:text'] = $client->trackResource(payload('text'));
    $results['trackResource:url'] = $client->trackResource(payload('url'));
    $results['trackResource:document'] = $client->trackResource(payload('document', 'application/pdf', '%PDF-1.4 test'));
    $results['trackResource:audio'] = $client->trackResource(payload('media', 'audio/wav', Media::wav(30)));
    $results['trackResource:video'] = $client->trackResource(payload('media', 'video/mp4', Media::mp4(30)));
    $results['untrackResource'] = $client->untrackResource(['r1', 'r2']);
    $results['untrackAssets'] = $client->untrackAssets(['a1']);
    $results['getResourceStatus'] = $client->getResourceStatus('r1');
    $results['checkTrackedResources'] = $client->checkTrackedResources(['r1', 'r2']);
    $results['listVoices'] = $client->listVoices();
    $results['getUsage'] = $client->getUsage(time() - 3600, time());
    $results['listBrains'] = $client->listBrains();
    return $results;
}
