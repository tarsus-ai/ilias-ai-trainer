<?php

/**
 * Bootstrap for the plugin's unit tests.
 *
 * The tests run outside ILIAS but need its interfaces (ilDBInterface,
 * ilPlugin, the DI container). They come from the composer autoloader of
 * an ILIAS checkout: point ILIAS_ROOT at one (default /srv/ilias11; the
 * ILIAS 9 layout with libs/composer is detected too).
 *
 *   ILIAS_ROOT=/path/to/ilias vendor/bin/phpunit -c phpunit.xml
 */

declare(strict_types=1);

$root = getenv('ILIAS_ROOT') ?: '/srv/ilias11';
foreach ([$root . '/vendor/composer/vendor/autoload.php', $root . '/libs/composer/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
if (!interface_exists('ilDBInterface')) {
    fwrite(STDERR, "ILIAS classes not found; set ILIAS_ROOT to an ILIAS checkout.\n");
    exit(1);
}

// The checkout may have an installed copy of this very plugin in its
// composer class map; the repository's classes must win, so they are
// loaded by a prepended loader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'TarsusIlias\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $base = strncmp($relative, 'Tests\\', 6) === 0 ? __DIR__ . '/' . substr($relative, 6) : dirname(__DIR__) . '/src/' . $relative;
    $file = str_replace('\\', '/', $base) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);
require_once dirname(__DIR__) . '/src/autoload.php';
require_once __DIR__ . '/PluginTestCase.php';
