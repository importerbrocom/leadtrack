<?php

/**
 * Shared bootstrap: PSR-4 style autoloader (no composer needed on shared
 * hosting), config, timezone and error handling.
 */

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

use App\Core\Config;

Config::load();

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

$debug = (bool) Config::get('app.debug', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logDir = rtrim((string) Config::get('storage.path'), '/') . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php-error.log');

error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
