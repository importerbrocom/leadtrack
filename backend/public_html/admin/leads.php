<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Models\Lead;

$currentUser = Session::requireLogin();
$pageTitle   = 'Leads';

// ---------------------------------------------------------------- actions
if (is_post()) {
    Session::verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $name  = trim((string) ($_POST['name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $normalized = Helpers::normalizePhone($phone);

            if ($name === '' || $normalized === null || strlen($normalized) < 10) {
                throw new RuntimeException('Enter a name and a valid 10-digit phone number');
            }

            $assignedTo = (int) ($_POST['assigned_to'] ?? 0) ?: null;
            $partnerId  = Auth::isPartner() ? Auth::id() : ((int) ($_POST['partner_id'] ?? 0) ?: null);

            if ($partnerId === null && $assignedTo !== null) {
                $target = Database::first('SELECT role, parent_id FROM users WHERE id = ?', [$assignedTo]);
                if ($target !== null) {
                    $partnerId = $target['role'] === Auth::PARTNER
                        ? $assignedTo
                        : ($target['parent_id'] !== null ? (int) $target['parent_id'] : null);
                }
            }

            if (Auth::isPartner() && $assignedTo !== null && !in_array($assignedTo, Auth::visibleUserIds(), true)) {
                throw new RuntimeException('You can only assign leads to your own telecallers');
            }

            $duplicate = Database::scalar(
                'SELECT id FROM leads WHERE phone_normalized = ? AND partner_id <=> ?',
                [$normalized, $partnerId]
            );

            if ($duplicate !== null) {
                throw new RuntimeException('This phone number is already saved as lead #' . $duplicate);
            }

            $followUp = trim((string) ($_POST['next_follow_up_at'] ?? ''));

            // $_POST is a superglobal, so it is visible inside the closure
            // without a use() binding (PHP rejects auto-globals in use()).
            $leadId = Database::transaction(function () use ($name, $phone, $normalized, $partnerId, $assignedTo, $followUp) {
                $id = Database::insert('leads', [
                    'partner_id'        => $partnerId,
                    'assigned_to'       => $assignedTo,
                    'name'              => mb_substr($name, 0, 160),
                    'phone'             => mb_substr($phone, 0, 20),
                    'phone_normalized'  => $normalized,
                    'email'             => trim((string) ($_POST['email'] ?? '')) ?: null,
                    'city'              => trim((string) ($_POST['city'] ?? '')) ?: null,
                    'district'          => trim((string) ($_POST['district'] ?? '')) ?: null,
                    'source_id'         => (int) ($_POST['source_id'] ?? 0) ?: null,
                    'job_category_id'   => (int) ($_POST['job_category_id'] ?? 0) ?: null,
                    'preferred_country' => trim((string) ($_POST['preferred_country'] ?? '')) ?: null,
                    'qualification'     => trim((string) ($_POST['qualification'] ?? '')) ?: null,
                    'priority'          => in_array($_POST['priority'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['priority'] : 'medium',
                    'next_follow_up_at' => $followUp !== '' ? Helpers::toDateTime($followUp) : null,
                    'notes'             => trim((string) ($_POST['notes'] ?? '')) ?: null,
                    'status'            => 'new',
                    'created_by'        => Auth::id(),
                ]);

                Database::insert('lead_status_history', [
                    'lead_id'   => $id,
                    'user_id'   => Auth::id(),
                    'to_status' => 'new',
                    'remarks'   => 'Created from admin panel',
                ]);

                if ($followUp !== '' && $assignedTo !== null) {
                    Database::insert('follow_ups', [
                        'lead_id'      => $id,
                        'user_id'      => $assignedTo,
                        'scheduled_at' => Helpers::toDateTime($followUp),
                        'created_by'   => Auth::id(),
                    ]);
                }

                return $id;
            });

            if ($assignedTo !== null) {
                Helpers::notify($assignedTo, 'New lead assigned', $name . ' - ' . $phone, 'lead_assigned', 'lead', $leadId);
            }

            Helpers::log(Auth::id(), 'lead_created', 'lead', $leadId);
            Session::flash('Lead "' . $name . '" added');
            redirect('lead.php?id=' . $leadId);
        }

        if ($action === 'bulk_assign') {
            $ids        = array_map('intval', (array) ($_POST['lead_ids'] ?? []));
            $assignedTo = (int) ($_POST['assigned_to'] ?? 0);

            if ($ids === [] || $assignedTo === 0) {
                throw new RuntimeException('Select at least one lead and a team member');
            }

            $target = Database::first('SELECT * FROM users WHERE id = ?', [$assignedTo]);
            if ($target === null) {
                throw new RuntimeException('Team member not found');
            }

            if (Auth::isPartner() && !in_array($assignedTo, Auth::visibleUserIds(), true)) {
                throw new RuntimeException('You can only assign leads to your own telecallers');
            }

            $partnerId = Auth::isPartner()
                ? Auth::id()
                : ($target['role'] === Auth::PARTNER ? $assignedTo
                    : ($target['parent_id'] !== null ? (int) $target['parent_id'] : null));

            $done = 0;
            foreach ($ids as $leadId) {
                $lead = Lead::find($leadId);
                if ($lead === null) {
                    continue;
                }
                try {
                    Auth::assertCanAccessLead($lead);
                } catch (Throwable $e) {
                    continue;
                }
                Database::update('leads', ['assigned_to' => $assignedTo, 'partner_id' => $partnerId], 'id = ?', [$leadId]);
                $done++;
            }

            if ($done > 0) {
                Helpers::notify($assignedTo, "{$done} new leads assigned", 'Open the app to start calling', 'lead_assigned');
            }

            Session::flash("{$done} lead(s) assigned to {$target['name']}");
            redirect('leads.php?' . query_with([]));
        }

        if ($action === 'import') {
            if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a CSV file to upload');
            }

            $handle = fopen($_FILES['csv']['tmp_name'], 'r');
            if ($handle === false) {
                throw new RuntimeException('Could not read the uploaded file');
            }

            $assignedTo = (int) ($_POST['import_assigned_to'] ?? 0) ?: null;
            $partnerId  = Auth::isPartner() ? Auth::id() : null;

            if ($partnerId === null && $assignedTo !== null) {
                $target = Database::first('SELECT role, parent_id FROM users WHERE id = ?', [$assignedTo]);
                if ($target !== null) {
                    $partnerId = $target['role'] === Auth::PARTNER ? $assignedTo
                        : ($target['parent_id'] !== null ? (int) $target['parent_id'] : null);
                }
            }

            // First row is a header: name,phone,city,email,job_category,preferred_country,notes
            $header  = fgetcsv($handle);
            $created = 0;
            $skipped = 0;
            $rowNum  = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if ($rowNum > 5001) {
                    break; // safety valve on shared hosting
                }

                $name  = trim((string) ($row[0] ?? ''));
                $phone = trim((string) ($row[1] ?? ''));
                $norm  = Helpers::normalizePhone($phone);

                if ($name === '' || $norm === null || strlen($norm) < 10) {
                    $skipped++;
                    continue;
                }

                $exists = Database::scalar(
                    'SELECT id FROM leads WHERE phone_normalized = ? AND partner_id <=> ?',
                    [$norm, $partnerId]
                );

                if ($exists !== null) {
                    $skipped++;
                    continue;
                }

                $id = Database::insert('leads', [
                    'partner_id'        => $partnerId,
                    'assigned_to'       => $assignedTo,
                    'name'              => mb_substr($name, 0, 160),
                    'phone'             => mb_substr($phone, 0, 20),
                    'phone_normalized'  => $norm,
                    'city'              => trim((string) ($row[2] ?? '')) ?: null,
                    'email'             => trim((string) ($row[3] ?? '')) ?: null,
                    'preferred_country' => trim((string) ($row[5] ?? '')) ?: null,
                    'notes'             => trim((string) ($row[6] ?? '')) ?: null,
                    'status'            => 'new',
                    'created_by'        => Auth::id(),
                ]);

                Database::insert('lead_status_history', [
                    'lead_id'   => $id,
                    'user_id'   => Auth::id(),
                    'to_status' => 'new',
                    'remarks'   => 'CSV import',
                ]);

                $created++;
            }

            fclose($handle);

            Helpers::log(Auth::id(), 'leads_imported', null, null, ['created' => $created, 'skipped' => $skipped]);
            Session::flash("Imported {$created} lead(s); skipped {$skipped} (duplicate or invalid)");
            redirect('leads.php');
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
        redirect('leads.php?' . query_with([]));
    }
}

// ---------------------------------------------------------------- filters
[$scopeSql, $params] = Auth::scopeClause('l');
$where = [$scopeSql];

if ($status = q('status')) {
    $where[]  = 'l.status = ?';
    $params[] = $status;
}

if ($assigned = q('assigned_to')) {
    $where[]  = 'l.assigned_to = ?';
    $params[] = (int) $assigned;
}

if ($partner = q('partner_id')) {
    $where[]  = 'l.partner_id = ?';
    $params[] = (int) $partner;
}

if ($category = q('job_category_id')) {
    $where[]  = 'l.job_category_id = ?';
    $params[] = (int) $category;
}

if ($priority = q('priority')) {
    $where[]  = 'l.priority = ?';
    $params[] = $priority;
}

if ($search = q('search')) {
    $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.city LIKE ?)';
    $like    = '%' . $search . '%';
    $params  = array_merge($params, [$like, $like, $like, $like]);
}

if (q('bucket') === 'overdue') {
    $where[] = "l.next_follow_up_at < NOW() AND l.status NOT IN ('converted','lost','invalid','dnd')";
} elseif (q('bucket') === 'today') {
    $where[] = 'DATE(l.next_follow_up_at) = CURDATE()';
} elseif (q('bucket') === 'unassigned') {
    $where[] = 'l.assigned_to IS NULL';
}

$whereSql = implode(' AND ', $where);

$sortMap = [
    'recent'   => 'l.updated_at DESC',
    'created'  => 'l.created_at DESC',
    'name'     => 'l.name ASC',
    'followup' => 'l.next_follow_up_at IS NULL, l.next_follow_up_at ASC',
    'calls'    => 'l.call_count DESC',
];
$sort    = (string) q('sort', 'recent');
$orderBy = $sortMap[$sort] ?? $sortMap['recent'];

$total = (int) Database::scalar("SELECT COUNT(*) FROM leads l WHERE {$whereSql}", $params);
$pg    = paginate($total, 30);

$leads = Database::all(
    "SELECT l.*, u.name AS assigned_name, p.name AS partner_name,
            s.name AS source_name, jc.name AS job_category_name,
            pr.id AS project_id
       FROM leads l
       LEFT JOIN users u ON u.id = l.assigned_to
       LEFT JOIN users p ON p.id = l.partner_id
       LEFT JOIN lead_sources s ON s.id = l.source_id
       LEFT JOIN job_categories jc ON jc.id = l.job_category_id
       LEFT JOIN projects pr ON pr.lead_id = l.id
      WHERE {$whereSql}
      ORDER BY {$orderBy}
      LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$assignables = assignable_users();
$showNewForm = q('action') === 'new';

require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Leads <span class="text-muted fs-6">(<?= number_format($total) ?>)</span></h1>
  <div class="btn-group">
    <button class="btn btn-app btn-sm" data-bs-toggle="collapse" data-bs-target="#newLeadForm">
      <i class="bi bi-plus-lg me-1"></i>Add lead
    </button>
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#importForm">
      <i class="bi bi-upload me-1"></i>Import CSV
    </button>
  </div>
</div>

<!-- add lead -->
<div class="collapse <?= $showNewForm ? 'show' : '' ?> mb-3" id="newLeadForm">
  <div class="card"><div class="card-body">
    <form method="post" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-4"><label class="form-label small">Name *</label><input name="name" class="form-control form-control-sm" required></div>
      <div class="col-md-3"><label class="form-label small">Phone *</label><input name="phone" class="form-control form-control-sm" placeholder="9876543210" required></div>
      <div class="col-md-2"><label class="form-label small">Priority</label>
        <select name="priority" class="form-select form-select-sm">
          <option value="medium">Medium</option><option value="high">High</option><option value="low">Low</option>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label small">Assign to</label>
        <select name="assigned_to" class="form-select form-select-sm">
          <option value="">Unassigned</option>
          <?php foreach ($assignables as $u): ?>
            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label small">City</label><input name="city" class="form-control form-control-sm"></div>
      <div class="col-md-3"><label class="form-label small">Job category</label>
        <select name="job_category_id" class="form-select form-select-sm">
          <option value="">—</option><?= select_options(lookup('categories'), null) ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label small">Source</label>
        <select name="source_id" class="form-select form-select-sm">
          <option value="">—</option><?= select_options(lookup('sources'), null) ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label small">Country wanted</label><input name="preferred_country" class="form-control form-control-sm" placeholder="UAE"></div>
      <div class="col-md-2"><label class="form-label small">Call back at</label><input type="datetime-local" name="next_follow_up_at" class="form-control form-control-sm"></div>
      <div class="col-md-6"><label class="form-label small">Qualification</label><input name="qualification" class="form-control form-control-sm"></div>
      <div class="col-md-6"><label class="form-label small">Notes</label><input name="notes" class="form-control form-control-sm"></div>
      <div class="col-12 text-end"><button class="btn btn-app btn-sm">Save lead</button></div>
    </form>
  </div></div>
</div>

<!-- import CSV -->
<div class="collapse mb-3" id="importForm">
  <div class="card"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">
      <div class="col-md-5">
        <label class="form-label small">CSV file</label>
        <input type="file" name="csv" accept=".csv,text/csv" class="form-control form-control-sm" required>
        <div class="form-text">Columns, in order: <code class="small-code">name, phone, city, email, job_category, preferred_country, notes</code>. First row is treated as a header.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label small">Assign all to</label>
        <select name="import_assigned_to" class="form-select form-select-sm">
          <option value="">Unassigned</option>
          <?php foreach ($assignables as $u): ?>
            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 text-end"><button class="btn btn-app btn-sm">Import</button></div>
    </form>
  </div></div>
</div>

<!-- filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="get" data-autosubmit class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small mb-1">Search</label>
      <input name="search" value="<?= e(q('search')) ?>" class="form-control form-control-sm" placeholder="Name, phone, city">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (Lead::STATUSES as $s): ?>
          <option value="<?= e($s) ?>"<?= q('status') === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1">Assigned to</label>
      <select name="assigned_to" class="form-select form-select-sm">
        <option value="">Anyone</option>
        <?php foreach ($assignables as $u): ?>
          <option value="<?= (int) $u['id'] ?>"<?= (string) q('assigned_to') === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
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
      <label class="form-label small mb-1">Show</label>
      <select name="bucket" class="form-select form-select-sm">
        <option value="">Everything</option>
        <option value="today"<?= q('bucket') === 'today' ? ' selected' : '' ?>>Callbacks today</option>
        <option value="overdue"<?= q('bucket') === 'overdue' ? ' selected' : '' ?>>Overdue callbacks</option>
        <option value="unassigned"<?= q('bucket') === 'unassigned' ? ' selected' : '' ?>>Unassigned</option>
      </select>
    </div>
    <div class="col-md-1">
      <label class="form-label small mb-1">Sort</label>
      <select name="sort" class="form-select form-select-sm">
        <option value="recent"<?= $sort === 'recent' ? ' selected' : '' ?>>Recent</option>
        <option value="created"<?= $sort === 'created' ? ' selected' : '' ?>>Newest</option>
        <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>>Name</option>
        <option value="followup"<?= $sort === 'followup' ? ' selected' : '' ?>>Callback</option>
        <option value="calls"<?= $sort === 'calls' ? ' selected' : '' ?>>Calls</option>
      </select>
    </div>
    <div class="col-12">
      <button class="btn btn-sm btn-app">Apply</button>
      <a href="leads.php" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
  </form>
</div></div>

<!-- list -->
<form method="post" id="bulkForm">
<?= csrf_field() ?>
<input type="hidden" name="action" value="bulk_assign">

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:2rem"><input type="checkbox" class="form-check-input" id="checkAll"></th>
          <th>Lead</th>
          <th>Status</th>
          <th>Job / Country</th>
          <th>Calls</th>
          <th>Next callback</th>
          <th>Assigned to</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($leads === []): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">
          No leads match these filters.
          <a href="leads.php?action=new">Add a lead</a> or <a href="leads.php">clear the filters</a>.
        </td></tr>
      <?php else: foreach ($leads as $lead):
          $overdue = $lead['next_follow_up_at'] !== null
              && strtotime($lead['next_follow_up_at']) < time()
              && !in_array($lead['status'], ['converted', 'lost', 'invalid', 'dnd'], true);
      ?>
        <tr>
          <td><input type="checkbox" class="form-check-input row-check" name="lead_ids[]" value="<?= (int) $lead['id'] ?>"></td>
          <td>
            <a href="lead.php?id=<?= (int) $lead['id'] ?>" class="text-decoration-none fw-semibold"><?= e($lead['name']) ?></a>
            <?= $lead['priority'] === 'high' ? ' ' . priority_badge('high') : '' ?>
            <div class="small text-muted">
              <?= e($lead['phone']) ?><?= $lead['city'] ? ' &middot; ' . e($lead['city']) : '' ?>
            </div>
          </td>
          <td>
            <?= status_badge($lead['status']) ?>
            <?php if ($lead['project_id'] !== null): ?>
              <a href="project.php?id=<?= (int) $lead['project_id'] ?>" class="badge bg-success-subtle text-success text-decoration-none">Project</a>
            <?php endif; ?>
          </td>
          <td class="small">
            <?= e($lead['job_category_name'] ?? '—') ?>
            <div class="text-muted"><?= e($lead['preferred_country'] ?? '') ?></div>
          </td>
          <td class="small">
            <?= (int) $lead['call_count'] ?>
            <div class="text-muted"><?= e(Helpers::humanDuration((int) $lead['total_talk_time_sec'])) ?></div>
          </td>
          <td class="small <?= $overdue ? 'overdue' : '' ?>">
            <?= $lead['next_follow_up_at'] ? dt($lead['next_follow_up_at']) : '<span class="text-muted">&mdash;</span>' ?>
            <?php if ($lead['next_follow_up_at']): ?><div><?= ago($lead['next_follow_up_at']) ?></div><?php endif; ?>
          </td>
          <td class="small">
            <?= e($lead['assigned_name'] ?? '—') ?>
            <?php if (Auth::isAdmin() && $lead['partner_name']): ?>
              <div class="text-muted"><?= e($lead['partner_name']) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a href="tel:<?= e($lead['phone']) ?>" class="btn btn-sm btn-outline-primary" title="Call"><i class="bi bi-telephone"></i></a>
            <a href="lead.php?id=<?= (int) $lead['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Open"><i class="bi bi-chevron-right"></i></a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <span class="small text-muted"><span id="selCount">0</span> selected</span>
      <select name="assigned_to" class="form-select form-select-sm" style="width:auto">
        <option value="">Assign selected to…</option>
        <?php foreach ($assignables as $u): ?>
          <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-app" id="bulkBtn" disabled>Assign</button>
    </div>
    <?php render_pagination($pg); ?>
  </div>
</div>
</form>

<script>
(function () {
  var checkAll = document.getElementById('checkAll');
  var rows     = Array.prototype.slice.call(document.querySelectorAll('.row-check'));
  var counter  = document.getElementById('selCount');
  var button   = document.getElementById('bulkBtn');

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
