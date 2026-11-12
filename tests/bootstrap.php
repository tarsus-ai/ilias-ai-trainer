<?php

/**
 * Test bootstrap: the fake ILIAS, the plugin's class loader and the test
 * helpers. Nothing here touches a real ILIAS, a real database or the
 * network beyond 127.0.0.1.
 *
 * @license GPL-3.0-or-later
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Berlin');

define('PLUGIN_ROOT', dirname(__DIR__));

// Notices and warnings are failures: the plugin must run clean.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/lib/framework.php';
require_once __DIR__ . '/lib/fake_ilias.php';
require_once __DIR__ . '/lib/fake_gui.php';
require_once __DIR__ . '/lib/FakeDb.php';
require_once __DIR__ . '/lib/TarsusServer.php';
require_once __DIR__ . '/lib/Media.php';
require_once __DIR__ . '/lib/World.php';
require_once PLUGIN_ROOT . '/src/autoload.php';
require_once __DIR__ . '/lib/helpers.php';
