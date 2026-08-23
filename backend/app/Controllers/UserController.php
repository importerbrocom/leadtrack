<?php

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Admin creates partners. Partners create their own telecallers.
 */
final class UserController
{
    /** GET /users?role=&search=&is_active= */
    public function index(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN, Auth::PARTNER);

        $where  = ['1 = 1'];
        $params = [];

        if (Auth::isPartner()) {
            // A partner only ever sees its own telecallers.
            $where[]  = 'u.parent_id = ? AND u.role = ?';
            $params[] = Auth::id();
            $params[] = Auth::TELECALLER;
        }

        if ($role = $request->query('role')) {
            $where[]  = 'u.role = ?';
            $params[] = $role;
        }

        if ($request->query('parent_id') !== null && Auth::isAdmin()) {
            $where[]  = 'u.parent_id = ?';
            $params[] = (int) $request->query('parent_id');
        }

        if ($request->query('is_active') !== null) {
            $where[]  = 'u.is_active = ?';
            $params[] = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if ($search = $request->query('search')) {
            $where[]  = '(u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.agency_name LIKE ?)';
            $like     = '%' . $search . '%';
            $params   = array_merge($params, [$like, $like, $like, $like]);
        }

        $whereSql = implode(' AND ', $where);
        $page     = $request->page();
        $perPage  = $request->perPage(50);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $params);

        $rows = Database::all(
            "SELECT u.*, p.name AS parent_name,
                    (SELECT COUNT(*) FROM users c WHERE c.parent_id = u.id) AS telecaller_count,
                    (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.id) AS assigned_leads
               FROM users u
               LEFT JOIN users p ON p.id = u.parent_id
              WHERE {$whereSql}
              ORDER BY u.role, u.name
              LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        );

        $items = array_map(function (array $r): array {
            $u = AuthController::publicUser($r);
            $u['parent_name']      = $r['parent_name'];
            $u['telecaller_count'] = (int) $r['telecaller_count'];
            $u['assigned_leads']   = (int) $r['assigned_leads'];
            $u['last_login_at']    = $r['last_login_at'];

            return $u;
        }, $rows);

        Response::paginated($items, $total, $page, $perPage);
    }

    /** GET /users/{id} */
    public function show(Request $request): void
    {
        Auth::authenticate($request);

        $id = $request->intParam('id');

        if ($id !== Auth::id()) {
            Auth::require(Auth::ADMIN, Auth::PARTNER);
        }

        $user = Database::first('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null) {
            throw ApiException::notFound('User not found');
        }

        if ($id !== Auth::id()) {
            Auth::assertCanManageUser($user);
        }

        Response::ok(AuthController::publicUser($user));
    }

    /**
     * POST /users
     * Admin  -> may create partner or telecaller (telecaller needs parent_id)
     * Partner-> may create telecaller under itself only
     */
    public function store(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN, Auth::PARTNER);

        $data = Validator::make($request->body(), [
            'role'            => 'required|in:partner,telecaller',
            'name'            => 'required|string|max:120',
            'phone'           => 'required|phone',
            'email'           => 'nullable|email|max:160',
            'password'        => 'required|string|min:6|max:100',
            'agency_name'     => 'nullable|string|max:160',
            'city'            => 'nullable|string|max:100',
            'state'           => 'nullable|string|max:100',
            'parent_id'       => 'nullable|int',
            'max_telecallers' => 'nullable|int|min:0|max:1000',
        ]);

        $role = $data['role'];

        if (Auth::isPartner()) {
            if ($role !== Auth::TELECALLER) {
                throw ApiException::forbidden('Partners can only create telecaller accounts');
            }
            $parentId = Auth::id();

            // Respect the seat limit set by head office.
            $limit = (int) Database::scalar('SELECT max_telecallers FROM users WHERE id = ?', [Auth::id()]);
            $current = (int) Database::scalar(
                'SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = ? AND is_active = 1',
                [Auth::id(), Auth::TELECALLER]
            );
            if ($limit > 0 && $current >= $limit) {
                throw ApiException::forbidden(
                    "You have reached your limit of {$limit} telecallers. Ask head office to increase it."
                );
            }
        } else {
            // admin
            $parentId = $role === Auth::TELECALLER ? ($data['parent_id'] ?? null) : null;

            if ($role === Auth::TELECALLER && $parentId === null) {
                throw ApiException::validation(['parent_id' => 'Choose which partner this telecaller belongs to']);
            }

            if ($parentId !== null) {
                $parent = Database::first('SELECT id, role FROM users WHERE id = ?', [$parentId]);
                if ($parent === null || $parent['role'] !== Auth::PARTNER) {
                    throw ApiException::validation(['parent_id' => 'Selected parent is not a partner']);
                }
            }
        }

        $phoneExists = Database::scalar('SELECT id FROM users WHERE phone = ?', [$data['phone']]);
        if ($phoneExists !== null) {
            throw ApiException::conflict('An account with this phone number already exists');
        }

        if (!empty($data['email'])) {
            $emailExists = Database::scalar('SELECT id FROM users WHERE email = ?', [$data['email']]);
            if ($emailExists !== null) {
                throw ApiException::conflict('An account with this email already exists');
            }
        }

        $id = Database::insert('users', [
            'parent_id'       => $parentId,
            'role'            => $role,
            'name'            => $data['name'],
            'phone'           => $data['phone'],
            'email'           => $data['email'] ?? null,
            'password_hash'   => password_hash($data['password'], PASSWORD_BCRYPT),
            'agency_name'     => $data['agency_name'] ?? null,
            'city'            => $data['city'] ?? null,
            'state'           => $data['state'] ?? null,
            'max_telecallers' => $role === Auth::PARTNER ? ($data['max_telecallers'] ?? 10) : 0,
            'is_active'       => 1,
            'created_by'      => Auth::id(),
        ]);

        Helpers::log(Auth::id(), 'user_created', 'user', $id, ['role' => $role]);

        $user = Database::first('SELECT * FROM users WHERE id = ?', [$id]);

        Response::created(AuthController::publicUser($user), ucfirst($role) . ' account created');
    }

    /** PATCH /users/{id} */
    public function update(Request $request): void
    {
        Auth::authenticate($request);

        $id     = $request->intParam('id');
        $target = Database::first('SELECT * FROM users WHERE id = ?', [$id]);

        if ($target === null) {
            throw ApiException::notFound('User not found');
        }

        $isSelf = $id === Auth::id();
        if (!$isSelf) {
            Auth::require(Auth::ADMIN, Auth::PARTNER);
            Auth::assertCanManageUser($target);
        }

        $data = Validator::make($request->body(), [
            'name'            => 'nullable|string|max:120',
            'phone'           => 'nullable|phone',
            'email'           => 'nullable|email|max:160',
            'agency_name'     => 'nullable|string|max:160',
            'city'            => 'nullable|string|max:100',
            'state'           => 'nullable|string|max:100',
            'password'        => 'nullable|string|min:6|max:100',
            'is_active'       => 'nullable|boolean',
            'max_telecallers' => 'nullable|int|min:0|max:1000',
        ]);

        $update = [];
        foreach (['name', 'phone', 'email', 'agency_name', 'city', 'state'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (!empty($data['phone'])) {
            $clash = Database::scalar('SELECT id FROM users WHERE phone = ? AND id <> ?', [$data['phone'], $id]);
            if ($clash !== null) {
                throw ApiException::conflict('Another account already uses this phone number');
            }
        }

        if (!empty($data['email'])) {
            $clash = Database::scalar('SELECT id FROM users WHERE email = ? AND id <> ?', [$data['email'], $id]);
            if ($clash !== null) {
                throw ApiException::conflict('Another account already uses this email');
            }
        }

        // Only a manager may reset someone else's password or deactivate them.
        if (!empty($data['password'])) {
            if ($isSelf) {
                throw ApiException::badRequest('Use /auth/change-password to change your own password');
            }
            $update['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        if (array_key_exists('is_active', $data) && !$isSelf) {
            $update['is_active'] = $data['is_active'];
            if ((int) $data['is_active'] === 0) {
                Database::update('auth_tokens', ['revoked_at' => Helpers::now()], 'user_id = ? AND revoked_at IS NULL', [$id]);
            }
        }

        if (array_key_exists('max_telecallers', $data) && Auth::isAdmin()) {
            $update['max_telecallers'] = $data['max_telecallers'];
        }

        if ($update === []) {
            throw ApiException::badRequest('Nothing to update');
        }

        Database::update('users', $update, 'id = ?', [$id]);
        Helpers::log(Auth::id(), 'user_updated', 'user', $id, ['fields' => array_keys($update)]);

        Response::ok(AuthController::publicUser(Database::first('SELECT * FROM users WHERE id = ?', [$id])));
    }

    /**
     * GET /users/assignable
     * Who can this caller hand a lead to? Used by the app's "Assign" picker.
     */
    public function assignable(Request $request): void
    {
        Auth::authenticate($request);

        if (Auth::isTelecaller()) {
            Response::ok([]);
        }

        if (Auth::isPartner()) {
            $rows = Database::all(
                'SELECT id, name, role, phone FROM users
                  WHERE is_active = 1 AND (id = ? OR (parent_id = ? AND role = ?))
                  ORDER BY role DESC, name',
                [Auth::id(), Auth::id(), Auth::TELECALLER]
            );
        } else {
            $rows = Database::all(
                'SELECT id, name, role, phone, parent_id FROM users
                  WHERE is_active = 1 AND role IN (?, ?)
                  ORDER BY role, name',
                [Auth::PARTNER, Auth::TELECALLER]
            );
        }

        Response::ok(array_map(fn($r) => [
            'id'        => (int) $r['id'],
            'name'      => $r['name'],
            'role'      => $r['role'],
            'phone'     => $r['phone'],
            'parent_id' => isset($r['parent_id']) && $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
        ], $rows));
    }
}
