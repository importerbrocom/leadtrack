<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Controllers\ProjectController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Uploader;

$currentUser = Session::requireLogin();

$projectId = (int) ($_GET['id'] ?? $_POST['project_id'] ?? 0);
$project   = Database::first('SELECT * FROM projects WHERE id = ?', [$projectId]);

if ($project === null) {
    Session::flash('Project not found', 'danger');
    redirect('projects.php');
}

try {
    Auth::assertCanAccessProject($project);
} catch (Throwable $e) {
    Session::flash($e->getMessage(), 'danger');
    redirect('projects.php');
}

// ---------------------------------------------------------------- actions
if (is_post()) {
    Session::verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'status') {
            $status  = (string) ($_POST['status'] ?? '');
            $remarks = trim((string) ($_POST['remarks'] ?? '')) ?: null;

            if (!in_array($status, ProjectController::STATUSES, true)) {
                throw new RuntimeException('Unknown stage');
            }

            if (!Auth::isAdmin() && in_array($status, ['cancelled', 'completed', 'deployed'], true)) {
                throw new RuntimeException('Only head office can set that stage');
            }

            if ($project['status'] !== $status) {
                Database::transaction(function () use ($projectId, $project, $status, $remarks) {
                    Database::update('projects', ['status' => $status], 'id = ?', [$projectId]);
                    Database::insert('project_status_history', [
                        'project_id'  => $projectId,
                        'user_id'     => Auth::id(),
                        'from_status' => $project['status'],
                        'to_status'   => $status,
                        'remarks'     => $remarks,
                    ]);
                });

                if ($project['assigned_to'] !== null && (int) $project['assigned_to'] !== Auth::id()) {
                    Helpers::notify(
                        (int) $project['assigned_to'],
                        'Project stage updated',
                        $project['candidate_name'] . ' → ' . label($status),
                        'project_status',
                        'project',
                        $projectId
                    );
                }

                Session::flash('Stage updated to ' . label($status));
            }

            redirect('project.php?id=' . $projectId);
        }

        if ($action === 'update') {
            $update = [
                'candidate_name'      => trim((string) ($_POST['candidate_name'] ?? '')) ?: $project['candidate_name'],
                'candidate_phone'     => trim((string) ($_POST['candidate_phone'] ?? '')) ?: $project['candidate_phone'],
                'candidate_email'     => trim((string) ($_POST['candidate_email'] ?? '')) ?: null,
                'dob'                 => trim((string) ($_POST['dob'] ?? '')) ?: null,
                'gender'              => in_array($_POST['gender'] ?? '', ['male', 'female', 'other'], true) ? $_POST['gender'] : null,
                'passport_no'         => trim((string) ($_POST['passport_no'] ?? '')) ?: null,
                'passport_expiry'     => trim((string) ($_POST['passport_expiry'] ?? '')) ?: null,
                'position'            => trim((string) ($_POST['position'] ?? '')) ?: null,
                'employer_name'       => trim((string) ($_POST['employer_name'] ?? '')) ?: null,
                'destination_country' => trim((string) ($_POST['destination_country'] ?? '')) ?: null,
                'visa_type'           => trim((string) ($_POST['visa_type'] ?? '')) ?: null,
                'visa_number'         => trim((string) ($_POST['visa_number'] ?? '')) ?: null,
                'visa_expiry'         => trim((string) ($_POST['visa_expiry'] ?? '')) ?: null,
                'interview_date'      => ($_POST['interview_date'] ?? '') !== '' ? Helpers::toDateTime($_POST['interview_date']) : null,
                'medical_date'        => trim((string) ($_POST['medical_date'] ?? '')) ?: null,
                'deployment_date'     => trim((string) ($_POST['deployment_date'] ?? '')) ?: null,
                'job_category_id'     => (int) ($_POST['job_category_id'] ?? 0) ?: null,
                'remarks'             => trim((string) ($_POST['remarks'] ?? '')) ?: null,
            ];

            if (Auth::isAdmin()) {
                $update['agreed_amount']   = (float) ($_POST['agreed_amount'] ?? 0);
                $update['paid_amount']     = (float) ($_POST['paid_amount'] ?? 0);
                $update['offered_salary']  = ($_POST['offered_salary'] ?? '') !== '' ? (float) $_POST['offered_salary'] : null;
                $update['salary_currency'] = trim((string) ($_POST['salary_currency'] ?? 'AED')) ?: 'AED';
            }

            Database::update('projects', $update, 'id = ?', [$projectId]);
            Helpers::log(Auth::id(), 'project_updated', 'project', $projectId);
            Session::flash('Project saved');
            redirect('project.php?id=' . $projectId);
        }

        if ($action === 'upload') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a file to upload');
            }

            $stored = Uploader::store($_FILES['file'], 'documents');

            $docId = Database::insert('documents', [
                'project_id'       => $projectId,
                'lead_id'          => (int) $project['lead_id'],
                'document_type_id' => (int) ($_POST['document_type_id'] ?? 0) ?: null,
                'title'            => trim((string) ($_POST['title'] ?? '')) ?: $stored['file_name'],
                'file_name'        => $stored['file_name'],
                'stored_name'      => $stored['stored_name'],
                'mime_type'        => $stored['mime_type'],
                'file_size'        => $stored['file_size'],
                'document_number'  => trim((string) ($_POST['document_number'] ?? '')) ?: null,
                'expiry_date'      => trim((string) ($_POST['expiry_date'] ?? '')) ?: null,
                // Head office uploading a document counts as already verified.
                'verification_status' => Auth::isAdmin() ? 'verified' : 'pending',
                'verified_by'      => Auth::isAdmin() ? Auth::id() : null,
                'verified_at'      => Auth::isAdmin() ? Helpers::now() : null,
                'uploaded_by'      => Auth::id(),
            ]);

            Helpers::log(Auth::id(), 'document_uploaded', 'document', $docId, ['project_id' => $projectId]);
            Session::flash('Document uploaded');
            redirect('project.php?id=' . $projectId);
        }

        if ($action === 'verify') {
            Session::requireAdmin();

            $docId  = (int) ($_POST['document_id'] ?? 0);
            $status = (string) ($_POST['verification_status'] ?? '');
            $reason = trim((string) ($_POST['reject_reason'] ?? '')) ?: null;

            if (!in_array($status, ['pending', 'verified', 'rejected'], true)) {
                throw new RuntimeException('Unknown verification status');
            }

            if ($status === 'rejected' && $reason === null) {
                throw new RuntimeException('Give a reason so the partner can fix it');
            }

            $doc = Database::first('SELECT * FROM documents WHERE id = ? AND project_id = ?', [$docId, $projectId]);
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
            redirect('project.php?id=' . $projectId);
        }

        if ($action === 'delete_doc') {
            $docId = (int) ($_POST['document_id'] ?? 0);
            $doc   = Database::first('SELECT * FROM documents WHERE id = ? AND project_id = ?', [$docId, $projectId]);

            if ($doc === null) {
                throw new RuntimeException('Document not found');
            }

            if (!Auth::isAdmin()) {
                if ((int) $doc['uploaded_by'] !== Auth::id()) {
                    throw new RuntimeException('You can only delete your own uploads');
                }
                if ($doc['verification_status'] === 'verified') {
                    throw new RuntimeException('Verified documents cannot be deleted');
                }
            }

            Uploader::deleteStored('documents', $doc['stored_name']);
            Database::delete('documents', 'id = ?', [$docId]);

            Session::flash('Document deleted');
            redirect('project.php?id=' . $projectId);
        }
    } catch (Throwable $e) {
        Session::flash($e->getMessage(), 'danger');
        redirect('project.php?id=' . $projectId);
    }
}

// ---------------------------------------------------------------- data
$project = Database::first(
    'SELECT p.*, pu.name AS partner_name, au.name AS assigned_name, jc.name AS job_category_name,
            l.phone AS lead_phone
       FROM projects p
       LEFT JOIN users pu ON pu.id = p.partner_id
       LEFT JOIN users au ON au.id = p.assigned_to
       LEFT JOIN job_categories jc ON jc.id = p.job_category_id
       LEFT JOIN leads l ON l.id = p.lead_id
      WHERE p.id = ?',
    [$projectId]
);

$documents = Database::all(
    'SELECT d.*, dt.name AS document_type_name, u.name AS uploaded_by_name, v.name AS verified_by_name
       FROM documents d
       LEFT JOIN document_types dt ON dt.id = d.document_type_id
       LEFT JOIN users u ON u.id = d.uploaded_by
       LEFT JOIN users v ON v.id = d.verified_by
      WHERE d.project_id = ?
      ORDER BY dt.sort_order, d.created_at DESC',
    [$projectId]
);

// Checklist of required overseas paperwork
$checklist = [];
foreach (Database::all("SELECT * FROM document_types WHERE is_active = 1 AND applies_to IN ('project','both') ORDER BY sort_order") as $type) {
    $match = null;
    foreach ($documents as $doc) {
        if ((int) ($doc['document_type_id'] ?? 0) === (int) $type['id']) {
            $match = $doc;
            break;
        }
    }

    $checklist[] = [
        'name'        => $type['name'],
        'is_required' => (int) $type['is_required'] === 1,
        'status'      => $match === null ? 'missing' : $match['verification_status'],
        'document_id' => $match === null ? null : (int) $match['id'],
    ];
}

$requiredItems = array_filter($checklist, fn($c) => $c['is_required']);
$verifiedItems = array_filter($requiredItems, fn($c) => $c['status'] === 'verified');
$percent       = count($requiredItems) > 0 ? (int) round(count($verifiedItems) / count($requiredItems) * 100) : 0;

$history = Database::all(
    'SELECT h.*, u.name AS user_name FROM project_status_history h LEFT JOIN users u ON u.id = h.user_id
      WHERE h.project_id = ? ORDER BY h.created_at DESC LIMIT 50',
    [$projectId]
);

$pageTitle = $project['project_code'];

require __DIR__ . '/partials/header.php';
?>

<nav aria-label="breadcrumb">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="projects.php">Projects</a></li>
    <li class="breadcrumb-item active"><?= e($project['project_code']) ?></li>
  </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
  <div>
    <h1 class="h4 mb-1"><?= e($project['candidate_name']) ?> <?= status_badge($project['status']) ?></h1>
    <div class="text-muted small">
      <code class="small-code"><?= e($project['project_code']) ?></code> &middot;
      <?= e($project['candidate_phone']) ?>
      <?php if ($project['destination_country']): ?> &middot; <?= e($project['destination_country']) ?><?php endif; ?>
      <?php if ($project['position']): ?> &middot; <?= e($project['position']) ?><?php endif; ?>
    </div>
  </div>
  <div class="btn-group">
    <a href="tel:<?= e($project['candidate_phone']) ?>" class="btn btn-sm btn-app"><i class="bi bi-telephone me-1"></i>Call</a>
    <a href="lead.php?id=<?= (int) $project['lead_id'] ?>" class="btn btn-sm btn-outline-secondary">Original lead</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <!-- stage -->
    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Move to next stage</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="project_id" value="<?= $projectId ?>">
          <div class="col-md-4">
            <label class="form-label small">Stage</label>
            <select name="status" class="form-select form-select-sm">
              <?php foreach (ProjectController::STATUSES as $s):
                  $locked = !Auth::isAdmin() && in_array($s, ['cancelled', 'completed', 'deployed'], true); ?>
                <option value="<?= e($s) ?>"<?= $project['status'] === $s ? ' selected' : '' ?><?= $locked ? ' disabled' : '' ?>>
                  <?= e(label($s)) ?><?= $locked ? ' (head office only)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small">Remarks</label>
            <input name="remarks" class="form-control form-control-sm" placeholder="e.g. Medical booked for 5 Sep">
          </div>
          <div class="col-md-2"><button class="btn btn-sm btn-app w-100">Update</button></div>
        </form>
      </div>
    </div>

    <!-- documents -->
    <div class="card mb-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Documents</strong>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#uploadDoc">
          <i class="bi bi-upload me-1"></i>Upload
        </button>
      </div>

      <div class="collapse" id="uploadDoc">
        <div class="card-body border-bottom bg-light">
          <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <div class="col-md-4"><label class="form-label small">File *</label><input type="file" name="file" class="form-control form-control-sm" required></div>
            <div class="col-md-3"><label class="form-label small">Type</label>
              <select name="document_type_id" class="form-select form-select-sm">
                <option value="">Other</option><?= select_options(lookup('doc_types'), null) ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label small">Doc number</label><input name="document_number" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="form-label small">Expiry</label><input type="date" name="expiry_date" class="form-control form-control-sm"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-app w-100">Save</button></div>
          </form>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Document</th><th>Type</th><th>Status</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php if ($documents === []): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No documents yet</td></tr>
          <?php else: foreach ($documents as $doc): ?>
            <tr>
              <td class="small">
                <?= e($doc['title'] ?: $doc['file_name']) ?>
                <div class="text-muted"><?= e($doc['file_name']) ?> &middot; <?= e(file_size_display((int) $doc['file_size'])) ?></div>
                <?php if ($doc['reject_reason']): ?>
                  <div class="text-danger">Rejected: <?= e($doc['reject_reason']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small"><?= e($doc['document_type_name'] ?? '—') ?>
                <?php if ($doc['expiry_date']): ?><div class="text-muted">exp <?= dt($doc['expiry_date'], false) ?></div><?php endif; ?>
              </td>
              <td><?= status_badge($doc['verification_status']) ?></td>
              <td class="small"><?= e($doc['uploaded_by_name'] ?? '—') ?><div class="text-muted"><?= ago($doc['created_at']) ?></div></td>
              <td class="text-end text-nowrap">
                <a href="download.php?type=document&id=<?= (int) $doc['id'] ?>" class="btn btn-sm btn-outline-primary" title="Download"><i class="bi bi-download"></i></a>
                <?php if (Auth::isAdmin()): ?>
                  <?php if ($doc['verification_status'] !== 'verified'): ?>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="verify">
                      <input type="hidden" name="project_id" value="<?= $projectId ?>">
                      <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
                      <input type="hidden" name="verification_status" value="verified">
                      <button class="btn btn-sm btn-outline-success" title="Verify"><i class="bi bi-check2"></i></button>
                    </form>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-danger" title="Reject"
                          data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int) $doc['id'] ?>"><i class="bi bi-x"></i></button>
                <?php endif; ?>
                <form method="post" class="d-inline" data-confirm="Delete this document?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_doc">
                  <input type="hidden" name="project_id" value="<?= $projectId ?>">
                  <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- full details -->
    <div class="card">
      <div class="card-header bg-white"><strong>Case details</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="project_id" value="<?= $projectId ?>">

          <div class="col-12"><h6 class="small text-uppercase text-muted mt-1 mb-0">Candidate</h6></div>
          <div class="col-md-4"><label class="form-label small">Name</label><input name="candidate_name" value="<?= e($project['candidate_name']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">Phone</label><input name="candidate_phone" value="<?= e($project['candidate_phone']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">Email</label><input name="candidate_email" value="<?= e($project['candidate_email']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Date of birth</label><input type="date" name="dob" value="<?= e($project['dob']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Gender</label>
            <select name="gender" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach (['male', 'female', 'other'] as $g): ?>
                <option value="<?= e($g) ?>"<?= $project['gender'] === $g ? ' selected' : '' ?>><?= e(ucfirst($g)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label small">Passport no.</label><input name="passport_no" value="<?= e($project['passport_no']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Passport expiry</label><input type="date" name="passport_expiry" value="<?= e($project['passport_expiry']) ?>" class="form-control form-control-sm"></div>

          <div class="col-12"><h6 class="small text-uppercase text-muted mt-3 mb-0">Placement</h6></div>
          <div class="col-md-3"><label class="form-label small">Job category</label>
            <select name="job_category_id" class="form-select form-select-sm">
              <option value="">—</option><?= select_options(lookup('categories'), $project['job_category_id']) ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label small">Position</label><input name="position" value="<?= e($project['position']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Employer</label><input name="employer_name" value="<?= e($project['employer_name']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Country</label><input name="destination_country" value="<?= e($project['destination_country']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Visa type</label><input name="visa_type" value="<?= e($project['visa_type']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Visa number</label><input name="visa_number" value="<?= e($project['visa_number']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Visa expiry</label><input type="date" name="visa_expiry" value="<?= e($project['visa_expiry']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Interview</label>
            <input type="datetime-local" name="interview_date"
                   value="<?= e($project['interview_date'] ? date('Y-m-d\TH:i', strtotime($project['interview_date'])) : '') ?>"
                   class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Medical date</label><input type="date" name="medical_date" value="<?= e($project['medical_date']) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Deployment date</label><input type="date" name="deployment_date" value="<?= e($project['deployment_date']) ?>" class="form-control form-control-sm"></div>

          <?php if (Auth::isAdmin()): ?>
            <div class="col-12"><h6 class="small text-uppercase text-muted mt-3 mb-0">Commercials (head office only)</h6></div>
            <div class="col-md-3"><label class="form-label small">Offered salary</label><input type="number" step="0.01" name="offered_salary" value="<?= e($project['offered_salary']) ?>" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="form-label small">Currency</label><input name="salary_currency" value="<?= e($project['salary_currency']) ?>" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small">Agreed amount (₹)</label><input type="number" step="0.01" name="agreed_amount" value="<?= e($project['agreed_amount']) ?>" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small">Paid so far (₹)</label><input type="number" step="0.01" name="paid_amount" value="<?= e($project['paid_amount']) ?>" class="form-control form-control-sm"></div>
          <?php endif; ?>

          <div class="col-12"><label class="form-label small">Remarks</label><textarea name="remarks" rows="2" class="form-control form-control-sm"><?= e($project['remarks']) ?></textarea></div>
          <div class="col-12 text-end"><button class="btn btn-sm btn-app">Save case</button></div>
        </form>
      </div>
    </div>
  </div>

  <!-- right column -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Document checklist</strong>
        <span class="small text-muted"><?= $percent ?>%</span>
      </div>
      <div class="card-body">
        <div class="progress mb-3" style="height:8px">
          <div class="progress-bar bg-<?= $percent === 100 ? 'success' : 'primary' ?>" style="width: <?= $percent ?>%"></div>
        </div>
        <?php foreach ($checklist as $item): ?>
          <div class="checklist-item">
            <span class="small">
              <?= e($item['name']) ?>
              <?php if ($item['is_required']): ?><span class="text-danger">*</span><?php endif; ?>
            </span>
            <?php if ($item['status'] === 'missing'): ?>
              <span class="badge bg-light text-muted">Missing</span>
            <?php else: ?>
              <a href="download.php?type=document&id=<?= (int) $item['document_id'] ?>" class="text-decoration-none">
                <?= status_badge($item['status']) ?>
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (Auth::isAdmin()): ?>
      <div class="card mb-3">
        <div class="card-header bg-white"><strong>Payment</strong></div>
        <div class="card-body">
          <dl class="row small mb-0">
            <dt class="col-6 text-muted">Agreed</dt><dd class="col-6 text-end"><?= money($project['agreed_amount']) ?></dd>
            <dt class="col-6 text-muted">Received</dt><dd class="col-6 text-end text-success"><?= money($project['paid_amount']) ?></dd>
            <dt class="col-6 text-muted">Balance</dt>
            <dd class="col-6 text-end fw-semibold"><?= money((float) $project['agreed_amount'] - (float) $project['paid_amount']) ?></dd>
          </dl>
        </div>
      </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-header bg-white"><strong>Ownership</strong></div>
      <div class="card-body">
        <dl class="row small mb-0">
          <dt class="col-5 text-muted">Partner</dt><dd class="col-7"><?= e($project['partner_name'] ?? 'Head office') ?></dd>
          <dt class="col-5 text-muted">Handled by</dt><dd class="col-7"><?= e($project['assigned_name'] ?? '—') ?></dd>
          <dt class="col-5 text-muted">Created</dt><dd class="col-7"><?= dt($project['created_at'], false) ?></dd>
          <dt class="col-5 text-muted">Updated</dt><dd class="col-7"><?= ago($project['updated_at']) ?></dd>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-white"><strong>Stage history</strong></div>
      <div class="card-body">
        <?php if ($history === []): ?>
          <p class="text-muted small mb-0">No history yet</p>
        <?php else: ?>
          <ul class="timeline small">
            <?php foreach ($history as $h): ?>
              <li>
                <div>
                  <?php if ($h['from_status']): ?><?= e(label($h['from_status'])) ?> <i class="bi bi-arrow-right"></i> <?php endif; ?>
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
  </div>
</div>

<?php if (Auth::isAdmin()): foreach ($documents as $doc): ?>
  <div class="modal fade" id="rejectModal<?= (int) $doc['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">
        <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
        <input type="hidden" name="verification_status" value="rejected">
        <div class="modal-header"><h5 class="modal-title">Reject document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted mb-2"><?= e($doc['file_name']) ?></p>
          <label class="form-label small">Reason (the partner will see this)</label>
          <textarea name="reject_reason" rows="3" class="form-control form-control-sm" required
                    placeholder="e.g. Passport photo is blurred, please re-scan"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-sm btn-danger">Reject</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
