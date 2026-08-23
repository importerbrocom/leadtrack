<?php

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class AuthController
{
    /**
     * POST /auth/login
     * Body: { login: "<phone or email>", password, device_id?, device_name?, fcm_token?, app_version? }
     */
    public function login(Request $request): void
    {
        $data = Validator::make($request->body(), [
            'login'       => 'required|string|max:160',
            'password'    => 'required|string|min:4|max:100',
            'device_id'   => 'nullable|string|max:120',
            'device_name' => 'nullable|string|max:160',
            'fcm_token'   => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:30',
        ]);

        $login  = $data['login'];
        $digits = Helpers::normalizePhone($login);

        $user = Database::first(
            'SELECT * FROM users
              WHERE email = ?
                 OR phone = ?
                 OR (? IS NOT NULL AND RIGHT(phone, 10) = ?)
              LIMIT 1',
            [$login, $login, $digits, $digits]
        );

        if ($user === null || !password_verify($data['password'], $user['password_hash'])) {
            // Deliberately vague - do not reveal whether the account exists.
            throw ApiException::unauthorized('Incorrect phone number or password');
        }

        if ((int) $user['is_active'] !== 1) {
            throw ApiException::forbidden('Your account has been deactivated. Contact your administrator.');
        }

        $issued = Auth::issueToken((int) $user['id'], [
            'device_id'   => $data['device_id']   ?? null,
            'device_name' => $data['device_name'] ?? null,
            'fcm_token'   => $data['fcm_token']   ?? null,
            'app_version' => $data['app_version'] ?? null,
        ]);

        Database::update('users', ['last_login_at' => Helpers::now()], 'id = ?', [(int) $user['id']]);
        Helpers::log((int) $user['id'], 'login', 'user', (int) $user['id']);

        Response::ok([
            'token'      => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'user'       => self::publicUser($user),
        ], 'Login successful');
    }

    /** GET /auth/me */
    public function me(Request $request): void
    {
        $user = Auth::authenticate($request);

        $counts = [];
        if (Auth::isPartner()) {
            $counts['telecallers'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = ?',
                [Auth::id(), Auth::TELECALLER]
            );
        }

        Response::ok([
            'user'   => self::publicUser($user),
            'counts' => $counts,
        ]);
    }

    /** POST /auth/logout */
    public function logout(Request $request): void
    {
        Auth::authenticate($request);
        Auth::revokeCurrentToken();
        Helpers::log(Auth::id(), 'logout', 'user', Auth::id());

        Response::ok(null, 'Logged out');
    }

    /**
     * POST /auth/device - refresh the FCM token for push notifications.
     */
    public function updateDevice(Request $request): void
    {
        Auth::authenticate($request);

        $data = Validator::make($request->body(), [
            'fcm_token'   => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:30',
            'device_name' => 'nullable|string|max:160',
        ]);

        if ($data === [] || Auth::tokenId() === null) {
            Response::ok(null, 'Nothing to update');
        }

        Database::update('auth_tokens', $data, 'id = ?', [Auth::tokenId()]);

        Response::ok(null, 'Device updated');
    }

    /** POST /auth/change-password */
    public function changePassword(Request $request): void
    {
        Auth::authenticate($request);

        $data = Validator::make($request->body(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|max:100',
        ]);

        $hash = Database::scalar('SELECT password_hash FROM users WHERE id = ?', [Auth::id()]);

        if ($hash === null || !password_verify($data['current_password'], (string) $hash)) {
            throw ApiException::badRequest('Your current password is incorrect');
        }

        Database::update(
            'users',
            ['password_hash' => password_hash($data['new_password'], PASSWORD_BCRYPT)],
            'id = ?',
            [Auth::id()]
        );

        // Force every other device to log in again.
        Database::query(
            'UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL',
            [Helpers::now(), Auth::id(), Auth::tokenId()]
        );

        Helpers::log(Auth::id(), 'password_changed', 'user', Auth::id());

        Response::ok(null, 'Password changed');
    }

    /** Shape a users row for API output. */
    public static function publicUser(array $u): array
    {
        return [
            'id'          => (int) $u['id'],
            'parent_id'   => $u['parent_id'] === null ? null : (int) $u['parent_id'],
            'role'        => $u['role'],
            'name'        => $u['name'],
            'phone'       => $u['phone'],
            'email'       => $u['email'],
            'agency_name' => $u['agency_name'] ?? null,
            'city'        => $u['city'] ?? null,
            'state'       => $u['state'] ?? null,
            'is_active'   => (int) ($u['is_active'] ?? 1) === 1,
            'created_at'  => $u['created_at'] ?? null,
        ];
    }
}
