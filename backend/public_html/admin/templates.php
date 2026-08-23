<?php

/**
 * Blank form templates: head office uploads, the field team downloads.
 */

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Uploader;

$currentUser = Session::requireLogin();
$pageTitle   = 'Forms';

if (is_post()) {
    Session::verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        Session::requireAdmin();

        if ($action === 'upload') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a file to upload');
            }

            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Give the form a title');
            }

            $stored = Uploader::store($_FILES['file'], 'templates');

            $id = Database::insert('form_templates', [
                'title'       => mb_substr($title, 0, 200),
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                'category'    => trim((string) ($_POST['category'] ?? '')) ?: null,
                'file_name'   => $stored['file_name'],
                'stored_name' => $stored['stored_name'],
                'mime_type'   => $stored['mime_type'],
                'file_size'   => $stored['file_size'],
                'version'     => trim((string) ($_POST['version'] ?? '1.0')) ?: '1.0',
                'uploaded_by' => Auth::id(),
            ]);

            foreach (Database::all("SELECT id FROM users WHERE role IN ('partner','telecaller') AND is_active = 1") as $u) {
                Helpers::notify((int) $u['id'], 'New form available', $title, 'template_added', 'form_template', $id);
            }

            Helpers::log(Auth::id(), 'template_uploaded', 'form_template', $id, ['title' => $title]);
            Session::flash('Form uploaded and the team has been notified');
        }

        if ($action === 'toggle') {
            $id  = (int) ($_POST['id'] ?? 0);
            $row = Database::first('SELECT is_active FROM form_templates WHERE id = ?', [$id]);

            if ($row === null) {
                throw new RuntimeException('Form not found');
            }

            Database::update('form_templates', ['is_active' => (int) $row['is_active'] === 1 ? 0 : 1], 'id = ?', [$id]);
            Session::flash('Form visibility updated');
        }

        if ($action === 'delete') {
            $id  = (int) ($_POST['id'] ?? 0);
            $row = Database::first('SELECT * FROM form_templates WHERE id = ?', [$id]);

            if ($row === null) {
                throw new RuntimeException('Form not found');
            }

            Uploader::deleteStored('templates', $row['stored_name']);
            Database::delete('form_templates', 'id = ?', [$id]);

            Helpers::log(Auth::id(), 'template_deleted', 'form_template', $id);
            Session::flash('Form deleted');
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
    }

    redirect('templates.php');
}

$where  = Auth::isAdmin() ? '1 = 1' : 't.is_active = 1';
$rows   = Database::all(
    "SELECT t.*, u.name AS uploaded_by_name
       FROM form_templates t LEFT JOIN users u ON u.id = t.uploaded_by
      WHERE {$where}
      ORDER BY t.category IS NULL, t.category, t.title"
);

// Group by category for a tidier list
$grouped = [];
foreach ($rows as $row) {
    $grouped[$row['category'] ?: 'General'][] = $row;
}

require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Forms &amp; templates</h1>
  <?php if (Auth::isAdmin()): ?>
    <button class="btn btn-app btn-sm" data-bs-toggle="collapse" data-bs-target="#uploadForm">
      <i class="bi bi-upload me-1"></i>Upload form
    </button>
  <?php endif; ?>
</div>

<p class="text-muted small">
  Head office uploads blank forms here. Partners and telecallers download them from the mobile app,
  get them filled and signed by the candidate, then upload the scan back against the lead or project.
</p>

<?php if (Auth::isAdmin()): ?>
<div class="collapse mb-3" id="uploadForm">
  <div class="card"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <div class="col-md-4"><label class="form-label small">File * <span class="text-muted">(PDF, Word, Excel, image)</span></label>
        <input type="file" name="file" class="form-control form-control-sm" required></div>
      <div class="col-md-3"><label class="form-label small">Title *</label>
        <input name="title" class="form-control form-control-sm" placeholder="Candidate Application Form" required></div>
      <div class="col-md-2"><label class="form-label small">Category</label>
        <input name="category" class="form-control form-control-sm" placeholder="application" list="categoryList">
        <datalist id="categoryList">
          <?php foreach (array_keys($grouped) as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-1"><label class="form-label small">Version</label><input name="version" value="1.0" class="form-control form-control-sm"></div>
      <div class="col-md-2"><button class="btn btn-sm btn-app w-100">Upload</button></div>
      <div class="col-12"><input name="description" class="form-control form-control-sm" placeholder="Short description (optional)"></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php if ($rows === []): ?>
  <div class="card"><div class="card-body text-center text-muted py-5">
    No forms uploaded yet.<?= Auth::isAdmin() ? ' Use the Upload form button above.' : ' Head office will add them shortly.' ?>
  </div></div>
<?php else: foreach ($grouped as $category => $items): ?>
  <div class="card mb-3">
    <div class="card-header bg-white"><strong><?= e(ucfirst($category)) ?></strong></div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Form</th><th>File</th><th>Version</th><th>Downloads</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($items as $row): ?>
          <tr<?= (int) $row['is_active'] === 0 ? ' class="opacity-50"' : '' ?>>
            <td>
              <div class="fw-semibold"><?= e($row['title']) ?>
                <?php if ((int) $row['is_active'] === 0): ?><span class="badge bg-secondary">Hidden</span><?php endif; ?>
              </div>
              <?php if ($row['description']): ?><div class="small text-muted"><?= e($row['description']) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= e($row['file_name']) ?><div class="text-muted"><?= e(file_size_display((int) $row['file_size'])) ?></div></td>
            <td class="small"><?= e($row['version']) ?></td>
            <td class="small"><?= number_format((int) $row['download_count']) ?></td>
            <td class="small"><?= e($row['uploaded_by_name'] ?? '—') ?><div class="text-muted"><?= ago($row['created_at']) ?></div></td>
            <td class="text-end text-nowrap">
              <a href="download.php?type=template&id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-app"><i class="bi bi-download me-1"></i>Download</a>
              <?php if (Auth::isAdmin()): ?>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="<?= (int) $row['is_active'] === 1 ? 'Hide from team' : 'Show to team' ?>">
                    <i class="bi bi-eye<?= (int) $row['is_active'] === 1 ? '-slash' : '' ?>"></i>
                  </button>
                </form>
                <form method="post" class="d-inline" data-confirm="Delete this form permanently?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
