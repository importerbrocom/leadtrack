<?php

/**
 * Every document the field team has uploaded, with bulk verification.
 */

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

$currentUser = Session::requireLogin();
$pageTitle   = 'Documents';

if (is_post()) {
    Session::verifyCsrf();

    try {
        Session::requireAdmin();

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'verify') {
            $docId  = (int) ($_POST['document_id'] ?? 0);
            $status = (string) ($_POST['verification_status'] ?? '');
            $reason = trim((string) ($_POST['reject_reason'] ?? '')) ?: null;

            if (!in_array($status, ['pending', 'verified', 'rejected'], true)) {
                throw new RuntimeException('Unknown status');
            }

            if ($status === 'rejected' && $reason === null) {
                throw new RuntimeException('Give a rejection reason');
            }

            $doc = Database::first('SELECT * FROM documents WHERE id = ?', [$docId]);
            if ($doc === null) {
                throw new RuntimeException('Document not found');
            }

            Database::update('documents', [
                'verification_status' => $status,
                'reject_reason'       => $status === 'rejected' ? $reason : null,
                'verified_by'         => Auth::id(),
                'verified_at'         => Helpers::now(),
            ], 'id = ?', [$docId]);

            Helpers::notify(
                (int) $doc['uploaded_by'],
                'Document ' . $status,
                $doc['file_name'] . ($status === 'rejected' ? ' — ' . $reason : ''),
                'document_verified',
                'document',
                $docId
            );

            Session::flash('Document marked ' . $status);
        }

        if ($action === 'bulk_verify') {
            $ids  = array_map('intval', (array) ($_POST['document_ids'] ?? []));
            $done = 0;

            foreach ($ids as $docId) {
                $doc = Database::first('SELECT * FROM documents WHERE id = ?', [$docId]);
                if ($doc === null) {
                    continue;
                }

                Database::update('documents', [
                    'verification_status' => 'verified',
                    'reject_reason'       => null,
                    'verified_by'         => Auth::id(),
                    'verified_at'         => Helpers::now(),
                ], 'id = ?', [$docId]);

                Helpers::notify((int) $doc['uploaded_by'], 'Document verified', $doc['file_name'], 'document_verified', 'document', $docId);
                $done++;
            }

            Session::flash("{$done} document(s) verified");
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
    }

    redirect('documents.php?' . query_with([]));
}

// ---------------------------------------------------------------- filters
$where  = ['1 = 1'];
$params = [];

if (!Auth::isAdmin()) {
    $ids     = Auth::visibleUserIds();
    $ph      = implode(',', array_fill(0, count($ids), '?'));
    $where[] = "(d.uploaded_by IN ({$ph}) OR p.partner_id = ? OR l.partner_id = ?)";
    $params  = array_merge($params, $ids, [Auth::id(), Auth::id()]);
}

if ($status = q('verification_status')) {
    $where[]  = 'd.verification_status = ?';
    $params[] = $status;
}

if ($typeId = q('document_type_id')) {
    $where[]  = 'd.document_type_id = ?';
    $params[] = (int) $typeId;
}

if ($search = q('search')) {
    $where[] = '(d.file_name LIKE ? OR d.title LIKE ? OR p.candidate_name LIKE ? OR l.name LIKE ? OR p.project_code LIKE ?)';
    $like    = '%' . $search . '%';
    $params  = array_merge($params, [$like, $like, $like, $like, $like]);
}

$whereSql = implode(' AND ', $where);

$fromSql = 'FROM documents d
            LEFT JOIN projects p ON p.id = d.project_id
            LEFT JOIN leads l ON l.id = d.lead_id
            LEFT JOIN document_types dt ON dt.id = d.document_type_id
            LEFT JOIN users u ON u.id = d.uploaded_by
            LEFT JOIN users v ON v.id = d.verified_by';

$total = (int) Database::scalar("SELECT COUNT(*) {$fromSql} WHERE {$whereSql}", $params);
$pg    = paginate($total, 40);

$docs = Database::all(
    "SELECT d.*, dt.name AS document_type_name, u.name AS uploaded_by_name, v.name AS verified_by_name,
            p.project_code, p.candidate_name, l.name AS lead_name
       {$fromSql}
      WHERE {$whereSql}
      ORDER BY FIELD(d.verification_status,'pending','rejected','verified'), d.created_at DESC
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$pendingTotal = (int) Database::scalar(
    "SELECT COUNT(*) {$fromSql} WHERE {$whereSql} AND d.verification_status = 'pending'",
    $params
);

require __DIR__ . '/partials/header.php';
?>

<h1 class="h4 mb-3">
  Documents <span class="text-muted fs-6">(<?= number_format($total) ?>)</span>
  <?php if ($pendingTotal > 0): ?>
    <span class="badge bg-warning text-dark"><?= number_format($pendingTotal) ?> awaiting verification</span>
  <?php endif; ?>
</h1>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" data-autosubmit class="row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label small mb-1">Search</label>
      <input name="search" value="<?= e(q('search')) ?>" class="form-control form-control-sm" placeholder="Candidate, file name, project code">
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">Verification</label>
      <select name="verification_status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['pending', 'verified', 'rejected'] as $s): ?>
          <option value="<?= e($s) ?>"<?= q('verification_status') === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">Document type</label>
      <select name="document_type_id" class="form-select form-select-sm">
        <option value="">All types</option><?= select_options(lookup('doc_types'), q('document_type_id')) ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-sm btn-app">Apply</button>
      <a href="documents.php" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
  </form>
</div></div>

<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="bulk_verify">

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <?php if (Auth::isAdmin()): ?><th style="width:2rem"><input type="checkbox" class="form-check-input" id="checkAll"></th><?php endif; ?>
          <th>Document</th><th>Belongs to</th><th>Type</th><th>Status</th><th>Uploaded by</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($docs === []): ?>
        <tr><td colspan="<?= Auth::isAdmin() ? 7 : 6 ?>" class="text-center text-muted py-5">No documents match these filters.</td></tr>
      <?php else: foreach ($docs as $doc): ?>
        <tr>
          <?php if (Auth::isAdmin()): ?>
            <td>
              <?php if ($doc['verification_status'] !== 'verified'): ?>
                <input type="checkbox" class="form-check-input row-check" name="document_ids[]" value="<?= (int) $doc['id'] ?>">
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td>
            <div class="small fw-semibold"><?= e($doc['title'] ?: $doc['file_name']) ?></div>
            <div class="small text-muted"><?= e($doc['file_name']) ?> &middot; <?= e(file_size_display((int) $doc['file_size'])) ?></div>
            <?php if ($doc['reject_reason']): ?>
              <div class="small text-danger">Rejected: <?= e($doc['reject_reason']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($doc['project_id'] !== null): ?>
              <a href="project.php?id=<?= (int) $doc['project_id'] ?>" class="text-decoration-none"><?= e($doc['candidate_name']) ?></a>
              <div class="text-muted"><code class="small-code"><?= e($doc['project_code']) ?></code></div>
            <?php elseif ($doc['lead_id'] !== null): ?>
              <a href="lead.php?id=<?= (int) $doc['lead_id'] ?>" class="text-decoration-none"><?= e($doc['lead_name']) ?></a>
              <div class="text-muted">Lead stage</div>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($doc['document_type_name'] ?? '—') ?>
            <?php if ($doc['expiry_date']): ?><div class="text-muted">exp <?= dt($doc['expiry_date'], false) ?></div><?php endif; ?>
          </td>
          <td>
            <?= status_badge($doc['verification_status']) ?>
            <?php if ($doc['verified_by_name']): ?>
              <div class="small text-muted">by <?= e($doc['verified_by_name']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($doc['uploaded_by_name'] ?? '—') ?><div class="text-muted"><?= ago($doc['created_at']) ?></div></td>
          <td class="text-end text-nowrap">
            <a href="download.php?type=document&id=<?= (int) $doc['id'] ?>" class="btn btn-sm btn-app" title="Download">
              <i class="bi bi-download"></i>
            </a>
            <?php if (Auth::isAdmin()): ?>
              <?php if ($doc['verification_status'] !== 'verified'): ?>
                <button class="btn btn-sm btn-outline-success" title="Verify"
                        formaction="documents.php?<?= e(query_with([])) ?>"
                        name="__single_verify" value="<?= (int) $doc['id'] ?>"
                        onclick="return singleVerify(this, <?= (int) $doc['id'] ?>)"><i class="bi bi-check2"></i></button>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-outline-danger" title="Reject"
                      data-bs-toggle="modal" data-bs-target="#reject<?= (int) $doc['id'] ?>"><i class="bi bi-x"></i></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (Auth::isAdmin()): ?>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <span class="small text-muted me-2"><span id="selCount">0</span> selected</span>
        <button class="btn btn-sm btn-outline-success" id="bulkBtn" disabled>
          <i class="bi bi-check2-all me-1"></i>Verify selected
        </button>
      </div>
      <?php render_pagination($pg); ?>
    </div>
  <?php else: ?>
    <div class="card-footer bg-white d-flex justify-content-end"><?php render_pagination($pg); ?></div>
  <?php endif; ?>
</div>
</form>

<?php if (Auth::isAdmin()): foreach ($docs as $doc): ?>
  <div class="modal fade" id="reject<?= (int) $doc['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
        <input type="hidden" name="verification_status" value="rejected">
        <div class="modal-header"><h5 class="modal-title">Reject document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted mb-2"><?= e($doc['file_name']) ?></p>
          <label class="form-label small">Reason (shown to the uploader)</label>
          <textarea name="reject_reason" rows="3" class="form-control form-control-sm" required></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-sm btn-danger">Reject</button>
        </div>
      </form>
    </div>
  </div>

  <form method="post" id="verifyForm<?= (int) $doc['id'] ?>" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
    <input type="hidden" name="verification_status" value="verified">
  </form>
<?php endforeach; endif; ?>

<script>
function singleVerify(button, id) {
  document.getElementById('verifyForm' + id).submit();
  return false;
}

(function () {
  var checkAll = document.getElementById('checkAll');
  var rows     = Array.prototype.slice.call(document.querySelectorAll('.row-check'));
  var counter  = document.getElementById('selCount');
  var button   = document.getElementById('bulkBtn');

  if (!counter) { return; }

  function refresh() {
    var n = rows.filter(function (r) { return r.checked; }).length;
    counter.textContent = n;
    button.disabled = n === 0;
  }

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      rows.forEach(function (r) { r.checked = checkAll.checked; });
      refresh();
    });
  }

  rows.forEach(function (r) { r.addEventListener('change', refresh); });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
