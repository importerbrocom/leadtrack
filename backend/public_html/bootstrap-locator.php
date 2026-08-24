<?php

/**
 * Finds the application code from inside the web root.
 *
 * On cPanel the document root is often NOT a subdirectory of the project (a
 * subdomain gets its own folder, e.g. /home/acct/sub.domain.in), so api/ and
 * admin/ end up separated from app/. Rather than making you edit PHP paths by
 * hand, this locates app/bootstrap.php automatically.
 *
 * Resolution order:
 *   1. an app-path.php sitting next to this file that returns an absolute path
 *      (fastest and unambiguous - the deploy bundle ships one)
 *   2. the standard repo layout, where public_html/ is a sibling of app/
 *   3. a short walk up the tree, checking each ancestor and its subfolders
 *
 * Returns the absolute path to app/bootstrap.php.
 */

return (static function (): string {
    $tried = [];

    // 1. Explicit override.
    $override = __DIR__ . '/app-path.php';
    if (is_file($override)) {
        /** @var mixed $configured */
        $configured = require $override;

        if (is_string($configured) && $configured !== '') {
            $bootstrap = rtrim($configured, '/') . '/app/bootstrap.php';

            if (is_file($bootstrap)) {
                return $bootstrap;
            }

            $tried[] = $bootstrap;
        }
    }

    // 2. Repo layout: <project>/public_html/... with <project>/app/
    $sibling = dirname(__DIR__) . '/app/bootstrap.php';
    if (is_file($sibling)) {
        return $sibling;
    }
    $tried[] = $sibling;

    // 3. Walk up, checking each ancestor and one level of its children.
    $dir = __DIR__;
    for ($depth = 0; $depth < 5; $depth++) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;

        $direct = $dir . '/app/bootstrap.php';
        if (is_file($direct)) {
            return $direct;
        }
        $tried[] = $direct;

        foreach (glob($dir . '/*/app/bootstrap.php') ?: [] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo "Setup problem: could not find the application code (app/bootstrap.php).\n\n";
    echo "Create a file named app-path.php next to this one containing:\n\n";
    echo "    <?php return '/home/youraccount/your-app-folder';\n\n";
    echo "(the folder that contains app/, config/ and storage/)\n\n";
    echo "Looked in:\n";
    foreach ($tried as $path) {
        echo "  - {$path}\n";
    }

    exit(1);
})();
