<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;

$currentUser = Session::requireLogin();
$pageTitle   = 'Notifications';

if (is_post()) {
    Session::verifyCsrf();
    Database::query('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [Auth::id()]);
    Session::flash('All notifications marked as read', 'info');
    redirect('notifications.php');
}

$total = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [Auth::id()]);
$pg    = paginate($total, 40);

$rows = Database::all(
    "SELECT * FROM notifications WHERE user_id = ?
      ORDER BY created_at DESC LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    [Auth::id()]
);

/** Where should clicking a notification take you? */
function notification_link(array $n): ?string
{
    return match ($n['ref_type']) {
        'lead'     => 'lead.php?id=' . (int) $n['ref_id'],
        'project'  => 'project.php?id=' . (int) $n['ref_id'],
        'document' => 'documents.php',
        'form_template' => 'templates.php',
        default    => null,
    };
}

require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Notifications</h1>
  <form method="post">
    <?= csrf_field() ?>
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-all me-1"></i>Mark all read</button>
  </form>
</div>

<div class="card">
  <div class="list-group list-group-flush">
    <?php if ($rows === []): ?>
      <div class="text-center text-muted py-5">Nothing here yet.</div>
    <?php else: foreach ($rows as $n):
        $link = notification_link($n); ?>
      <?php if ($link !== null): ?><a href="<?= e($link) ?>" class="list-group-item list-group-item-action<?= (int) $n['is_read'] === 0 ? ' bg-light' : '' ?>">
      <?php else: ?><div class="list-group-item<?= (int) $n['is_read'] === 0 ? ' bg-light' : '' ?>"><?php endif; ?>
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="fw-semibold">
              <?php if ((int) $n['is_read'] === 0): ?><span class="badge bg-primary rounded-pill">&nbsp;</span><?php endif; ?>
              <?= e($n['title']) ?>
            </div>
            <?php if ($n['body']): ?><div class="small text-muted"><?= e($n['body']) ?></div><?php endif; ?>
          </div>
          <span class="small text-muted text-nowrap ms-3"><?= ago($n['created_at']) ?></span>
        </div>
      <?php if ($link !== null): ?></a><?php else: ?></div><?php endif; ?>
    <?php endforeach; endif; ?>
  </div>
  <div class="card-footer bg-white d-flex justify-content-end"><?php render_pagination($pg); ?></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
