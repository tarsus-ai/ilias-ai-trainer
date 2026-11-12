<?php

/**
 * Checks on the source itself: syntax, loadability, no way around the
 * credential or the counting rules, and what goes into the release zip.
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Support\Interactions;

/**
 * @return string[] Every PHP file of the plugin and its tests.
 */
function phpFiles(): array
{
    $files = [PLUGIN_ROOT . '/plugin.php', PLUGIN_ROOT . '/sql/dbupdate.php'];
    foreach (['classes', 'src', 'tests'] as $dir) {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PLUGIN_ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

test('every PHP file parses', function () {
    $files = phpFiles();
    ok(count($files) > 60);
    foreach ($files as $file) {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        same(0, $code, str_replace(PLUGIN_ROOT . '/', '', $file) . ': ' . implode(' ', $output));
    }
});

test('every class of the plugin\'s namespace loads', function () {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PLUGIN_ROOT . '/src', \FilesystemIterator::SKIP_DOTS));
    $loaded = 0;
    foreach ($iterator as $file) {
        $relative = str_replace([PLUGIN_ROOT . '/src/', '.php'], '', $file->getPathname());
        if ($file->getExtension() !== 'php' || $relative === 'autoload'
                || in_array($relative, ['Cron/LegacyCronJob', 'Cron/ModernCronJob'], true)) {
            // The cron job classes extend ILIAS classes this suite has no stand-in for.
            continue;
        }
        $class = 'TarsusIlias\\' . str_replace('/', '\\', $relative);
        ok(class_exists($class) || interface_exists($class), $class);
        $loaded++;
    }
    ok($loaded >= 40, $loaded . ' classes');
});

test('the browser script parses', function () {
    exec('command -v node 2>/dev/null', $found, $missing);
    if ($missing !== 0) {
        skip('node is not installed');
    }
    exec('node --check ' . escapeshellarg(PLUGIN_ROOT . '/templates/js/tarsus.js') . ' 2>&1', $output, $code);
    same(0, $code, implode(' ', $output));
});

test('only the credential names the brain ID field of a request', function () {
    foreach (sources(['php']) as $file) {
        if (strpos($file, PLUGIN_ROOT . '/src/') !== 0 || basename($file) === 'Credential.php') {
            // The configuration screen has a form field of that name; requests are built in src/.
            continue;
        }
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($file));
        not((bool) preg_match('/[\'"]brain_id[\'"]\s*=>/', $code), basename($file) . ' builds a request with brain_id itself');
        if (basename($file) !== 'HttpClient.php') {
            not(strpos($code, 'curl_init(') !== false, basename($file) . ' sends requests of its own');
        }
    }
    contains("'brain_id'", (string) file_get_contents(PLUGIN_ROOT . '/src/Client/Credential.php'));
});

test('no estimate of six credits is left anywhere', function () {
    foreach (sources(['php']) as $file) {
        $source = (string) file_get_contents($file);
        not((bool) preg_match('/[\'"]credits[\'"]\s*=>\s*6\b/', $source), basename($file));
        not((bool) preg_match('/[\'"]credits[\'"]\]\s*\?\?\s*6\b/', $source), basename($file));
    }
});

test('every endpoint the client calls has its rule in the table', function () {
    $source = (string) file_get_contents(PLUGIN_ROOT . '/src/Client/HttpClient.php');
    preg_match_all('/\$this->send\(\s*\'[A-Z]+\',\s*\'([^\']+)\'/', $source, $sends);
    preg_match_all('/\'(access\/track\/[a-z-]+)\'/', $source, $tracks);
    $paths = array_unique(array_merge($sends[1], $tracks[1], array_values(Interactions::EVALUATE)));
    ok(count($paths) >= 18, count($paths) . ' endpoints');
    foreach ($paths as $path) {
        ok(isset(Interactions::REQUESTS[Interactions::normalise($path)]), $path);
    }
});

test('the client counts in one place: where the request is sent', function () {
    $source = (string) file_get_contents(PLUGIN_ROOT . '/src/Client/HttpClient.php');
    same(1, substr_count($source, 'Interactions::forRequest('), 'one call of the counting rule');
    same(1, substr_count($source, 'curl_exec('), 'one place that sends');
    ok(strpos($source, 'Interactions::forRequest(') > strpos($source, 'curl_exec('), 'counted after the request was sent');
});

test('the release zip holds the plugin and nothing else', function () {
    $git = 'cd ' . escapeshellarg(PLUGIN_ROOT) . ' && git ';
    exec($git . 'rev-parse --is-inside-work-tree 2>/dev/null', $inside, $code);
    if ($code !== 0) {
        skip('not a git checkout');
    }
    // What the release workflow builds, with the attributes of this checkout.
    exec($git . 'archive --format=tar --worktree-attributes --prefix=Tarsus/ HEAD | tar -t', $entries, $code);
    same(0, $code);
    ok(count($entries) > 50, 'the archive has the plugin in it');
    foreach ($entries as $entry) {
        not((bool) preg_match('~^Tarsus/(tests|docs|\.github)/|^Tarsus/\.git(attributes|ignore)$~', $entry), $entry . ' stays out of the zip');
    }
    foreach (['plugin.php', 'sql/dbupdate.php', 'lang/ilias_de.lang', 'src/Support/Plan.php', 'classes/class.ilTarsusConfigGUI.php'] as $path) {
        ok(in_array('Tarsus/' . $path, $entries, true), $path . ' goes into the zip');
    }
    // Once committed, the new classes go in and the tests stay out.
    foreach (['src/Support/Interactions.php', 'src/Client/Credential.php', 'src/Material/MediaDuration.php'] as $path) {
        exec($git . 'cat-file -e ' . escapeshellarg('HEAD:' . $path) . ' 2>/dev/null', $ignored, $uncommitted);
        if ($uncommitted === 0) {
            ok(in_array('Tarsus/' . $path, $entries, true), $path . ' goes into the zip');
        }
        ok(is_file(PLUGIN_ROOT . '/' . $path), $path . ' exists');
    }
});

test('no credential of any kind is in the repository', function () {
    foreach (array_merge(phpFiles(), [PLUGIN_ROOT . '/README.md', PLUGIN_ROOT . '/CHANGELOG.md', PLUGIN_ROOT . '/lang/ilias_en.lang', PLUGIN_ROOT . '/lang/ilias_de.lang']) as $file) {
        $source = (string) file_get_contents($file);
        // Long hex or base64 runs that look like keys; test fixtures use readable names.
        not((bool) preg_match('/[\'"][A-Fa-f0-9]{32,}[\'"]/', $source), basename($file) . ' holds something that looks like a key');
    }
});
