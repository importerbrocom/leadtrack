<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

$currentUser = Session::requireLogin();
$pageTitle   = 'Dashboard';

[$leadScope, $leadParams] = Auth::scopeClause('l');
[$projScope, $projParams] = Auth::scopeClause('p');

$callWhere  = '1 = 1';
$callParams = [];
if (!Auth::isAdmin()) {
    $ids        = Auth::visibleUserIds();
    $callWhere  = 'c.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $callParams = $ids;
}

// ---- lead counts by status
$byStatus = [];
foreach (Database::all("SELECT l.status, COUNT(*) AS total FROM leads l WHERE {$leadScope} GROUP BY l.status", $leadParams) as $row) {
    $byStatus[$row['status']] = (int) $row['total'];
}
$totalLeads = array_sum($byStatus);

// ---- callbacks
$followUps = Database::first(
    "SELECT SUM(CASE WHEN DATE(l.next_follow_up_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
            SUM(CASE WHEN l.next_follow_up_at < NOW() THEN 1 ELSE 0 END) AS overdue
       FROM leads l
      WHERE {$leadScope} AND l.next_follow_up_at IS NOT NULL
        AND l.status NOT IN ('converted','lost','invalid','dnd')",
    $leadParams
) ?? [];

// ---- calls today
$callsToday = Database::first(
    "SELECT COUNT(*) AS total, SUM(c.answered) AS connected, COALESCE(SUM(c.duration_sec),0) AS seconds
       FROM call_logs c WHERE {$callWhere} AND DATE(c.started_at) = CURDATE()",
    $callParams
) ?? [];

// ---- projects
$projectsByStatus = [];
foreach (Database::all("SELECT p.status, COUNT(*) AS total FROM projects p WHERE {$projScope} GROUP BY p.status", $projParams) as $row) {
    $projectsByStatus[$row['status']] = (int) $row['total'];
}
$activeProjects = array_sum(array_diff_key($projectsByStatus, array_flip(['cancelled', 'completed'])));

$convertedThisMonth = (int) Database::scalar(
    "SELECT COUNT(*) FROM leads l WHERE {$leadScope} AND l.converted_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    $leadParams
);

$pendingDocuments = Auth::isAdmin()
    ? (int) Database::scalar("SELECT COUNT(*) FROM documents WHERE verification_status = 'pending'")
    : 0;

// ---- overdue callbacks list
$overdueList = Database::all(
    "SELECT l.id, l.name, l.phone, l.next_follow_up_at, l.status, u.name AS assigned_name
       FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
      WHERE {$leadScope} AND l.next_follow_up_at < NOW()
        AND l.status NOT IN ('converted','lost','invalid','dnd')
      ORDER BY l.next_follow_up_at ASC LIMIT 8",
    $leadParams
);

// ---- today's team activity
$teamToday = Database::all(
    "SELECT u.id, u.name, u.role,
            COUNT(c.id) AS calls,
            COALESCE(SUM(c.duration_sec),0) AS seconds,
            SUM(c.answered) AS connected
       FROM users u
       LEFT JOIN call_logs c ON c.user_id = u.id AND DATE(c.started_at) = CURDATE()
      WHERE u.is_active = 1 AND u.role <> 'admin'"
        . (Auth::isAdmin() ? '' : ' AND (u.id = ? OR u.parent_id = ?)')
        . " GROUP BY u.id, u.name, u.role
      ORDER BY calls DESC, u.name LIMIT 10",
    Auth::isAdmin() ? [] : [Auth::id(), Auth::id()]
);

// ---- recent leads
$recentLeads = Database::all(
    "SELECT l.id, l.name, l.phone, l.status, l.priority, l.created_at, u.name AS assigned_name
       FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
      WHERE {$leadScope} ORDER BY l.created_at DESC LIMIT 8",
    $leadParams
);

$conversionRate = $totalLeads > 0 ? round((($byStatus['converted'] ?? 0) / $totalLeads) * 100, 1) : 0;

require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Dashboard</h1>
  <span class="text-muted small"><?= e(date('l, d M Y')) ?></span>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100"><div class="card-body">
      <div class="stat-label">Total leads</div>
      <div class="stat-value"><?= number_format($totalLeads) ?></div>
      <div class="small text-muted"><?= number_format($byStatus['new'] ?? 0) ?> new &middot; <?= number_format($byStatus['interested'] ?? 0) ?> interested</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card warning h-100"><div class="card-body">
      <div class="stat-label">Callbacks today</div>
      <div class="stat-value"><?= number_format((int) ($followUps['today'] ?? 0)) ?></div>
      <div class="small <?= (int) ($followUps['overdue'] ?? 0) > 0 ? 'overdue' : 'text-muted' ?>">
        <?= number_format((int) ($followUps['overdue'] ?? 0)) ?> overdue
      </div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card info h-100"><div class="card-body">
      <div class="stat-label">Calls today</div>
      <div class="stat-value"><?= number_format((int) ($callsToday['total'] ?? 0)) ?></div>
      <div class="small text-muted">
        <?= number_format((int) ($callsToday['connected'] ?? 0)) ?> connected &middot;
        <?= e(Helpers::humanDuration((int) ($callsToday['seconds'] ?? 0))) ?>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card success h-100"><div class="card-body">
      <div class="stat-label">Active projects</div>
      <div class="stat-value"><?= number_format($activeProjects) ?></div>
      <div class="small text-muted"><?= number_format($convertedThisMonth) ?> converted this month</div>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <!-- pipeline -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Lead pipeline</strong>
        <span class="small text-muted"><?= e((string) $conversionRate) ?>% conversion</span>
      </div>
      <div class="card-body">
        <?php
        $pipeline = ['new', 'contacted', 'interested', 'follow_up', 'documents_pending', 'converted', 'not_interested', 'lost'];
        foreach ($pipeline as $status):
            $count   = $byStatus[$status] ?? 0;
            $percent = $totalLeads > 0 ? round($count / $totalLeads * 100) : 0;
        ?>
          <div class="d-flex align-items-center mb-2">
            <div style="width:9.5rem"><a href="leads.php?status=<?= e($status) ?>" class="text-decoration-none small"><?= e(label($status)) ?></a></div>
            <div class="progress flex-grow-1 me-2" style="height:8px">
              <div class="progress-bar bg-<?= $status === 'converted' ? 'success' : ($status === 'lost' || $status === 'not_interested' ? 'secondary' : 'primary') ?>"
                   style="width: <?= $percent ?>%"></div>
            </div>
            <div class="small text-muted" style="width:2.75rem; text-align:right"><?= number_format($count) ?></div>
          </div>
        <?php endforeach; ?>

        <?php if ($pendingDocuments > 0): ?>
          <a href="documents.php?verification_status=pending" class="btn btn-sm btn-outline-warning w-100 mt-3">
            <i class="bi bi-file-earmark-text me-1"></i><?= $pendingDocuments ?> document(s) waiting for verification
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- overdue callbacks -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Overdue callbacks</strong>
        <a href="followups.php?bucket=overdue" class="small">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Lead</th><th>Was due</th><th>Assigned to</th><th></th></tr></thead>
          <tbody>
          <?php if ($overdueList === []): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Nothing overdue. Good work.</td></tr>
          <?php else: foreach ($overdueList as $row): ?>
            <tr>
              <td>
                <a href="lead.php?id=<?= (int) $row['id'] ?>" class="text-decoration-none fw-semibold"><?= e($row['name']) ?></a>
                <div class="text-muted small"><?= e($row['phone']) ?></div>
              </td>
              <td class="small"><?= dt($row['next_follow_up_at']) ?><div class="overdue small"><?= ago($row['next_follow_up_at']) ?></div></td>
              <td class="small"><?= e($row['assigned_name'] ?? '—') ?></td>
              <td class="text-end"><a href="tel:<?= e($row['phone']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-telephone"></i></a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- team activity today -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header bg-white"><strong>Team activity today</strong></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Member</th><th>Role</th><th class="text-end">Calls</th><th class="text-end">Connected</th><th class="text-end">Talk time</th></tr></thead>
          <tbody>
          <?php if ($teamToday === []): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No team members yet</td></tr>
          <?php else: foreach ($teamToday as $row): ?>
            <tr>
              <td><a href="users.php?id=<?= (int) $row['id'] ?>" class="text-decoration-none"><?= e($row['name']) ?></a></td>
              <td><span class="badge bg-light text-dark"><?= e(ucfirst($row['role'])) ?></span></td>
              <td class="text-end"><?= number_format((int) $row['calls']) ?></td>
              <td class="text-end"><?= number_format((int) $row['connected']) ?></td>
              <td class="text-end"><?= e(Helpers::humanDuration((int) $row['seconds'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- recent leads -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Latest leads</strong>
        <a href="leads.php" class="small">View all</a>
      </div>
      <div class="list-group list-group-flush">
        <?php if ($recentLeads === []): ?>
          <div class="text-center text-muted py-4">No leads yet. <a href="leads.php?action=new">Add the first one</a>.</div>
        <?php else: foreach ($recentLeads as $row): ?>
          <a href="lead.php?id=<?= (int) $row['id'] ?>" class="list-group-item list-group-item-action">
            <div class="d-flex justify-content-between">
              <span class="fw-semibold"><?= e($row['name']) ?></span>
              <?= status_badge($row['status']) ?>
            </div>
            <div class="small text-muted">
              <?= e($row['phone']) ?> &middot; <?= e($row['assigned_name'] ?? 'Unassigned') ?> &middot; <?= ago($row['created_at']) ?>
            </div>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
