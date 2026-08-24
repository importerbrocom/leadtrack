<?php

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uploader;
use App\Core\Validator;
use App\Models\Lead;

/**
 * Candidate paperwork. Partners and telecallers upload the filled forms and
 * scans they collect; head office downloads and verifies them.
 */
final class DocumentController
{
    private const SUBDIR = 'documents';

    /** GET /documents?project_id=&lead_id=&verification_status=&document_type_id= */
    public function index(Request $request): void
    {
        Auth::authenticate($request);

        $where  = ['1 = 1'];
        $params = [];

        if (($projectId = $request->query('project_id')) !== null) {
            $project = Database::first('SELECT * FROM projects WHERE id = ?', [(int) $projectId]);
            if ($project === null) {
                throw ApiException::notFound('Project not found');
            }
            Auth::assertCanAccessProject($project);

            $where[]  = 'd.project_id = ?';
            $params[] = (int) $projectId;
        } elseif (($leadId = $request->query('lead_id')) !== null) {
            $lead = Lead::findOrFail((int) $leadId);
            Auth::assertCanAccessLead($lead);

            $where[]  = 'd.lead_id = ?';
            $params[] = (int) $leadId;
        } else {
            // No specific parent: fall back to what the caller may see.
            if (!Auth::isAdmin()) {
                $ids     = Auth::visibleUserIds();
                $where[] = '(d.uploaded_by IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
                    . ' OR p.partner_id = ? OR p.assigned_to IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
                $params  = array_merge($params, $ids, [Auth::partnerScopeId() ?? Auth::id()], $ids);
            }
        }

        if ($status = $request->query('verification_status')) {
            $where[]  = 'd.verification_status = ?';
            $params[] = $status;
        }

        if (($typeId = $request->query('document_type_id')) !== null) {
            $where[]  = 'd.document_type_id = ?';
            $params[] = (int) $typeId;
        }

        $whereSql = implode(' AND ', $where);
        $page     = $request->page();
        $perPage  = $request->perPage(50);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM documents d LEFT JOIN projects p ON p.id = d.project_id WHERE {$whereSql}",
            $params
        );

        $rows = Database::all(
            "SELECT d.*, dt.name AS document_type_name, u.name AS uploaded_by_name,
                    v.name AS verified_by_name, p.project_code, p.candidate_name,
                    l.name AS lead_name
               FROM documents d
               LEFT JOIN document_types dt ON dt.id = d.document_type_id
               LEFT JOIN users u ON u.id = d.uploaded_by
               LEFT JOIN users v ON v.id = d.verified_by
               LEFT JOIN projects p ON p.id = d.project_id
               LEFT JOIN leads l ON l.id = d.lead_id
              WHERE {$whereSql}
              ORDER BY d.created_at DESC
              LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );

        Response::paginated(array_map([$this, 'toApi'], $rows), $total, $page, $perPage);
    }

    /**
     * POST /documents  (multipart/form-data)
     * Fields: file, project_id or lead_id, document_type_id?, title?,
     *         document_number?, issue_date?, expiry_date?
     */
    public function store(Request $request): void
    {
        Auth::authenticate($request);

        $file = $request->file('file');
        if ($file === null) {
            throw ApiException::badRequest('Attach the document in the "file" field');
        }

        $data = Validator::make($request->body(), [
            'project_id'       => 'nullable|int',
            'lead_id'          => 'nullable|int',
            'document_type_id' => 'nullable|int|exists:document_types,id',
            'title'            => 'nullable|string|max:200',
            'document_number'  => 'nullable|string|max:80',
            'issue_date'       => 'nullable|date',
            'expiry_date'      => 'nullable|date',
        ]);

        $projectId = $data['project_id'] ?? null;
        $leadId    = $data['lead_id'] ?? null;

        if ($projectId === null && $leadId === null) {
            throw ApiException::badRequest('Provide either project_id or lead_id');
        }

        // Authorise against the parent record, and backfill the other side of
        // the link so documents stay reachable from both lead and project.
        if ($projectId !== null) {
            $project = Database::first('SELECT * FROM projects WHERE id = ?', [(int) $projectId]);
            if ($project === null) {
                throw ApiException::notFound('Project not found');
            }
            Auth::assertCanAccessProject($project);
            $leadId = (int) $project['lead_id'];
        } else {
            $lead = Lead::findOrFail((int) $leadId);
            Auth::assertCanAccessLead($lead);

            $existingProject = Database::scalar('SELECT id FROM projects WHERE lead_id = ?', [(int) $leadId]);
            if ($existingProject !== null) {
                $projectId = (int) $existingProject;
            }
        }

        $stored = Uploader::store($file, self::SUBDIR);

        $id = Database::insert('documents', [
            'project_id'       => $projectId === null ? null : (int) $projectId,
            'lead_id'          => $leadId === null ? null : (int) $leadId,
            'document_type_id' => $data['document_type_id'] ?? null,
            'title'            => $data['title'] ?? $stored['file_name'],
            'file_name'        => $stored['file_name'],
            'stored_name'      => $stored['stored_name'],
            'mime_type'        => $stored['mime_type'],
            'file_size'        => $stored['file_size'],
            'document_number'  => $data['document_number'] ?? null,
            'issue_date'       => $data['issue_date'] ?? null,
            'expiry_date'      => $data['expiry_date'] ?? null,
            'uploaded_by'      => Auth::id(),
        ]);

        // A project sitting in "documents_pending" starts moving on first upload.
        if ($projectId !== null) {
            $current = Database::scalar('SELECT status FROM projects WHERE id = ?', [(int) $projectId]);
            if ($current === 'initiated') {
                Database::update('projects', ['status' => 'documents_pending'], 'id = ?', [(int) $projectId]);
                Database::insert('project_status_history', [
                    'project_id'  => (int) $projectId,
                    'user_id'     => Auth::id(),
                    'from_status' => 'initiated',
                    'to_status'   => 'documents_pending',
                    'remarks'     => 'First document uploaded',
                ]);
            }
        }

        Helpers::log(Auth::id(), 'document_uploaded', 'document', $id, [
            'project_id' => $projectId,
            'lead_id'    => $leadId,
        ]);

        // Head office needs to know there is something to verify.
        if (!Auth::isAdmin()) {
            $label = $data['title'] ?? $stored['file_name'];
            foreach (Database::all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1") as $admin) {
                Helpers::notify(
                    (int) $admin['id'],
                    'New document uploaded',
                    Auth::user()['name'] . ' uploaded ' . $label,
                    'document_uploaded',
                    'document',
                    $id
                );
            }
        }

        $row = $this->fetchRow($id);

        Response::created($this->toApi($row), 'Document uploaded');
    }

    /** GET /documents/{id}/download */
    public function download(Request $request): void
    {
        Auth::authenticate($request);

        $id  = $request->intParam('id');
        $row = $this->fetchRow($id);

        if ($row === null) {
            throw ApiException::notFound('Document not found');
        }

        $this->assertCanAccessDocument($row);

        $path = Uploader::resolve(self::SUBDIR, $row['stored_name']);
        if ($path === null) {
            throw ApiException::notFound('The file is missing on the server');
        }

        Helpers::log(Auth::id(), 'document_downloaded', 'document', $id);

        Response::download($path, $row['file_name'], $row['mime_type']);
    }

    /**
     * POST /documents/{id}/verify  (admin)
     * Body: { verification_status: verified|rejected|pending, reject_reason? }
     */
    public function verify(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN);

        $id  = $request->intParam('id');
        $row = $this->fetchRow($id);

        if ($row === null) {
            throw ApiException::notFound('Document not found');
        }

        $data = Validator::make($request->body(), [
            'verification_status' => 'required|in:pending,verified,rejected',
            'reject_reason'       => 'nullable|string|max:500',
        ]);

        if ($data['verification_status'] === 'rejected' && empty($data['reject_reason'])) {
            throw ApiException::validation(['reject_reason' => 'Tell the partner why it was rejected']);
        }

        Database::update('documents', [
            'verification_status' => $data['verification_status'],
            'reject_reason'       => $data['verification_status'] === 'rejected' ? $data['reject_reason'] : null,
            'verified_by'         => Auth::id(),
            'verified_at'         => Helpers::now(),
        ], 'id = ?', [$id]);

        Helpers::notify(
            (int) $row['uploaded_by'],
            'Document ' . $data['verification_status'],
            $row['file_name'] . ($data['verification_status'] === 'rejected' ? ' - ' . $data['reject_reason'] : ''),
            'document_verified',
            'document',
            $id
        );

        // When every required document is verified, advance the project.
        if ($data['verification_status'] === 'verified' && $row['project_id'] !== null) {
            $this->maybeMarkDocumentsVerified((int) $row['project_id']);
        }

        Helpers::log(Auth::id(), 'document_' . $data['verification_status'], 'document', $id);

        Response::ok($this->toApi($this->fetchRow($id)), 'Document ' . $data['verification_status']);
    }

    /** DELETE /documents/{id} - uploader (while pending) or admin */
    public function destroy(Request $request): void
    {
        Auth::authenticate($request);

        $id  = $request->intParam('id');
        $row = $this->fetchRow($id);

        if ($row === null) {
            throw ApiException::notFound('Document not found');
        }

        if (!Auth::isAdmin()) {
            if ((int) $row['uploaded_by'] !== Auth::id()) {
                throw ApiException::forbidden('You can only delete documents you uploaded');
            }
            if ($row['verification_status'] === 'verified') {
                throw ApiException::forbidden('Verified documents cannot be deleted. Contact head office.');
            }
        }

        Uploader::deleteStored(self::SUBDIR, $row['stored_name']);
        Database::delete('documents', 'id = ?', [$id]);

        Helpers::log(Auth::id(), 'document_deleted', 'document', $id, ['file_name' => $row['file_name']]);

        Response::ok(null, 'Document deleted');
    }

    /** GET /document-types */
    public function types(Request $request): void
    {
        Auth::authenticate($request);

        $rows = Database::all('SELECT * FROM document_types WHERE is_active = 1 ORDER BY sort_order, name');

        Response::ok(array_map(fn($t) => [
            'id'          => (int) $t['id'],
            'name'        => $t['name'],
            'code'        => $t['code'],
            'applies_to'  => $t['applies_to'],
            'is_required' => (int) $t['is_required'] === 1,
            'has_expiry'  => (int) $t['has_expiry'] === 1,
        ], $rows));
    }

    private function maybeMarkDocumentsVerified(int $projectId): void
    {
        $missing = (int) Database::scalar(
            "SELECT COUNT(*) FROM document_types dt
              WHERE dt.is_active = 1 AND dt.is_required = 1 AND dt.applies_to IN ('project','both')
                AND NOT EXISTS (
                    SELECT 1 FROM documents d
                     WHERE d.project_id = ? AND d.document_type_id = dt.id
                       AND d.verification_status = 'verified'
                )",
            [$projectId]
        );

        if ($missing > 0) {
            return;
        }

        $current = Database::scalar('SELECT status FROM projects WHERE id = ?', [$projectId]);

        if (in_array((string) $current, ['initiated', 'documents_pending'], true)) {
            Database::update('projects', ['status' => 'documents_verified'], 'id = ?', [$projectId]);
            Database::insert('project_status_history', [
                'project_id'  => $projectId,
                'user_id'     => Auth::id(),
                'from_status' => (string) $current,
                'to_status'   => 'documents_verified',
                'remarks'     => 'All required documents verified',
            ]);
        }
    }

    private function fetchRow(int $id): ?array
    {
        return Database::first(
            'SELECT d.*, dt.name AS document_type_name, u.name AS uploaded_by_name,
                    v.name AS verified_by_name, p.project_code, p.candidate_name,
                    p.partner_id AS project_partner_id, p.assigned_to AS project_assigned_to,
                    l.name AS lead_name, l.partner_id AS lead_partner_id, l.assigned_to AS lead_assigned_to
               FROM documents d
               LEFT JOIN document_types dt ON dt.id = d.document_type_id
               LEFT JOIN users u ON u.id = d.uploaded_by
               LEFT JOIN users v ON v.id = d.verified_by
               LEFT JOIN projects p ON p.id = d.project_id
               LEFT JOIN leads l ON l.id = d.lead_id
              WHERE d.id = ?',
            [$id]
        );
    }

    private function assertCanAccessDocument(array $row): void
    {
        if (Auth::isAdmin() || (int) $row['uploaded_by'] === Auth::id()) {
            return;
        }

        Auth::assertCanAccessLead([
            'partner_id'  => $row['project_partner_id'] ?? $row['lead_partner_id'],
            'assigned_to' => $row['project_assigned_to'] ?? $row['lead_assigned_to'],
        ]);
    }

    private function toApi(array $d): array
    {
        return [
            'id'                  => (int) $d['id'],
            'project_id'          => $d['project_id'] !== null ? (int) $d['project_id'] : null,
            'project_code'        => $d['project_code'] ?? null,
            'lead_id'             => $d['lead_id'] !== null ? (int) $d['lead_id'] : null,
            'candidate_name'      => $d['candidate_name'] ?? $d['lead_name'] ?? null,
            'document_type_id'    => $d['document_type_id'] !== null ? (int) $d['document_type_id'] : null,
            'document_type_name'  => $d['document_type_name'] ?? null,
            'title'               => $d['title'],
            'file_name'           => $d['file_name'],
            'mime_type'           => $d['mime_type'],
            'file_size'           => (int) $d['file_size'],
            'file_size_display'   => FormTemplateController::humanSize((int) $d['file_size']),
            'document_number'     => $d['document_number'],
            'issue_date'          => $d['issue_date'],
            'expiry_date'         => $d['expiry_date'],
            'verification_status' => $d['verification_status'],
            'reject_reason'       => $d['reject_reason'],
            'uploaded_by'         => (int) $d['uploaded_by'],
            'uploaded_by_name'    => $d['uploaded_by_name'] ?? null,
            'verified_by_name'    => $d['verified_by_name'] ?? null,
            'verified_at'         => $d['verified_at'],
            'download_url'        => rtrim((string) \App\Core\Config::get('app.base_url'), '/') . '/documents/' . (int) $d['id'] . '/download',
            'created_at'          => $d['created_at'],
        ];
    }
}
