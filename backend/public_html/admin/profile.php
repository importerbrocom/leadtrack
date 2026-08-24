<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

$currentUser = Session::requireLogin();
$pageTitle   = 'My account';

if (is_post()) {
    Session::verifyCsrf();

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new     = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            $hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [Auth::id()]);

            if (!password_verify($current, $hash)) {
                throw new RuntimeException('Your current password is incorrect');
            }

            if (strlen($new) < 6) {
                throw new RuntimeException('New password must be at least 6 characters');
            }

            if ($new !== $confirm) {
                throw new RuntimeException('The two new passwords do not match');
            }

            Database::update('users', ['password_hash' => password_hash($new, PASSWORD_BCRYPT)], 'id = ?', [Auth::id()]);
            Database::update('auth_tokens', ['revoked_at' => Helpers::now()], 'user_id = ? AND revoked_at IS NULL', [Auth::id()]);

            Helpers::log(Auth::id(), 'password_changed', 'user', Auth::id());
            Session::flash('Password changed. Any app sessions have been signed out.');
        }

        if ($action === 'profile') {
            Database::update('users', [
                'name'  => trim((string) ($_POST['name'] ?? '')) ?: $currentUser['name'],
                'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'city'  => trim((string) ($_POST['city'] ?? '')) ?: null,
                'state' => trim((string) ($_POST['state'] ?? '')) ?: null,
            ], 'id = ?', [Auth::id()]);

            Session::flash('Profile saved');
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
    }

    redirect('profile.php');
}

$devices = Database::all(
    'SELECT device_name, device_id, app_version, last_used_at, created_at
       FROM auth_tokens
      WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
      ORDER BY last_used_at DESC',
    [Auth::id()]
);

require __DIR__ . '/partials/header.php';
?>

<h1 class="h4 mb-3">My account</h1>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Profile</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="profile">
          <div class="col-12"><label class="form-label small">Name</label>
            <input name="name" value="<?= e($currentUser['name']) ?>" class="form-control form-control-sm"></div>
          <div class="col-12"><label class="form-label small">Phone (login) </label>
            <input value="<?= e($currentUser['phone']) ?>" class="form-control form-control-sm" disabled>
            <div class="form-text small">Ask head office if your login number needs to change.</div>
          </div>
          <div class="col-12"><label class="form-label small">Email</label>
            <input type="email" name="email" value="<?= e($currentUser['email']) ?>" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">City</label>
            <input name="city" value="<?= e($currentUser['city']) ?>" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">State</label>
            <input name="state" value="<?= e($currentUser['state']) ?>" class="form-control form-control-sm"></div>
          <div class="col-12 text-end"><button class="btn btn-sm btn-app">Save profile</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Change password</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">
          <div class="col-12"><label class="form-label small">Current password</label>
            <input type="password" name="current_password" class="form-control form-control-sm" required></div>
          <div class="col-12"><label class="form-label small">New password</label>
            <input type="password" name="new_password" class="form-control form-control-sm" minlength="6" required></div>
          <div class="col-12"><label class="form-label small">Confirm new password</label>
            <input type="password" name="confirm_password" class="form-control form-control-sm" minlength="6" required></div>
          <div class="col-12 text-end"><button class="btn btn-sm btn-app">Change password</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-white"><strong>Signed-in app devices</strong></div>
      <div class="card-body">
        <?php if ($devices === []): ?>
          <p class="text-muted small mb-0">No mobile app sessions.</p>
        <?php else: ?>
          <ul class="list-unstyled small mb-0">
            <?php foreach ($devices as $d): ?>
              <li class="d-flex justify-content-between py-1 border-bottom">
                <span>
                  <i class="bi bi-phone me-1"></i><?= e($d['device_name'] ?: 'Unknown device') ?>
                  <?php if ($d['app_version']): ?><span class="text-muted">v<?= e($d['app_version']) ?></span><?php endif; ?>
                </span>
                <span class="text-muted"><?= $d['last_used_at'] ? ago($d['last_used_at']) : '—' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
