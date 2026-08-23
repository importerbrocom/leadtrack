<?php

/**
 * Router script for PHP's built-in web server, used only for local testing.
 * It reproduces what public_html/api/.htaccess does on cPanel: forward every
 * /api/* path to the API front controller while leaving real files alone.
 *
 *   php -S 127.0.0.1:8099 -t public_html tests/dev-router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($uri, '/api')) {
    $path = substr($uri, 4);
    $_SERVER['PATH_INFO']   = $path === '' ? '/' : $path;
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';

    require __DIR__ . '/../public_html/api/index.php';

    return true;
}

// Anything else: let the built-in server serve it from public_html.
return false;
