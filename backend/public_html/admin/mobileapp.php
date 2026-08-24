<?php

/**
 * Mobile app distribution.
 *
 * Head office uploads the signed APK here and it becomes available at /app/ for
 * the field team. Keeps app updates a browser job rather than an SSH job.
 */

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Helpers;

$currentUser = Session::requireAdmin();
$pageTitle   = 'Mobile App';

/** Where /app/ serves from. admin/ and app/ are siblings in the web root. */
$appDir = dirname(__DIR__) . '/app';

/**
 * Does this APK carry a signature?
 *
 * There are two places to look, and checking only one gives the wrong answer:
 *
 *  - v1 (JAR signing) puts .SF/.RSA/.DSA/.EC files under META-INF/. Only needed
 *    below Android 7, so modern builds usually omit it entirely.
 *  - v2/v3 put the signature in the "APK Signing Block", which sits between the
 *    ZIP entries and the central directory. It is invisible to ZipArchive,
 *    because it is not a ZIP entry at all. Its last 16 bytes are the magic
 *    string "APK Sig Block 42", immediately before the central directory.
 *
 * So we find the End Of Central Directory record, read the central directory
 * offset from it, and check for that magic right before it.
 *
 * @return array{signed:bool,determined:bool,scheme:string}
 */
function apk_signature_info(string $path): array
{
    $unknown = ['signed' => false, 'determined' => false, 'scheme' => ''];

    $size = filesize($path);
    if ($size === false || $size < 100) {
        return $unknown;
    }

    // ---- v1: signature files under META-INF/
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && preg_match('#^META-INF/.+\.(SF|RSA|DSA|EC)$#i', $name)) {
                    $zip->close();

                    return ['signed' => true, 'determined' => true, 'scheme' => 'v1'];
                }
            }
            $zip->close();
        }
    }

    // ---- v2/v3: the APK Signing Block
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return $unknown;
    }

    // The EOCD lives in the last 64KB (its comment field can be that long).
    $tailLength = (int) min($size, 65_557);
    fseek($handle, -$tailLength, SEEK_END);
    $tail = (string) fread($handle, $tailLength);

    $eocd = strrpos($tail, "PK\x05\x06");
    if ($eocd === false) {
        fclose($handle);

        return $unknown;
    }

    // Central directory offset is a 4-byte little-endian value at EOCD + 16.
    $offsetField = substr($tail, $eocd + 16, 4);
    if (strlen($offsetField) !== 4) {
        fclose($handle);

        return $unknown;
    }

    $unpacked = unpack('V', $offsetField);
    $cdOffset = $unpacked === false ? 0 : (int) $unpacked[1];

    // 0xFFFFFFFF means ZIP64; we cannot follow that cheaply, so stay honest.
    if ($cdOffset <= 16 || $cdOffset >= $size || $cdOffset === 0xFFFFFFFF) {
        fclose($handle);

        return $unknown;
    }

    fseek($handle, $cdOffset - 16);
    $magic = (string) fread($handle, 16);
    fclose($handle);

    if ($magic === 'APK Sig Block 42') {
        return ['signed' => true, 'determined' => true, 'scheme' => 'v2/v3'];
    }

    return ['signed' => false, 'determined' => true, 'scheme' => ''];
}

/**
 * Is this really an Android package?
 *
 * Checks the content rather than trusting the extension or the browser-supplied
 * MIME type, so nobody can publish an arbitrary file to every telecaller's
 * phone.
 *
 * @return array{0:bool,1:string} [ok, reason]
 */
function validate_apk(string $path, string $originalName): array
{
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'apk') {
        return [false, 'The file must have a .apk extension'];
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [false, 'Could not read the uploaded file'];
    }

    $magic = fread($handle, 4);
    fclose($handle);

    // Every ZIP (and therefore every APK) starts with "PK\x03\x04".
    if ($magic !== "PK\x03\x04") {
        return [false, 'That is not a valid APK (it is not even a ZIP archive)'];
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return [false, 'The APK appears to be corrupt'];
        }

        $hasManifest = $zip->locateName('AndroidManifest.xml') !== false;
        $hasDex      = $zip->locateName('classes.dex') !== false;
        $zip->close();

        if (!$hasManifest || !$hasDex) {
            return [false, 'That ZIP is not an Android app (no AndroidManifest.xml / classes.dex)'];
        }
    }

    $signature = apk_signature_info($path);

    // Only reject when we are sure. An inconclusive read (ZIP64, an unusual
    // packer) must not block a perfectly good build - and an unsigned APK is
    // refused by the phone anyway, so this is belt and braces, not the only gate.
    if ($signature['determined'] && !$signature['signed']) {
        return [false, 'This APK is not signed - Android will refuse to install it. Build it with a release keystore.'];
    }

    return [true, ''];
}

if (is_post()) {
    Session::verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!is_dir($appDir)) {
            @mkdir($appDir, 0755, true);
        }

        if (!is_dir($appDir) || !is_writable($appDir)) {
            throw new RuntimeException(
                'The app folder is not writable. Run: chmod 755 ' . $appDir
            );
        }

        if ($action === 'upload') {
            $file = $_FILES['apk'] ?? null;

            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new RuntimeException(match ($code) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                        'The APK is larger than this server allows. Raise upload_max_filesize and post_max_size in cPanel > MultiPHP INI Editor.',
                    UPLOAD_ERR_PARTIAL => 'The upload was interrupted, please retry',
                    UPLOAD_ERR_NO_FILE => 'Choose an APK file first',
                    default => 'Upload failed',
                });
            }

            [$ok, $reason] = validate_apk($file['tmp_name'], (string) $file['name']);
            if (!$ok) {
                throw new RuntimeException($reason);
            }

            $version = trim((string) ($_POST['version'] ?? ''));

            // Fall back to a version in the filename, then to a date stamp.
            if ($version === '' && preg_match('/(\d+\.\d+(?:\.\d+)?)/', (string) $file['name'], $m)) {
                $version = $m[1];
            }
            if ($version === '') {
                $version = date('Y.m.d');
            }

            $version = preg_replace('/[^0-9A-Za-z.\-]/', '', $version) ?: date('Y.m.d');
            $target  = $appDir . '/leadtrack-' . $version . '.apk';

            if (!move_uploaded_file($file['tmp_name'], $target)) {
                throw new RuntimeException('Could not save the APK to ' . $appDir);
            }

            chmod($target, 0644);

            // Optionally clear out older builds so /app/ only offers the latest.
            if (!empty($_POST['remove_old'])) {
                foreach (glob($appDir . '/leadtrack*.apk') ?: [] as $old) {
                    if ($old !== $target) {
                        @unlink($old);
                    }
                }
            }

            Helpers::log(Auth::id(), 'apk_uploaded', null, null, [
                'version' => $version,
                'size'    => filesize($target),
            ]);

            // Tell the field team there is a new build waiting.
            if (!empty($_POST['notify'])) {
                $rows = \App\Core\Database::all(
                    "SELECT id FROM users WHERE role IN ('partner','telecaller') AND is_active = 1"
                );
                foreach ($rows as $u) {
                    Helpers::notify(
                        (int) $u['id'],
                        'App update available',
                        'Version ' . $version . ' - open the download link your office sent you',
                        'app_update'
                    );
                }
                Session::flash('APK published and ' . count($rows) . ' user(s) notified');
            } else {
                Session::flash('APK published as version ' . $version);
            }
        }

        if ($action === 'delete') {
            $name = basename((string) ($_POST['file'] ?? ''));

            // Only ever touch leadtrack*.apk inside the app folder.
            if ($name === '' || !preg_match('/^leadtrack[0-9A-Za-z.\-]*\.apk$/', $name)) {
                throw new RuntimeException('Refusing to delete that filename');
            }

            $path = $appDir . '/' . $name;
            if (!is_file($path)) {
                throw new RuntimeException('That file no longer exists');
            }

            @unlink($path);
            Helpers::log(Auth::id(), 'apk_deleted', null, null, ['file' => $name]);
            Session::flash('Removed ' . $name);
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
    }

    redirect('mobileapp.php');
}

// ---------------------------------------------------------------- current state
$builds = [];
foreach (glob($appDir . '/leadtrack*.apk') ?: [] as $path) {
    $builds[] = [
        'name'    => basename($path),
        'size'    => filesize($path),
        'time'    => filemtime($path),
        'version' => preg_match('/(\d+\.\d+(?:\.\d+)?)/', basename($path), $m) ? $m[1] : null,
        'sha256'  => hash_file('sha256', $path),
    ];
}

usort($builds, fn($a, $b) => $b['time'] <=> $a['time']);

$writable      = is_dir($appDir) && is_writable($appDir);
$serverLimitMb = (int) min(
    (float) ini_get('upload_max_filesize'),
    ((float) ini_get('post_max_size')) ?: PHP_INT_MAX
);

$scheme    = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$publicUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain') . '/app/';

require __DIR__ . '/partials/header.php';
?>

<h1 class="h4 mb-1">Mobile app</h1>
<p class="text-muted small">
  Publish the signed APK here. Your team installs it from one link &mdash; no Play Store, no
  emailing files around.
</p>

<?php if (!$writable): ?>
  <div class="alert alert-danger">
    <strong>The app folder is not writable.</strong>
    Run this over SSH, then reload:
    <div><code>chmod 755 <?= e($appDir) ?></code></div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Published builds</strong></div>

      <?php if ($builds === []): ?>
        <div class="card-body text-center text-muted py-4">
          Nothing published yet. Upload your signed APK on the right.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead><tr><th>File</th><th>Version</th><th>Size</th><th>Published</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($builds as $i => $b): ?>
              <tr>
                <td class="small">
                  <?= e($b['name']) ?>
                  <?php if ($i === 0): ?>
                    <span class="badge bg-success">current</span>
                  <?php endif; ?>
                  <div class="text-muted" style="font-size:.7rem">
                    SHA-256 <?= e(substr($b['sha256'], 0, 24)) ?>&hellip;
                  </div>
                </td>
                <td class="small"><?= e($b['version'] ?? '—') ?></td>
                <td class="small"><?= e(file_size_display($b['size'])) ?></td>
                <td class="small"><?= dt(date('Y-m-d H:i:s', $b['time'])) ?></td>
                <td class="text-end text-nowrap">
                  <a href="../app/<?= e(rawurlencode($b['name'])) ?>" class="btn btn-sm btn-outline-primary" title="Download">
                    <i class="bi bi-download"></i>
                  </a>
                  <form method="post" class="d-inline" data-confirm="Remove <?= e($b['name']) ?>? Phones that already installed it keep working.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="file" value="<?= e($b['name']) ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header bg-white"><strong>The link to give your team</strong></div>
      <div class="card-body">
        <div class="input-group mb-2">
          <input class="form-control form-control-sm" id="staffLink" value="<?= e($publicUrl) ?>" readonly>
          <button class="btn btn-sm btn-outline-secondary" onclick="copyLink()">Copy</button>
        </div>
        <p class="small text-muted mb-0">
          Send this to telecallers over WhatsApp. The page walks them through installing the
          app, granting the four permissions, and turning off battery optimisation.
          WhatsApp blocks <code>.apk</code> attachments, which is exactly why this page exists.
        </p>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Publish a build</strong></div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload">

          <label class="form-label small">Signed APK file *</label>
          <input type="file" name="apk" accept=".apk,application/vnd.android.package-archive"
                 class="form-control form-control-sm" required <?= $writable ? '' : 'disabled' ?>>
          <div class="form-text small">
            This server accepts uploads up to <?= (int) $serverLimitMb ?> MB.
          </div>

          <label class="form-label small mt-3">Version</label>
          <input name="version" class="form-control form-control-sm" placeholder="1.0.0">
          <div class="form-text small">Leave blank to read it from the filename.</div>

          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" name="remove_old" id="removeOld" checked>
            <label class="form-check-label small" for="removeOld">
              Remove older builds, so only this one is offered
            </label>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="notify" id="notify" checked>
            <label class="form-check-label small" for="notify">
              Notify partners and telecallers in the app
            </label>
          </div>

          <button class="btn btn-app btn-sm w-100 mt-3" <?= $writable ? '' : 'disabled' ?>>
            Publish
          </button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-white"><strong>Before you publish</strong></div>
      <div class="card-body small">
        <p class="mb-2">
          The APK must be <strong>signed</strong>, or Android refuses to install it. This page
          rejects unsigned files and anything that is not really an Android package.
        </p>
        <p class="mb-2">
          Every update must be signed with the <strong>same keystore</strong> as the version
          already on your team's phones, otherwise they cannot update and would have to
          uninstall first.
        </p>
        <p class="mb-0">
          Raise <code>versionCode</code> and <code>versionName</code> in
          <code>app/build.gradle.kts</code> for each release, or phones will not treat it as
          an update.
        </p>
      </div>
    </div>
  </div>
</div>

<script>
function copyLink() {
  var el = document.getElementById('staffLink');
  el.select();
  try { document.execCommand('copy'); } catch (e) {}
  if (navigator.clipboard) { navigator.clipboard.writeText(el.value); }
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
