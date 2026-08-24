<?php

namespace App\Core;

final class Helpers
{
    /**
     * Reduce any phone number to the last 10 digits.
     *
     * This is the key to automatic call matching: the device reports numbers in
     * wildly inconsistent formats (+919876543210, 09876543210, 919876543210,
     * 98765 43210) but the last 10 digits are stable for Indian numbers, so we
     * store that alongside the display number and match on it.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /** Current timestamp in the app timezone, MySQL DATETIME format. */
    public static function now(): string
    {
        return (new \DateTime('now', self::tz()))->format('Y-m-d H:i:s');
    }

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(Config::get('app.timezone', 'UTC'));
    }

    /**
     * Accept ISO-8601 or 'Y-m-d H:i:s' and return MySQL DATETIME, or null.
     */
    public static function toDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Numeric input = epoch millis (what the Android app sends).
            if (is_numeric($value)) {
                $seconds = (int) $value > 20000000000 ? (int) ((int) $value / 1000) : (int) $value;
                $dt = (new \DateTime('@' . $seconds))->setTimezone(self::tz());
            } else {
                $dt = new \DateTime((string) $value, self::tz());
            }

            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function toDate($value): ?string
    {
        $dt = self::toDateTime($value);

        return $dt === null ? null : substr($dt, 0, 10);
    }

    /** Cryptographically strong opaque token for the mobile app. */
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Safe randomised on-disk filename that keeps the original extension. */
    public static function storedFileName(string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';

        return date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    }

    /** Strip anything that could break out of a directory. */
    public static function sanitizeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w\s\.\-\(\)]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($name), 0, 200) ?: 'file';
    }

    public static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($m < 60) {
            return $s === 0 ? "{$m}m" : "{$m}m {$s}s";
        }
        $h = intdiv($m, 60);
        $m %= 60;

        return "{$h}h {$m}m";
    }

    /** Read a settings row (cached per request). */
    public static function setting(string $key, $default = null)
    {
        static $cache = null;

        if ($cache === null) {
            $cache = [];
            foreach (Database::all('SELECT `key_name`, `value` FROM `settings`') as $row) {
                $cache[$row['key_name']] = $row['value'];
            }
        }

        return $cache[$key] ?? $default;
    }

    /** Next sequential project code, e.g. PRJ-2026-00042. */
    public static function nextProjectCode(): string
    {
        $prefix = self::setting('project_code_prefix', 'PRJ');
        $year   = date('Y');
        $like   = $prefix . '-' . $year . '-%';

        $last = Database::scalar(
            'SELECT `project_code` FROM `projects` WHERE `project_code` LIKE ? ORDER BY `id` DESC LIMIT 1',
            [$like]
        );

        $next = 1;
        if ($last !== null && preg_match('/(\d+)$/', (string) $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return sprintf('%s-%s-%05d', $prefix, $year, $next);
    }

    public static function log(?int $userId, string $action, ?string $entityType = null, $entityId = null, array $meta = []): void
    {
        try {
            Database::insert('activity_log', [
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId === null ? null : (int) $entityId,
                'meta'        => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Never let audit logging break a real request.
        }
    }

    public static function notify(int $userId, string $title, ?string $body, string $type = 'general', ?string $refType = null, $refId = null): void
    {
        try {
            Database::insert('notifications', [
                'user_id'  => $userId,
                'title'    => $title,
                'body'     => $body,
                'type'     => $type,
                'ref_type' => $refType,
                'ref_id'   => $refId === null ? null : (int) $refId,
            ]);
        } catch (\Throwable $e) {
        }
    }
}
