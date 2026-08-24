<?php

/**
 * Admin: manage partners (franchises) and their telecallers.
 * Partner: manage only their own telecallers.
 */

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

$currentUser = Session::requireLogin();
$pageTitle   = Auth::isAdmin() ? 'Partners & Team' : 'My Telecallers';

if (is_post()) {
    Session::verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $role     = (string) ($_POST['role'] ?? '');
            $name     = trim((string) ($_POST['name'] ?? ''));
            $phone    = trim((string) ($_POST['phone'] ?? ''));
            $email    = trim((string) ($_POST['email'] ?? '')) ?: null;
            $password = (string) ($_POST['password'] ?? '');

            if (!in_array($role, ['partner', 'telecaller'], true)) {
                throw new RuntimeException('Choose a valid role');
            }

            if ($name === '' || strlen((string) Helpers::normalizePhone($phone)) < 10) {
                throw new RuntimeException('Enter a name and a valid 10-digit phone number');
            }

            if (strlen($password) < 6) {
                throw new RuntimeException('Password must be at least 6 characters');
            }

            if (Auth::isPartner()) {
                if ($role !== 'telecaller') {
                    throw new RuntimeException('You can only create telecaller accounts');
                }

                $parentId = Auth::id();

                $limit   = (int) Database::scalar('SELECT max_telecallers FROM users WHERE id = ?', [Auth::id()]);
                $current = (int) Database::scalar(
                    "SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = 'telecaller' AND is_active = 1",
                    [Auth::id()]
                );

                if ($limit > 0 && $current >= $limit) {
                    throw new RuntimeException("You have reached your limit of {$limit} telecallers. Ask head office to raise it.");
                }
            } else {
                $parentId = $role === 'telecaller' ? ((int) ($_POST['parent_id'] ?? 0) ?: null) : null;

                if ($role === 'telecaller' && $parentId === null) {
                    throw new RuntimeException('Choose which partner this telecaller works under');
                }
            }

            if (Database::scalar('SELECT id FROM users WHERE phone = ?', [$phone]) !== null) {
                throw new RuntimeException('An account with this phone number already exists');
            }

            if ($email !== null && Database::scalar('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
                throw new RuntimeException('An account with this email already exists');
            }

            $id = Database::insert('users', [
                'parent_id'       => $parentId,
                'role'            => $role,
                'name'            => mb_substr($name, 0, 120),
                'phone'           => mb_substr($phone, 0, 20),
                'email'           => $email,
                'password_hash'   => password_hash($password, PASSWORD_BCRYPT),
                'agency_name'     => trim((string) ($_POST['agency_name'] ?? '')) ?: null,
                'city'            => trim((string) ($_POST['city'] ?? '')) ?: null,
                'state'           => trim((string) ($_POST['state'] ?? '')) ?: null,
                'max_telecallers' => $role === 'partner' ? (int) ($_POST['max_telecallers'] ?? 10) : 0,
                'is_active'       => 1,
                'created_by'      => Auth::id(),
            ]);

            Helpers::log(Auth::id(), 'user_created', 'user', $id, ['role' => $role]);
            Session::flash(ucfirst($role) . ' "' . $name . '" created. They can sign in to the app with this phone number.');
        }

        if ($action === 'update') {
            $id     = (int) ($_POST['id'] ?? 0);
            $target = Database::first('SELECT * FROM users WHERE id = ?', [$id]);

            if ($target === null) {
                throw new RuntimeException('User not found');
            }

            Auth::assertCanManageUser($target);

            $update = [
                'name'        => trim((string) ($_POST['name'] ?? '')) ?: $target['name'],
                'email'       => trim((string) ($_POST['email'] ?? '')) ?: null,
                'agency_name' => trim((string) ($_POST['agency_name'] ?? '')) ?: null,
                'city'        => trim((string) ($_POST['city'] ?? '')) ?: null,
                'state'       => trim((string) ($_POST['state'] ?? '')) ?: null,
            ];

            $phone = trim((string) ($_POST['phone'] ?? ''));
            if ($phone !== '' && $phone !== $target['phone']) {
                if (Database::scalar('SELECT id FROM users WHERE phone = ? AND id <> ?', [$phone, $id]) !== null) {
                    throw new RuntimeException('Another account already uses this phone number');
                }
                $update['phone'] = $phone;
            }

            if ($update['email'] !== null
                && Database::scalar('SELECT id FROM users WHERE email = ? AND id <> ?', [$update['email'], $id]) !== null) {
                throw new RuntimeException('Another account already uses this email');
            }

            $password = (string) ($_POST['password'] ?? '');
            if ($password !== '') {
                if (strlen($password) < 6) {
                    throw new RuntimeException('Password must be at least 6 characters');
                }
                $update['password_hash'] = password_hash($password, PASSWORD_BCRYPT);

                // Force their devices to sign in again with the new password.
                Database::update('auth_tokens', ['revoked_at' => Helpers::now()], 'user_id = ? AND revoked_at IS NULL', [$id]);
            }

            if (Auth::isAdmin() && $target['role'] === 'partner') {
                $update['max_telecallers'] = (int) ($_POST['max_telecallers'] ?? $target['max_telecallers']);
            }

            Database::update('users', $update, 'id = ?', [$id]);
            Helpers::log(Auth::id(), 'user_updated', 'user', $id);
            Session::flash('Account updated' . ($password !== '' ? ' and password reset' : ''));
        }

        if ($action === 'toggle') {
            $id     = (int) ($_POST['id'] ?? 0);
            $target = Database::first('SELECT * FROM users WHERE id = ?', [$id]);

            if ($target === null) {
                throw new RuntimeException('User not found');
            }

            if ($id === Auth::id()) {
                throw new RuntimeException('You cannot deactivate your own account');
            }

            Auth::assertCanManageUser($target);

            $activate = (int) $target['is_active'] === 0;
            Database::update('users', ['is_active' => $activate ? 1 : 0], 'id = ?', [$id]);

            if (!$activate) {
                // Kick their devices out immediately.
                Database::update('auth_tokens', ['revoked_at' => Helpers::now()], 'user_id = ? AND revoked_at IS NULL', [$id]);
            }

            Helpers::log(Auth::id(), $activate ? 'user_activated' : 'user_deactivated', 'user', $id);
            Session::flash($target['name'] . ($activate ? ' reactivated' : ' deactivated'));
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
    }

    redirect('users.php?' . query_with([]));
}

// ---------------------------------------------------------------- list
$where  = ['1 = 1'];
$params = [];

if (Auth::isPartner()) {
    $where[]  = "u.parent_id = ? AND u.role = 'telecaller'";
    $params[] = Auth::id();
} else {
    $where[] = "u.role <> 'admin'";
}

if ($role = q('role')) {
    $where[]  = 'u.role = ?';
    $params[] = $role;
}

if ($search = q('search')) {
    $where[] = '(u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.agency_name LIKE ?)';
    $like    = '%' . $search . '%';
    $params  = array_merge($params, [$like, $like, $like, $like]);
}

if (q('inactive') === '1') {
    $where[] = 'u.is_active = 0';
} elseif (q('inactive') !== 'all') {
    $where[] = 'u.is_active = 1';
}

$whereSql = implode(' AND ', $where);

$users = Database::all(
    "SELECT u.*, p.name AS parent_name,
            (SELECT COUNT(*) FROM users c WHERE c.parent_id = u.id AND c.is_active = 1) AS telecaller_count,
            (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id) AS lead_count,
            (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id AND l.status = 'converted') AS converted_count,
            (SELECT COUNT(*) FROM call_logs c WHERE c.user_id = u.id AND DATE(c.started_at) = CURDATE()) AS calls_today
       FROM users u
       LEFT JOIN users p ON p.id = u.parent_id
      WHERE {$whereSql}
      ORDER BY u.role, u.name",
    $params
);

require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><?= e($pageTitle) ?> <span class="text-muted fs-6">(<?= count($users) ?>)</span></h1>
  <button class="btn btn-app btn-sm" data-bs-toggle="collapse" data-bs-target="#newUser">
    <i class="bi bi-person-plus me-1"></i><?= Auth::isAdmin() ? 'Add partner / telecaller' : 'Add telecaller' ?>
  </button>
</div>

<div class="collapse mb-3" id="newUser">
  <div class="card"><div class="card-body">
    <form method="post" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <?php if (Auth::isAdmin()): ?>
        <div class="col-md-2">
          <label class="form-label small">Role *</label>
          <select name="role" class="form-select form-select-sm" id="roleSelect">
            <option value="partner">Partner (franchise)</option>
            <option value="telecaller">Telecaller</option>
          </select>
        </div>
        <div class="col-md-3" id="parentWrap" style="display:none">
          <label class="form-label small">Works under partner *</label>
          <select name="parent_id" class="form-select form-select-sm">
            <option value="">Choose partner…</option>
            <?php foreach (lookup('partners') as $p): ?>
              <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?><?= $p['agency_name'] ? ' — ' . e($p['agency_name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="role" value="telecaller">
      <?php endif; ?>

      <div class="col-md-3"><label class="form-label small">Name *</label><input name="name" class="form-control form-control-sm" required></div>
      <div class="col-md-2"><label class="form-label small">Phone * <span class="text-muted">(login)</span></label><input name="phone" class="form-control form-control-sm" required></div>
      <div class="col-md-2"><label class="form-label small">Password *</label><input name="password" class="form-control form-control-sm" minlength="6" required></div>
      <div class="col-md-3"><label class="form-label small">Email</label><input type="email" name="email" class="form-control form-control-sm"></div>
      <div class="col-md-3" id="agencyWrap"><label class="form-label small">Agency / franchise name</label><input name="agency_name" class="form-control form-control-sm"></div>
      <div class="col-md-2"><label class="form-label small">City</label><input name="city" class="form-control form-control-sm"></div>
      <div class="col-md-2"><label class="form-label small">State</label><input name="state" class="form-control form-control-sm"></div>
      <?php if (Auth::isAdmin()): ?>
        <div class="col-md-2" id="seatsWrap">
          <label class="form-label small">Telecaller limit</label>
          <input type="number" name="max_telecallers" value="10" min="0" max="1000" class="form-control form-control-sm">
        </div>
      <?php endif; ?>
      <div class="col-12 text-end"><button class="btn btn-sm btn-app">Create account</button></div>
    </form>
  </div></div>
</div>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" data-autosubmit class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small mb-1">Search</label>
      <input name="search" value="<?= e(q('search')) ?>" class="form-control form-control-sm" placeholder="Name, phone, agency"></div>
    <?php if (Auth::isAdmin()): ?>
      <div class="col-md-3"><label class="form-label small mb-1">Role</label>
        <select name="role" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="partner"<?= q('role') === 'partner' ? ' selected' : '' ?>>Partners</option>
          <option value="telecaller"<?= q('role') === 'telecaller' ? ' selected' : '' ?>>Telecallers</option>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-md-3"><label class="form-label small mb-1">Show</label>
      <select name="inactive" class="form-select form-select-sm">
        <option value="">Active only</option>
        <option value="1"<?= q('inactive') === '1' ? ' selected' : '' ?>>Deactivated only</option>
        <option value="all"<?= q('inactive') === 'all' ? ' selected' : '' ?>>Everyone</option>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-sm btn-app">Apply</button></div>
  </form>
</div></div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Name</th><th>Role</th><th>Login phone</th>
          <?php if (Auth::isAdmin()): ?><th>Under</th><?php endif; ?>
          <th class="text-end">Leads</th><th class="text-end">Converted</th><th class="text-end">Calls today</th>
          <th>Last login</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($users === []): ?>
        <tr><td colspan="9" class="text-center text-muted py-5">No accounts yet. Use the button above to add one.</td></tr>
      <?php else: foreach ($users as $u): ?>
        <tr<?= (int) $u['is_active'] === 0 ? ' class="opacity-50"' : '' ?>>
          <td>
            <div class="fw-semibold"><?= e($u['name']) ?>
              <?php if ((int) $u['is_active'] === 0): ?><span class="badge bg-secondary">Inactive</span><?php endif; ?>
            </div>
            <?php if ($u['agency_name']): ?><div class="small text-muted"><?= e($u['agency_name']) ?></div><?php endif; ?>
            <?php if ($u['city']): ?><div class="small text-muted"><?= e($u['city']) ?></div><?php endif; ?>
          </td>
          <td>
            <span class="badge bg-<?= $u['role'] === 'partner' ? 'primary' : 'info' ?>"><?= e(ucfirst($u['role'])) ?></span>
            <?php if ($u['role'] === 'partner'): ?>
              <div class="small text-muted"><?= (int) $u['telecaller_count'] ?>/<?= (int) $u['max_telecallers'] ?> telecallers</div>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($u['phone']) ?><?php if ($u['email']): ?><div class="text-muted"><?= e($u['email']) ?></div><?php endif; ?></td>
          <?php if (Auth::isAdmin()): ?><td class="small"><?= e($u['parent_name'] ?? '—') ?></td><?php endif; ?>
          <td class="text-end small"><a href="leads.php?assigned_to=<?= (int) $u['id'] ?>" class="text-decoration-none"><?= number_format((int) $u['lead_count']) ?></a></td>
          <td class="text-end small text-success"><?= number_format((int) $u['converted_count']) ?></td>
          <td class="text-end small"><?= number_format((int) $u['calls_today']) ?></td>
          <td class="small"><?= $u['last_login_at'] ? ago($u['last_login_at']) : '<span class="text-muted">Never</span>' ?></td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#edit<?= (int) $u['id'] ?>" title="Edit">
              <i class="bi bi-pencil"></i>
            </button>
            <form method="post" class="d-inline"
                  data-confirm="<?= (int) $u['is_active'] === 1 ? 'Deactivate this account? They will be signed out of the app.' : 'Reactivate this account?' ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="btn btn-sm btn-outline-<?= (int) $u['is_active'] === 1 ? 'danger' : 'success' ?>"
                      title="<?= (int) $u['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>">
                <i class="bi bi-<?= (int) $u['is_active'] === 1 ? 'person-slash' : 'person-check' ?>"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($users as $u): ?>
  <div class="modal fade" id="edit<?= (int) $u['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Edit <?= e($u['name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-2">
          <div class="col-6"><label class="form-label small">Name</label><input name="name" value="<?= e($u['name']) ?>" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">Phone (login)</label><input name="phone" value="<?= e($u['phone']) ?>" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">Email</label><input type="email" name="email" value="<?= e($u['email']) ?>" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">Agency name</label><input name="agency_name" value="<?= e($u['agency_name']) ?>" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">City</label><input name="city" value="<?= e($u['city']) ?>" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">State</label><input name="state" value="<?= e($u['state']) ?>" class="form-control form-control-sm"></div>
          <?php if (Auth::isAdmin() && $u['role'] === 'partner'): ?>
            <div class="col-6"><label class="form-label small">Telecaller limit</label>
              <input type="number" name="max_telecallers" value="<?= (int) $u['max_telecallers'] ?>" min="0" max="1000" class="form-control form-control-sm"></div>
          <?php endif; ?>
          <div class="col-6"><label class="form-label small">Reset password</label>
            <input name="password" class="form-control form-control-sm" placeholder="Leave blank to keep" minlength="6">
            <div class="form-text small">Changing it signs them out of all devices.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-sm btn-app">Save</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<script>
(function () {
  var roleSelect = document.getElementById('roleSelect');
  if (!roleSelect) { return; }

  function sync() {
    var isTelecaller = roleSelect.value === 'telecaller';
    document.getElementById('parentWrap').style.display = isTelecaller ? '' : 'none';
    var seats = document.getElementById('seatsWrap');
    var agency = document.getElementById('agencyWrap');
    if (seats)  { seats.style.display  = isTelecaller ? 'none' : ''; }
    if (agency) { agency.style.display = isTelecaller ? 'none' : ''; }
  }

  roleSelect.addEventListener('change', sync);
  sync();
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
