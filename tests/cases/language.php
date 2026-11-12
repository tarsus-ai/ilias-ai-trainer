<?php

/**
 * The two language files: same variables, same placeholders, and every
 * variable the code asks for.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Client\Credential;
use TarsusIlias\Index\Status;
use TarsusIlias\Support\Plan;

/**
 * The {placeholders} of a text, sorted.
 *
 * @return string[]
 */
function placeholders(string $text): array
{
    preg_match_all('/\{([a-z_]+)\}/', $text, $matches);
    $names = array_unique($matches[1]);
    sort($names);
    return array_values($names);
}

/**
 * Every plugin source file.
 *
 * @return string[]
 */
function sources(array $extensions = ['php', 'js']): array
{
    $files = [];
    foreach (['classes', 'src', 'templates/js'] as $dir) {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PLUGIN_ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

test('English and German hold the same variables', function () {
    $en = FakePlugin::strings('en');
    $de = FakePlugin::strings('de');
    same([], array_values(array_diff(array_keys($en), array_keys($de))), 'missing in German');
    same([], array_values(array_diff(array_keys($de), array_keys($en))), 'missing in English');
    ok(count($en) > 450);
});

test('no variable is defined twice and none is empty', function () {
    foreach (['en', 'de'] as $language) {
        $seen = [];
        foreach (file(PLUGIN_ROOT . '/lang/ilias_' . $language . '.lang', FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            if ($line === '' || strncmp($line, '//', 2) === 0) {
                continue;
            }
            $parts = explode('#:#', $line, 2);
            same(2, count($parts), $language . ' line ' . ($number + 1) . ' is "variable#:#text"');
            ok(!isset($seen[$parts[0]]), $language . ': ' . $parts[0] . ' is defined once');
            ok(trim($parts[1]) !== '', $language . ': ' . $parts[0] . ' has a text');
            $seen[$parts[0]] = true;
        }
    }
});

test('both languages use the same placeholders in every text', function () {
    $en = FakePlugin::strings('en');
    $de = FakePlugin::strings('de');
    foreach ($en as $key => $text) {
        same(placeholders($text), placeholders($de[$key]), $key);
    }
});

test('every text the code asks for by name exists', function () {
    $en = FakePlugin::strings('en');
    $used = [];
    foreach (sources(['php']) as $file) {
        $source = (string) file_get_contents($file);
        // $this->txt('x'), Ilias::txt('x'), self::t('x'), ->txt("x") and the
        // key lists handed to the browser.
        // A name followed by a dot is a prefix; those are checked below.
        preg_match_all('/(?:->txt|::txt|self::t|Ilias::hasTxt)\(\s*\'([a-z0-9_]+)\'(?!\s*\.)/', $source, $matches);
        foreach ($matches[1] as $key) {
            $used[$key] = basename($file);
        }
        if (preg_match_all('/\$this->strings\(\[(.*?)\]\)/s', $source, $lists)) {
            foreach ($lists[1] as $list) {
                preg_match_all('/\'([a-z0-9_]+)\'/', $list, $keys);
                foreach ($keys[1] as $key) {
                    $used[$key] = basename($file);
                }
            }
        }
    }
    // Core ILIAS texts, asked through $this->lng, are not the plugin's.
    $core = ['save', 'yes', 'no', 'cancel', 'confirm', 'settings_saved', 'title', 'description', 'online', 'learning_progress',
        'msg_obj_modified', 'form_input_not_valid', 'permission_denied', 'lastname', 'firstname', 'login'];
    ok(count($used) > 150, 'the scan found the texts');
    foreach ($used as $key => $file) {
        if (in_array($key, $core, true)) {
            continue;
        }
        ok(isset($en[$key]), $key . ' (used in ' . $file . ')');
    }
});

test('texts built from a prefix exist for every value', function () {
    $en = FakePlugin::strings('en');
    $expected = [];
    foreach (Plan::ALL as $plan) {
        $expected[] = 'plan_' . $plan;
        $expected[] = 'plan_' . $plan . '_desc';
    }
    foreach (['killswitch', 'breaker', 'budget', 'ratelimit'] as $reason) {
        $expected[] = 'guardrail_blocked_' . $reason;
    }
    foreach (Status::ALL as $status) {
        $expected[] = 'status_' . $status;
    }
    foreach (['ask', 'test', 'build'] as $mode) {
        $expected[] = 'mode_' . $mode;
        $expected[] = $mode . 'enabled';
        $expected[] = $mode . 'enabled_help';
    }
    foreach (['err_notconfigured', 'err_nobrainselected', 'err_nolicensekey'] as $error) {
        $expected[] = $error;
    }
    foreach ($expected as $key) {
        ok(isset($en[$key]), $key);
    }
});

test('the texts speak of AI interactions and packages, as the agreement does', function () {
    foreach (['en' => ['credit', 'subscription'], 'de' => ['credit', 'abonnement']] as $language => $outdated) {
        foreach (FakePlugin::strings($language) as $key => $text) {
            foreach ($outdated as $word) {
                // The placeholder {credits} is a name, not a word of the text.
                $plain = (string) preg_replace('/\{[a-z_]+\}/', '', $text);
                ok(stripos($plain, $word) === false, $language . ' ' . $key . ' still says "' . $word . '": ' . $text);
            }
        }
    }
    same('Package & AI interactions', FakePlugin::strings('en')['cfg_tab_plan']);
    same('Paket & KI-Interaktionen', FakePlugin::strings('de')['cfg_tab_plan']);
    foreach (['budget_banner_warning', 'budget_banner_exhausted', 'budget_mail_body', 'budget_mail_body_exhausted'] as $key) {
        contains(FakePlugin::strings('en')['cfg_tab_plan'], FakePlugin::strings('en')[$key], $key . ' names the tab as it is called');
        contains(FakePlugin::strings('de')['cfg_tab_plan'], FakePlugin::strings('de')[$key], $key . ' (de)');
    }
});

test('the packages carry the names of the agreement in German', function () {
    $de = FakePlugin::strings('de');
    contains('„Fragen stellen“', $de['plan_ask']);
    contains('„Wissen testen“', $de['plan_practice']);
    contains('„Wissen aufbauen“', $de['plan_learn']);
    same('Pay-As-You-Grow', $de['plan_payg']);
    foreach (['Audio-Erklärer', 'Spracheingabe', 'Agent-Modus'] as $function) {
        contains($function, $de['plan_learn_desc'], 'included in Wissen aufbauen');
        contains($function, $de['plan_practice_desc'], 'named as excluded in Wissen testen');
        contains($function, $de['plan_ask_desc'], 'named as excluded in Fragen stellen');
    }
});

test('placeholders the code fills are the ones the texts hold', function () {
    $en = FakePlugin::strings('en');
    $expected = [
        'usage_charged_value' => ['cost', 'interactions'],
        'usage_charged_tarsus' => ['interactions'],
        'usage_local_value' => ['credits', 'requests'],
        'usage_remote_value' => ['credits', 'fetched'],
        'plan_included_value' => ['credits', 'price'],
        'plan_payg_note' => ['price'],
        'plan_unset_warning' => ['tab'],
        'status_credential_license' => ['field'],
        'licensefield_desc' => ['default'],
        'error_licensefield' => ['field'],
        'monthly_limit_value' => ['limit', 'remaining'],
        'limit_reduce_confirm' => ['new', 'old', 'used'],
        'budget_banner_warning' => ['limit', 'percent', 'reset', 'used'],
        'analytics_tooltip' => ['calls', 'credits'],
    ];
    foreach ($expected as $key => $names) {
        same($names, placeholders($en[$key]), $key);
    }
    same(Credential::DEFAULT_LICENSE_FIELD, 'license_key');
});
