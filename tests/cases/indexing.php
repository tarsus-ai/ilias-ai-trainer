<?php

/**
 * Indexing is an AI interaction per upload - audio and video one per
 * started two hours. Driven through the teacher's API and the cron
 * reconciler, with material in a fake course and the live client.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Index\Reconciler;
use TarsusIlias\Index\Repository;
use TarsusIlias\Index\Status;
use TarsusIlias\Service\ManagerApi;
use TarsusIlias\Support\Budget;

function manager(): ManagerApi
{
    return new ManagerApi(World::OBJECT, World::COURSE);
}

function status(string $resourceid): ?string
{
    $row = (new Repository())->get($resourceid);
    return $row === null ? null : $row->status;
}

test('indexing a web link and a document each count once', function () {
    World::live();
    $link = World::link(120, 'Welding wiki', 'https://example.org/welding');
    $pdf = World::file(130, 'Safety sheet.pdf', 'application/pdf', '%PDF-1.4 safety');

    $result = manager()->action($link, 'index');
    ok($result['ok'], $result['error']);
    same(Status::PENDING_CONFIRMATION, status($link));
    same(['DELETE /api/v1/brain/access/untrack/multiple-resources', 'POST /api/v1/brain/access/track/url-bits'],
        array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], TarsusServer::requests()));

    $result = manager()->action($pdf, 'index');
    ok($result['ok'], $result['error']);
    same('Safety sheet.pdf', TarsusServer::only('document-bits')['files']['document']['filename']);

    same([['track', 1], ['track', 1]], World::ledgerRows());
    same(2, Budget::used());
});

test('a recording is counted by its length: one per started two hours', function () {
    World::live();
    $cases = [
        131 => ['Intro.mp4', 'video/mp4', Media::mp4(20 * 60), 1],
        132 => ['Lecture.mp4', 'video/mp4', Media::mp4(3 * 3600), 2],
        133 => ['Seminar day.m4a', 'audio/mp4', Media::mp4(7 * 3600 + 30, 1, true), 4],
        134 => ['Podcast.wav', 'audio/wav', Media::wav(2 * 3600), 1],
        135 => ['Mystery.ogg', 'audio/ogg', 'OggS-but-not-readable-here', 1],
    ];
    $expected = [];
    foreach ($cases as $refid => [$title, $mimetype, $content, $count]) {
        $resourceid = World::file($refid, $title, $mimetype, $content);
        $result = manager()->action($resourceid, 'index');
        ok($result['ok'], $title . ': ' . $result['error']);
        $expected[] = ['track', $count];
    }
    same($expected, World::ledgerRows());
    same(9, Budget::used());
    same(5, Budget::localRequests(), 'five uploads');
});

test('re-indexing counts again; withdrawing, checking and syncing are free', function () {
    World::live();
    $link = World::link(120, 'Welding wiki', 'https://example.org/welding');
    ok(manager()->action($link, 'index')['ok']);

    // The brain confirms: syncing the page asks it once, for free.
    $sync = manager()->sync([$link]);
    ok($sync['ok'], $sync['error']);
    same(Status::INDEXED, status($link));
    same([['track', 1]], World::ledgerRows());

    ok(manager()->action($link, 'reindex')['ok']);
    same([['track', 1], ['track', 1]], World::ledgerRows(), 'the second upload is a second interaction');

    manager()->sync([$link]);
    ok(manager()->action($link, 'remove')['ok']);
    same(null, status($link));
    same([['track', 1], ['track', 1]], World::ledgerRows(), 'removing adds nothing');
    same(2, Budget::used());
});

test('when the month\'s interactions are used up, indexing is refused and nothing is withdrawn', function () {
    World::live();
    World::configure(['monthly_limit' => '1']);
    $link = World::link(120, 'Welding wiki', 'https://example.org/welding');
    $pdf = World::file(130, 'Safety sheet.pdf', 'application/pdf', '%PDF-1.4 safety');

    ok(manager()->action($link, 'index')['ok']);
    manager()->sync([$link]);
    same(Status::INDEXED, status($link));
    same(1, Budget::used());

    // A first index of new material: refused, back to square one.
    TarsusServer::clear();
    $result = manager()->action($pdf, 'index');
    not($result['ok']);
    contains('used up', $result['error']);
    same(Status::NOT_INDEXED, status($pdf));
    same([], TarsusServer::requests(), 'nothing left the server');

    // A re-index of indexed material: refused BEFORE the old version is
    // withdrawn, so the brain keeps what it has.
    $result = manager()->action($link, 'reindex');
    not($result['ok']);
    contains('used up', $result['error']);
    same(Status::INDEXED, status($link));
    same([], TarsusServer::requests(), 'in particular no untrack request');
    same([['track', 1]], World::ledgerRows());

    // Removing stays possible: it costs nothing.
    ok(manager()->action($link, 'remove')['ok']);
    same('/api/v1/brain/access/untrack/multiple-resources', TarsusServer::only()['path']);
});

test('a failed upload is not counted', function () {
    World::live();
    $link = World::link(120, 'Welding wiki', 'https://example.org/welding');
    TarsusServer::arrange(['fail' => ['/api/v1/brain/access/track/url-bits' => ['status' => 500, 'body' => 'boom']]]);
    $result = manager()->action($link, 'index');
    not($result['ok']);
    same(Status::NOT_INDEXED, status($link));
    same([], World::ledgerRows());

    TarsusServer::arrange(['received' => false]);
    not(manager()->action($link, 'index')['ok']);
    same([], World::ledgerRows(), 'an upload the brain did not acknowledge is not counted either');
});

test('the cron job re-sends changed material, and each re-send is counted', function () {
    World::live();
    $link = World::link(120, 'Welding wiki', 'https://example.org/welding');
    $pdf = World::file(130, 'Safety sheet.pdf', 'application/pdf', '%PDF-1.4 safety');
    foreach ([$link, $pdf] as $resourceid) {
        ok(manager()->action($resourceid, 'index')['ok']);
    }
    manager()->sync([$link, $pdf]);
    same(2, Budget::used());

    // Nothing changed: a run costs nothing.
    Reconciler::resetSweep();
    $summary = (new Reconciler())->reconcileAll(10);
    same(0, $summary['uploaded']);
    same(2, Budget::used());

    // The file gets a new version.
    World::$files['rid-130']['version'] = 2;
    Reconciler::resetSweep();
    $summary = (new Reconciler())->reconcileAll(10);
    same(1, $summary['changed']);
    same(1, $summary['uploaded']);
    same([['track', 1], ['track', 1], ['track', 1]], World::ledgerRows());
});

test('the cron job stops re-sending at the monthly limit and keeps the old version', function () {
    World::live();
    $pdf = World::file(130, 'Safety sheet.pdf', 'application/pdf', '%PDF-1.4 safety');
    ok(manager()->action($pdf, 'index')['ok']);
    manager()->sync([$pdf]);
    World::configure(['monthly_limit' => '1']);

    World::$files['rid-130']['version'] = 2;
    Reconciler::resetSweep();
    TarsusServer::clear();
    $summary = (new Reconciler())->reconcileAll(10);
    same(0, $summary['uploaded']);
    same(1, $summary['failed']);
    same(Status::PENDING_REINDEX, status($pdf), 'the teacher sees "content changed"');
    same([], TarsusServer::requests('untrack'), 'the old version stays in the brain');
    same([['track', 1]], World::ledgerRows());
});

test('material that leaves the course is withdrawn for free', function () {
    World::live();
    $link = World::link(120, 'Welding wiki', 'https://example.org/welding');
    ok(manager()->action($link, 'index')['ok']);
    manager()->sync([$link]);

    World::$nodes[120]['trash'] = true;
    Reconciler::resetSweep();
    $summary = (new Reconciler())->reconcileAll(10);
    same(1, $summary['withdrawn']);
    same(null, status($link));
    same([['track', 1]], World::ledgerRows());
});

test('the limit asks for one interaction; a long recording is entered with what it really counted', function () {
    World::live();
    World::configure(['monthly_limit' => '2']);
    World::ledger('chat_ask', 1);
    // One interaction is left; the recording will count three.
    $video = World::file(132, 'Conference.mp4', 'video/mp4', Media::mp4(5 * 3600));
    $result = manager()->action($video, 'index');
    ok($result['ok'], $result['error']);
    same([['chat_ask', 1], ['track', 3]], World::ledgerRows());
    same(4, Budget::used());
    ok(Budget::exhausted(), 'and the limit holds from then on');
});

test('indexing in mock mode counts like the live system', function () {
    $link = World::link(120, 'Welding wiki', 'https://example.org/welding');
    $video = World::file(132, 'Lecture.mp4', 'video/mp4', Media::mp4(600));
    ok(manager()->action($link, 'index')['ok']);
    ok(manager()->action($video, 'index')['ok']);
    same([['track', 1], ['track', 1]], World::ledgerRows());
    same([], TarsusServer::requests(), 'and sends nothing');
});

test('a License-Key customer\'s material is indexed under the License-Key', function () {
    World::live();
    World::configure(['credential' => 'license', 'license_key' => 'LK-55']);
    $video = World::file(132, 'Lecture.mp4', 'video/mp4', Media::mp4(3 * 3600));
    ok(manager()->action($video, 'index')['ok']);
    foreach (TarsusServer::requests() as $request) {
        same('LK-55', $request['fields']['license_key'], $request['path']);
        not(isset($request['fields']['brain_id']), $request['path']);
    }
    same([['track', 2]], World::ledgerRows());
});

test('only material of this course can be indexed', function () {
    World::live();
    World::node(300, 400, 'crs', 'Another course', 1);
    $foreign = World::file(310, 'Foreign.pdf', 'application/pdf', '%PDF', 300);
    $result = manager()->action($foreign, 'index');
    not($result['ok']);
    same([], TarsusServer::requests());
    same([], World::ledgerRows());
});
