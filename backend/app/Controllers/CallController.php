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
 * Automatic call tracking.
 *
 * The Android app watches PHONE_STATE, reads the device CallLog when a call
 * ends, and posts batches here. Every row carries the device's own CallLog._ID
 * so re-syncing the same call is a harmless no-op (idempotent upsert).
 */
final class CallController
{
    private const DIRECTIONS   = ['outgoing', 'incoming', 'missed', 'rejected', 'blocked', 'unknown'];
    private const DISPOSITIONS = ['connected', 'no_answer', 'busy', 'switched_off', 'wrong_number',
                                  'call_back_later', 'not_reachable', 'other'];

    /**
     * POST /calls/sync
     *
     * Body: { calls: [ {
     *     device_call_id, phone_number, direction, started_at, duration_sec,
     *     sim_slot?, disposition?, notes?, status_set?, next_follow_up_at?,
     *     lead_id?
     * } ] }
     *
     * Returns one result per submitted call, including the matched lead so the
     * app can update its local cache and show the right name.
     */
    public function sync(Request $request): void
    {
        Auth::authenticate($request);

        $calls = $request->input('calls');

        // Allow a single-call body too, which is what the post-call popup sends.
        if (!is_array($calls)) {
            $calls = $request->body() === [] ? [] : [$request->body()];
        }

        if ($calls === []) {
            throw ApiException::badRequest('Provide a "calls" array');
        }

        if (count($calls) > 500) {
            throw ApiException::badRequest('Sync at most 500 calls per request');
        }

        $userId  = Auth::id();
        $results = [];

        foreach ($calls as $index => $raw) {
            if (!is_array($raw)) {
                $results[] = ['index' => $index, 'status' => 'error', 'message' => 'Not an object'];
                continue;
            }

            try {
                $results[] = ['index' => $index] + $this->storeOne($raw, $userId);
            } catch (ApiException $e) {
                $results[] = ['index' => $index, 'status' => 'error', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $results[] = ['index' => $index, 'status' => 'error', 'message' => 'Could not save this call'];
            }
        }

        $saved = count(array_filter($results, fn($r) => ($r['status'] ?? '') !== 'error'));

        Response::ok([
            'synced'  => $saved,
            'failed'  => count($results) - $saved,
            'results' => $results,
        ], "{$saved} call(s) synced");
    }

    /**
     * Insert or update one call row + roll its effects into the lead.
     *
     * @return array{status:string, call_id:int, lead_id:?int, lead_name:?string, matched:bool}
     */
    private function storeOne(array $raw, int $userId): array
    {
        $data = Validator::make($raw, [
            'phone_number'      => 'required|string|max:25',
            'started_at'        => 'required|datetime',
            'duration_sec'      => 'nullable|int|min:0|max:86400',
            'direction'         => 'nullable|in:' . implode(',', self::DIRECTIONS),
            'device_call_id'    => 'nullable|string|max:64',
            'sim_slot'          => 'nullable|int|min:0|max:5',
            'disposition'       => 'nullable|in:' . implode(',', self::DISPOSITIONS),
            'notes'             => 'nullable|string|max:2000',
            'status_set'        => 'nullable|in:' . implode(',', Lead::STATUSES),
            'next_follow_up_at' => 'nullable|datetime',
            'lead_id'           => 'nullable|int',
        ]);

        $normalized = Helpers::normalizePhone($data['phone_number']);
        if ($normalized === null) {
            throw ApiException::badRequest('Unusable phone number');
        }

        $duration = (int) ($data['duration_sec'] ?? 0);

        // Resolve the lead: explicit id wins, otherwise match on the number.
        $lead = null;
        if (!empty($data['lead_id'])) {
            $lead = Lead::find((int) $data['lead_id']);
            if ($lead !== null) {
                Auth::assertCanAccessLead($lead);
            }
        }
        if ($lead === null) {
            $lead = Lead::findByPhoneForUser($data['phone_number'], $userId, Auth::partnerScopeId(), Auth::role());
        }

        $leadId = $lead === null ? null : (int) $lead['id'];

        $row = [
            'lead_id'          => $leadId,
            'user_id'          => $userId,
            'phone_number'     => $data['phone_number'],
            'phone_normalized' => $normalized,
            'direction'        => $data['direction'] ?? 'outgoing',
            'started_at'       => $data['started_at'],
            'ended_at'         => date('Y-m-d H:i:s', strtotime($data['started_at']) + $duration),
            'duration_sec'     => $duration,
            'answered'         => $duration > 0 ? 1 : 0,
            'disposition'      => $data['disposition'] ?? ($duration > 0 ? 'connected' : null),
            'status_set'       => $data['status_set'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'device_call_id'   => $data['device_call_id'] ?? null,
            'sim_slot'         => $data['sim_slot'] ?? null,
        ];

        $callId = Database::transaction(function () use ($row, $data, $leadId, $userId) {
            $existingId = null;

            if ($row['device_call_id'] !== null) {
                $existingId = Database::scalar(
                    'SELECT id FROM call_logs WHERE user_id = ? AND device_call_id = ?',
                    [$userId, $row['device_call_id']]
                );
            }

            if ($existingId !== null) {
                // Re-sync: refresh mutable fields (the popup may have added notes).
                Database::update('call_logs', [
                    'lead_id'      => $row['lead_id'],
                    'duration_sec' => $row['duration_sec'],
                    'answered'     => $row['answered'],
                    'ended_at'     => $row['ended_at'],
                    'disposition'  => $row['disposition'] ?? null,
                    'status_set'   => $row['status_set'] ?? null,
                    'notes'        => $row['notes'] ?? null,
                ], 'id = ?', [(int) $existingId]);

                $id = (int) $existingId;
            } else {
                $id = Database::insert('call_logs', $row);
            }

            if ($leadId !== null) {
                // Recalculated from call_logs, so duplicates never inflate it.
                Lead::refreshCallStats($leadId);

                $leadUpdate = [];

                if (!empty($data['status_set'])) {
                    Lead::changeStatus($leadId, $data['status_set'], $userId, $data['notes'] ?? 'Updated after call');
                } elseif ($row['duration_sec'] > 0) {
                    // A connected call on an untouched lead moves it to "contacted".
                    $currentStatus = Database::scalar('SELECT status FROM leads WHERE id = ?', [$leadId]);
                    if ($currentStatus === 'new') {
                        Lead::changeStatus($leadId, 'contacted', $userId, 'Auto-updated: first call connected');
                    }
                }

                if (!empty($data['next_follow_up_at'])) {
                    $leadUpdate['next_follow_up_at'] = $data['next_follow_up_at'];

                    Database::insert('follow_ups', [
                        'lead_id'      => $leadId,
                        'user_id'      => $userId,
                        'scheduled_at' => $data['next_follow_up_at'],
                        'remarks'      => $data['notes'] ?? null,
                        'created_by'   => $userId,
                    ]);

                    // Close out the follow-up this call was answering.
                    Database::query(
                        "UPDATE follow_ups
                            SET status = 'done', completed_at = ?
                          WHERE lead_id = ? AND user_id = ? AND status = 'pending' AND scheduled_at <= ?",
                        [Helpers::now(), $leadId, $userId, $row['started_at']]
                    );
                }

                if ($leadUpdate !== []) {
                    Database::update('leads', $leadUpdate, 'id = ?', [$leadId]);
                }
            }

            return $id;
        });

        return [
            'status'    => 'ok',
            'call_id'   => $callId,
            'lead_id'   => $leadId,
            'lead_name' => $lead === null ? null : $lead['name'],
            'matched'   => $leadId !== null,
        ];
    }

    /**
     * PATCH /calls/{id}
     * Attach a disposition/notes/status to an already-synced call. Used when the
     * telecaller dismisses the popup and fills it in from the app later.
     */
    public function update(Request $request): void
    {
        Auth::authenticate($request);

        $id   = $request->intParam('id');
        $call = Database::first('SELECT * FROM call_logs WHERE id = ?', [$id]);

        if ($call === null) {
            throw ApiException::notFound('Call log not found');
        }

        if ((int) $call['user_id'] !== Auth::id() && !Auth::isAdmin()) {
            if (!Auth::isPartner() || !in_array((int) $call['user_id'], Auth::visibleUserIds(), true)) {
                throw ApiException::forbidden('That call belongs to another user');
            }
        }

        $data = Validator::make($request->body(), [
            'disposition'       => 'nullable|in:' . implode(',', self::DISPOSITIONS),
            'notes'             => 'nullable|string|max:2000',
            'lead_id'           => 'nullable|int',
            'status_set'        => 'nullable|in:' . implode(',', Lead::STATUSES),
            'next_follow_up_at' => 'nullable|datetime',
        ]);

        $update = array_intersect_key($data, array_flip(['disposition', 'notes', 'status_set']));

        // Allow linking an unmatched call to a lead after the fact.
        $leadId = $call['lead_id'] !== null ? (int) $call['lead_id'] : null;
        if (array_key_exists('lead_id', $data) && $data['lead_id'] !== null) {
            $lead = Lead::findOrFail((int) $data['lead_id']);
            Auth::assertCanAccessLead($lead);
            $update['lead_id'] = $leadId = (int) $lead['id'];
        }

        Database::transaction(function () use ($id, $update, $data, $leadId) {
            if ($update !== []) {
                Database::update('call_logs', $update, 'id = ?', [$id]);
            }

            if ($leadId !== null) {
                Lead::refreshCallStats($leadId);

                if (!empty($data['status_set'])) {
                    Lead::changeStatus($leadId, $data['status_set'], Auth::id(), $data['notes'] ?? 'Updated after call');
                }

                if (!empty($data['next_follow_up_at'])) {
                    Database::update('leads', ['next_follow_up_at' => $data['next_follow_up_at']], 'id = ?', [$leadId]);
                    Database::insert('follow_ups', [
                        'lead_id'      => $leadId,
                        'user_id'      => Auth::id(),
                        'scheduled_at' => $data['next_follow_up_at'],
                        'remarks'      => $data['notes'] ?? null,
                        'created_by'   => Auth::id(),
                    ]);
                }
            }
        });

        Response::ok(null, 'Call updated');
    }

    /**
     * GET /calls?lead_id=&user_id=&from=&to=&unmatched=1
     */
    public function index(Request $request): void
    {
        Auth::authenticate($request);

        $where  = ['1 = 1'];
        $params = [];

        // Visibility: telecaller sees own calls, partner sees the team's, admin all.
        if (!Auth::isAdmin()) {
            $ids = Auth::visibleUserIds();
            $where[] = 'c.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }

        if (($leadId = $request->query('lead_id')) !== null) {
            $where[]  = 'c.lead_id = ?';
            $params[] = (int) $leadId;
        }

        if (($userId = $request->query('user_id')) !== null) {
            $where[]  = 'c.user_id = ?';
            $params[] = (int) $userId;
        }

        if ($request->query('unmatched') !== null) {
            $where[] = 'c.lead_id IS NULL';
        }

        if ($from = Helpers::toDateTime($request->query('from'))) {
            $where[]  = 'c.started_at >= ?';
            $params[] = $from;
        }

        if ($to = Helpers::toDateTime($request->query('to'))) {
            $where[]  = 'c.started_at <= ?';
            $params[] = $to;
        }

        $whereSql = implode(' AND ', $where);
        $page     = $request->page();
        $perPage  = $request->perPage(50);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM call_logs c WHERE {$whereSql}", $params);

        $rows = Database::all(
            "SELECT c.*, l.name AS lead_name, l.status AS lead_status, u.name AS user_name
               FROM call_logs c
               LEFT JOIN leads l ON l.id = c.lead_id
               LEFT JOIN users u ON u.id = c.user_id
              WHERE {$whereSql}
              ORDER BY c.started_at DESC
              LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );

        $items = array_map(fn($c) => [
            'id'           => (int) $c['id'],
            'lead_id'      => $c['lead_id'] !== null ? (int) $c['lead_id'] : null,
            'lead_name'    => $c['lead_name'],
            'lead_status'  => $c['lead_status'],
            'user_id'      => (int) $c['user_id'],
            'user_name'    => $c['user_name'],
            'phone_number' => $c['phone_number'],
            'direction'    => $c['direction'],
            'started_at'   => $c['started_at'],
            'duration_sec' => (int) $c['duration_sec'],
            'duration'     => Helpers::humanDuration((int) $c['duration_sec']),
            'answered'     => (int) $c['answered'] === 1,
            'disposition'  => $c['disposition'],
            'status_set'   => $c['status_set'],
            'notes'        => $c['notes'],
        ], $rows);

        Response::paginated($items, $total, $page, $perPage);
    }

    /**
     * GET /calls/stats?from=&to=&group_by=user|day
     * Powers the "my performance" card and the admin call report.
     */
    public function stats(Request $request): void
    {
        Auth::authenticate($request);

        $where  = ['1 = 1'];
        $params = [];

        if (!Auth::isAdmin()) {
            $ids = Auth::visibleUserIds();
            $where[] = 'c.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }

        $from = Helpers::toDateTime($request->query('from')) ?? date('Y-m-d 00:00:00');
        $to   = Helpers::toDateTime($request->query('to'))   ?? date('Y-m-d 23:59:59');

        $where[]  = 'c.started_at BETWEEN ? AND ?';
        $params[] = $from;
        $params[] = $to;

        $whereSql = implode(' AND ', $where);

        $totals = Database::first(
            "SELECT COUNT(*) AS total_calls,
                    SUM(c.answered) AS connected_calls,
                    COALESCE(SUM(c.duration_sec), 0) AS total_seconds,
                    COUNT(DISTINCT c.lead_id) AS leads_touched
               FROM call_logs c WHERE {$whereSql}",
            $params
        ) ?? [];

        $groupBy = $request->query('group_by', 'user');

        if ($groupBy === 'day') {
            $breakdown = Database::all(
                "SELECT DATE(c.started_at) AS label,
                        COUNT(*) AS calls,
                        SUM(c.answered) AS connected,
                        COALESCE(SUM(c.duration_sec),0) AS seconds
                   FROM call_logs c WHERE {$whereSql}
                  GROUP BY DATE(c.started_at) ORDER BY label",
                $params
            );
        } else {
            $breakdown = Database::all(
                "SELECT u.name AS label, c.user_id,
                        COUNT(*) AS calls,
                        SUM(c.answered) AS connected,
                        COALESCE(SUM(c.duration_sec),0) AS seconds
                   FROM call_logs c
                   LEFT JOIN users u ON u.id = c.user_id
                  WHERE {$whereSql}
                  GROUP BY c.user_id, u.name ORDER BY calls DESC",
                $params
            );
        }

        $totalSeconds = (int) ($totals['total_seconds'] ?? 0);
        $totalCalls   = (int) ($totals['total_calls'] ?? 0);

        Response::ok([
            'from'            => $from,
            'to'              => $to,
            'total_calls'     => $totalCalls,
            'connected_calls' => (int) ($totals['connected_calls'] ?? 0),
            'total_seconds'   => $totalSeconds,
            'total_talk_time' => Helpers::humanDuration($totalSeconds),
            'avg_seconds'     => $totalCalls > 0 ? (int) round($totalSeconds / $totalCalls) : 0,
            'leads_touched'   => (int) ($totals['leads_touched'] ?? 0),
            'breakdown'       => array_map(fn($b) => [
                'label'     => (string) $b['label'],
                'user_id'   => isset($b['user_id']) ? (int) $b['user_id'] : null,
                'calls'     => (int) $b['calls'],
                'connected' => (int) $b['connected'],
                'seconds'   => (int) $b['seconds'],
                'talk_time' => Helpers::humanDuration((int) $b['seconds']),
            ], $breakdown),
        ]);
    }
}
