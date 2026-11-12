<?php

/**
 * The cron job: reconciles the brain with the repository and fetches the
 * brain's usage figure. What it re-sends is counted; the rest is free.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Cron\Sweep;
use TarsusIlias\Index\Reconciler;
use TarsusIlias\Service\ManagerApi;
use TarsusIlias\Support\Budget;

test('a run with nothing to do sends no counted request and reports the month in AI interactions', function () {
    World::live();
    World::ledger('chat_ask', 4);
    $result = Sweep::run();
    ok($result['ok'], $result['message']);
    contains('AI interactions this month: 27 of 100,000', $result['message']);
    lacks('redit', $result['message']);
    same(['/api/v1/brain/awareness/get/usage'], array_column(TarsusServer::requests(), 'path'), 'only the usage was asked for');
    same([['chat_ask', 4]], World::ledgerRows());
});

test('a run re-sends changed material, counts it and then fetches the usage', function () {
    World::live();
    $pdf = World::file(130, 'Safety sheet.pdf', 'application/pdf', '%PDF-1.4 safety');
    $manager = new ManagerApi(World::OBJECT, World::COURSE);
    ok($manager->action($pdf, 'index')['ok']);
    $manager->sync([$pdf]);
    World::$files['rid-130']['version'] = 2;
    Reconciler::resetSweep();
    TarsusServer::clear();

    $result = Sweep::run();
    ok($result['ok'], $result['message']);
    ok($result['acted']);
    contains('1 changed, 1 re-sent', $result['message']);
    same([['track', 1], ['track', 1]], World::ledgerRows());
    same(1, count(TarsusServer::requests('document-bits')));
    same(1, count(TarsusServer::requests('get/usage')));
});

test('with the kill switch on, a run sends nothing at all', function () {
    World::live();
    World::configure(['killswitch' => '1']);
    $result = Sweep::run();
    ok($result['ok']);
    not($result['acted']);
    same([], TarsusServer::requests());
});

test('a run for a License-Key customer without a key fails softly and bills nobody', function () {
    World::live();
    World::configure(['credential' => 'license']);
    $result = Sweep::run();
    ok($result['ok'], 'nothing to reconcile, so nothing failed: ' . $result['message']);
    contains('brain figure unavailable', $result['message']);
    same([], TarsusServer::requests(), 'no request went out on the brain ID');
    same(null, Budget::remote());
});
