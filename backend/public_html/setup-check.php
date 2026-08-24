<?php

/**
 * One-off deployment self-check.
 *
 * Upload this to the document root, open it in a browser, fix whatever it
 * reports, then DELETE IT. It deliberately prints no passwords and no database
 * contents, but it does reveal which PHP extensions and paths exist, so it
 * should not be left on a live server.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$checks = [];

/** @param string $status pass|fail|warn */
function check(array &$checks, string $status, string $label, string $detail, string $fix = ''): void
{
    $checks[] = compact('status', 'label', 'detail', 'fix');
}

// ---------------------------------------------------------------- PHP itself
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
check($checks, $phpOk ? 'pass' : 'fail', 'PHP version', PHP_VERSION,
    $phpOk ? '' : 'Set PHP 8.0 or newer in cPanel > MultiPHP Manager.');

foreach (['pdo_mysql' => true, 'fileinfo' => true, 'mbstring' => true, 'json' => true, 'zip' => false] as $ext => $required) {
    $loaded = extension_loaded($ext);
    check(
        $checks,
        $loaded ? 'pass' : ($required ? 'fail' : 'warn'),
        "Extension: {$ext}",
        $loaded ? 'loaded' : 'missing',
        $loaded ? '' : "Enable {$ext} in cPanel > Select PHP Version > Extensions."
    );
}

// ---------------------------------------------------------------- rewrite
$rewrite = function_exists('apache_get_modules')
    ? in_array('mod_rewrite', apache_get_modules(), true)
    : null;

if ($rewrite === true) {
    check($checks, 'pass', 'mod_rewrite', 'enabled');
} elseif ($rewrite === false) {
    check($checks, 'fail', 'mod_rewrite', 'not enabled',
        'Without it, use the fallback API URL: /api/index.php?_route=');
} else {
    check($checks, 'warn', 'mod_rewrite', 'cannot detect (normal on LiteSpeed/CGI)',
        'Confirm by opening /api/health - if that 404s, use /api/index.php?_route=/health');
}

// ---------------------------------------------------------------- HTTPS
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
check($checks, $https ? 'pass' : 'fail', 'HTTPS', $https ? 'yes' : 'no - served over plain HTTP',
    $https ? '' : 'Run AutoSSL in cPanel > SSL/TLS Status. The Android app refuses plain HTTP.');

// ---------------------------------------------------------------- app code
$bootstrapPath = null;
$locator = __DIR__ . '/bootstrap-locator.php';

if (!is_file($locator)) {
    check($checks, 'fail', 'Application code', 'bootstrap-locator.php is missing from the web root',
        'Re-upload the contents of docroot/.');
} else {
    // The locator exits with guidance if it cannot find the code, so capture that.
    $bootstrapPath = @include $locator;

    if (is_string($bootstrapPath) && is_file($bootstrapPath)) {
        check($checks, 'pass', 'Application code', dirname(dirname($bootstrapPath)));
    } else {
        check($checks, 'fail', 'Application code', 'app/bootstrap.php not found',
            'Fix the path in app-path.php so it points at the folder holding app/, config/ and storage/.');
    }
}

// ---------------------------------------------------------------- config, db, storage
$appRoot = is_string($bootstrapPath) && $bootstrapPath !== '' ? dirname(dirname($bootstrapPath)) : null;

if ($appRoot !== null) {
    $configFile = $appRoot . '/config/config.php';

    if (!is_file($configFile)) {
        check($checks, 'fail', 'config.php', 'missing',
            'Copy config/config.example.php to config/config.php and fill in your database details.');
    } else {
        check($checks, 'pass', 'config.php', 'present');

        $config = require $configFile;

        // ---- storage writability
        $storage = $config['storage']['path'] ?? ($appRoot . '/storage');
        foreach (['uploads', 'logs'] as $sub) {
            $dir = rtrim((string) $storage, '/') . '/' . $sub;

            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }

            $writable = is_dir($dir) && is_writable($dir);
            check($checks, $writable ? 'pass' : 'fail', "storage/{$sub} writable",
                $writable ? 'yes' : 'no', $writable ? '' : "chmod 755 {$dir}");
        }

        // ---- is storage exposed to the web?
        $docRootReal = realpath(__DIR__);
        $storageReal = realpath((string) $storage);
        if ($docRootReal && $storageReal && str_starts_with($storageReal, $docRootReal)) {
            check($checks, 'fail', 'storage location', 'inside the web root - documents are publicly reachable',
                'Move the application folder outside the document root.');
        } else {
            check($checks, 'pass', 'storage location', 'outside the web root');
        }

        // ---- upload limits
        $configuredMb = (int) ($config['storage']['max_upload_mb'] ?? 15);
        $serverMb = (int) min(
            (float) ini_get('upload_max_filesize'),
            (float) ini_get('post_max_size') ?: PHP_INT_MAX
        );
        check(
            $checks,
            $serverMb >= $configuredMb ? 'pass' : 'warn',
            'Upload limit',
            "PHP allows {$serverMb}MB, app is set to {$configuredMb}MB",
            $serverMb >= $configuredMb ? '' : 'Raise upload_max_filesize and post_max_size in cPanel > MultiPHP INI Editor.'
        );

        // ---- database
        $db = $config['db'] ?? [];
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'] ?? 'localhost',
                $db['port'] ?? 3306,
                $db['database'] ?? '',
                $db['charset'] ?? 'utf8mb4'
            );

            $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            check($checks, 'pass', 'Database connection', 'connected to ' . ($db['database'] ?? '?'));

            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $expected = 16;
            check(
                $checks,
                count($tables) >= $expected ? 'pass' : 'fail',
                'Tables imported',
                count($tables) . " of {$expected}",
                count($tables) >= $expected ? '' : 'Import database/schema.sql, then database/seed.sql, via phpMyAdmin.'
            );

            if (in_array('users', $tables, true)) {
                $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                check(
                    $checks,
                    $admins > 0 ? 'pass' : 'fail',
                    'Admin account',
                    $admins > 0 ? "{$admins} admin user(s)" : 'none',
                    $admins > 0 ? '' : 'Import database/seed.sql.'
                );

                // Warn while the shipped default password is still in place.
                $hash = $pdo->query("SELECT password_hash FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
                if (is_string($hash) && password_verify('Admin@123', $hash)) {
                    check($checks, 'warn', 'Default password', 'the seeded Admin@123 is still active',
                        'Sign in at /admin/ and change it now.');
                } else {
                    check($checks, 'pass', 'Default password', 'changed');
                }
            }

            if (in_array('document_types', $tables, true)) {
                $types = (int) $pdo->query('SELECT COUNT(*) FROM document_types')->fetchColumn();
                check($checks, $types > 0 ? 'pass' : 'warn', 'Document checklist', "{$types} document types",
                    $types > 0 ? '' : 'Import database/seed.sql.');
            }
        } catch (Throwable $e) {
            check($checks, 'fail', 'Database connection', 'failed: ' . $e->getMessage(),
                'Check the database name, username and password in config.php. In cPanel the user must be ADDED to the database with ALL PRIVILEGES.');
        }
    }
}

$fails = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));
$warns = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup check</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; background: #f6f8fa; color: #1b1f24; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 64px; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; }
  .sub { color: #6a737d; font-size: .875rem; margin-bottom: 20px; }
  .banner { padding: 14px 16px; border-radius: 8px; font-weight: 600; margin-bottom: 20px; }
  .ok { background: #dafbe1; color: #116329; }
  .bad { background: #ffebe9; color: #a40e26; }
  .row { display: flex; gap: 12px; padding: 12px 14px; background: #fff; border: 1px solid #e3e8ee; border-top: 0; }
  .row:first-of-type { border-top: 1px solid #e3e8ee; border-radius: 8px 8px 0 0; }
  .row:last-of-type { border-radius: 0 0 8px 8px; }
  .icon { flex: 0 0 20px; font-weight: 700; }
  .pass .icon { color: #1a7f37; }
  .fail .icon { color: #cf222e; }
  .warn .icon { color: #9a6700; }
  .label { font-weight: 600; font-size: .9rem; }
  .detail { color: #57606a; font-size: .85rem; }
  .fix { margin-top: 4px; font-size: .85rem; color: #a40e26; }
  .warn .fix { color: #9a6700; }
  .final { margin-top: 24px; padding: 14px 16px; background: #fff8c5; border: 1px solid #d4a72c; border-radius: 8px; font-size: .9rem; }
  code { background: #eff2f5; padding: 1px 5px; border-radius: 4px; font-size: .85em; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Lead Manager &mdash; setup check</h1>
  <div class="sub">
    PHP <?= htmlspecialchars(PHP_VERSION) ?> &middot;
    <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'unknown host') ?>
  </div>

  <?php if ($fails === 0): ?>
    <div class="banner ok">
      Everything required is in place<?= $warns > 0 ? " ({$warns} thing(s) worth a look)" : '' ?>.
      Open <code>/admin/</code> to sign in.
    </div>
  <?php else: ?>
    <div class="banner bad"><?= $fails ?> problem(s) must be fixed before the app will work.</div>
  <?php endif; ?>

  <?php foreach ($checks as $c): ?>
    <div class="row <?= $c['status'] ?>">
      <div class="icon"><?= $c['status'] === 'pass' ? '&check;' : ($c['status'] === 'warn' ? '!' : '&times;') ?></div>
      <div>
        <div class="label"><?= htmlspecialchars($c['label']) ?></div>
        <div class="detail"><?= htmlspecialchars($c['detail']) ?></div>
        <?php if ($c['fix'] !== ''): ?>
          <div class="fix"><?= htmlspecialchars($c['fix']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="final">
    <strong>Delete this file when you are done.</strong>
    It lists your server's configuration, which is not something to leave
    published. Remove <code>setup-check.php</code> from the document root.
  </div>
</div>
</body>
</html>
