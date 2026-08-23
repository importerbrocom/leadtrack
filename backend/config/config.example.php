<?php
/**
 * Copy this file to config.php on the server and fill in your cPanel values.
 * config.php is git-ignored so your credentials never end up in the repo.
 */

return [
    'app' => [
        'name'     => 'Recruit Lead Manager',
        // Public base URL of the API (no trailing slash)
        'base_url' => 'https://yourdomain.com/api',
        // Set to false in production - hides stack traces
        'debug'    => false,
        // IANA timezone used for every DATETIME stored / returned
        'timezone' => 'Asia/Kolkata',
    ],

    'db' => [
        // cPanel prefixes DB + user names with your account name
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'cpaneluser_leadmgr',
        'username' => 'cpaneluser_leadmgr',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    'auth' => [
        // Mobile app tokens are long-lived; refreshed on every request
        'token_ttl_days'      => 90,
        // Admin web-panel session lifetime
        'session_ttl_minutes' => 240,
    ],

    'storage' => [
        // Absolute path OUTSIDE public_html. Files are served through a PHP
        // download script so nobody can guess a URL and grab a passport scan.
        'path'          => dirname(__DIR__) . '/storage',
        'max_upload_mb' => 15,
        'allowed_mime'  => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    'cors' => [
        // The Android app does not need CORS. Add your web origins here if you
        // later build a browser front-end.
        'allowed_origins' => ['*'],
    ],
];
