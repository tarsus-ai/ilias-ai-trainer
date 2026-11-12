<?php

/**
 * Runs the plugin's tests:
 *
 *     php tests/run.php              every test
 *     php tests/run.php -v           one line per test
 *     php tests/run.php budget plan  only tests whose "file: name" contains a word
 *
 * Needs PHP with the curl and pdo_sqlite extensions; no ILIAS, no
 * database server, no network.
 *
 * @license GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
foreach (['curl', 'pdo_sqlite', 'mbstring', 'json'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "The tests need the PHP extension {$extension}.\n");
        exit(2);
    }
}

require_once __DIR__ . '/bootstrap.php';

use TarsusIlias\Tests\Registry;

$arguments = array_slice($argv, 1);
$verbose = in_array('-v', $arguments, true);
$filters = array_values(array_filter($arguments, static fn(string $argument): bool => $argument !== '-v'));

$cases = glob(__DIR__ . '/cases/*.php') ?: [];
sort($cases);
foreach ($cases as $case) {
    Registry::$group = basename($case, '.php');
    require $case;
}

exit(\TarsusIlias\Tests\run($filters, $verbose));
