<?php

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Lead;

/**
 * A "project" is a lead that converted into a real overseas placement case:
 * documents, medical, PCC, visa, deployment.
 */
final class ProjectController
{
    public const STATUSES = [
        'initiated', 'documents_pending', 'documents_verified', 'interview_scheduled',
        'selected', 'medical_pending', 'medical_cleared', 'pcc_pending', 'visa_processing',
        'visa_approved', 'ticket_booked', 'deployed', 'on_hold', 'cancelled', 'completed',
    ];

    /**
     * POST /leads/{id}/convert
     *
     * Turns a lead into a project. Everything except the candidate name/phone is
     * optional at conversion time - the rest gets filled in as the case moves.
     */
    public function convert(Request $request): void
    {
        Auth::authenticate($request);

        $leadId = $request->intParam('id');
        $lead   = Lead::findOrFail($leadId);
        Auth::assertCanAccessLead($lead);

        // Head office can restrict conversion to itself via settings.
        if (!Auth::isAdmin() && (string) Helpers::setting('partner_can_convert', '1') !== '1') {
            throw ApiException::forbidden('Only head office can convert leads. Ask your admin to do this.');
        }

        $existing = Database::first('SELECT id, project_code FROM projects WHERE lead_id = ?', [$leadId]);
        if ($existing !== null) {
            throw ApiException::conflict('This lead is already converted (project ' . $existing['project_code'] . ')');
        }

        $data = Validator::make($request->body(), [
            'candidate_name'      => 'nullable|string|max:160',
            'candidate_phone'     => 'nullable|phone',
            'candidate_email'     => 'nullable|email|max:160',
            'dob'                 => 'nullable|date',
            'gender'              => 'nullable|in:male,female,other',
            'passport_no'         => 'nullable|string|max:30',
            'passport_expiry'     => 'nullable|date',
            'job_category_id'     => 'nullable|int|exists:job_categories,id',
            'position'            => 'nullable|string|max:160',
            'employer_name'       => 'nullable|string|max:200',
            'destination_country' => 'nullable|string|max:80',
            'visa_type'           => 'nullable|string|max:80',
            'offered_salary'      => 'nullable|numeric|min:0',
            'salary_currency'     => 'nullable|string|max:10',
            'agreed_amount'       => 'nullable|numeric|min:0',
            'paid_amount'         => 'nullable|numeric|min:0',
            'status'              => 'nullable|in:' . implode(',', self::STATUSES),
            'remarks'             => 'nullable|string|max:5000',
        ]);

        $status = $data['status'] ?? 'documents_pending';

        $projectId = Database::transaction(function () use ($lead, $leadId, $data, $status) {
            $code = Helpers::nextProjectCode();

            $id = Database::insert('projects', [
                'lead_id'             => $leadId,
                'project_code'        => $code,
                'partner_id'          => $lead['partner_id'] !== null ? (int) $lead['partner_id'] : null,
                'assigned_to'         => $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : Auth::id(),
                'candidate_name'      => $data['candidate_name']  ?? $lead['name'],
                'candidate_phone'     => $data['candidate_phone'] ?? $lead['phone'],
                'candidate_email'     => $data['candidate_email'] ?? $lead['email'],
                'dob'                 => $data['dob'] ?? null,
                'gender'              => $data['gender'] ?? null,
                'passport_no'         => $data['passport_no'] ?? null,
                'passport_expiry'     => $data['passport_expiry'] ?? null,
                'job_category_id'     => $data['job_category_id'] ?? ($lead['job_category_id'] !== null ? (int) $lead['job_category_id'] : null),
                'position'            => $data['position'] ?? null,
                'employer_name'       => $data['employer_name'] ?? null,
                'destination_country' => $data['destination_country'] ?? $lead['preferred_country'],
                'visa_type'           => $data['visa_type'] ?? null,
                'offered_salary'      => $data['offered_salary'] ?? null,
                'salary_currency'     => $data['salary_currency'] ?? 'AED',
                'agreed_amount'       => $data['agreed_amount'] ?? 0,
                'paid_amount'         => $data['paid_amount'] ?? 0,
                'status'              => $status,
                'remarks'             => $data['remarks'] ?? null,
                'created_by'          => Auth::id(),
            ]);

            Database::insert('project_status_history', [
                'project_id' => $id,
                'user_id'    => Auth::id(),
                'to_status'  => $status,
                'remarks'    => 'Converted from lead',
            ]);

            Lead::changeStatus($leadId, 'converted', Auth::id(), 'Converted to project ' . $code);

            Database::update(
                'leads',
                ['converted_at' => Helpers::now(), 'next_follow_up_at' => null],
                'id = ?',
                [$leadId]
            );

            // No more chasing calls for a converted lead.
            Database::update('follow_ups', ['status' => 'cancelled'], "lead_id = ? AND status = 'pending'", [$leadId]);

            // Carry any documents already collected at lead stage into the project.
            Database::update('documents', ['project_id' => $id], 'lead_id = ? AND project_id IS NULL', [$leadId]);

            return $id;
        });

        Helpers::log(Auth::id(), 'lead_converted', 'project', $projectId, ['lead_id' => $leadId]);

        // Tell head office a new case has landed.
        foreach (Database::all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1") as $admin) {
            if ((int) $admin['id'] !== Auth::id()) {
                Helpers::notify(
                    (int) $admin['id'],
                    'Lead converted to project',
                    ($data['candidate_name'] ?? $lead['name']) . ' by ' . Auth::user()['name'],
                    'lead_converted',
                    'project',
                    $projectId
                );
            }
        }

        Response::created($this->fetch($projectId), 'Lead converted to project');
    }

    /** GET /projects */
    public function index(Request $request): void
    {
        Auth::authenticate($request);

        [$scopeSql, $params] = Auth::scopeClause('p');
        $where = [$scopeSql];

        if ($status = $request->query('status')) {
            $list = array_values(array_filter(explode(',', (string) $status)));
            if ($list !== []) {
                $where[] = 'p.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
                $params  = array_merge($params, $list);
            }
        }

        if (($partnerId = $request->query('partner_id')) !== null) {
            $where[]  = 'p.partner_id = ?';
            $params[] = (int) $partnerId;
        }

        if (($country = $request->query('destination_country')) !== null) {
            $where[]  = 'p.destination_country = ?';
            $params[] = $country;
        }

        if ($search = $request->query('search')) {
            $where[] = '(p.candidate_name LIKE ? OR p.candidate_phone LIKE ? OR p.project_code LIKE ? OR p.passport_no LIKE ?)';
            $like    = '%' . $search . '%';
            $params  = array_merge($params, [$like, $like, $like, $like]);
        }

        if ($since = Helpers::toDateTime($request->query('updated_since'))) {
            $where[]  = 'p.updated_at > ?';
            $params[] = $since;
        }

        $whereSql = implode(' AND ', $where);
        $page     = $request->page();
        $perPage  = $request->perPage(25);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM projects p WHERE {$whereSql}", $params);

        $rows = Database::all(
            $this->selectSql() . " WHERE {$whereSql} ORDER BY p.updated_at DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );

        Response::paginated(array_map([$this, 'toApi'], $rows), $total, $page, $perPage);
    }

    /** GET /projects/{id} - includes the document checklist */
    public function show(Request $request): void
    {
        Auth::authenticate($request);

        $id  = $request->intParam('id');
        $row = Database::first($this->selectSql() . ' WHERE p.id = ?', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Project not found');
        }

        Auth::assertCanAccessProject($row);

        $project = $this->toApi($row);

        $project['documents'] = array_map(fn($d) => [
            'id'                  => (int) $d['id'],
            'document_type_id'    => $d['document_type_id'] !== null ? (int) $d['document_type_id'] : null,
            'document_type_name'  => $d['document_type_name'],
            'title'               => $d['title'],
            'file_name'           => $d['file_name'],
            'mime_type'           => $d['mime_type'],
            'file_size'           => (int) $d['file_size'],
            'document_number'     => $d['document_number'],
            'expiry_date'         => $d['expiry_date'],
            'verification_status' => $d['verification_status'],
            'reject_reason'       => $d['reject_reason'],
            'uploaded_by_name'    => $d['uploaded_by_name'],
            'created_at'          => $d['created_at'],
        ], Database::all(
            'SELECT d.*, dt.name AS document_type_name, u.name AS uploaded_by_name
               FROM documents d
               LEFT JOIN document_types dt ON dt.id = d.document_type_id
               LEFT JOIN users u ON u.id = d.uploaded_by
              WHERE d.project_id = ?
              ORDER BY dt.sort_order, d.created_at DESC',
            [$id]
        ));

        // Checklist: every required overseas document and whether it has arrived.
        $project['checklist'] = array_map(function ($t) use ($id) {
            $doc = Database::first(
                'SELECT id, verification_status FROM documents
                  WHERE project_id = ? AND document_type_id = ?
                  ORDER BY created_at DESC LIMIT 1',
                [$id, (int) $t['id']]
            );

            return [
                'document_type_id' => (int) $t['id'],
                'name'             => $t['name'],
                'code'             => $t['code'],
                'is_required'      => (int) $t['is_required'] === 1,
                'uploaded'         => $doc !== null,
                'document_id'      => $doc === null ? null : (int) $doc['id'],
                'status'           => $doc === null ? 'missing' : $doc['verification_status'],
            ];
        }, Database::all(
            "SELECT * FROM document_types
              WHERE is_active = 1 AND applies_to IN ('project','both')
              ORDER BY sort_order"
        ));

        $required = array_filter($project['checklist'], fn($c) => $c['is_required']);
        $done     = array_filter($required, fn($c) => $c['status'] === 'verified');

        $project['document_progress'] = [
            'required'          => count($required),
            'verified'          => count($done),
            'uploaded'          => count(array_filter($required, fn($c) => $c['uploaded'])),
            'percent_complete'  => count($required) > 0 ? (int) round(count($done) / count($required) * 100) : 0,
        ];

        $project['status_history'] = array_map(fn($h) => [
            'from_status' => $h['from_status'],
            'to_status'   => $h['to_status'],
            'remarks'     => $h['remarks'],
            'user_name'   => $h['user_name'],
            'created_at'  => $h['created_at'],
        ], Database::all(
            'SELECT h.*, u.name AS user_name FROM project_status_history h
              LEFT JOIN users u ON u.id = h.user_id
             WHERE h.project_id = ? ORDER BY h.created_at DESC',
            [$id]
        ));

        Response::ok($project);
    }

    /** PATCH /projects/{id} */
    public function update(Request $request): void
    {
        Auth::authenticate($request);

        $id  = $request->intParam('id');
        $row = Database::first('SELECT * FROM projects WHERE id = ?', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Project not found');
        }

        Auth::assertCanAccessProject($row);

        $data = Validator::make($request->body(), [
            'candidate_name'      => 'nullable|string|max:160',
            'candidate_phone'     => 'nullable|phone',
            'candidate_email'     => 'nullable|email|max:160',
            'dob'                 => 'nullable|date',
            'gender'              => 'nullable|in:male,female,other',
            'passport_no'         => 'nullable|string|max:30',
            'passport_expiry'     => 'nullable|date',
            'job_category_id'     => 'nullable|int|exists:job_categories,id',
            'position'            => 'nullable|string|max:160',
            'employer_name'       => 'nullable|string|max:200',
            'destination_country' => 'nullable|string|max:80',
            'visa_type'           => 'nullable|string|max:80',
            'offered_salary'      => 'nullable|numeric|min:0',
            'salary_currency'     => 'nullable|string|max:10',
            'agreed_amount'       => 'nullable|numeric|min:0',
            'paid_amount'         => 'nullable|numeric|min:0',
            'interview_date'      => 'nullable|datetime',
            'medical_date'        => 'nullable|date',
            'visa_number'         => 'nullable|string|max:60',
            'visa_expiry'         => 'nullable|date',
            'deployment_date'     => 'nullable|date',
            'remarks'             => 'nullable|string|max:5000',
        ]);

        // Money is head-office business.
        if (!Auth::isAdmin()) {
            unset($data['agreed_amount'], $data['paid_amount'], $data['offered_salary']);
        }

        if ($data === []) {
            throw ApiException::badRequest('Nothing to update');
        }

        Database::update('projects', $data, 'id = ?', [$id]);
        Helpers::log(Auth::id(), 'project_updated', 'project', $id, ['fields' => array_keys($data)]);

        Response::ok($this->fetch($id), 'Project updated');
    }

    /** POST /projects/{id}/status  Body: { status, remarks? } */
    public function updateStatus(Request $request): void
    {
        Auth::authenticate($request);

        $id  = $request->intParam('id');
        $row = Database::first('SELECT * FROM projects WHERE id = ?', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Project not found');
        }

        Auth::assertCanAccessProject($row);

        $data = Validator::make($request->body(), [
            'status'  => 'required|in:' . implode(',', self::STATUSES),
            'remarks' => 'nullable|string|max:500',
        ]);

        // Only head office may cancel a case or mark it deployed/completed.
        if (!Auth::isAdmin() && in_array($data['status'], ['cancelled', 'completed', 'deployed'], true)) {
            throw ApiException::forbidden('Only head office can set the status to ' . str_replace('_', ' ', $data['status']));
        }

        if ($row['status'] === $data['status']) {
            Response::ok($this->fetch($id), 'Status unchanged');
        }

        Database::transaction(function () use ($id, $row, $data) {
            Database::update('projects', ['status' => $data['status']], 'id = ?', [$id]);

            Database::insert('project_status_history', [
                'project_id'  => $id,
                'user_id'     => Auth::id(),
                'from_status' => $row['status'],
                'to_status'   => $data['status'],
                'remarks'     => $data['remarks'] ?? null,
            ]);
        });

        Helpers::log(Auth::id(), 'project_status_changed', 'project', $id, ['status' => $data['status']]);

        // Keep the field team informed.
        if ($row['assigned_to'] !== null && (int) $row['assigned_to'] !== Auth::id()) {
            Helpers::notify(
                (int) $row['assigned_to'],
                'Project status updated',
                $row['candidate_name'] . ' -> ' . str_replace('_', ' ', $data['status']),
                'project_status',
                'project',
                $id
            );
        }

        Response::ok($this->fetch($id), 'Status updated');
    }

    private function selectSql(): string
    {
        return 'SELECT p.*, jc.name AS job_category_name,
                       pu.name AS partner_name, au.name AS assigned_to_name,
                       l.phone AS lead_phone,
                       (SELECT COUNT(*) FROM documents d WHERE d.project_id = p.id) AS document_count,
                       (SELECT COUNT(*) FROM documents d WHERE d.project_id = p.id AND d.verification_status = \'pending\') AS pending_document_count
                  FROM projects p
                  LEFT JOIN job_categories jc ON jc.id = p.job_category_id
                  LEFT JOIN users pu ON pu.id = p.partner_id
                  LEFT JOIN users au ON au.id = p.assigned_to
                  LEFT JOIN leads l  ON l.id = p.lead_id';
    }

    private function fetch(int $id): array
    {
        return $this->toApi(Database::first($this->selectSql() . ' WHERE p.id = ?', [$id]));
    }

    private function toApi(array $r): array
    {
        $out = [
            'id'                  => (int) $r['id'],
            'lead_id'             => (int) $r['lead_id'],
            'project_code'        => $r['project_code'],
            'candidate_name'      => $r['candidate_name'],
            'candidate_phone'     => $r['candidate_phone'],
            'candidate_email'     => $r['candidate_email'],
            'dob'                 => $r['dob'],
            'gender'              => $r['gender'],
            'passport_no'         => $r['passport_no'],
            'passport_expiry'     => $r['passport_expiry'],
            'job_category_id'     => $r['job_category_id'] !== null ? (int) $r['job_category_id'] : null,
            'job_category_name'   => $r['job_category_name'] ?? null,
            'position'            => $r['position'],
            'employer_name'       => $r['employer_name'],
            'destination_country' => $r['destination_country'],
            'visa_type'           => $r['visa_type'],
            'salary_currency'     => $r['salary_currency'],
            'status'              => $r['status'],
            'interview_date'      => $r['interview_date'],
            'medical_date'        => $r['medical_date'],
            'visa_number'         => $r['visa_number'],
            'visa_expiry'         => $r['visa_expiry'],
            'deployment_date'     => $r['deployment_date'],
            'remarks'             => $r['remarks'],
            'partner_id'          => $r['partner_id'] !== null ? (int) $r['partner_id'] : null,
            'partner_name'        => $r['partner_name'] ?? null,
            'assigned_to'         => $r['assigned_to'] !== null ? (int) $r['assigned_to'] : null,
            'assigned_to_name'    => $r['assigned_to_name'] ?? null,
            'document_count'      => (int) ($r['document_count'] ?? 0),
            'pending_document_count' => (int) ($r['pending_document_count'] ?? 0),
            'created_at'          => $r['created_at'],
            'updated_at'          => $r['updated_at'],
        ];

        // Commercial figures are admin-only.
        if (Auth::isAdmin()) {
            $out['offered_salary'] = $r['offered_salary'] !== null ? (float) $r['offered_salary'] : null;
            $out['agreed_amount']  = (float) $r['agreed_amount'];
            $out['paid_amount']    = (float) $r['paid_amount'];
            $out['balance_amount'] = (float) $r['agreed_amount'] - (float) $r['paid_amount'];
        }

        return $out;
    }
}
