<?php

/**
 * Call report: everything the app captured automatically.
 */

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

$currentUser = Session::requireLogin();
$pageTitle   = 'Call Report';

$from = (string) q('from', date('Y-m-d', strtotime('-6 days')));
$to   = (string) q('to', date('Y-m-d'));

$where  = ['c.started_at BETWEEN ? AND ?'];
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];

if (!Auth::isAdmin()) {
    $ids     = Auth::visibleUserIds();
    $where[] = 'c.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params  = array_merge($params, $ids);
}

if ($userId = q('user_id')) {
    $where[]  = 'c.user_id = ?';
    $params[] = (int) $userId;
}

if (q('unmatched') === '1') {
    $where[] = 'c.lead_id IS NULL';
}

if ($direction = q('direction')) {
    $where[]  = 'c.direction = ?';
    $params[] = $direction;
}

$whereSql = implode(' AND ', $where);

// ---- totals
$totals = Database::first(
    "SELECT COUNT(*) AS total, SUM(c.answered) AS connected,
            COALESCE(SUM(c.duration_sec),0) AS seconds,
            COUNT(DISTINCT c.lead_id) AS leads_touched,
            SUM(CASE WHEN c.lead_id IS NULL THEN 1 ELSE 0 END) AS unmatched
       FROM call_logs c WHERE {$whereSql}",
    $params
) ?? [];

$totalCalls = (int) ($totals['total'] ?? 0);
$seconds    = (int) ($totals['seconds'] ?? 0);

// ---- per telecaller
$perUser = Database::all(
    "SELECT c.user_id, u.name AS user_name, u.role,
            COUNT(*) AS calls, SUM(c.answered) AS connected,
            COALESCE(SUM(c.duration_sec),0) AS seconds,
            COUNT(DISTINCT c.lead_id) AS leads_touched
       FROM call_logs c LEFT JOIN users u ON u.id = c.user_id
      WHERE {$whereSql}
      GROUP BY c.user_id, u.name, u.role
      ORDER BY calls DESC",
    $params
);

// ---- per day
$perDay = Database::all(
    "SELECT DATE(c.started_at) AS day, COUNT(*) AS calls,
            SUM(c.answered) AS connected, COALESCE(SUM(c.duration_sec),0) AS seconds
       FROM call_logs c WHERE {$whereSql}
      GROUP BY DATE(c.started_at) ORDER BY day",
    $params
);

$maxDayCalls = 0;
foreach ($perDay as $d) {
    $maxDayCalls = max($maxDayCalls, (int) $d['calls']);
}

// ---- recent call rows
$logTotal = $totalCalls;
$pg       = paginate($logTotal, 50);

$calls = Database::all(
    "SELECT c.*, l.name AS lead_name, l.status AS lead_status, u.name AS user_name
       FROM call_logs c
       LEFT JOIN leads l ON l.id = c.lead_id
       LEFT JOIN users u ON u.id = c.user_id
      WHERE {$whereSql}
      ORDER BY c.started_at DESC
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$teamOptions = assignable_users();

require __DIR__ . '/partials/header.php';
?>

<h1 class="h4 mb-3">Call report</h1>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label small mb-1">From</label>
      <input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small mb-1">To</label>
      <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Telecaller</label>
      <select name="user_id" class="form-select form-select-sm">
        <option value="">Everyone</option>
        <?php foreach ($teamOptions as $u): ?>
          <option value="<?= (int) $u['id'] ?>"<?= (string) q('user_id') === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label small mb-1">Direction</label>
      <select name="direction" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['outgoing', 'incoming', 'missed', 'rejected'] as $d): ?>
          <option value="<?= e($d) ?>"<?= q('direction') === $d ? ' selected' : '' ?>><?= e(ucfirst($d)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 form-check ms-2 mb-2">
      <input class="form-check-input" type="checkbox" name="unmatched" value="1" id="unmatched"<?= q('unmatched') === '1' ? ' checked' : '' ?>>
      <label class="form-check-label small" for="unmatched">Unknown numbers only</label>
    </div>
    <div class="col-md-1"><button class="btn btn-sm btn-app w-100">Go</button></div>
  </form>
</div></div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
    <div class="stat-label">Total calls</div>
    <div class="stat-value"><?= number_format($totalCalls) ?></div>
    <div class="small text-muted"><?= number_format((int) ($totals['connected'] ?? 0)) ?> connected</div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card stat-card info h-100"><div class="card-body">
    <div class="stat-label">Talk time</div>
    <div class="stat-value"><?= e(Helpers::humanDuration($seconds)) ?></div>
    <div class="small text-muted">avg <?= e(Helpers::humanDuration($totalCalls > 0 ? (int) round($seconds / $totalCalls) : 0)) ?> per call</div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card stat-card success h-100"><div class="card-body">
    <div class="stat-label">Leads touched</div>
    <div class="stat-value"><?= number_format((int) ($totals['leads_touched'] ?? 0)) ?></div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card stat-card warning h-100"><div class="card-body">
    <div class="stat-label">Unknown numbers</div>
    <div class="stat-value"><?= number_format((int) ($totals['unmatched'] ?? 0)) ?></div>
    <div class="small text-muted">not saved as leads</div>
  </div></div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header bg-white"><strong>Per telecaller</strong></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Member</th><th class="text-end">Calls</th><th class="text-end">Connected</th><th class="text-end">Talk time</th><th class="text-end">Leads</th></tr></thead>
          <tbody>
          <?php if ($perUser === []): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No calls in this period</td></tr>
          <?php else: foreach ($perUser as $r): ?>
            <tr>
              <td><?= e($r['user_name'] ?? '—') ?>
                <?php if ($r['role']): ?><span class="badge bg-light text-dark"><?= e(ucfirst($r['role'])) ?></span><?php endif; ?>
              </td>
              <td class="text-end"><?= number_format((int) $r['calls']) ?></td>
              <td class="text-end"><?= number_format((int) $r['connected']) ?></td>
              <td class="text-end"><?= e(Helpers::humanDuration((int) $r['seconds'])) ?></td>
              <td class="text-end"><?= number_format((int) $r['leads_touched']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header bg-white"><strong>Daily volume</strong></div>
      <div class="card-body">
        <?php if ($perDay === []): ?>
          <p class="text-muted small mb-0">No calls in this period</p>
        <?php else: foreach ($perDay as $d):
            $pct = $maxDayCalls > 0 ? round((int) $d['calls'] / $maxDayCalls * 100) : 0; ?>
          <div class="d-flex align-items-center mb-2">
            <div class="small text-muted" style="width:5.5rem"><?= e(date('d M', strtotime($d['day']))) ?></div>
            <div class="progress flex-grow-1 me-2" style="height:8px">
              <div class="progress-bar" style="width: <?= $pct ?>%"></div>
            </div>
            <div class="small" style="width:5.5rem; text-align:right">
              <?= number_format((int) $d['calls']) ?>
              <span class="text-muted">/ <?= e(Helpers::humanDuration((int) $d['seconds'])) ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white"><strong>Call log</strong></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>When</th><th>Number</th><th>Lead</th><th>Direction</th><th>Duration</th><th>Outcome</th><th>By</th><th>Notes</th></tr></thead>
      <tbody>
      <?php if ($calls === []): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">No calls recorded in this period.</td></tr>
      <?php else: foreach ($calls as $c): ?>
        <tr>
          <td class="small text-nowrap"><?= dt($c['started_at']) ?></td>
          <td class="small"><?= e($c['phone_number']) ?></td>
          <td class="small">
            <?php if ($c['lead_id'] !== null): ?>
              <a href="lead.php?id=<?= (int) $c['lead_id'] ?>" class="text-decoration-none"><?= e($c['lead_name']) ?></a>
            <?php else: ?>
              <span class="badge bg-light text-muted">Not a lead</span>
            <?php endif; ?>
          </td>
          <td class="small">
            <i class="bi bi-<?= $c['direction'] === 'incoming' ? 'telephone-inbound' : ($c['direction'] === 'missed' ? 'telephone-x' : 'telephone-outbound') ?> me-1"></i>
            <?= e(ucfirst($c['direction'])) ?>
          </td>
          <td class="small <?= (int) $c['duration_sec'] === 0 ? 'text-muted' : '' ?>"><?= e(Helpers::humanDuration((int) $c['duration_sec'])) ?></td>
          <td class="small"><?= $c['disposition'] ? e(label($c['disposition'])) : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= e($c['user_name'] ?? '—') ?></td>
          <td class="small text-muted"><?= e($c['notes'] ?? '') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex justify-content-end"><?php render_pagination($pg); ?></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
