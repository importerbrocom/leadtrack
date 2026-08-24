<?php

/**
 * Install page for the field team.
 *
 * Staff open https://your-domain/app/ on their phone and tap Download. Solves
 * the practical problem that WhatsApp refuses to send .apk files.
 *
 * Drop the signed APK in this folder as leadtrack.apk (or any leadtrack-*.apk -
 * the newest by version is picked automatically).
 */

declare(strict_types=1);

$candidates = glob(__DIR__ . '/leadtrack*.apk') ?: [];

// Newest first, so uploading a new version supersedes the old one.
usort($candidates, static fn($a, $b) => filemtime($b) <=> filemtime($a));

$apk = $candidates[0] ?? null;
$apkName = $apk !== null ? basename($apk) : null;
$apkSize = $apk !== null ? round(filesize($apk) / 1048576, 1) . ' MB' : null;
$apkDate = $apk !== null ? date('d M Y', filemtime($apk)) : null;

// Pull the version out of the filename when it is there.
$version = null;
if ($apkName !== null && preg_match('/(\d+\.\d+\.\d+)/', $apkName, $m)) {
    $version = $m[1];
}

$agency = 'Lead Manager';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install <?= htmlspecialchars($agency) ?></title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    margin: 0; background: #f6f8fa; color: #1b1f24; line-height: 1.5;
  }
  .wrap { max-width: 480px; margin: 0 auto; padding: 28px 18px 56px; }
  .card { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; padding: 22px; }
  h1 { font-size: 1.35rem; margin: 0 0 2px; }
  .sub { color: #6a737d; font-size: .875rem; margin-bottom: 20px; }
  .btn {
    display: block; text-align: center; text-decoration: none;
    background: #0d4f8b; color: #fff; font-weight: 600; font-size: 1.05rem;
    padding: 15px; border-radius: 10px; margin: 18px 0 8px;
  }
  .btn:active { background: #093a68; }
  .meta { text-align: center; color: #6a737d; font-size: .8rem; }
  ol { padding-left: 20px; margin: 0; }
  li { margin-bottom: 14px; font-size: .92rem; }
  .step-title { font-weight: 600; }
  .note { background: #fff8c5; border: 1px solid #d4a72c; border-radius: 8px; padding: 12px 14px; font-size: .87rem; margin-top: 18px; }
  .warn { background: #ffebe9; border-color: #cf222e; }
  h2 { font-size: 1rem; margin: 26px 0 10px; }
  code { background: #eff2f5; padding: 1px 5px; border-radius: 4px; font-size: .85em; }
  .missing { background: #ffebe9; border: 1px solid #cf222e; border-radius: 8px; padding: 14px; font-size: .9rem; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1><?= htmlspecialchars($agency) ?></h1>
    <div class="sub">Staff app &mdash; Android 8.0 or newer</div>

    <?php if ($apkName === null): ?>
      <div class="missing">
        No APK has been uploaded yet. Put the signed file in this folder as
        <code>leadtrack.apk</code>.
      </div>
    <?php else: ?>
      <a class="btn" href="<?= htmlspecialchars($apkName) ?>">Download the app</a>
      <div class="meta">
        <?= $version !== null ? 'Version ' . htmlspecialchars($version) . ' &middot; ' : '' ?>
        <?= htmlspecialchars((string) $apkSize) ?> &middot;
        <?= htmlspecialchars((string) $apkDate) ?>
      </div>
    <?php endif; ?>
  </div>

  <h2>After downloading</h2>
  <div class="card">
    <ol>
      <li>
        <span class="step-title">Tap the downloaded file.</span><br>
        Android will say it is not allowed to install unknown apps. Tap
        <strong>Settings</strong>, turn on <strong>Allow from this source</strong>,
        then go back and tap <strong>Install</strong>. This is normal for a
        company app that is not on the Play Store.
      </li>
      <li>
        <span class="step-title">Sign in.</span><br>
        Use the phone number and password your office gave you.
      </li>
      <li>
        <span class="step-title">Allow all four permissions.</span><br>
        Phone, Call logs, Make calls, Notifications. The app cannot record your
        calls without them.
      </li>
      <li>
        <span class="step-title">Turn off battery saving for the app.</span><br>
        Settings &rarr; Apps &rarr; Lead Manager &rarr; Battery &rarr;
        <strong>Unrestricted</strong>. On Xiaomi or Redmi also turn on
        <strong>Autostart</strong>.
      </li>
    </ol>

    <div class="note warn">
      Skip step 4 and your phone will stop recording calls after a while.
      Nothing is lost, but calls reach the office late instead of straight away.
    </div>
  </div>

  <h2>Checking it works</h2>
  <div class="card">
    <p style="margin:0; font-size:.92rem">
      Call any saved lead from the app and talk for a few seconds. When you hang
      up, a box should appear showing the number and how long you spoke, asking
      what happened and when to call back. If it does not appear, check the four
      permissions and the battery setting above.
    </p>
  </div>
</div>
</body>
</html>
