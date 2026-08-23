<?php

/**
 * Head-office settings: agency details, workflow rules and the lookup lists
 * (lead sources, job categories, document checklist).
 */

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

$currentUser = Session::requireAdmin();
$pageTitle   = 'Settings';

if (is_post()) {
    Session::verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'general') {
            $values = [
                'agency_name'               => trim((string) ($_POST['agency_name'] ?? '')) ?: 'Recruitment Agency',
                'project_code_prefix'       => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['project_code_prefix'] ?? 'PRJ')) ?: 'PRJ'),
                'max_upload_mb'             => (string) max(1, min(100, (int) ($_POST['max_upload_mb'] ?? 15))),
                'followup_reminder_minutes' => (string) max(0, min(1440, (int) ($_POST['followup_reminder_minutes'] ?? 15))),
                'partner_can_convert'       => isset($_POST['partner_can_convert']) ? '1' : '0',
            ];

            foreach ($values as $key => $value) {
                Database::query(
                    'INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    [$key, $value]
                );
            }

            Helpers::log(Auth::id(), 'settings_updated');
            Session::flash('Settings saved');
        }

        if ($action === 'add_lookup') {
            $table = (string) ($_POST['table'] ?? '');
            $name  = trim((string) ($_POST['name'] ?? ''));

            if (!in_array($table, ['lead_sources', 'job_categories'], true)) {
                throw new RuntimeException('Unknown list');
            }

            if ($name === '') {
                throw new RuntimeException('Enter a name');
            }

            if (Database::scalar("SELECT id FROM `{$table}` WHERE name = ?", [$name]) !== null) {
                throw new RuntimeException('"' . $name . '" is already in the list');
            }

            Database::insert($table, ['name' => mb_substr($name, 0, 120), 'is_active' => 1]);
            Session::flash('Added "' . $name . '"');
        }

        if ($action === 'toggle_lookup') {
            $table = (string) ($_POST['table'] ?? '');
            $id    = (int) ($_POST['id'] ?? 0);

            if (!in_array($table, ['lead_sources', 'job_categories', 'document_types'], true)) {
                throw new RuntimeException('Unknown list');
            }

            $row = Database::first("SELECT is_active FROM `{$table}` WHERE id = ?", [$id]);
            if ($row === null) {
                throw new RuntimeException('Item not found');
            }

            Database::update($table, ['is_active' => (int) $row['is_active'] === 1 ? 0 : 1], 'id = ?', [$id]);
            Session::flash('List updated');
        }

        if ($action === 'toggle_required') {
            $id  = (int) ($_POST['id'] ?? 0);
            $row = Database::first('SELECT is_required FROM document_types WHERE id = ?', [$id]);

            if ($row === null) {
                throw new RuntimeException('Document type not found');
            }

            Database::update('document_types', ['is_required' => (int) $row['is_required'] === 1 ? 0 : 1], 'id = ?', [$id]);
            Session::flash('Document checklist updated');
        }

        if ($action === 'add_doc_type') {
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Enter a document name');
            }

            $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name) ?: 'DOC');

            if (Database::scalar('SELECT id FROM document_types WHERE code = ?', [$code]) !== null) {
                throw new RuntimeException('A similar document type already exists');
            }

            $maxOrder = (int) Database::scalar('SELECT COALESCE(MAX(sort_order), 0) FROM document_types');

            Database::insert('document_types', [
                'name'        => mb_substr($name, 0, 120),
                'code'        => mb_substr($code, 0, 40),
                'applies_to'  => in_array($_POST['applies_to'] ?? '', ['lead', 'project', 'both'], true) ? $_POST['applies_to'] : 'project',
                'is_required' => isset($_POST['is_required']) ? 1 : 0,
                'has_expiry'  => isset($_POST['has_expiry']) ? 1 : 0,
                'sort_order'  => $maxOrder + 1,
                'is_active'   => 1,
            ]);

            Session::flash('Document type added');
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
    }

    redirect('settings.php');
}

$sources    = Database::all('SELECT * FROM lead_sources ORDER BY name');
$categories = Database::all('SELECT * FROM job_categories ORDER BY name');
$docTypes   = Database::all('SELECT * FROM document_types ORDER BY sort_order');

require __DIR__ . '/partials/header.php';
?>

<h1 class="h4 mb-3">Settings</h1>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>General</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="general">
          <div class="col-12">
            <label class="form-label small">Agency name</label>
            <input name="agency_name" value="<?= e(Helpers::setting('agency_name', '')) ?>" class="form-control form-control-sm">
            <div class="form-text small">Shown in the panel header and inside the mobile app.</div>
          </div>
          <div class="col-6">
            <label class="form-label small">Project code prefix</label>
            <input name="project_code_prefix" value="<?= e(Helpers::setting('project_code_prefix', 'PRJ')) ?>" class="form-control form-control-sm">
            <div class="form-text small">e.g. PRJ → PRJ-<?= date('Y') ?>-00001</div>
          </div>
          <div class="col-6">
            <label class="form-label small">Max upload size (MB)</label>
            <input type="number" name="max_upload_mb" min="1" max="100"
                   value="<?= e(Helpers::setting('max_upload_mb', '15')) ?>" class="form-control form-control-sm">
            <div class="form-text small">Cannot exceed your hosting's own PHP limit.</div>
          </div>
          <div class="col-6">
            <label class="form-label small">Callback reminder (minutes before)</label>
            <input type="number" name="followup_reminder_minutes" min="0" max="1440"
                   value="<?= e(Helpers::setting('followup_reminder_minutes', '15')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-12 form-check ms-1 mt-3">
            <input class="form-check-input" type="checkbox" name="partner_can_convert" id="pcc"
                   <?= (string) Helpers::setting('partner_can_convert', '1') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label small" for="pcc">
              Allow partners and telecallers to convert leads into projects
              <div class="form-text small mb-0">Turn this off if only head office should create projects.</div>
            </label>
          </div>
          <div class="col-12 text-end"><button class="btn btn-sm btn-app">Save settings</button></div>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Lead sources</strong></div>
      <div class="card-body">
        <form method="post" class="d-flex gap-2 mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_lookup">
          <input type="hidden" name="table" value="lead_sources">
          <input name="name" class="form-control form-control-sm" placeholder="Add a source, e.g. YouTube" required>
          <button class="btn btn-sm btn-app text-nowrap">Add</button>
        </form>
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($sources as $s): ?>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_lookup">
              <input type="hidden" name="table" value="lead_sources">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-sm btn-outline-<?= (int) $s['is_active'] === 1 ? 'primary' : 'secondary' ?>"
                      title="<?= (int) $s['is_active'] === 1 ? 'Click to hide' : 'Click to show' ?>">
                <?= e($s['name']) ?>
                <?php if ((int) $s['is_active'] === 0): ?><i class="bi bi-eye-slash ms-1"></i><?php endif; ?>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-white"><strong>Job categories</strong></div>
      <div class="card-body">
        <form method="post" class="d-flex gap-2 mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_lookup">
          <input type="hidden" name="table" value="job_categories">
          <input name="name" class="form-control form-control-sm" placeholder="Add a category, e.g. Forklift Operator" required>
          <button class="btn btn-sm btn-app text-nowrap">Add</button>
        </form>
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($categories as $c): ?>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_lookup">
              <input type="hidden" name="table" value="job_categories">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-outline-<?= (int) $c['is_active'] === 1 ? 'primary' : 'secondary' ?>">
                <?= e($c['name']) ?>
                <?php if ((int) $c['is_active'] === 0): ?><i class="bi bi-eye-slash ms-1"></i><?php endif; ?>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header bg-white">
        <strong>Document checklist</strong>
        <div class="small text-muted">Which papers each overseas candidate must submit</div>
      </div>
      <div class="card-body border-bottom bg-light">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_doc_type">
          <div class="col-md-5"><label class="form-label small">New document type</label>
            <input name="name" class="form-control form-control-sm" placeholder="e.g. Bank Statement" required></div>
          <div class="col-md-3"><label class="form-label small">Applies to</label>
            <select name="applies_to" class="form-select form-select-sm">
              <option value="project">Project</option>
              <option value="lead">Lead</option>
              <option value="both">Both</option>
            </select>
          </div>
          <div class="col-md-2 form-check ms-2">
            <input class="form-check-input" type="checkbox" name="is_required" id="isReq">
            <label class="form-check-label small" for="isReq">Required</label>
          </div>
          <div class="col-md-1"><button class="btn btn-sm btn-app w-100">Add</button></div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Document</th><th>Applies to</th><th>Required</th><th class="text-end">Visible</th></tr></thead>
          <tbody>
          <?php foreach ($docTypes as $t): ?>
            <tr<?= (int) $t['is_active'] === 0 ? ' class="opacity-50"' : '' ?>>
              <td class="small"><?= e($t['name']) ?><div class="text-muted"><code class="small-code"><?= e($t['code']) ?></code></div></td>
              <td class="small"><?= e(ucfirst($t['applies_to'])) ?></td>
              <td>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_required">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button class="btn btn-sm btn-<?= (int) $t['is_required'] === 1 ? 'danger' : 'outline-secondary' ?>">
                    <?= (int) $t['is_required'] === 1 ? 'Required' : 'Optional' ?>
                  </button>
                </form>
              </td>
              <td class="text-end">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_lookup">
                  <input type="hidden" name="table" value="document_types">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-eye<?= (int) $t['is_active'] === 1 ? '' : '-slash' ?>"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
