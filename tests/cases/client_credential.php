<?php

/**
 * What identifies the customer on the wire: the API key together with the
 * brain ID, or - for existing Tarsus customers - with the License-Key in
 * its place.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Client\ConfigurationException;
use TarsusIlias\Client\Credential;
use TarsusIlias\Client\HttpClient;
use TarsusIlias\Client\MockClient;
use TarsusIlias\Support\Config;

/** The one request that names no customer: listing the brains of an API key. */
const NO_CUSTOMER = '/api/v1/brain/awareness/list/memories';

test('with a brain ID every request carries the API key and the brain ID', function () {
    everyCall(client('brain-test-1'));
    $requests = TarsusServer::requests();
    ok(count($requests) >= 20, 'every method reached the API');
    foreach ($requests as $request) {
        same('test-api-key', $request['fields']['api_key'] ?? null, $request['path']);
        not(isset($request['fields']['license_key']), $request['path'] . ' carries no License-Key');
        if ($request['path'] === NO_CUSTOMER) {
            not(isset($request['fields']['brain_id']), 'listing brains names none');
        } else {
            same('brain-test-1', $request['fields']['brain_id'] ?? null, $request['path']);
        }
    }
});

test('with a License-Key every request carries the API key and the License-Key instead of a brain ID', function () {
    everyCall(client(Credential::license('LK-4711-EXISTING')));
    $requests = TarsusServer::requests();
    ok(count($requests) >= 20, 'every method reached the API');
    foreach ($requests as $request) {
        same('test-api-key', $request['fields']['api_key'] ?? null, $request['path']);
        not(isset($request['fields']['brain_id']), $request['path'] . ' carries no brain ID');
        if ($request['path'] === NO_CUSTOMER) {
            not(isset($request['fields']['license_key']), 'listing brains names no customer');
        } else {
            same('LK-4711-EXISTING', $request['fields']['license_key'] ?? null, $request['path']);
        }
    }
});

test('the License-Key travels in the configured form field', function () {
    everyCall(client(Credential::license('LK-4711', 'licence')));
    foreach (TarsusServer::requests() as $request) {
        not(isset($request['fields']['license_key']), $request['path']);
        not(isset($request['fields']['brain_id']), $request['path']);
        if ($request['path'] !== NO_CUSTOMER) {
            same('LK-4711', $request['fields']['licence'] ?? null, $request['path']);
        }
    }
});

test('the plugin configuration decides which credential is sent', function () {
    World::live('brain-of-the-partner');
    ok(ClientFactory::getClient() instanceof HttpClient);
    ClientFactory::getClient()->generateWidgets(conversation(['query' => 'q']));
    $request = TarsusServer::only('generate/next/widgets');
    same('brain-of-the-partner', $request['fields']['brain_id']);
    not(isset($request['fields']['license_key']));

    // The customer turns out to be an existing Tarsus customer.
    TarsusServer::clear();
    World::configure(['credential' => 'license', 'license_key' => ' LK-EXISTING ']);
    ClientFactory::getClient()->generateWidgets(conversation(['query' => 'q']));
    $request = TarsusServer::only('generate/next/widgets');
    same('LK-EXISTING', $request['fields']['license_key'], 'trimmed');
    not(isset($request['fields']['brain_id']), 'the brain ID still on record is not sent');
    same('test-api-key', $request['fields']['api_key']);

    // Another field name, as Tarsus might ask for.
    TarsusServer::clear();
    World::configure(['license_field' => 'customer_license']);
    ClientFactory::getClient()->generateWidgets(conversation(['query' => 'q']));
    $request = TarsusServer::only('generate/next/widgets');
    same('LK-EXISTING', $request['fields']['customer_license']);
    not(isset($request['fields']['license_key']));
    not(isset($request['fields']['brain_id']));

    // And back.
    TarsusServer::clear();
    World::configure(['credential' => 'brain']);
    ClientFactory::getClient()->generateWidgets(conversation(['query' => 'q']));
    $request = TarsusServer::only('generate/next/widgets');
    same('brain-of-the-partner', $request['fields']['brain_id']);
    not(isset($request['fields']['customer_license']));
});

test('a missing License-Key is an error, never a silent fallback to the brain ID', function () {
    World::live('brain-of-the-partner');
    World::configure(['credential' => 'license']);
    $error = throws(ConfigurationException::class, function () {
        ClientFactory::getClient()->generateWidgets(conversation(['query' => 'q']));
    });
    same('err_nolicensekey', $error->langKey);
    same([], TarsusServer::requests(), 'nothing was sent - in particular nothing on the partner\'s brain ID');

    foreach (['', '   '] as $empty) {
        World::configure(['license_key' => $empty]);
        throws(ConfigurationException::class, function () {
            ClientFactory::getClient()->trackResource(payload('text'));
        });
    }
    same([], TarsusServer::requests());
});

test('a missing brain ID is an error; listing brains works without one', function () {
    $client = client('');
    $error = throws(ConfigurationException::class, function () use ($client) {
        $client->generateWidgets(conversation(['query' => 'q']));
    });
    same('err_nobrainselected', $error->langKey);
    same([], TarsusServer::requests());
    same(2, count($client->listBrains()), 'an admin can discover the brains before choosing one');
    same(2, count(client(Credential::license(''))->listBrains()), 'and so can one with a License-Key customer');
});

test('without base URL or API key nothing is sent', function () {
    foreach ([['', 'key'], [TarsusServer::url(), ''], ['  ', 'key'], [TarsusServer::url(), '  ']] as [$url, $key]) {
        $client = new HttpClient($url, $key, 'brain-test-1');
        $error = throws(ConfigurationException::class, function () use ($client) {
            $client->listBrains();
        });
        same('err_notconfigured', $error->langKey);
    }
    same([], TarsusServer::requests());
});

test('the credential object: brain ID by default, License-Key on request', function () {
    $brain = Credential::brain(' brain-1 ');
    same(Credential::BRAIN, $brain->type());
    not($brain->isLicense());
    same(['brain_id' => 'brain-1'], $brain->fields());
    same('brain_id', $brain->field());

    $license = Credential::license(' LK-1 ');
    same(Credential::LICENSE, $license->type());
    ok($license->isLicense());
    same(['license_key' => 'LK-1'], $license->fields());

    same(['customer_key' => 'LK-1'], Credential::license('LK-1', 'customer_key')->fields());
    // Tarsus may decide to take the key in the brain_id field.
    same(['brain_id' => 'LK-1'], Credential::license('LK-1', 'brain_id')->fields());
    ok(Credential::license('')->isEmpty());
    not(Credential::license('x')->isEmpty());
});

test('the License-Key cannot travel under a name another field uses', function () {
    foreach (['license_key', 'licence', 'licenseKey', 'customer_license', 'brain_id', 'x', 'a1_b2', str_repeat('a', 64)] as $name) {
        ok(Credential::validField($name), $name . ' is usable');
    }
    foreach (['', ' ', 'api_key', 'API_KEY', 'query', 'user_id', 'resource_ids', 'audio_content', 'think_longer', 'state',
        '1key', '_key', 'license-key', 'license key', 'license.key', 'key[]', 'lizenzschlüssel', "key\n", str_repeat('a', 65)] as $name) {
        not(Credential::validField($name), var_export($name, true) . ' is refused');
    }
    // An unusable name falls back to the default instead of breaking the request.
    same(['license_key' => 'LK-1'], Credential::license('LK-1', 'api_key')->fields());
    same(['license_key' => 'LK-1'], Credential::license('LK-1', 'query')->fields());
    same(['license_key' => 'LK-1'], Credential::license('LK-1', '')->fields());
});

test('every field the client sends is reserved, so no License-Key field can overwrite one', function () {
    everyCall(client('brain-test-1'));
    $sent = [];
    foreach (TarsusServer::requests() as $request) {
        foreach (array_merge(array_keys($request['fields']), array_keys($request['files'])) as $field) {
            $sent[$field] = true;
        }
    }
    unset($sent['brain_id']);
    foreach (array_keys($sent) as $field) {
        ok(in_array($field, Credential::RESERVED_FIELDS, true), 'field ' . $field . ' is reserved');
    }
    ok(count($sent) > 30, 'the calls covered the wire format');
});

test('configuration accessors: brain ID unless License-Key is chosen', function () {
    same(Credential::BRAIN, Config::credential());
    not(Config::usesLicenseKey());
    same('license_key', Config::licenseField());
    same('', Config::licenseKey());

    World::configure(['credential' => 'license', 'license_key' => ' LK-9 ', 'license_field' => ' customer_key ']);
    same(Credential::LICENSE, Config::credential());
    ok(Config::usesLicenseKey());
    same('LK-9', Config::licenseKey());
    same('customer_key', Config::licenseField());

    World::configure(['license_field' => 'api_key']);
    same('license_key', Config::licenseField(), 'an unusable stored name falls back to the default');
    World::configure(['credential' => 'something-else']);
    same(Credential::BRAIN, Config::credential(), 'anything but "license" means brain ID');
});

test('the connection is ready with base URL, API key and the chosen credential', function () {
    not(Config::connectionReady());
    World::configure(['base_url' => 'https://api.example.org', 'api_key' => 'k']);
    not(Config::connectionReady(), 'no brain ID yet');
    World::configure(['brain_id' => 'b']);
    ok(Config::connectionReady());
    World::configure(['credential' => 'license']);
    not(Config::connectionReady(), 'License-Key chosen, none stored - the brain ID does not count');
    World::configure(['license_key' => 'LK']);
    ok(Config::connectionReady());
    World::configure(['brain_id' => '']);
    ok(Config::connectionReady(), 'a License-Key customer needs no brain ID');
    World::configure(['api_key' => '']);
    not(Config::connectionReady());
});

test('mock mode never builds an HTTP client, whatever the credential', function () {
    ok(ClientFactory::getClient() instanceof MockClient);
    World::configure(['credential' => 'license', 'license_key' => 'LK', 'base_url' => TarsusServer::url(), 'api_key' => 'k']);
    ok(ClientFactory::getClient() instanceof MockClient);
    ClientFactory::getClient()->generateWidgets(conversation(['query' => 'q']));
    same([], TarsusServer::requests());
});

test('neither the API key nor the License-Key shows up in an error', function () {
    TarsusServer::arrange(['fail' => ['/api/t0/activity/generate/next/widgets' => ['status' => 503, 'body' => 'down']]]);
    $client = new HttpClient(TarsusServer::url(), 'SECRET-API-KEY', Credential::license('SECRET-LICENSE-KEY'));
    $error = throws(\TarsusIlias\Client\TransportException::class, function () use ($client) {
        $client->generateWidgets(conversation(['query' => 'q']));
    });
    lacks('SECRET', $error->getMessage());
    lacks('SECRET', $error->body);
    same(503, $error->httpstatus);
});
