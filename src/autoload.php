<?php

/**
 * Tarsus for ILIAS - class loader for the plugin's own namespace.
 *
 * ILIAS loads plugin classes through the composer class map, which is
 * rebuilt by "composer du" after the plugin is copied into place. This
 * loader is a safety net on top of it: when the plugin is updated and new
 * files arrive before the class map is rebuilt, they still load.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */

if (!defined('TARSUS_ILIAS_AUTOLOAD')) {
    define('TARSUS_ILIAS_AUTOLOAD', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'TarsusIlias\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
