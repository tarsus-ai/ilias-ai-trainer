<?php

/**
 * The harness itself: the fake ILIAS behaves, the database steps apply,
 * the fake Tarsus API answers.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;

test('a fresh world has every plugin table and runs in mock mode', function () {
    foreach (['cfg', 'data', 'idx', 'inc', 'prg', 'use', 'grd'] as $suffix) {
        ok(World::$db->tableExists('rep_robj_xtar_' . $suffix), 'table ' . $suffix);
    }
    ok(Config::mockMode(), 'mock mode is the default');
    same('en', Ilias::userLanguage());
    same('Tarsus', Ilias::txt('obj_xtar'));
});

test('the fake database refuses what MySQL would refuse', function () {
    throws(\RuntimeException::class, function () {
        World::ledger(str_repeat('x', 51), 1);
    }, 'a value longer than its column');
    throws(\Throwable::class, function () {
        World::$db->manipulate('SELECT nonsense FROM nowhere');
    }, 'invalid SQL');
});

test('the fake Tarsus API records form fields and files of every method', function () {
    $url = TarsusServer::url();
    foreach (['POST', 'GET', 'DELETE'] as $method) {
        $curl = curl_init($url . '/api/v1/brain/access/untrack/multiple-resources');
        $file = World::tempFile('hello');
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['api_key' => 'k', 'resource_ids' => '["a","b"]', 'document' => new \CURLFile($file, 'text/plain', 'hello.txt')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Expect:'],
        ]);
        $body = json_decode((string) curl_exec($curl), true);
        same(200, (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), $method);
        curl_close($curl);
        same(['a', 'b'], $body['access']['untracked']['resource_ids'], $method);
    }
    $requests = TarsusServer::requests();
    same(['POST', 'GET', 'DELETE'], array_column($requests, 'method'));
    foreach ($requests as $request) {
        same('k', $request['fields']['api_key']);
        same('hello.txt', $request['files']['document']['filename']);
        same(5, $request['files']['document']['size']);
    }
});
