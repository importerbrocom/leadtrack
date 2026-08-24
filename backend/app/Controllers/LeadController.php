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

final class LeadController
{
    /**
     * GET /leads
     * Filters: status, priority, assigned_to, partner_id, source_id,
     *          job_category_id, search, follow_up (today|overdue|upcoming),
     *          updated_since (for offline sync), sort, page, per_page
     */
    public function index(Request $request): void
    {
        Auth::authenticate($request);

        [$scopeSql, $params] = Auth::scopeClause('l');
        $where = [$scopeSql];

        if ($status = $request->query('status')) {
            $list = array_values(array_filter(explode(',', (string) $status)));
            if ($list !== []) {
                $where[] = 'l.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
                $params  = array_merge($params, $list);
            }
        }

        foreach (['priority' => 'l.priority', 'assigned_to' => 'l.assigned_to', 'partner_id' => 'l.partner_id',
                  'source_id' => 'l.source_id', 'job_category_id' => 'l.job_category_id'] as $key => $column) {
            if (($value = $request->query($key)) !== null) {
                $where[]  = "{$column} = ?";
                $params[] = $key === 'priority' ? $value : (int) $value;
            }
        }

        if ($search = $request->query('search')) {
            $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.alt_phone LIKE ? OR l.email LIKE ? OR l.city LIKE ?)';
            $like    = '%' . $search . '%';
            $params  = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        // Follow-up buckets drive the app's "Today's calls" screen.
        switch ($request->query('follow_up')) {
            case 'today':
                $where[] = 'DATE(l.next_follow_up_at) = CURDATE()';
                break;
            case 'overdue':
                $where[] = 'l.next_follow_up_at < NOW() AND l.status NOT IN (\'converted\',\'lost\',\'invalid\',\'dnd\')';
                break;
            case 'upcoming':
                $where[] = 'l.next_follow_up_at > NOW()';
                break;
            case 'none':
                $where[] = 'l.next_follow_up_at IS NULL';
                break;
        }

        // Incremental sync for the mobile app's local Room cache.
        if ($since = $request->query('updated_since')) {
            $parsed = Helpers::toDateTime($since);
            if ($parsed !== null) {
                $where[]  = 'l.updated_at > ?';
                $params[] = $parsed;
            }
        }

        $whereSql = implode(' AND ', $where);

        $sortMap = [
            'recent'     => 'l.updated_at DESC',
            'created'    => 'l.created_at DESC',
            'name'       => 'l.name ASC',
            'follow_up'  => 'l.next_follow_up_at IS NULL, l.next_follow_up_at ASC',
            'priority'   => "FIELD(l.priority,'high','medium','low'), l.updated_at DESC",
            'calls'      => 'l.call_count DESC',
        ];
        $orderBy = $sortMap[$request->query('sort', 'recent')] ?? $sortMap['recent'];

        $page    = $request->page();
        $perPage = $request->perPage(25, 200);
        $offset  = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM leads l WHERE {$whereSql}", $params);

        $rows = Database::all(
            Lead::selectSql() . " WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Response::paginated(array_map([Lead::class, 'toApi'], $rows), $total, $page, $perPage);
    }

    /** GET /leads/{id} - with call history, status history and documents */
    public function show(Request $request): void
    {
        Auth::authenticate($request);

        $id  = $request->intParam('id');
        $row = Database::first(Lead::selectSql() . ' WHERE l.id = ?', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Lead not found');
        }

        Auth::assertCanAccessLead($row);

        $lead = Lead::toApi($row);

        $lead['calls'] = array_map(fn($c) => [
            'id'           => (int) $c['id'],
            'phone_number' => $c['phone_number'],
            'direction'    => $c['direction'],
            'started_at'   => $c['started_at'],
            'duration_sec' => (int) $c['duration_sec'],
            'duration'     => Helpers::humanDuration((int) $c['duration_sec']),
            'answered'     => (int) $c['answered'] === 1,
            'disposition'  => $c['disposition'],
            'notes'        => $c['notes'],
            'user_name'    => $c['user_name'],
        ], Database::all(
            'SELECT c.*, u.name AS user_name FROM call_logs c
              LEFT JOIN users u ON u.id = c.user_id
             WHERE c.lead_id = ? ORDER BY c.started_at DESC, c.id DESC LIMIT 100',
            [$id]
        ));

        $lead['status_history'] = array_map(fn($h) => [
            'from_status' => $h['from_status'],
            'to_status'   => $h['to_status'],
            'remarks'     => $h['remarks'],
            'user_name'   => $h['user_name'],
            'created_at'  => $h['created_at'],
        ], Database::all(
            'SELECT h.*, u.name AS user_name FROM lead_status_history h
              LEFT JOIN users u ON u.id = h.user_id
             WHERE h.lead_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT 100',
            [$id]
        ));

        $lead['follow_ups'] = array_map(fn($f) => [
            'id'           => (int) $f['id'],
            'scheduled_at' => $f['scheduled_at'],
            'remarks'      => $f['remarks'],
            'status'       => $f['status'],
            'user_name'    => $f['user_name'],
        ], Database::all(
            'SELECT f.*, u.name AS user_name FROM follow_ups f
              LEFT JOIN users u ON u.id = f.user_id
             WHERE f.lead_id = ? ORDER BY f.scheduled_at DESC LIMIT 50',
            [$id]
        ));

        $lead['documents'] = array_map(fn($d) => [
            'id'                  => (int) $d['id'],
            'title'               => $d['title'],
            'file_name'           => $d['file_name'],
            'document_type_name'  => $d['document_type_name'],
            'file_size'           => (int) $d['file_size'],
            'verification_status' => $d['verification_status'],
            'created_at'          => $d['created_at'],
        ], Database::all(
            'SELECT d.*, dt.name AS document_type_name FROM documents d
              LEFT JOIN document_types dt ON dt.id = d.document_type_id
             WHERE d.lead_id = ? ORDER BY d.created_at DESC',
            [$id]
        ));

        Response::ok($lead);
    }

    /**
     * GET /leads/lookup?phone=...
     * The app calls this the moment a call ends, to decide whether to show the
     * "update this lead" popup or the "save as new lead" popup.
     */
    public function lookup(Request $request): void
    {
        Auth::authenticate($request);

        $phone = (string) $request->query('phone', '');
        if (trim($phone) === '') {
            throw ApiException::badRequest('phone query parameter is required');
        }

        $lead = Lead::findByPhoneForUser($phone, Auth::id(), Auth::partnerScopeId(), Auth::role());

        if ($lead === null) {
            Response::ok(['found' => false, 'lead' => null]);
        }

        $row = Database::first(Lead::selectSql() . ' WHERE l.id = ?', [(int) $lead['id']]);

        Response::ok(['found' => true, 'lead' => Lead::toApi($row)]);
    }

    /** POST /leads */
    public function store(Request $request): void
    {
        Auth::authenticate($request);

        $data = Validator::make($request->body(), [
            'name'              => 'required|string|max:160',
            'phone'             => 'required|phone',
            'alt_phone'         => 'nullable|phone',
            'whatsapp'          => 'nullable|phone',
            'email'             => 'nullable|email|max:160',
            'city'              => 'nullable|string|max:100',
            'district'          => 'nullable|string|max:100',
            'state'             => 'nullable|string|max:100',
            'source_id'         => 'nullable|int|exists:lead_sources,id',
            'job_category_id'   => 'nullable|int|exists:job_categories,id',
            'preferred_country' => 'nullable|string|max:80',
            'qualification'     => 'nullable|string|max:160',
            'experience_years'  => 'nullable|numeric|min:0|max:60',
            'current_salary'    => 'nullable|numeric|min:0',
            'expected_salary'   => 'nullable|numeric|min:0',
            'passport_status'   => 'nullable|in:not_applied,applied,ready,expired',
            'status'            => 'nullable|in:' . implode(',', Lead::STATUSES),
            'priority'          => 'nullable|in:low,medium,high',
            'next_follow_up_at' => 'nullable|datetime',
            'notes'             => 'nullable|string|max:5000',
            'assigned_to'       => 'nullable|int',
            'partner_id'        => 'nullable|int',
        ]);

        // Work out ownership from the caller's role.
        [$partnerId, $assignedTo] = $this->resolveOwnership($data);

        $normalized = Helpers::normalizePhone($data['phone']);

        // Duplicate check within the same franchise.
        $existing = Database::first(
            'SELECT id, name, status, assigned_to FROM leads WHERE phone_normalized = ? AND partner_id <=> ? LIMIT 1',
            [$normalized, $partnerId]
        );

        if ($existing !== null) {
            throw ApiException::conflict(sprintf(
                'This number is already saved as a lead: %s (%s)',
                $existing['name'],
                str_replace('_', ' ', $existing['status'])
            ));
        }

        $status = $data['status'] ?? 'new';

        $leadId = Database::transaction(function () use ($data, $partnerId, $assignedTo, $normalized, $status) {
            $id = Database::insert('leads', [
                'partner_id'           => $partnerId,
                'assigned_to'          => $assignedTo,
                'name'                 => $data['name'],
                'phone'                => $data['phone'],
                'phone_normalized'     => $normalized,
                'alt_phone'            => $data['alt_phone'] ?? null,
                'alt_phone_normalized' => Helpers::normalizePhone($data['alt_phone'] ?? null),
                'whatsapp'             => $data['whatsapp'] ?? null,
                'email'                => $data['email'] ?? null,
                'city'                 => $data['city'] ?? null,
                'district'             => $data['district'] ?? null,
                'state'                => $data['state'] ?? null,
                'source_id'            => $data['source_id'] ?? null,
                'job_category_id'      => $data['job_category_id'] ?? null,
                'preferred_country'    => $data['preferred_country'] ?? null,
                'qualification'        => $data['qualification'] ?? null,
                'experience_years'     => $data['experience_years'] ?? null,
                'current_salary'       => $data['current_salary'] ?? null,
                'expected_salary'      => $data['expected_salary'] ?? null,
                'passport_status'      => $data['passport_status'] ?? null,
                'status'               => $status,
                'priority'             => $data['priority'] ?? 'medium',
                'next_follow_up_at'    => $data['next_follow_up_at'] ?? null,
                'notes'                => $data['notes'] ?? null,
                'created_by'           => Auth::id(),
            ]);

            Database::insert('lead_status_history', [
                'lead_id'     => $id,
                'user_id'     => Auth::id(),
                'from_status' => null,
                'to_status'   => $status,
                'remarks'     => 'Lead created',
            ]);

            if (!empty($data['next_follow_up_at']) && $assignedTo !== null) {
                Database::insert('follow_ups', [
                    'lead_id'      => $id,
                    'user_id'      => $assignedTo,
                    'scheduled_at' => $data['next_follow_up_at'],
                    'remarks'      => 'Initial follow-up',
                    'created_by'   => Auth::id(),
                ]);
            }

            return $id;
        });

        Helpers::log(Auth::id(), 'lead_created', 'lead', $leadId);

        if ($assignedTo !== null && $assignedTo !== Auth::id()) {
            Helpers::notify($assignedTo, 'New lead assigned', $data['name'] . ' - ' . $data['phone'], 'lead_assigned', 'lead', $leadId);
        }

        $row = Database::first(Lead::selectSql() . ' WHERE l.id = ?', [$leadId]);

        Response::created(Lead::toApi($row), 'Lead created');
    }

    /** PATCH /leads/{id} */
    public function update(Request $request): void
    {
        Auth::authenticate($request);

        $id   = $request->intParam('id');
        $lead = Lead::findOrFail($id);
        Auth::assertCanAccessLead($lead);

        $data = Validator::make($request->body(), [
            'name'              => 'nullable|string|max:160',
            'phone'             => 'nullable|phone',
            'alt_phone'         => 'nullable|phone',
            'whatsapp'          => 'nullable|phone',
            'email'             => 'nullable|email|max:160',
            'city'              => 'nullable|string|max:100',
            'district'          => 'nullable|string|max:100',
            'state'             => 'nullable|string|max:100',
            'source_id'         => 'nullable|int|exists:lead_sources,id',
            'job_category_id'   => 'nullable|int|exists:job_categories,id',
            'preferred_country' => 'nullable|string|max:80',
            'qualification'     => 'nullable|string|max:160',
            'experience_years'  => 'nullable|numeric|min:0|max:60',
            'current_salary'    => 'nullable|numeric|min:0',
            'expected_salary'   => 'nullable|numeric|min:0',
            'passport_status'   => 'nullable|in:not_applied,applied,ready,expired',
            'priority'          => 'nullable|in:low,medium,high',
            'next_follow_up_at' => 'nullable|datetime',
            'notes'             => 'nullable|string|max:5000',
        ]);

        $update = $data;

        if (array_key_exists('phone', $data) && $data['phone'] !== null) {
            $normalized = Helpers::normalizePhone($data['phone']);
            $clash = Database::scalar(
                'SELECT id FROM leads WHERE phone_normalized = ? AND partner_id <=> ? AND id <> ?',
                [$normalized, $lead['partner_id'], $id]
            );
            if ($clash !== null) {
                throw ApiException::conflict('Another lead already uses this phone number');
            }
            $update['phone_normalized'] = $normalized;
        }

        if (array_key_exists('alt_phone', $data)) {
            $update['alt_phone_normalized'] = Helpers::normalizePhone($data['alt_phone']);
        }

        if ($update === []) {
            throw ApiException::badRequest('Nothing to update');
        }

        Database::update('leads', $update, 'id = ?', [$id]);
        Helpers::log(Auth::id(), 'lead_updated', 'lead', $id, ['fields' => array_keys($update)]);

        $row = Database::first(Lead::selectSql() . ' WHERE l.id = ?', [$id]);

        Response::ok(Lead::toApi($row), 'Lead updated');
    }

    /**
     * POST /leads/{id}/status
     * Body: { status, remarks?, next_follow_up_at? }
     *
     * This is what the post-call popup submits.
     */
    public function updateStatus(Request $request): void
    {
        Auth::authenticate($request);

        $id   = $request->intParam('id');
        $lead = Lead::findOrFail($id);
        Auth::assertCanAccessLead($lead);

        $data = Validator::make($request->body(), [
            'status'            => 'required|in:' . implode(',', Lead::STATUSES),
            'remarks'           => 'nullable|string|max:500',
            'next_follow_up_at' => 'nullable|datetime',
            'clear_follow_up'   => 'nullable|boolean',
        ]);

        if ($data['status'] === 'converted') {
            throw ApiException::badRequest('Use POST /leads/{id}/convert to convert a lead into a project');
        }

        Database::transaction(function () use ($id, $data, $lead) {
            Lead::changeStatus($id, $data['status'], Auth::id(), $data['remarks'] ?? null);

            $update = [];

            if (!empty($data['next_follow_up_at'])) {
                $update['next_follow_up_at'] = $data['next_follow_up_at'];

                Database::insert('follow_ups', [
                    'lead_id'      => $id,
                    'user_id'      => $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : Auth::id(),
                    'scheduled_at' => $data['next_follow_up_at'],
                    'remarks'      => $data['remarks'] ?? null,
                    'created_by'   => Auth::id(),
                ]);
            } elseif (!empty($data['clear_follow_up'])
                || in_array($data['status'], ['not_interested', 'lost', 'invalid', 'dnd'], true)) {
                // Closing a lead should not leave a stale reminder behind.
                $update['next_follow_up_at'] = null;
                Database::update(
                    'follow_ups',
                    ['status' => 'cancelled'],
                    'lead_id = ? AND status = ?',
                    [$id, 'pending']
                );
            }

            if ($update !== []) {
                Database::update('leads', $update, 'id = ?', [$id]);
            }
        });

        Helpers::log(Auth::id(), 'lead_status_changed', 'lead', $id, ['status' => $data['status']]);

        $row = Database::first(Lead::selectSql() . ' WHERE l.id = ?', [$id]);

        Response::ok(Lead::toApi($row), 'Status updated');
    }

    /** POST /leads/{id}/assign  Body: { assigned_to, partner_id? } */
    public function assign(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN, Auth::PARTNER);

        $id   = $request->intParam('id');
        $lead = Lead::findOrFail($id);
        Auth::assertCanAccessLead($lead);

        $data = Validator::make($request->body(), [
            'assigned_to' => 'required|int|exists:users,id',
            'partner_id'  => 'nullable|int|exists:users,id',
        ]);

        $target = Database::first('SELECT * FROM users WHERE id = ?', [$data['assigned_to']]);

        if ((int) $target['is_active'] !== 1) {
            throw ApiException::badRequest('That user is deactivated');
        }

        $update = ['assigned_to' => (int) $target['id']];

        if (Auth::isAdmin()) {
            // Admin may move the lead to another franchise outright.
            if (array_key_exists('partner_id', $data)) {
                $update['partner_id'] = $data['partner_id'];
            } elseif ($target['role'] === Auth::PARTNER) {
                $update['partner_id'] = (int) $target['id'];
            } elseif ($target['role'] === Auth::TELECALLER && $target['parent_id'] !== null) {
                $update['partner_id'] = (int) $target['parent_id'];
            }
        } else {
            // Partner may only assign inside its own team.
            if (!in_array((int) $target['id'], Auth::visibleUserIds(), true)) {
                throw ApiException::forbidden('You can only assign leads to your own telecallers');
            }
            $update['partner_id'] = Auth::id();
        }

        Database::update('leads', $update, 'id = ?', [$id]);

        Helpers::log(Auth::id(), 'lead_assigned', 'lead', $id, ['assigned_to' => (int) $target['id']]);
        Helpers::notify(
            (int) $target['id'],
            'New lead assigned',
            $lead['name'] . ' - ' . $lead['phone'],
            'lead_assigned',
            'lead',
            $id
        );

        $row = Database::first(Lead::selectSql() . ' WHERE l.id = ?', [$id]);

        Response::ok(Lead::toApi($row), 'Lead assigned to ' . $target['name']);
    }

    /**
     * POST /leads/bulk-assign  Body: { lead_ids: [], assigned_to }
     */
    public function bulkAssign(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN, Auth::PARTNER);

        $data = Validator::make($request->body(), [
            'lead_ids'    => 'required|array',
            'assigned_to' => 'required|int|exists:users,id',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['lead_ids'])));
        if ($ids === []) {
            throw ApiException::badRequest('No leads selected');
        }

        $target = Database::first('SELECT * FROM users WHERE id = ?', [$data['assigned_to']]);

        if (Auth::isPartner() && !in_array((int) $target['id'], Auth::visibleUserIds(), true)) {
            throw ApiException::forbidden('You can only assign leads to your own telecallers');
        }

        $partnerId = Auth::isPartner()
            ? Auth::id()
            : ($target['role'] === Auth::PARTNER ? (int) $target['id']
                : ($target['parent_id'] !== null ? (int) $target['parent_id'] : null));

        $assigned = 0;
        $skipped  = [];

        foreach ($ids as $leadId) {
            $lead = Lead::find($leadId);
            if ($lead === null) {
                $skipped[] = $leadId;
                continue;
            }

            try {
                Auth::assertCanAccessLead($lead);
            } catch (ApiException $e) {
                $skipped[] = $leadId;
                continue;
            }

            Database::update(
                'leads',
                ['assigned_to' => (int) $target['id'], 'partner_id' => $partnerId],
                'id = ?',
                [$leadId]
            );
            $assigned++;
        }

        if ($assigned > 0) {
            Helpers::notify(
                (int) $target['id'],
                "{$assigned} new leads assigned",
                'Open the app to start calling',
                'lead_assigned'
            );
        }

        Helpers::log(Auth::id(), 'leads_bulk_assigned', 'user', (int) $target['id'], ['count' => $assigned]);

        Response::ok(
            ['assigned' => $assigned, 'skipped' => $skipped],
            "{$assigned} lead(s) assigned to {$target['name']}"
        );
    }

    /**
     * POST /leads/import  Body: { leads: [ {name, phone, ...}, ... ] }
     * Used by the admin panel CSV import and app bulk-add.
     */
    public function import(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN, Auth::PARTNER);

        $payload = $request->input('leads');
        if (!is_array($payload) || $payload === []) {
            throw ApiException::badRequest('Provide a non-empty "leads" array');
        }

        if (count($payload) > 1000) {
            throw ApiException::badRequest('Import at most 1000 leads at a time');
        }

        $defaultAssignee = $request->input('assigned_to');
        $created = 0;
        $duplicates = [];
        $failed = [];

        foreach ($payload as $index => $raw) {
            if (!is_array($raw)) {
                $failed[] = ['row' => $index + 1, 'reason' => 'Not an object'];
                continue;
            }

            $name  = trim((string) ($raw['name'] ?? ''));
            $phone = trim((string) ($raw['phone'] ?? ''));
            $normalized = Helpers::normalizePhone($phone);

            if ($name === '' || $normalized === null || strlen($normalized) < 10) {
                $failed[] = ['row' => $index + 1, 'reason' => 'Name and a valid 10-digit phone are required'];
                continue;
            }

            $partnerId = Auth::isPartner() ? Auth::id() : ($raw['partner_id'] ?? null);
            $assignedTo = $raw['assigned_to'] ?? $defaultAssignee;

            if ($assignedTo !== null && Auth::isPartner()
                && !in_array((int) $assignedTo, Auth::visibleUserIds(), true)) {
                $assignedTo = Auth::id();
            }

            $exists = Database::scalar(
                'SELECT id FROM leads WHERE phone_normalized = ? AND partner_id <=> ?',
                [$normalized, $partnerId]
            );

            if ($exists !== null) {
                $duplicates[] = ['row' => $index + 1, 'phone' => $phone, 'lead_id' => (int) $exists];
                continue;
            }

            try {
                $id = Database::insert('leads', [
                    'partner_id'        => $partnerId === null ? null : (int) $partnerId,
                    'assigned_to'       => $assignedTo === null ? null : (int) $assignedTo,
                    'name'              => mb_substr($name, 0, 160),
                    'phone'             => mb_substr($phone, 0, 20),
                    'phone_normalized'  => $normalized,
                    'email'             => !empty($raw['email']) ? mb_substr((string) $raw['email'], 0, 160) : null,
                    'city'              => !empty($raw['city']) ? mb_substr((string) $raw['city'], 0, 100) : null,
                    'district'          => !empty($raw['district']) ? mb_substr((string) $raw['district'], 0, 100) : null,
                    'state'             => !empty($raw['state']) ? mb_substr((string) $raw['state'], 0, 100) : null,
                    'source_id'         => !empty($raw['source_id']) ? (int) $raw['source_id'] : null,
                    'job_category_id'   => !empty($raw['job_category_id']) ? (int) $raw['job_category_id'] : null,
                    'preferred_country' => !empty($raw['preferred_country']) ? mb_substr((string) $raw['preferred_country'], 0, 80) : null,
                    'qualification'     => !empty($raw['qualification']) ? mb_substr((string) $raw['qualification'], 0, 160) : null,
                    'notes'             => !empty($raw['notes']) ? mb_substr((string) $raw['notes'], 0, 5000) : null,
                    'priority'          => in_array($raw['priority'] ?? '', ['low', 'medium', 'high'], true) ? $raw['priority'] : 'medium',
                    'status'            => 'new',
                    'created_by'        => Auth::id(),
                ]);

                Database::insert('lead_status_history', [
                    'lead_id'   => $id,
                    'user_id'   => Auth::id(),
                    'to_status' => 'new',
                    'remarks'   => 'Imported',
                ]);

                $created++;
            } catch (\Throwable $e) {
                $failed[] = ['row' => $index + 1, 'reason' => 'Database error'];
            }
        }

        Helpers::log(Auth::id(), 'leads_imported', null, null, ['created' => $created]);

        Response::ok([
            'created'          => $created,
            'duplicate_count'  => count($duplicates),
            'duplicates'       => array_slice($duplicates, 0, 100),
            'failed_count'     => count($failed),
            'failed'           => array_slice($failed, 0, 100),
        ], "{$created} lead(s) imported");
    }

    /** DELETE /leads/{id} - admin only, refuses if already converted */
    public function destroy(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN);

        $id   = $request->intParam('id');
        $lead = Lead::findOrFail($id);

        if ($lead['status'] === 'converted') {
            throw ApiException::conflict('Converted leads cannot be deleted. Cancel the project first.');
        }

        Database::delete('leads', 'id = ?', [$id]);
        Helpers::log(Auth::id(), 'lead_deleted', 'lead', $id, ['name' => $lead['name']]);

        Response::ok(null, 'Lead deleted');
    }

    /**
     * Decide partner_id / assigned_to for a new lead based on who is creating it.
     *
     * @return array{0:?int,1:?int}
     */
    private function resolveOwnership(array $data): array
    {
        if (Auth::isTelecaller()) {
            $user = Auth::user();

            return [
                $user['parent_id'] === null ? null : (int) $user['parent_id'],
                Auth::id(),
            ];
        }

        if (Auth::isPartner()) {
            $assignedTo = $data['assigned_to'] ?? Auth::id();

            if (!in_array((int) $assignedTo, Auth::visibleUserIds(), true)) {
                throw ApiException::forbidden('You can only assign leads to your own telecallers');
            }

            return [Auth::id(), (int) $assignedTo];
        }

        // admin
        $partnerId  = $data['partner_id'] ?? null;
        $assignedTo = $data['assigned_to'] ?? null;

        if ($assignedTo !== null && $partnerId === null) {
            $target = Database::first('SELECT id, role, parent_id FROM users WHERE id = ?', [$assignedTo]);
            if ($target === null) {
                throw ApiException::validation(['assigned_to' => 'User not found']);
            }
            $partnerId = $target['role'] === Auth::PARTNER
                ? (int) $target['id']
                : ($target['parent_id'] !== null ? (int) $target['parent_id'] : null);
        }

        return [
            $partnerId === null ? null : (int) $partnerId,
            $assignedTo === null ? null : (int) $assignedTo,
        ];
    }
}
