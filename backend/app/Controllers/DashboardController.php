<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;

final class DashboardController
{
    /**
     * GET /dashboard
     * One call that fills the app's home screen for any role.
     */
    public function summary(Request $request): void
    {
        Auth::authenticate($request);

        [$leadScope, $leadParams] = Auth::scopeClause('l');
        [$projScope, $projParams] = Auth::scopeClause('p');

        $callWhere  = '1 = 1';
        $callParams = [];
        if (!Auth::isAdmin()) {
            $ids        = Auth::visibleUserIds();
            $callWhere  = 'c.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $callParams = $ids;
        }

        // ---- lead counts by status ----
        $statusRows = Database::all(
            "SELECT l.status, COUNT(*) AS total FROM leads l WHERE {$leadScope} GROUP BY l.status",
            $leadParams
        );

        $byStatus = [];
        foreach ($statusRows as $r) {
            $byStatus[$r['status']] = (int) $r['total'];
        }

        $totalLeads = array_sum($byStatus);

        // ---- follow-up buckets ----
        $followUps = Database::first(
            "SELECT
                SUM(CASE WHEN DATE(l.next_follow_up_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN l.next_follow_up_at < NOW() THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN l.next_follow_up_at > NOW()
                          AND l.next_follow_up_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week
               FROM leads l
              WHERE {$leadScope}
                AND l.next_follow_up_at IS NOT NULL
                AND l.status NOT IN ('converted','lost','invalid','dnd')",
            $leadParams
        ) ?? [];

        // ---- today's calls ----
        $callsToday = Database::first(
            "SELECT COUNT(*) AS total,
                    SUM(c.answered) AS connected,
                    COALESCE(SUM(c.duration_sec),0) AS seconds
               FROM call_logs c
              WHERE {$callWhere} AND DATE(c.started_at) = CURDATE()",
            $callParams
        ) ?? [];

        // ---- this month's calls ----
        $callsMonth = Database::first(
            "SELECT COUNT(*) AS total, COALESCE(SUM(c.duration_sec),0) AS seconds
               FROM call_logs c
              WHERE {$callWhere}
                AND c.started_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
            $callParams
        ) ?? [];

        // ---- projects ----
        $projectRows = Database::all(
            "SELECT p.status, COUNT(*) AS total FROM projects p WHERE {$projScope} GROUP BY p.status",
            $projParams
        );

        $projectsByStatus = [];
        foreach ($projectRows as $r) {
            $projectsByStatus[$r['status']] = (int) $r['total'];
        }

        // ---- conversions this month ----
        $convertedThisMonth = (int) Database::scalar(
            "SELECT COUNT(*) FROM leads l
              WHERE {$leadScope} AND l.converted_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
            $leadParams
        );

        $newThisMonth = (int) Database::scalar(
            "SELECT COUNT(*) FROM leads l
              WHERE {$leadScope} AND l.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
            $leadParams
        );

        $data = [
            'leads' => [
                'total'          => $totalLeads,
                'new'            => $byStatus['new'] ?? 0,
                'contacted'      => $byStatus['contacted'] ?? 0,
                'interested'     => $byStatus['interested'] ?? 0,
                'follow_up'      => $byStatus['follow_up'] ?? 0,
                'documents_pending' => $byStatus['documents_pending'] ?? 0,
                'converted'      => $byStatus['converted'] ?? 0,
                'not_interested' => $byStatus['not_interested'] ?? 0,
                'lost'           => $byStatus['lost'] ?? 0,
                'by_status'      => $byStatus,
                'new_this_month' => $newThisMonth,
            ],
            'follow_ups' => [
                'today'     => (int) ($followUps['today'] ?? 0),
                'overdue'   => (int) ($followUps['overdue'] ?? 0),
                'this_week' => (int) ($followUps['this_week'] ?? 0),
            ],
            'calls' => [
                'today'           => (int) ($callsToday['total'] ?? 0),
                'today_connected' => (int) ($callsToday['connected'] ?? 0),
                'today_talk_time' => Helpers::humanDuration((int) ($callsToday['seconds'] ?? 0)),
                'month'           => (int) ($callsMonth['total'] ?? 0),
                'month_talk_time' => Helpers::humanDuration((int) ($callsMonth['seconds'] ?? 0)),
            ],
            'projects' => [
                'total'      => array_sum($projectsByStatus),
                'active'     => array_sum(array_diff_key(
                    $projectsByStatus,
                    array_flip(['cancelled', 'completed', 'deployed'])
                )),
                'deployed'   => $projectsByStatus['deployed'] ?? 0,
                'completed'  => $projectsByStatus['completed'] ?? 0,
                'by_status'  => $projectsByStatus,
                'converted_this_month' => $convertedThisMonth,
            ],
            'conversion_rate' => $totalLeads > 0
                ? round((($byStatus['converted'] ?? 0) / $totalLeads) * 100, 1)
                : 0.0,
        ];

        // ---- role-specific extras ----
        if (Auth::isAdmin()) {
            $data['team'] = [
                'partners'    => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'partner' AND is_active = 1"),
                'telecallers' => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'telecaller' AND is_active = 1"),
            ];
            $data['documents'] = [
                'pending_verification' => (int) Database::scalar(
                    "SELECT COUNT(*) FROM documents WHERE verification_status = 'pending'"
                ),
            ];
            $data['top_partners'] = array_map(fn($r) => [
                'partner_id'   => (int) $r['partner_id'],
                'partner_name' => $r['partner_name'],
                'leads'        => (int) $r['leads'],
                'converted'    => (int) $r['converted'],
            ], Database::all(
                "SELECT l.partner_id, u.name AS partner_name,
                        COUNT(*) AS leads,
                        SUM(CASE WHEN l.status = 'converted' THEN 1 ELSE 0 END) AS converted
                   FROM leads l JOIN users u ON u.id = l.partner_id
                  GROUP BY l.partner_id, u.name
                  ORDER BY converted DESC, leads DESC
                  LIMIT 10"
            ));
        }

        if (Auth::isPartner()) {
            $data['team'] = [
                'telecallers' => (int) Database::scalar(
                    "SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = 'telecaller' AND is_active = 1",
                    [Auth::id()]
                ),
            ];
            $data['documents'] = [
                'pending_verification' => (int) Database::scalar(
                    "SELECT COUNT(*) FROM documents d
                      WHERE d.verification_status = 'pending' AND d.uploaded_by IN ("
                        . implode(',', array_fill(0, count(Auth::visibleUserIds()), '?')) . ')',
                    Auth::visibleUserIds()
                ),
            ];
        }

        $data['unread_notifications'] = (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            [Auth::id()]
        );

        Response::ok($data);
    }

    /** GET /lookups - lists the app caches on first launch */
    public function lookups(Request $request): void
    {
        Auth::authenticate($request);

        Response::ok([
            'lead_sources' => array_map(
                fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
                Database::all('SELECT id, name FROM lead_sources WHERE is_active = 1 ORDER BY name')
            ),
            'job_categories' => array_map(
                fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
                Database::all('SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY name')
            ),
            'document_types' => array_map(fn($r) => [
                'id'          => (int) $r['id'],
                'name'        => $r['name'],
                'code'        => $r['code'],
                'applies_to'  => $r['applies_to'],
                'is_required' => (int) $r['is_required'] === 1,
                'has_expiry'  => (int) $r['has_expiry'] === 1,
            ], Database::all('SELECT * FROM document_types WHERE is_active = 1 ORDER BY sort_order, name')),
            'lead_statuses'    => \App\Models\Lead::STATUSES,
            'project_statuses' => ProjectController::STATUSES,
            'call_dispositions' => ['connected', 'no_answer', 'busy', 'switched_off',
                                    'wrong_number', 'call_back_later', 'not_reachable', 'other'],
            'priorities'       => ['low', 'medium', 'high'],
            'settings'         => [
                'partner_can_convert'       => (string) Helpers::setting('partner_can_convert', '1') === '1',
                'max_upload_mb'             => (int) Helpers::setting('max_upload_mb', 15),
                'followup_reminder_minutes' => (int) Helpers::setting('followup_reminder_minutes', 15),
                'agency_name'               => Helpers::setting('agency_name', 'Recruitment Agency'),
            ],
        ]);
    }

    /** GET /notifications */
    public function notifications(Request $request): void
    {
        Auth::authenticate($request);

        $page    = $request->page();
        $perPage = $request->perPage(30);

        $where  = 'user_id = ?';
        $params = [Auth::id()];

        if ($request->query('unread') !== null) {
            $where .= ' AND is_read = 0';
        }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE {$where}", $params);

        $rows = Database::all(
            "SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET "
                . (($page - 1) * $perPage),
            $params
        );

        Response::paginated(array_map(fn($n) => [
            'id'         => (int) $n['id'],
            'title'      => $n['title'],
            'body'       => $n['body'],
            'type'       => $n['type'],
            'ref_type'   => $n['ref_type'],
            'ref_id'     => $n['ref_id'] !== null ? (int) $n['ref_id'] : null,
            'is_read'    => (int) $n['is_read'] === 1,
            'created_at' => $n['created_at'],
        ], $rows), $total, $page, $perPage);
    }

    /** POST /notifications/read  Body: { ids?: [] }  (omit ids = mark all) */
    public function markNotificationsRead(Request $request): void
    {
        Auth::authenticate($request);

        $ids = $request->input('ids');

        if (is_array($ids) && $ids !== []) {
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            Database::query(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ({$placeholders})",
                array_merge([Auth::id()], $ids)
            );
        } else {
            Database::query('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [Auth::id()]);
        }

        Response::ok(null, 'Notifications marked as read');
    }
}
