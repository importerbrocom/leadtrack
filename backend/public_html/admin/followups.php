<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

$currentUser = Session::requireLogin();
$pageTitle   = 'Callbacks';

if (is_post()) {
    Session::verifyCsrf();

    try {
        $id  = (int) ($_POST['follow_up_id'] ?? 0);
        $row = Database::first(
            'SELECT f.*, l.partner_id, l.assigned_to FROM follow_ups f JOIN leads l ON l.id = f.lead_id WHERE f.id = ?',
            [$id]
        );

        if ($row === null) {
            throw new RuntimeException('Callback not found');
        }

        Auth::assertCanAccessLead($row);

        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['done', 'missed', 'cancelled', 'pending'], true)) {
            throw new RuntimeException('Unknown status');
        }

        Database::transaction(function () use ($id, $status, $row) {
            Database::update('follow_ups', [
                'status'       => $status,
                'completed_at' => $status === 'done' ? Helpers::now() : null,
            ], 'id = ?', [$id]);

            $earliest = Database::scalar(
                "SELECT MIN(scheduled_at) FROM follow_ups WHERE lead_id = ? AND status = 'pending'",
                [(int) $row['lead_id']]
            );

            Database::update('leads', ['next_follow_up_at' => $earliest], 'id = ?', [(int) $row['lead_id']]);
        });

        Session::flash('Callback marked as ' . $status);
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
    }

    redirect('followups.php?' . query_with([]));
}

$bucket = (string) q('bucket', 'today');

$where  = [];
$params = [];

if (!Auth::isAdmin()) {
    $ids     = Auth::visibleUserIds();
    $where[] = 'f.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params  = array_merge($params, $ids);
} else {
    $where[] = '1 = 1';
}

if ($userId = q('user_id')) {
    $where[]  = 'f.user_id = ?';
    $params[] = (int) $userId;
}

switch ($bucket) {
    case 'overdue':
        $where[] = "f.status = 'pending' AND f.scheduled_at < NOW()";
        break;
    case 'upcoming':
        $where[] = "f.status = 'pending' AND f.scheduled_at > NOW()";
        break;
    case 'week':
        $where[] = "f.status = 'pending' AND f.scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
        break;
    case 'done':
        $where[] = "f.status = 'done'";
        break;
    case 'all':
        break;
    case 'today':
    default:
        $where[] = "f.status = 'pending' AND DATE(f.scheduled_at) = CURDATE()";
        break;
}

$whereSql = implode(' AND ', $where);

$total = (int) Database::scalar("SELECT COUNT(*) FROM follow_ups f WHERE {$whereSql}", $params);
$pg    = paginate($total, 40);

$rows = Database::all(
    "SELECT f.*, l.name AS lead_name, l.phone AS lead_phone, l.status AS lead_status,
            l.priority AS lead_priority, l.city AS lead_city, u.name AS user_name
       FROM follow_ups f
       JOIN leads l ON l.id = f.lead_id
       LEFT JOIN users u ON u.id = f.user_id
      WHERE {$whereSql}
      ORDER BY f.scheduled_at ASC
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$buckets = [
    'today'    => 'Today',
    'overdue'  => 'Overdue',
    'week'     => 'Next 7 days',
    'upcoming' => 'All upcoming',
    'done'     => 'Completed',
    'all'      => 'Everything',
];

require __DIR__ . '/partials/header.php';
?>

<h1 class="h4 mb-3">Callbacks <span class="text-muted fs-6">(<?= number_format($total) ?>)</span></h1>

<ul class="nav nav-pills mb-3 small">
  <?php foreach ($buckets as $key => $labelText): ?>
    <li class="nav-item">
      <a class="nav-link <?= $bucket === $key ? 'active' : '' ?>" href="?bucket=<?= e($key) ?>"><?= e($labelText) ?></a>
    </li>
  <?php endforeach; ?>
</ul>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Scheduled</th><th>Lead</th><th>Lead status</th><th>Reason</th><th>Telecaller</th><th>State</th><th></th></tr></thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="7" class="text-center text-muted py-5">Nothing here.</td></tr>
      <?php else: foreach ($rows as $row):
          $overdue = $row['status'] === 'pending' && strtotime($row['scheduled_at']) < time(); ?>
        <tr>
          <td class="small text-nowrap <?= $overdue ? 'overdue' : '' ?>">
            <?= dt($row['scheduled_at']) ?>
            <div><?= ago($row['scheduled_at']) ?></div>
          </td>
          <td>
            <a href="lead.php?id=<?= (int) $row['lead_id'] ?>" class="text-decoration-none fw-semibold"><?= e($row['lead_name']) ?></a>
            <?= $row['lead_priority'] === 'high' ? ' ' . priority_badge('high') : '' ?>
            <div class="small text-muted"><?= e($row['lead_phone']) ?><?= $row['lead_city'] ? ' · ' . e($row['lead_city']) : '' ?></div>
          </td>
          <td><?= status_badge($row['lead_status']) ?></td>
          <td class="small text-muted"><?= e($row['remarks'] ?? '') ?></td>
          <td class="small"><?= e($row['user_name'] ?? '—') ?></td>
          <td>
            <span class="badge bg-<?= $row['status'] === 'done' ? 'success' : ($row['status'] === 'pending' ? 'warning' : 'secondary') ?>">
              <?= e(ucfirst($row['status'])) ?>
            </span>
          </td>
          <td class="text-end text-nowrap">
            <a href="tel:<?= e($row['lead_phone']) ?>" class="btn btn-sm btn-outline-primary" title="Call"><i class="bi bi-telephone"></i></a>
            <?php if ($row['status'] === 'pending'): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="follow_up_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="done">
                <button class="btn btn-sm btn-outline-success" title="Mark done"><i class="bi bi-check2"></i></button>
              </form>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="follow_up_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="cancelled">
                <button class="btn btn-sm btn-outline-secondary" title="Cancel"><i class="bi bi-x"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex justify-content-end"><?php render_pagination($pg); ?></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
