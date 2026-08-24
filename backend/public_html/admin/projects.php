<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Controllers\ProjectController;
use App\Core\Auth;
use App\Core\Database;

$currentUser = Session::requireLogin();
$pageTitle   = 'Projects';

[$scopeSql, $scopeParams] = Auth::scopeClause('p');
$params = $scopeParams;
$where  = [$scopeSql];

if ($status = q('status')) {
    $where[]  = 'p.status = ?';
    $params[] = $status;
}

if ($partner = q('partner_id')) {
    $where[]  = 'p.partner_id = ?';
    $params[] = (int) $partner;
}

if ($country = q('country')) {
    $where[]  = 'p.destination_country = ?';
    $params[] = $country;
}

if ($search = q('search')) {
    $where[] = '(p.candidate_name LIKE ? OR p.candidate_phone LIKE ? OR p.project_code LIKE ? OR p.passport_no LIKE ? OR p.employer_name LIKE ?)';
    $like    = '%' . $search . '%';
    $params  = array_merge($params, [$like, $like, $like, $like, $like]);
}

$whereSql = implode(' AND ', $where);

$total = (int) Database::scalar("SELECT COUNT(*) FROM projects p WHERE {$whereSql}", $params);
$pg    = paginate($total, 30);

$projects = Database::all(
    "SELECT p.*, pu.name AS partner_name, au.name AS assigned_name, jc.name AS job_category_name,
            (SELECT COUNT(*) FROM documents d WHERE d.project_id = p.id) AS doc_count,
            (SELECT COUNT(*) FROM documents d WHERE d.project_id = p.id AND d.verification_status = 'pending') AS doc_pending
       FROM projects p
       LEFT JOIN users pu ON pu.id = p.partner_id
       LEFT JOIN users au ON au.id = p.assigned_to
       LEFT JOIN job_categories jc ON jc.id = p.job_category_id
      WHERE {$whereSql}
      ORDER BY p.updated_at DESC
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

// Stage counts for the filter dropdown - scope only, ignoring the other filters.
$counts = [];
foreach (Database::all(
    "SELECT p.status, COUNT(*) AS total FROM projects p WHERE {$scopeSql} GROUP BY p.status",
    $scopeParams
) as $row) {
    $counts[$row['status']] = (int) $row['total'];
}

$countries = Database::all(
    "SELECT DISTINCT destination_country AS name, destination_country AS id
       FROM projects WHERE destination_country IS NOT NULL AND destination_country <> '' ORDER BY name"
);

require __DIR__ . '/partials/header.php';
?>

<h1 class="h4 mb-3">Projects <span class="text-muted fs-6">(<?= number_format($total) ?>)</span></h1>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" data-autosubmit class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small mb-1">Search</label>
      <input name="search" value="<?= e(q('search')) ?>" class="form-control form-control-sm" placeholder="Name, code, passport, employer">
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">Stage</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All stages</option>
        <?php foreach (ProjectController::STATUSES as $s): ?>
          <option value="<?= e($s) ?>"<?= q('status') === $s ? ' selected' : '' ?>>
            <?= e(label($s)) ?><?= isset($counts[$s]) ? ' (' . $counts[$s] . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">Country</label>
      <select name="country" class="form-select form-select-sm">
        <option value="">All</option><?= select_options($countries, q('country')) ?>
      </select>
    </div>
    <?php if (Auth::isAdmin()): ?>
    <div class="col-md-2">
      <label class="form-label small mb-1">Partner</label>
      <select name="partner_id" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (lookup('partners') as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= (string) q('partner_id') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-md-2">
      <button class="btn btn-sm btn-app">Apply</button>
      <a href="projects.php" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
  </form>
</div></div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Code</th><th>Candidate</th><th>Placement</th><th>Stage</th>
          <th>Docs</th>
          <?php if (Auth::isAdmin()): ?><th class="text-end">Balance</th><?php endif; ?>
          <th>Owner</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($projects === []): ?>
        <tr><td colspan="<?= Auth::isAdmin() ? 8 : 7 ?>" class="text-center text-muted py-5">
          No projects yet. Convert an interested lead from its <a href="leads.php?status=interested">lead page</a>.
        </td></tr>
      <?php else: foreach ($projects as $p): ?>
        <tr>
          <td class="small"><a href="project.php?id=<?= (int) $p['id'] ?>" class="text-decoration-none fw-semibold"><?= e($p['project_code']) ?></a></td>
          <td>
            <div class="fw-semibold"><?= e($p['candidate_name']) ?></div>
            <div class="small text-muted"><?= e($p['candidate_phone']) ?><?= $p['passport_no'] ? ' · ' . e($p['passport_no']) : '' ?></div>
          </td>
          <td class="small">
            <?= e($p['position'] ?: ($p['job_category_name'] ?? '—')) ?>
            <div class="text-muted"><?= e($p['destination_country'] ?? '') ?><?= $p['employer_name'] ? ' · ' . e($p['employer_name']) : '' ?></div>
          </td>
          <td><?= status_badge($p['status']) ?></td>
          <td class="small">
            <?= (int) $p['doc_count'] ?>
            <?php if ((int) $p['doc_pending'] > 0): ?>
              <span class="badge bg-warning text-dark"><?= (int) $p['doc_pending'] ?> pending</span>
            <?php endif; ?>
          </td>
          <?php if (Auth::isAdmin()): ?>
            <td class="text-end small">
              <?= money((float) $p['agreed_amount'] - (float) $p['paid_amount']) ?>
              <div class="text-muted">of <?= money($p['agreed_amount']) ?></div>
            </td>
          <?php endif; ?>
          <td class="small">
            <?= e($p['assigned_name'] ?? '—') ?>
            <?php if (Auth::isAdmin() && $p['partner_name']): ?><div class="text-muted"><?= e($p['partner_name']) ?></div><?php endif; ?>
          </td>
          <td class="text-end"><a href="project.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex justify-content-end"><?php render_pagination($pg); ?></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
