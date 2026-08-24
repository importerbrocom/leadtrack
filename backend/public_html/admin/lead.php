<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Models\Lead;

$currentUser = Session::requireLogin();

$leadId = (int) ($_GET['id'] ?? $_POST['lead_id'] ?? 0);
$lead   = Lead::find($leadId);

if ($lead === null) {
    Session::flash('Lead not found', 'danger');
    redirect('leads.php');
}

try {
    Auth::assertCanAccessLead($lead);
} catch (Throwable $e) {
    Session::flash($e->getMessage(), 'danger');
    redirect('leads.php');
}

// ---------------------------------------------------------------- actions
if (is_post()) {
    Session::verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'status') {
            $status   = (string) ($_POST['status'] ?? '');
            $remarks  = trim((string) ($_POST['remarks'] ?? '')) ?: null;
            $callback = trim((string) ($_POST['next_follow_up_at'] ?? ''));

            if ($status === 'converted') {
                throw new RuntimeException('Use the "Convert to project" button instead');
            }

            Database::transaction(function () use ($leadId, $status, $remarks, $callback, $lead) {
                Lead::changeStatus($leadId, $status, Auth::id(), $remarks);

                if ($callback !== '') {
                    $when = Helpers::toDateTime($callback);
                    Database::update('leads', ['next_follow_up_at' => $when], 'id = ?', [$leadId]);
                    Database::insert('follow_ups', [
                        'lead_id'      => $leadId,
                        'user_id'      => $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : Auth::id(),
                        'scheduled_at' => $when,
                        'remarks'      => $remarks,
                        'created_by'   => Auth::id(),
                    ]);
                } elseif (in_array($status, ['not_interested', 'lost', 'invalid', 'dnd'], true)) {
                    Database::update('leads', ['next_follow_up_at' => null], 'id = ?', [$leadId]);
                    Database::update('follow_ups', ['status' => 'cancelled'], "lead_id = ? AND status = 'pending'", [$leadId]);
                }
            });

            Session::flash('Status updated to ' . label($status));
            redirect('lead.php?id=' . $leadId);
        }

        if ($action === 'update') {
            $update = [
                'name'              => trim((string) ($_POST['name'] ?? '')) ?: $lead['name'],
                'email'             => trim((string) ($_POST['email'] ?? '')) ?: null,
                'alt_phone'         => trim((string) ($_POST['alt_phone'] ?? '')) ?: null,
                'whatsapp'          => trim((string) ($_POST['whatsapp'] ?? '')) ?: null,
                'city'              => trim((string) ($_POST['city'] ?? '')) ?: null,
                'district'          => trim((string) ($_POST['district'] ?? '')) ?: null,
                'state'             => trim((string) ($_POST['state'] ?? '')) ?: null,
                'source_id'         => (int) ($_POST['source_id'] ?? 0) ?: null,
                'job_category_id'   => (int) ($_POST['job_category_id'] ?? 0) ?: null,
                'preferred_country' => trim((string) ($_POST['preferred_country'] ?? '')) ?: null,
                'qualification'     => trim((string) ($_POST['qualification'] ?? '')) ?: null,
                'experience_years'  => ($_POST['experience_years'] ?? '') !== '' ? (float) $_POST['experience_years'] : null,
                'expected_salary'   => ($_POST['expected_salary'] ?? '') !== '' ? (float) $_POST['expected_salary'] : null,
                'passport_status'   => in_array($_POST['passport_status'] ?? '', ['not_applied', 'applied', 'ready', 'expired'], true) ? $_POST['passport_status'] : null,
                'priority'          => in_array($_POST['priority'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['priority'] : $lead['priority'],
                'notes'             => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ];

            $update['alt_phone_normalized'] = Helpers::normalizePhone($update['alt_phone']);

            Database::update('leads', $update, 'id = ?', [$leadId]);
            Helpers::log(Auth::id(), 'lead_updated', 'lead', $leadId);
            Session::flash('Lead details saved');
            redirect('lead.php?id=' . $leadId);
        }

        if ($action === 'assign') {
            $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
            if ($assignedTo === 0) {
                throw new RuntimeException('Choose a team member');
            }

            $target = Database::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [$assignedTo]);
            if ($target === null) {
                throw new RuntimeException('Team member not found or deactivated');
            }

            if (Auth::isPartner() && !in_array($assignedTo, Auth::visibleUserIds(), true)) {
                throw new RuntimeException('You can only assign to your own telecallers');
            }

            $partnerId = Auth::isPartner()
                ? Auth::id()
                : ($target['role'] === Auth::PARTNER ? $assignedTo
                    : ($target['parent_id'] !== null ? (int) $target['parent_id'] : null));

            Database::update('leads', ['assigned_to' => $assignedTo, 'partner_id' => $partnerId], 'id = ?', [$leadId]);
            Helpers::notify($assignedTo, 'Lead assigned to you', $lead['name'] . ' - ' . $lead['phone'], 'lead_assigned', 'lead', $leadId);

            Session::flash('Lead assigned to ' . $target['name']);
            redirect('lead.php?id=' . $leadId);
        }

        if ($action === 'convert') {
            if (!Auth::isAdmin() && (string) Helpers::setting('partner_can_convert', '1') !== '1') {
                throw new RuntimeException('Only head office can convert leads');
            }

            if (Database::scalar('SELECT id FROM projects WHERE lead_id = ?', [$leadId]) !== null) {
                throw new RuntimeException('This lead is already converted');
            }

            $projectId = Database::transaction(function () use ($lead, $leadId) {
                $code = Helpers::nextProjectCode();

                $id = Database::insert('projects', [
                    'lead_id'             => $leadId,
                    'project_code'        => $code,
                    'partner_id'          => $lead['partner_id'] !== null ? (int) $lead['partner_id'] : null,
                    'assigned_to'         => $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : Auth::id(),
                    'candidate_name'      => trim((string) ($_POST['candidate_name'] ?? '')) ?: $lead['name'],
                    'candidate_phone'     => $lead['phone'],
                    'candidate_email'     => $lead['email'],
                    'passport_no'         => trim((string) ($_POST['passport_no'] ?? '')) ?: null,
                    'job_category_id'     => $lead['job_category_id'] !== null ? (int) $lead['job_category_id'] : null,
                    'position'            => trim((string) ($_POST['position'] ?? '')) ?: null,
                    'employer_name'       => trim((string) ($_POST['employer_name'] ?? '')) ?: null,
                    'destination_country' => trim((string) ($_POST['destination_country'] ?? '')) ?: $lead['preferred_country'],
                    'agreed_amount'       => Auth::isAdmin() ? (float) ($_POST['agreed_amount'] ?? 0) : 0,
                    'paid_amount'         => Auth::isAdmin() ? (float) ($_POST['paid_amount'] ?? 0) : 0,
                    'status'              => 'documents_pending',
                    'remarks'             => trim((string) ($_POST['remarks'] ?? '')) ?: null,
                    'created_by'          => Auth::id(),
                ]);

                Database::insert('project_status_history', [
                    'project_id' => $id,
                    'user_id'    => Auth::id(),
                    'to_status'  => 'documents_pending',
                    'remarks'    => 'Converted from lead',
                ]);

                Lead::changeStatus($leadId, 'converted', Auth::id(), 'Converted to project ' . $code);

                Database::update('leads', ['converted_at' => Helpers::now(), 'next_follow_up_at' => null], 'id = ?', [$leadId]);
                Database::update('follow_ups', ['status' => 'cancelled'], "lead_id = ? AND status = 'pending'", [$leadId]);
                Database::update('documents', ['project_id' => $id], 'lead_id = ? AND project_id IS NULL', [$leadId]);

                return $id;
            });

            Helpers::log(Auth::id(), 'lead_converted', 'project', $projectId, ['lead_id' => $leadId]);
            Session::flash('Lead converted to a project');
            redirect('project.php?id=' . $projectId);
        }

        if ($action === 'callback') {
            $when = trim((string) ($_POST['scheduled_at'] ?? ''));
            if ($when === '') {
                throw new RuntimeException('Pick a date and time');
            }

            $parsed = Helpers::toDateTime($when);

            Database::insert('follow_ups', [
                'lead_id'      => $leadId,
                'user_id'      => $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : Auth::id(),
                'scheduled_at' => $parsed,
                'remarks'      => trim((string) ($_POST['remarks'] ?? '')) ?: null,
                'created_by'   => Auth::id(),
            ]);

            $earliest = Database::scalar(
                "SELECT MIN(scheduled_at) FROM follow_ups WHERE lead_id = ? AND status = 'pending'",
                [$leadId]
            );
            Database::update('leads', ['next_follow_up_at' => $earliest], 'id = ?', [$leadId]);

            Session::flash('Callback scheduled');
            redirect('lead.php?id=' . $leadId);
        }

        if ($action === 'delete' && Auth::isAdmin()) {
            if ($lead['status'] === 'converted') {
                throw new RuntimeException('Converted leads cannot be deleted');
            }

            Database::delete('leads', 'id = ?', [$leadId]);
            Helpers::log(Auth::id(), 'lead_deleted', 'lead', $leadId, ['name' => $lead['name']]);
            Session::flash('Lead deleted');
            redirect('leads.php');
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
        redirect('lead.php?id=' . $leadId);
    }
}

// ---------------------------------------------------------------- data
$row = Database::first(Lead::selectSql() . ' WHERE l.id = ?', [$leadId]);
$view = Lead::toApi($row);

$calls = Database::all(
    'SELECT c.*, u.name AS user_name FROM call_logs c LEFT JOIN users u ON u.id = c.user_id
      WHERE c.lead_id = ? ORDER BY c.started_at DESC, c.id DESC LIMIT 50',
    [$leadId]
);

$history = Database::all(
    'SELECT h.*, u.name AS user_name FROM lead_status_history h LEFT JOIN users u ON u.id = h.user_id
      WHERE h.lead_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT 50',
    [$leadId]
);

$followUps = Database::all(
    'SELECT f.*, u.name AS user_name FROM follow_ups f LEFT JOIN users u ON u.id = f.user_id
      WHERE f.lead_id = ? ORDER BY f.scheduled_at DESC LIMIT 20',
    [$leadId]
);

$documents = Database::all(
    'SELECT d.*, dt.name AS document_type_name, u.name AS uploaded_by_name
       FROM documents d
       LEFT JOIN document_types dt ON dt.id = d.document_type_id
       LEFT JOIN users u ON u.id = d.uploaded_by
      WHERE d.lead_id = ? ORDER BY d.created_at DESC',
    [$leadId]
);

$assignables = assignable_users();
$pageTitle   = $view['name'];

require __DIR__ . '/partials/header.php';
?>

<nav aria-label="breadcrumb">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="leads.php">Leads</a></li>
    <li class="breadcrumb-item active"><?= e($view['name']) ?></li>
  </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
  <div>
    <h1 class="h4 mb-1">
      <?= e($view['name']) ?>
      <?= status_badge($view['status']) ?>
      <?= priority_badge($view['priority']) ?>
    </h1>
    <div class="text-muted small">
      <i class="bi bi-telephone me-1"></i><?= e($view['phone']) ?>
      <?php if ($view['city']): ?> &middot; <?= e($view['city']) ?><?php endif; ?>
      &middot; <?= (int) $view['call_count'] ?> calls, <?= e($view['talk_time_display']) ?> talk time
    </div>
  </div>
  <div class="btn-group">
    <a href="tel:<?= e($view['phone']) ?>" class="btn btn-sm btn-app"><i class="bi bi-telephone me-1"></i>Call</a>
    <?php if ($view['whatsapp'] || $view['phone']): ?>
      <a href="https://wa.me/91<?= e(preg_replace('/\D/', '', $view['whatsapp'] ?: $view['phone'])) ?>"
         target="_blank" rel="noopener" class="btn btn-sm btn-outline-success"><i class="bi bi-whatsapp"></i></a>
    <?php endif; ?>
    <?php if ($view['project_id'] !== null): ?>
      <a href="project.php?id=<?= (int) $view['project_id'] ?>" class="btn btn-sm btn-success">
        <i class="bi bi-briefcase me-1"></i>Open project
      </a>
    <?php elseif (Auth::isAdmin() || (string) Helpers::setting('partner_can_convert', '1') === '1'): ?>
      <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#convertModal">
        <i class="bi bi-arrow-right-circle me-1"></i>Convert to project
      </button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <!-- left column -->
  <div class="col-lg-8">
    <!-- quick status update -->
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Update status &amp; next callback</strong></div>
      <div class="card-body">
        <?php if ($view['status'] === 'converted'): ?>
          <p class="text-muted mb-0">This lead is converted. Work the case from the
            <a href="project.php?id=<?= (int) $view['project_id'] ?>">project page</a>.</p>
        <?php else: ?>
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="lead_id" value="<?= $leadId ?>">
          <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm" required>
              <?php foreach (Lead::STATUSES as $s): if ($s === 'converted') continue; ?>
                <option value="<?= e($s) ?>"<?= $view['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Call back at</label>
            <input type="datetime-local" name="next_follow_up_at" class="form-control form-control-sm">
          </div>
          <div class="col-md-4">
            <label class="form-label small">Remarks</label>
            <input name="remarks" class="form-control form-control-sm" placeholder="What did the candidate say?">
          </div>
          <div class="col-md-2"><button class="btn btn-sm btn-app w-100">Save</button></div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- call history -->
    <div class="card mb-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Call history</strong>
        <span class="small text-muted">Captured automatically by the app</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>When</th><th>Direction</th><th>Duration</th><th>Outcome</th><th>By</th><th>Notes</th></tr></thead>
          <tbody>
          <?php if ($calls === []): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No calls recorded yet</td></tr>
          <?php else: foreach ($calls as $call): ?>
            <tr>
              <td class="small text-nowrap"><?= dt($call['started_at']) ?></td>
              <td class="small">
                <i class="bi bi-<?= $call['direction'] === 'incoming' ? 'telephone-inbound' : ($call['direction'] === 'missed' ? 'telephone-x' : 'telephone-outbound') ?> me-1"></i>
                <?= e(label($call['direction'])) ?>
              </td>
              <td class="small <?= (int) $call['duration_sec'] === 0 ? 'text-muted' : '' ?>">
                <?= e(Helpers::humanDuration((int) $call['duration_sec'])) ?>
              </td>
              <td class="small"><?= $call['disposition'] ? e(label($call['disposition'])) : '<span class="text-muted">&mdash;</span>' ?></td>
              <td class="small"><?= e($call['user_name'] ?? '—') ?></td>
              <td class="small text-muted"><?= e($call['notes'] ?? '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- documents -->
    <div class="card mb-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Documents</strong>
        <span class="small text-muted"><?= count($documents) ?> file(s)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Document</th><th>Type</th><th>Size</th><th>Status</th><th>Uploaded by</th><th></th></tr></thead>
          <tbody>
          <?php if ($documents === []): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Nothing uploaded yet. Partners upload from the mobile app.</td></tr>
          <?php else: foreach ($documents as $doc): ?>
            <tr>
              <td class="small"><?= e($doc['title'] ?: $doc['file_name']) ?><div class="text-muted"><?= e($doc['file_name']) ?></div></td>
              <td class="small"><?= e($doc['document_type_name'] ?? '—') ?></td>
              <td class="small"><?= e(file_size_display((int) $doc['file_size'])) ?></td>
              <td><?= status_badge($doc['verification_status']) ?></td>
              <td class="small"><?= e($doc['uploaded_by_name'] ?? '—') ?><div class="text-muted"><?= ago($doc['created_at']) ?></div></td>
              <td class="text-end">
                <a href="download.php?type=document&id=<?= (int) $doc['id'] ?>" class="btn btn-sm btn-outline-primary" title="Download">
                  <i class="bi bi-download"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- editable details -->
    <div class="card">
      <div class="card-header bg-white"><strong>Candidate details</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="lead_id" value="<?= $leadId ?>">
          <div class="col-md-4"><label class="form-label small">Name</label><input name="name" value="<?= e($view['name']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">Alternate phone</label><input name="alt_phone" value="<?= e($view['alt_phone']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">WhatsApp</label><input name="whatsapp" value="<?= e($view['whatsapp']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">Email</label><input name="email" value="<?= e($view['email']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">City</label><input name="city" value="<?= e($view['city']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">District</label><input name="district" value="<?= e($view['district']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">Job category</label>
            <select name="job_category_id" class="form-select form-select-sm">
              <option value="">—</option><?= select_options(lookup('categories'), $view['job_category_id']) ?>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label small">Source</label>
            <select name="source_id" class="form-select form-select-sm">
              <option value="">—</option><?= select_options(lookup('sources'), $view['source_id']) ?>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label small">Country wanted</label><input name="preferred_country" value="<?= e($view['preferred_country']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">Qualification</label><input name="qualification" value="<?= e($view['qualification']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-2"><label class="form-label small">Experience (yrs)</label><input name="experience_years" type="number" step="0.5" value="<?= e($view['experience_years']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Expected salary</label><input name="expected_salary" type="number" step="100" value="<?= e($view['expected_salary']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Passport</label>
            <select name="passport_status" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach (['not_applied', 'applied', 'ready', 'expired'] as $ps): ?>
                <option value="<?= e($ps) ?>"<?= $view['passport_status'] === $ps ? ' selected' : '' ?>><?= e(label($ps)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><label class="form-label small">Priority</label>
            <select name="priority" class="form-select form-select-sm">
              <?php foreach (['low', 'medium', 'high'] as $p): ?>
                <option value="<?= e($p) ?>"<?= $view['priority'] === $p ? ' selected' : '' ?>><?= e(ucfirst($p)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><label class="form-label small">Notes</label><textarea name="notes" rows="2" class="form-control form-control-sm"><?= e($view['notes']) ?></textarea></div>
          <div class="col-12 text-end"><button class="btn btn-sm btn-app">Save details</button></div>
        </form>
      </div>
    </div>
  </div>

  <!-- right column -->
  <div class="col-lg-4">
    <!-- ownership -->
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Ownership</strong></div>
      <div class="card-body">
        <dl class="row small mb-2">
          <dt class="col-5 text-muted">Partner</dt><dd class="col-7"><?= e($view['partner_name'] ?? 'Head office') ?></dd>
          <dt class="col-5 text-muted">Assigned to</dt><dd class="col-7"><?= e($view['assigned_to_name'] ?? 'Unassigned') ?></dd>
          <dt class="col-5 text-muted">Source</dt><dd class="col-7"><?= e($view['source_name'] ?? '—') ?></dd>
          <dt class="col-5 text-muted">Created</dt><dd class="col-7"><?= dt($view['created_at'], false) ?></dd>
          <dt class="col-5 text-muted">Last called</dt><dd class="col-7"><?= $view['last_contacted_at'] ? dt($view['last_contacted_at']) : '<span class="text-muted">Never</span>' ?></dd>
        </dl>
        <form method="post" class="d-flex gap-1">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="lead_id" value="<?= $leadId ?>">
          <select name="assigned_to" class="form-select form-select-sm">
            <option value="">Reassign to…</option>
            <?php foreach ($assignables as $u): ?>
              <option value="<?= (int) $u['id'] ?>"<?= (int) $view['assigned_to'] === (int) $u['id'] ? ' selected' : '' ?>>
                <?= e($u['name']) ?> (<?= e($u['role']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-outline-primary">Go</button>
        </form>
      </div>
    </div>

    <!-- callbacks -->
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Callbacks</strong></div>
      <div class="card-body">
        <?php if ($view['next_follow_up_at']):
            $overdue = strtotime($view['next_follow_up_at']) < time(); ?>
          <p class="mb-3 <?= $overdue ? 'overdue' : '' ?>">
            <i class="bi bi-alarm me-1"></i>Next: <?= dt($view['next_follow_up_at']) ?> (<?= ago($view['next_follow_up_at']) ?>)
          </p>
        <?php else: ?>
          <p class="text-muted small mb-3">No callback scheduled</p>
        <?php endif; ?>

        <form method="post" class="row g-1">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="callback">
          <input type="hidden" name="lead_id" value="<?= $leadId ?>">
          <div class="col-12"><input type="datetime-local" name="scheduled_at" class="form-control form-control-sm" required></div>
          <div class="col-12"><input name="remarks" class="form-control form-control-sm" placeholder="Reason (optional)"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Schedule callback</button></div>
        </form>

        <?php if ($followUps !== []): ?>
          <hr>
          <ul class="list-unstyled small mb-0">
            <?php foreach (array_slice($followUps, 0, 6) as $f): ?>
              <li class="d-flex justify-content-between py-1">
                <span><?= dt($f['scheduled_at']) ?></span>
                <span class="badge bg-<?= $f['status'] === 'done' ? 'success' : ($f['status'] === 'pending' ? 'warning' : 'secondary') ?>">
                  <?= e(ucfirst($f['status'])) ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- timeline -->
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Activity</strong></div>
      <div class="card-body">
        <?php if ($history === []): ?>
          <p class="text-muted small mb-0">No history yet</p>
        <?php else: ?>
          <ul class="timeline small">
            <?php foreach ($history as $h): ?>
              <li>
                <div>
                  <?php if ($h['from_status']): ?>
                    <?= e(label($h['from_status'])) ?> <i class="bi bi-arrow-right"></i>
                  <?php endif; ?>
                  <strong><?= e(label($h['to_status'])) ?></strong>
                </div>
                <?php if ($h['remarks']): ?><div class="text-muted"><?= e($h['remarks']) ?></div><?php endif; ?>
                <div class="text-muted"><?= e($h['user_name'] ?? 'System') ?> &middot; <?= dt($h['created_at']) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Auth::isAdmin() && $view['status'] !== 'converted'): ?>
      <form method="post" data-confirm="Delete this lead permanently? Call history will be lost.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="lead_id" value="<?= $leadId ?>">
        <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash me-1"></i>Delete lead</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- convert modal -->
<div class="modal fade" id="convertModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="convert">
      <input type="hidden" name="lead_id" value="<?= $leadId ?>">
      <div class="modal-header">
        <h5 class="modal-title">Convert to project</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">
          The lead becomes a placement case. You can fill the rest in later &mdash; only the name is required.
        </p>
        <div class="row g-2">
          <div class="col-12"><label class="form-label small">Candidate name</label>
            <input name="candidate_name" value="<?= e($view['name']) ?>" class="form-control form-control-sm" required></div>
          <div class="col-6"><label class="form-label small">Position</label><input name="position" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">Destination country</label>
            <input name="destination_country" value="<?= e($view['preferred_country']) ?>" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">Employer</label><input name="employer_name" class="form-control form-control-sm"></div>
          <div class="col-6"><label class="form-label small">Passport no.</label><input name="passport_no" class="form-control form-control-sm"></div>
          <?php if (Auth::isAdmin()): ?>
            <div class="col-6"><label class="form-label small">Agreed amount (₹)</label><input type="number" step="0.01" name="agreed_amount" class="form-control form-control-sm" value="0"></div>
            <div class="col-6"><label class="form-label small">Paid so far (₹)</label><input type="number" step="0.01" name="paid_amount" class="form-control form-control-sm" value="0"></div>
          <?php endif; ?>
          <div class="col-12"><label class="form-label small">Remarks</label><textarea name="remarks" rows="2" class="form-control form-control-sm"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-success">Convert</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
