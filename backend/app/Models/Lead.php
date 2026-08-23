<?php

namespace App\Models;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

final class Lead
{
    public const STATUSES = [
        'new', 'contacted', 'interested', 'not_interested', 'follow_up',
        'documents_pending', 'converted', 'lost', 'invalid', 'dnd',
    ];

    /** Statuses that mean "stop working this lead". */
    public const CLOSED = ['converted', 'lost', 'invalid', 'dnd', 'not_interested'];

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM leads WHERE id = ?', [$id]);
    }

    public static function findOrFail(int $id): array
    {
        $lead = self::find($id);
        if ($lead === null) {
            throw ApiException::notFound('Lead not found');
        }

        return $lead;
    }

    /**
     * Find the lead a phone number belongs to, restricted to what this user may
     * see. Used by the Android app right after a call ends.
     */
    public static function findByPhoneForUser(string $phone, int $userId, ?int $partnerId, string $role): ?array
    {
        $normalized = Helpers::normalizePhone($phone);
        if ($normalized === null) {
            return null;
        }

        $sql = 'SELECT * FROM leads
                 WHERE (phone_normalized = ? OR alt_phone_normalized = ?)';
        $params = [$normalized, $normalized];

        if ($role === Auth::TELECALLER) {
            $sql .= ' AND assigned_to = ?';
            $params[] = $userId;
        } elseif ($role === Auth::PARTNER) {
            $sql .= ' AND (partner_id = ? OR assigned_to IN (SELECT id FROM users WHERE parent_id = ? OR id = ?))';
            $params[] = $userId;
            $params[] = $userId;
            $params[] = $userId;
        }

        // Prefer the most recently touched match.
        $sql .= ' ORDER BY updated_at DESC LIMIT 1';

        return Database::first($sql, $params);
    }

    /**
     * Change a lead's status and write the history row.
     * Returns true when the status actually changed.
     */
    public static function changeStatus(int $leadId, string $toStatus, ?int $userId, ?string $remarks = null): bool
    {
        if (!in_array($toStatus, self::STATUSES, true)) {
            throw ApiException::validation(['status' => 'Unknown status: ' . $toStatus]);
        }

        $current = Database::scalar('SELECT status FROM leads WHERE id = ?', [$leadId]);
        if ($current === null) {
            throw ApiException::notFound('Lead not found');
        }

        if ($current === 'converted' && $toStatus !== 'converted') {
            throw ApiException::conflict('This lead is already converted to a project and cannot be moved back');
        }

        if ((string) $current === $toStatus) {
            if ($remarks !== null && $remarks !== '') {
                Database::insert('lead_status_history', [
                    'lead_id'     => $leadId,
                    'user_id'     => $userId,
                    'from_status' => $current,
                    'to_status'   => $toStatus,
                    'remarks'     => $remarks,
                ]);
            }

            return false;
        }

        Database::update('leads', ['status' => $toStatus], 'id = ?', [$leadId]);

        Database::insert('lead_status_history', [
            'lead_id'     => $leadId,
            'user_id'     => $userId,
            'from_status' => $current,
            'to_status'   => $toStatus,
            'remarks'     => $remarks,
        ]);

        return true;
    }

    /**
     * Recalculate call_count / total_talk_time_sec / last_contacted_at from the
     * call_logs table. Cheap enough per lead and keeps counters honest even if
     * the same call syncs twice.
     */
    public static function refreshCallStats(int $leadId): void
    {
        Database::query(
            'UPDATE leads l
                SET l.call_count = (SELECT COUNT(*) FROM call_logs c WHERE c.lead_id = l.id),
                    l.total_talk_time_sec = (SELECT COALESCE(SUM(c.duration_sec), 0) FROM call_logs c WHERE c.lead_id = l.id),
                    l.last_contacted_at = (SELECT MAX(c.started_at) FROM call_logs c WHERE c.lead_id = l.id AND c.duration_sec > 0)
              WHERE l.id = ?',
            [$leadId]
        );
    }

    /** Shape a joined leads row for API output. */
    public static function toApi(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'name'                => $r['name'],
            'phone'               => $r['phone'],
            'alt_phone'           => $r['alt_phone'] ?? null,
            'whatsapp'            => $r['whatsapp'] ?? null,
            'email'               => $r['email'] ?? null,
            'city'                => $r['city'] ?? null,
            'district'            => $r['district'] ?? null,
            'state'               => $r['state'] ?? null,
            'source_id'           => isset($r['source_id']) && $r['source_id'] !== null ? (int) $r['source_id'] : null,
            'source_name'         => $r['source_name'] ?? null,
            'job_category_id'     => isset($r['job_category_id']) && $r['job_category_id'] !== null ? (int) $r['job_category_id'] : null,
            'job_category_name'   => $r['job_category_name'] ?? null,
            'preferred_country'   => $r['preferred_country'] ?? null,
            'qualification'       => $r['qualification'] ?? null,
            'experience_years'    => isset($r['experience_years']) && $r['experience_years'] !== null ? (float) $r['experience_years'] : null,
            'expected_salary'     => isset($r['expected_salary']) && $r['expected_salary'] !== null ? (float) $r['expected_salary'] : null,
            'passport_status'     => $r['passport_status'] ?? null,
            'status'              => $r['status'],
            'priority'            => $r['priority'],
            'partner_id'          => $r['partner_id'] !== null ? (int) $r['partner_id'] : null,
            'partner_name'        => $r['partner_name'] ?? null,
            'assigned_to'         => $r['assigned_to'] !== null ? (int) $r['assigned_to'] : null,
            'assigned_to_name'    => $r['assigned_to_name'] ?? null,
            'next_follow_up_at'   => $r['next_follow_up_at'],
            'last_contacted_at'   => $r['last_contacted_at'],
            'call_count'          => (int) $r['call_count'],
            'total_talk_time_sec' => (int) $r['total_talk_time_sec'],
            'talk_time_display'   => Helpers::humanDuration((int) $r['total_talk_time_sec']),
            'notes'               => $r['notes'] ?? null,
            'project_id'          => isset($r['project_id']) && $r['project_id'] !== null ? (int) $r['project_id'] : null,
            'converted_at'        => $r['converted_at'] ?? null,
            'created_at'          => $r['created_at'],
            'updated_at'          => $r['updated_at'],
        ];
    }

    /** The standard SELECT with lookup joins. */
    public static function selectSql(): string
    {
        return 'SELECT l.*,
                       s.name AS source_name,
                       jc.name AS job_category_name,
                       p.name AS partner_name,
                       a.name AS assigned_to_name,
                       pr.id  AS project_id
                  FROM leads l
                  LEFT JOIN lead_sources s   ON s.id = l.source_id
                  LEFT JOIN job_categories jc ON jc.id = l.job_category_id
                  LEFT JOIN users p          ON p.id = l.partner_id
                  LEFT JOIN users a          ON a.id = l.assigned_to
                  LEFT JOIN projects pr      ON pr.lead_id = l.id';
    }
}
