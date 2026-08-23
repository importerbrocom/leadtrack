<?php

namespace App\Core;

/**
 * Bearer-token authentication + the role/visibility model.
 *
 * Hierarchy:
 *   admin       - head office, sees and does everything
 *   partner     - franchise / sub-agent, owns leads and creates its own telecallers
 *   telecaller  - only the leads assigned to them
 */
final class Auth
{
    public const ADMIN      = 'admin';
    public const PARTNER    = 'partner';
    public const TELECALLER = 'telecaller';

    private static ?array $user = null;
    private static ?int $tokenId = null;

    /**
     * Resolve the caller from the Authorization header. Throws 401 when absent
     * or expired.
     */
    public static function authenticate(Request $request): array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $token = $request->bearerToken();
        if ($token === null) {
            throw ApiException::unauthorized('Missing bearer token');
        }

        $row = Database::first(
            'SELECT t.id AS token_id, t.expires_at, u.*
               FROM auth_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.revoked_at IS NULL
              LIMIT 1',
            [Helpers::hashToken($token)]
        );

        if ($row === null) {
            throw ApiException::unauthorized('Invalid or revoked token');
        }

        if (strtotime($row['expires_at']) < time()) {
            throw ApiException::unauthorized('Session expired, please log in again');
        }

        if ((int) $row['is_active'] !== 1) {
            throw ApiException::forbidden('Your account has been deactivated');
        }

        self::$tokenId = (int) $row['token_id'];

        // Sliding expiry: every authenticated call extends the session.
        Database::update(
            'auth_tokens',
            [
                'last_used_at' => Helpers::now(),
                'expires_at'   => date('Y-m-d H:i:s', time() + 86400 * (int) Config::get('auth.token_ttl_days', 90)),
            ],
            'id = ?',
            [self::$tokenId]
        );

        unset($row['token_id'], $row['expires_at'], $row['password_hash']);
        self::$user = $row;

        return self::$user;
    }

    public static function user(): array
    {
        if (self::$user === null) {
            throw ApiException::unauthorized();
        }

        return self::$user;
    }

    public static function id(): int
    {
        return (int) self::user()['id'];
    }

    public static function role(): string
    {
        return self::user()['role'];
    }

    public static function tokenId(): ?int
    {
        return self::$tokenId;
    }

    /** For tests / the admin web panel, which authenticates via PHP session. */
    public static function setUser(array $user): void
    {
        unset($user['password_hash']);
        self::$user = $user;
    }

    public static function isAdmin(): bool
    {
        return self::role() === self::ADMIN;
    }

    public static function isPartner(): bool
    {
        return self::role() === self::PARTNER;
    }

    public static function isTelecaller(): bool
    {
        return self::role() === self::TELECALLER;
    }

    /** Throw 403 unless the caller has one of the given roles. */
    public static function require(string ...$roles): void
    {
        if (!in_array(self::role(), $roles, true)) {
            throw ApiException::forbidden('This action requires ' . implode(' or ', $roles) . ' access');
        }
    }

    /**
     * The partner "tenant" the caller belongs to.
     *   partner    -> own id
     *   telecaller -> parent partner id (null if created directly by admin)
     *   admin      -> null (no tenant restriction)
     */
    public static function partnerScopeId(): ?int
    {
        $u = self::user();

        return match ($u['role']) {
            self::PARTNER    => (int) $u['id'],
            self::TELECALLER => $u['parent_id'] === null ? null : (int) $u['parent_id'],
            default          => null,
        };
    }

    /**
     * Every user id the caller is allowed to see data for.
     *   admin      -> [] (meaning "no filter")
     *   partner    -> self + its telecallers
     *   telecaller -> [self]
     */
    public static function visibleUserIds(): array
    {
        $u = self::user();

        if ($u['role'] === self::ADMIN) {
            return [];
        }

        if ($u['role'] === self::TELECALLER) {
            return [(int) $u['id']];
        }

        $ids = [(int) $u['id']];
        foreach (Database::all('SELECT id FROM users WHERE parent_id = ?', [(int) $u['id']]) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * SQL fragment restricting a leads/projects query to what the caller may see.
     *
     * @param string $alias table alias holding partner_id / assigned_to
     * @return array{0:string,1:array} [whereSql, params]
     */
    public static function scopeClause(string $alias = 'l'): array
    {
        $u = self::user();

        if ($u['role'] === self::ADMIN) {
            return ['1 = 1', []];
        }

        if ($u['role'] === self::TELECALLER) {
            return ["{$alias}.assigned_to = ?", [(int) $u['id']]];
        }

        // Partner: everything owned by the franchise, plus anything assigned to
        // one of its telecallers (covers leads handed down from head office).
        $ids          = self::visibleUserIds();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return [
            "({$alias}.partner_id = ? OR {$alias}.assigned_to IN ({$placeholders}))",
            array_merge([(int) $u['id']], $ids),
        ];
    }

    /** Throw 403 unless the caller may act on this lead row. */
    public static function assertCanAccessLead(array $lead): void
    {
        if (self::isAdmin()) {
            return;
        }

        $uid = self::id();

        if (self::isTelecaller()) {
            if ((int) ($lead['assigned_to'] ?? 0) !== $uid) {
                throw ApiException::forbidden('This lead is not assigned to you');
            }

            return;
        }

        // partner
        if ((int) ($lead['partner_id'] ?? 0) === $uid) {
            return;
        }

        if (in_array((int) ($lead['assigned_to'] ?? 0), self::visibleUserIds(), true)) {
            return;
        }

        throw ApiException::forbidden('This lead belongs to another partner');
    }

    /** Same check for a project row. */
    public static function assertCanAccessProject(array $project): void
    {
        self::assertCanAccessLead($project);
    }

    /** Throw 403 unless the caller may manage this user row. */
    public static function assertCanManageUser(array $target): void
    {
        if (self::isAdmin()) {
            return;
        }

        if (self::isPartner()
            && $target['role'] === self::TELECALLER
            && (int) ($target['parent_id'] ?? 0) === self::id()) {
            return;
        }

        throw ApiException::forbidden('You can only manage your own telecallers');
    }

    /**
     * Issue a new bearer token for a user.
     *
     * @return array{token:string,expires_at:string}
     */
    public static function issueToken(int $userId, array $device = []): array
    {
        $token = Helpers::randomToken();
        $expires = date('Y-m-d H:i:s', time() + 86400 * (int) Config::get('auth.token_ttl_days', 90));

        // One active token per physical device.
        if (!empty($device['device_id'])) {
            Database::update(
                'auth_tokens',
                ['revoked_at' => Helpers::now()],
                'user_id = ? AND device_id = ? AND revoked_at IS NULL',
                [$userId, $device['device_id']]
            );
        }

        Database::insert('auth_tokens', [
            'user_id'      => $userId,
            'token_hash'   => Helpers::hashToken($token),
            'device_id'    => $device['device_id']   ?? null,
            'device_name'  => $device['device_name'] ?? null,
            'fcm_token'    => $device['fcm_token']   ?? null,
            'app_version'  => $device['app_version'] ?? null,
            'last_used_at' => Helpers::now(),
            'expires_at'   => $expires,
        ]);

        return ['token' => $token, 'expires_at' => $expires];
    }

    public static function revokeCurrentToken(): void
    {
        if (self::$tokenId !== null) {
            Database::update('auth_tokens', ['revoked_at' => Helpers::now()], 'id = ?', [self::$tokenId]);
        }
    }

    /** Reset in-memory state (used by the test harness). */
    public static function reset(): void
    {
        self::$user    = null;
        self::$tokenId = null;
    }
}
