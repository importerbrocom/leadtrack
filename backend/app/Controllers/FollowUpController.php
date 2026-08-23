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
 * "When should I call this person back?" - scheduled callbacks and reminders.
 */
final class FollowUpController
{
    /**
     * GET /follow-ups?bucket=today|overdue|upcoming|all&user_id=&page=
     */
    public function index(Request $request): void
    {
        Auth::authenticate($request);

        $where  = ["f.status = 'pending'"];
        $params = [];

        if ($request->query('bucket') === 'all' || $request->query('status') !== null) {
            $where = [];
            if (($status = $request->query('status')) !== null) {
                $where[]  = 'f.status = ?';
                $params[] = $status;
            } else {
                $where[] = '1 = 1';
            }
        }

        if (!Auth::isAdmin()) {
            $ids     = Auth::visibleUserIds();
            $where[] = 'f.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params  = array_merge($params, $ids);
        }

        if (($userId = $request->query('user_id')) !== null) {
            $where[]  = 'f.user_id = ?';
            $params[] = (int) $userId;
        }

        switch ($request->query('bucket', 'today')) {
            case 'today':
                $where[] = 'DATE(f.scheduled_at) = CURDATE()';
                break;
            case 'overdue':
                $where[] = 'f.scheduled_at < NOW()';
                break;
            case 'upcoming':
                $where[] = 'f.scheduled_at > NOW()';
                break;
            case 'week':
                $where[] = 'f.scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)';
                break;
        }

        $whereSql = implode(' AND ', $where);
        $page     = $request->page();
        $perPage  = $request->perPage(50);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM follow_ups f WHERE {$whereSql}", $params);

        $rows = Database::all(
            "SELECT f.*, l.name AS lead_name, l.phone AS lead_phone, l.status AS lead_status,
                    l.priority AS lead_priority, l.city AS lead_city, u.name AS user_name
               FROM follow_ups f
               JOIN leads l ON l.id = f.lead_id
               LEFT JOIN users u ON u.id = f.user_id
              WHERE {$whereSql}
              ORDER BY f.scheduled_at ASC
              LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );

        $items = array_map(fn($f) => [
            'id'            => (int) $f['id'],
            'lead_id'       => (int) $f['lead_id'],
            'lead_name'     => $f['lead_name'],
            'lead_phone'    => $f['lead_phone'],
            'lead_status'   => $f['lead_status'],
            'lead_priority' => $f['lead_priority'],
            'lead_city'     => $f['lead_city'],
            'user_id'       => (int) $f['user_id'],
            'user_name'     => $f['user_name'],
            'scheduled_at'  => $f['scheduled_at'],
            'remarks'       => $f['remarks'],
            'status'        => $f['status'],
            'is_overdue'    => $f['status'] === 'pending' && strtotime($f['scheduled_at']) < time(),
        ], $rows);

        Response::paginated($items, $total, $page, $perPage);
    }

    /** POST /follow-ups  Body: { lead_id, scheduled_at, remarks?, user_id? } */
    public function store(Request $request): void
    {
        Auth::authenticate($request);

        $data = Validator::make($request->body(), [
            'lead_id'      => 'required|int',
            'scheduled_at' => 'required|datetime',
            'remarks'      => 'nullable|string|max:500',
            'user_id'      => 'nullable|int',
        ]);

        $lead = Lead::findOrFail((int) $data['lead_id']);
        Auth::assertCanAccessLead($lead);

        $assignee = $data['user_id'] ?? ($lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : Auth::id());

        if ((int) $assignee !== Auth::id()
            && !Auth::isAdmin()
            && !in_array((int) $assignee, Auth::visibleUserIds(), true)) {
            throw ApiException::forbidden('You can only schedule calls for yourself or your telecallers');
        }

        $id = Database::transaction(function () use ($data, $assignee, $lead) {
            $id = Database::insert('follow_ups', [
                'lead_id'      => (int) $data['lead_id'],
                'user_id'      => (int) $assignee,
                'scheduled_at' => $data['scheduled_at'],
                'remarks'      => $data['remarks'] ?? null,
                'created_by'   => Auth::id(),
            ]);

            // Keep the lead's denormalised "next call" in step with the earliest
            // pending follow-up.
            $earliest = Database::scalar(
                "SELECT MIN(scheduled_at) FROM follow_ups WHERE lead_id = ? AND status = 'pending'",
                [(int) $data['lead_id']]
            );

            $update = ['next_follow_up_at' => $earliest];

            // Scheduling a callback on a fresh lead implies it is being worked.
            if (in_array($lead['status'], ['new', 'contacted'], true)) {
                Lead::changeStatus((int) $lead['id'], 'follow_up', Auth::id(), 'Callback scheduled');
            }

            Database::update('leads', $update, 'id = ?', [(int) $data['lead_id']]);

            return $id;
        });

        Response::created(['id' => $id], 'Callback scheduled');
    }

    /** PATCH /follow-ups/{id}  Body: { status?, scheduled_at?, remarks? } */
    public function update(Request $request): void
    {
        Auth::authenticate($request);

        $id  = $request->intParam('id');
        $row = Database::first(
            'SELECT f.*, l.partner_id, l.assigned_to FROM follow_ups f JOIN leads l ON l.id = f.lead_id WHERE f.id = ?',
            [$id]
        );

        if ($row === null) {
            throw ApiException::notFound('Follow-up not found');
        }

        Auth::assertCanAccessLead($row);

        $data = Validator::make($request->body(), [
            'status'       => 'nullable|in:pending,done,missed,cancelled',
            'scheduled_at' => 'nullable|datetime',
            'remarks'      => 'nullable|string|max:500',
        ]);

        $update = array_intersect_key($data, array_flip(['status', 'scheduled_at', 'remarks']));

        if ($update === []) {
            throw ApiException::badRequest('Nothing to update');
        }

        if (($data['status'] ?? null) === 'done') {
            $update['completed_at'] = Helpers::now();
        }

        Database::transaction(function () use ($id, $update, $row) {
            Database::update('follow_ups', $update, 'id = ?', [$id]);

            $earliest = Database::scalar(
                "SELECT MIN(scheduled_at) FROM follow_ups WHERE lead_id = ? AND status = 'pending'",
                [(int) $row['lead_id']]
            );

            Database::update('leads', ['next_follow_up_at' => $earliest], 'id = ?', [(int) $row['lead_id']]);
        });

        Response::ok(null, 'Follow-up updated');
    }

    /**
     * GET /follow-ups/due
     * Lightweight endpoint the app polls (or WorkManager hits) to raise local
     * reminder notifications. Returns pending callbacks inside the reminder
     * window that have not been notified yet.
     */
    public function due(Request $request): void
    {
        Auth::authenticate($request);

        $minutes = (int) Helpers::setting('followup_reminder_minutes', 15);

        $rows = Database::all(
            "SELECT f.id, f.lead_id, f.scheduled_at, f.remarks,
                    l.name AS lead_name, l.phone AS lead_phone
               FROM follow_ups f
               JOIN leads l ON l.id = f.lead_id
              WHERE f.user_id = ?
                AND f.status = 'pending'
                AND f.scheduled_at <= DATE_ADD(NOW(), INTERVAL ? MINUTE)
              ORDER BY f.scheduled_at ASC
              LIMIT 50",
            [Auth::id(), $minutes]
        );

        if ($rows !== []) {
            $ids = implode(',', array_map(fn($r) => (int) $r['id'], $rows));
            Database::query("UPDATE follow_ups SET reminded_at = ? WHERE id IN ({$ids})", [Helpers::now()]);
        }

        Response::ok(array_map(fn($r) => [
            'id'           => (int) $r['id'],
            'lead_id'      => (int) $r['lead_id'],
            'lead_name'    => $r['lead_name'],
            'lead_phone'   => $r['lead_phone'],
            'scheduled_at' => $r['scheduled_at'],
            'remarks'      => $r['remarks'],
            'is_overdue'   => strtotime($r['scheduled_at']) < time(),
        ], $rows));
    }
}
