<?php

/**
 * The plugin's configuration screen: the customer's credential, the
 * package, the usage panel and the status page.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Client\Credential;
use TarsusIlias\Support\Budget;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Plan;

require_once PLUGIN_ROOT . '/classes/class.ilTarsusConfigGUI.php';

/**
 * Run one command of the configuration screen.
 *
 * @param array<string, mixed> $post
 * @return array{content: string, redirect: ?string, success: string[], failure: string[], info: string[]}
 */
function config(string $cmd, array $post = []): array
{
    $_POST = $post;
    World::$tpl = new FakeTemplate();
    $redirect = null;
    try {
        (new \ilTarsusConfigGUI())->performCommand($cmd);
    } catch (Redirect $signal) {
        $redirect = $signal->command;
    }
    $_POST = [];
    return [
        'content' => World::$tpl->content,
        'redirect' => $redirect,
        'success' => World::$tpl->texts('success'),
        'failure' => World::$tpl->texts('failure'),
        'info' => World::$tpl->texts('info'),
    ];
}

/**
 * What the settings form posts when it is saved unchanged, plus overrides.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function settingsPost(array $overrides = []): array
{
    return $overrides + [
        'base_url' => 'https://api.example.org',
        'api_key' => '',
        'credential' => Config::credential(),
        'brain_id' => Config::brainId(),
        'license_key' => '',
        'license_field' => Config::licenseField(),
        'robot_name' => 'Tarsus',
        'robot_id' => '',
        'robot_description' => '',
        'voice_mode' => 'auto',
        'voice' => 'Daniel',
        'mockmode' => '1',
        'ratelimit' => '60',
        'help_links' => '',
    ];
}

/**
 * The text of an item of a rendered form, by its post variable.
 */
function item(string $html, string $postvar): string
{
    if (!preg_match('~<div class="item" data-postvar="' . preg_quote($postvar, '~') . '">(.*?)</div>~s', $html, $match)) {
        fail('the form has no item ' . $postvar);
    }
    return $match[1];
}

test('the settings form offers brain ID or License-Key, brain ID by default', function () {
    $page = config('configure');
    $credential = item($page['content'], 'credential');
    contains('data-value="brain" data-checked', $credential);
    contains('data-value="license"', $credential);
    lacks('data-value="license" data-checked', $credential);
    contains('Customer of the partner (brain ID)', $credential);
    contains('Existing Tarsus customer (License-Key)', $credential);
    contains('Tarsus bills this customer directly', $credential);
    contains('only once Tarsus has confirmed', $credential);

    contains('No License-Key is stored yet.', item($page['content'], 'license_key'));
    contains('<span class="value">license_key</span>', item($page['content'], 'license_field'));
    contains('(default: license_key)', item($page['content'], 'license_field'));
});

test('saving the License-Key mode stores key, field and mode', function () {
    $page = config('saveSettings', settingsPost(['api_key' => ' partner-key ', 'credential' => 'license', 'license_key' => ' LK-2026-0042 ', 'license_field' => '']));
    same('configure', $page['redirect']);
    same(1, count($page['success']));
    same(Credential::LICENSE, Config::credential());
    same('LK-2026-0042', Config::licenseKey());
    same('license_key', Config::licenseField(), 'an empty field name means the default');
    same('partner-key', Config::apiKey());
    same(Plan::BILLED_BY_TARSUS, Plan::billedBy());
    ok(Config::connectionReady());
});

test('the License-Key mode is refused without a key, and nothing is stored', function () {
    $page = config('saveSettings', settingsPost(['base_url' => 'https://other.example.org', 'api_key' => 'new-key', 'credential' => 'license', 'license_key' => '   ']));
    same(null, $page['redirect'], 'the form is shown again');
    contains('Enter the customer\'s License-Key', item($page['content'], 'license_key'));
    same(Credential::BRAIN, Config::credential());
    same('', Config::licenseKey());
    same('', Config::baseUrl(), 'the rest of the form was not stored either');
    same('', Config::apiKey());
});

test('a stored License-Key is kept when the field is left empty', function () {
    World::configure(['license_key' => 'LK-STORED', 'credential' => 'brain']);
    $page = config('saveSettings', settingsPost(['credential' => 'license', 'license_key' => '']));
    same('configure', $page['redirect']);
    same('LK-STORED', Config::licenseKey());
    same(Credential::LICENSE, Config::credential());

    // A new key replaces it.
    config('saveSettings', settingsPost(['credential' => 'license', 'license_key' => 'LK-NEW']));
    same('LK-NEW', Config::licenseKey());
});

test('switching back to the brain ID keeps the key on record but stops sending it', function () {
    World::configure(['license_key' => 'LK-STORED', 'credential' => 'license', 'brain_id' => 'brain-9']);
    $page = config('saveSettings', settingsPost(['credential' => 'brain']));
    same('configure', $page['redirect']);
    same(Credential::BRAIN, Config::credential());
    same('LK-STORED', Config::licenseKey());
    same(Plan::BILLED_BY_PARTNER, Plan::billedBy());
    same(['brain_id' => 'brain-9'], Credential::fromConfig()->fields());
});

test('a field name that would overwrite another request field is refused', function () {
    foreach (['api_key', 'query', 'user_id', 'license key', '9lives', 'key;drop'] as $name) {
        $page = config('saveSettings', settingsPost(['credential' => 'license', 'license_key' => 'LK-1', 'license_field' => $name]));
        same(null, $page['redirect'], $name);
        contains('cannot be used', item($page['content'], 'license_field'), $name);
        same(Credential::BRAIN, Config::credential(), $name . ': nothing stored');
        same('', Config::licenseKey(), $name);
    }
    $page = config('saveSettings', settingsPost(['credential' => 'license', 'license_key' => 'LK-1', 'license_field' => ' customer_license ']));
    same('configure', $page['redirect']);
    same('customer_license', Config::licenseField());
});

test('a markup attempt in the field name is shown escaped', function () {
    $page = config('saveSettings', settingsPost(['credential' => 'license', 'license_key' => 'LK-1', 'license_field' => 'a"b&c']));
    $alert = item($page['content'], 'license_field');
    contains('a&quot;b&amp;c', $alert);
    lacks('a"b&c', $alert);
});

test('neither key is ever written into the page', function () {
    World::configure(['api_key' => 'SECRET-API-KEY', 'license_key' => 'SECRET-LICENSE-KEY', 'credential' => 'license',
        'base_url' => 'https://api.example.org', 'mockmode' => '1']);
    foreach (['configure', 'showPlan', 'showStatus', 'showBrains', 'showDeletions'] as $cmd) {
        $page = config($cmd);
        lacks('SECRET', $page['content'] . implode(' ', $page['info']) . implode(' ', $page['failure']), $cmd);
    }
    contains('A License-Key is stored; leave the field empty to keep it.', item(config('configure')['content'], 'license_key'));
    // Not even when the form comes back with an error.
    $page = config('saveSettings', settingsPost(['api_key' => 'TYPED-API-KEY', 'license_key' => 'TYPED-LICENSE-KEY', 'license_field' => 'api_key']));
    lacks('TYPED-', $page['content']);
});

test('saving the form unchanged changes nothing about the credential', function () {
    World::configure(['brain_id' => 'brain-1', 'api_key' => 'k']);
    config('saveSettings', settingsPost());
    same(Credential::BRAIN, Config::credential());
    same('brain-1', Config::brainId());
    same('k', Config::apiKey());
    same('license_key', Config::licenseField());

    World::configure(['credential' => 'license', 'license_key' => 'LK', 'license_field' => 'customer_key']);
    config('saveSettings', settingsPost());
    same(Credential::LICENSE, Config::credential());
    same('LK', Config::licenseKey());
    same('customer_key', Config::licenseField());
});

test('the status page names the credential in use', function () {
    $page = config('showStatus');
    contains('Customer identified by', $page['content']);
    contains('<td>Brain ID</td>', $page['content']);

    World::configure(['credential' => 'license', 'license_key' => 'LK', 'license_field' => 'customer_key',
        'base_url' => 'https://api.example.org', 'api_key' => 'k']);
    $page = config('showStatus');
    contains('License-Key (sent as customer_key)', $page['content']);
    // "Connection configured": yes - with a License-Key no brain ID is needed.
    ok((bool) preg_match('~Connection configured</th><td>yes</td>~', $page['content']), 'configured');

    World::configure(['license_key' => '']);
    ok((bool) preg_match('~Connection configured</th><td>no</td>~', config('showStatus')['content']), 'not configured without the key');
});

test('the usage panel shows package, counting and who bills', function () {
    World::plan(Plan::PRACTICE);
    World::ledger('chat_ask', 1);
    World::ledger('practice_eval', 0);
    World::ledger('track', 2);
    foreach (['showPlan', 'showStatus'] as $cmd) {
        $page = config($cmd)['content'];
        contains('Package &quot;Test knowledge&quot; (PRACTICE)', $page, $cmd);
        contains('100,000 AI interactions per month, then 0.32 EUR each', $page, $cmd);
        contains('Through the partner', $page, $cmd);
        contains('3 AI interactions in 2 counted requests this month', $page, $cmd);
        contains('3 of 100,000 AI interactions used this month', $page, $cmd);
        contains('nothing so far this month', $page, $cmd);
        contains('One AI interaction per request in which the AI answers', $page, $cmd);
        contains('href="https://tarsus.ai/ki-interaktionen"', $page, $cmd);
        lacks('credits', strtolower(strip_tags($page)), $cmd . ' no longer speaks of credits');
    }
});

test('the usage panel estimates the cost beyond the package - for the partner', function () {
    World::plan(Plan::ASK);
    World::configure(['plan_credits' => '10']);
    World::ledger('chat_ask', 15);
    $page = config('showStatus')['content'];
    contains('5 AI interactions, about 1.60 EUR', $page);
    contains('Through the partner', $page);

    World::plan(Plan::PAYG);
    $page = config('showStatus')['content'];
    contains('No included AI interactions: each one costs 0.32 EUR', $page);
    contains('15 AI interactions, about 4.80 EUR', $page);
});

test('for a License-Key customer the panel names Tarsus and no partner price', function () {
    World::plan(Plan::PAYG);
    World::configure(['credential' => 'license', 'license_key' => 'LK']);
    World::ledger('chat_ask', 15);
    foreach (['showPlan', 'showStatus'] as $cmd) {
        $page = config($cmd)['content'];
        contains('By Tarsus directly', $page, $cmd);
        contains('15 AI interactions – billed by Tarsus under the customer&#039;s own contract', str_replace("'", '&#039;', $page), $cmd);
        lacks('about 4.80', $page, $cmd);
        lacks('Through the partner', $page, $cmd);
    }
});

test('while no package is set, the panel says that everything is open', function () {
    foreach (['showPlan', 'showStatus'] as $cmd) {
        $page = config($cmd)['content'];
        contains('No package is set yet', $page, $cmd);
        contains('not set yet, &quot;Build knowledge&quot; assumed', $page, $cmd);
    }
    World::plan(Plan::LEARN);
    lacks('No package is set yet', config('showPlan')['content']);
});

test('the package form offers the four packages with the agreement\'s functions', function () {
    $page = config('showPlan')['content'];
    $plan = item($page, 'plan');
    foreach (['Package "Build knowledge" (LEARN)', 'Package "Test knowledge" (PRACTICE)', 'Package "Ask questions" (ASK)', 'Pay-As-You-Grow'] as $label) {
        contains($label, $plan);
    }
    contains('audio explainer, voice input and agent mode', $plan);
    contains('No Build knowledge, no audio explainer, no voice input, no agent mode', $plan);
    contains('Included AI interactions per month', $page);
    contains('Price per additional AI interaction', $page);
    contains('Monthly limit of AI interactions', $page);
});

test('saving a package stores it and it applies at once', function () {
    $page = config('savePlan', ['plan' => Plan::ASK, 'plan_credits' => '100000', 'plan_price' => '0.32', 'plan_currency' => 'EUR',
        'monthly_limit' => '100000', 'notify_emails' => '']);
    same('showPlan', $page['redirect']);
    same(Plan::ASK, Plan::current());
    ok(Plan::isConfigured());
    not(Plan::allowsMode('test'));
    not(Plan::allowsAudio());
});

test('lowering the limit asks first and speaks of AI interactions', function () {
    World::ledger('chat_ask', 40);
    $page = config('savePlan', ['plan' => Plan::LEARN, 'plan_credits' => '100000', 'plan_price' => '0.32', 'plan_currency' => 'EUR',
        'monthly_limit' => '50', 'notify_emails' => '']);
    same(null, $page['redirect']);
    contains('from 100,000 to 50 AI interactions', $page['content']);
    contains('40 are already used this month', $page['content']);
    same(100000, Budget::limit(), 'not stored before the confirmation');

    $page = config('confirmPlan', ['plan_plan' => Plan::LEARN, 'plan_plan_credits' => '100000', 'plan_plan_price' => '0.32',
        'plan_plan_currency' => 'EUR', 'plan_monthly_limit' => '50', 'plan_notify_emails' => '']);
    same('showPlan', $page['redirect']);
    same(50, Budget::limit());
    same(1, count(World::$mails), '40 of 50 crosses 75 percent');
});

test('the brains tab explains that a License-Key needs no brain', function () {
    $page = config('showBrains');
    contains('No brain is selected yet', implode(' ', $page['info']));
    World::configure(['credential' => 'license', 'license_key' => 'LK']);
    $page = config('showBrains');
    contains('identifies the customer by License-Key', implode(' ', $page['info']));
    lacks('No brain is selected yet', implode(' ', $page['info']));
});

test('every screen renders in German without a missing text', function () {
    World::$user->language = 'de';
    World::plan(Plan::PAYG);
    World::configure(['credential' => 'license', 'license_key' => 'LK', 'monthly_limit' => '10']);
    World::ledger('chat_ask', 8);
    foreach (['configure', 'showPlan', 'showStatus', 'showBrains', 'showDeletions'] as $cmd) {
        $page = config($cmd);
        $all = $page['content'] . ' ' . implode(' ', array_merge($page['info'], $page['failure'], $page['success']));
        // A missing language variable comes back as -name-.
        not((bool) preg_match('/(?<![A-Za-z0-9])-[a-z][a-z0-9_]+-(?![A-Za-z0-9])/', strip_tags($all), $missing), $cmd . ' misses ' . ($missing[0] ?? ''));
    }
    $status = config('showStatus')['content'];
    contains('Direkt durch Tarsus', $status);
    contains('KI-Interaktionen', $status);
    contains('Pay-As-You-Grow', $status);
    $form = config('configure')['content'];
    contains('Tarsus-Bestandskunde (License-Key)', $form);
    contains('Ein License-Key ist hinterlegt', $form);
    $banner = World::$tpl->texts('warning');
    // 8 of 10: the banner speaks of AI interactions, too.
    contains('80 % der KI-Interaktionen dieses Monats sind verbraucht (8 von 10)', implode(' ', $banner));
});
