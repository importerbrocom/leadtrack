<?php

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uploader;
use App\Core\Validator;

/**
 * Blank forms: head office uploads them, partners and telecallers download,
 * print, get them filled by the candidate, then upload the scan back as a
 * document (see DocumentController).
 */
final class FormTemplateController
{
    private const SUBDIR = 'templates';

    /** GET /form-templates?category=&search= */
    public function index(Request $request): void
    {
        Auth::authenticate($request);

        $where  = [];
        $params = [];

        // Only admins see deactivated templates.
        if (!Auth::isAdmin()) {
            $where[] = 't.is_active = 1';
        } elseif ($request->query('is_active') !== null) {
            $where[]  = 't.is_active = ?';
            $params[] = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if ($category = $request->query('category')) {
            $where[]  = 't.category = ?';
            $params[] = $category;
        }

        if ($search = $request->query('search')) {
            $where[] = '(t.title LIKE ? OR t.description LIKE ? OR t.file_name LIKE ?)';
            $like    = '%' . $search . '%';
            $params  = array_merge($params, [$like, $like, $like]);
        }

        $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);

        $rows = Database::all(
            "SELECT t.*, u.name AS uploaded_by_name
               FROM form_templates t
               LEFT JOIN users u ON u.id = t.uploaded_by
              WHERE {$whereSql}
              ORDER BY t.category IS NULL, t.category, t.title",
            $params
        );

        Response::ok(array_map(fn($t) => [
            'id'               => (int) $t['id'],
            'title'            => $t['title'],
            'description'      => $t['description'],
            'category'         => $t['category'],
            'file_name'        => $t['file_name'],
            'mime_type'        => $t['mime_type'],
            'file_size'        => (int) $t['file_size'],
            'file_size_display' => self::humanSize((int) $t['file_size']),
            'version'          => $t['version'],
            'download_count'   => (int) $t['download_count'],
            'is_active'        => (int) $t['is_active'] === 1,
            'uploaded_by_name' => $t['uploaded_by_name'],
            'download_url'     => rtrim((string) \App\Core\Config::get('app.base_url'), '/') . '/form-templates/' . (int) $t['id'] . '/download',
            'created_at'       => $t['created_at'],
        ], $rows));
    }

    /**
     * POST /form-templates  (admin only, multipart/form-data)
     * Fields: file, title, description?, category?, version?
     */
    public function store(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN);

        $file = $request->file('file');
        if ($file === null) {
            throw ApiException::badRequest('Attach the form file in the "file" field');
        }

        $data = Validator::make($request->body(), [
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string|max:500',
            'category'    => 'nullable|string|max:80',
            'version'     => 'nullable|string|max:20',
        ]);

        $stored = Uploader::store($file, self::SUBDIR);

        $id = Database::insert('form_templates', [
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'category'    => $data['category'] ?? null,
            'file_name'   => $stored['file_name'],
            'stored_name' => $stored['stored_name'],
            'mime_type'   => $stored['mime_type'],
            'file_size'   => $stored['file_size'],
            'version'     => $data['version'] ?? '1.0',
            'uploaded_by' => Auth::id(),
        ]);

        Helpers::log(Auth::id(), 'template_uploaded', 'form_template', $id, ['title' => $data['title']]);

        // Let the field team know a new form is available.
        foreach (Database::all("SELECT id FROM users WHERE role IN ('partner','telecaller') AND is_active = 1") as $u) {
            Helpers::notify((int) $u['id'], 'New form available', $data['title'], 'template_added', 'form_template', $id);
        }

        Response::created(['id' => $id], 'Form template uploaded');
    }

    /** GET /form-templates/{id}/download - any logged-in user */
    public function download(Request $request): void
    {
        Auth::authenticate($request);

        $id  = $request->intParam('id');
        $row = Database::first('SELECT * FROM form_templates WHERE id = ?', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Form template not found');
        }

        if ((int) $row['is_active'] !== 1 && !Auth::isAdmin()) {
            throw ApiException::notFound('This form is no longer available');
        }

        $path = Uploader::resolve(self::SUBDIR, $row['stored_name']);
        if ($path === null) {
            throw ApiException::notFound('The file is missing on the server');
        }

        Database::query('UPDATE form_templates SET download_count = download_count + 1 WHERE id = ?', [$id]);
        Helpers::log(Auth::id(), 'template_downloaded', 'form_template', $id);

        Response::download($path, $row['file_name'], $row['mime_type']);
    }

    /** PATCH /form-templates/{id} (admin) */
    public function update(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN);

        $id  = $request->intParam('id');
        $row = Database::first('SELECT id FROM form_templates WHERE id = ?', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Form template not found');
        }

        $data = Validator::make($request->body(), [
            'title'       => 'nullable|string|max:200',
            'description' => 'nullable|string|max:500',
            'category'    => 'nullable|string|max:80',
            'version'     => 'nullable|string|max:20',
            'is_active'   => 'nullable|boolean',
        ]);

        if ($data === []) {
            throw ApiException::badRequest('Nothing to update');
        }

        Database::update('form_templates', $data, 'id = ?', [$id]);

        Response::ok(null, 'Form template updated');
    }

    /** DELETE /form-templates/{id} (admin) */
    public function destroy(Request $request): void
    {
        Auth::authenticate($request);
        Auth::require(Auth::ADMIN);

        $id  = $request->intParam('id');
        $row = Database::first('SELECT * FROM form_templates WHERE id = ?', [$id]);

        if ($row === null) {
            throw ApiException::notFound('Form template not found');
        }

        Uploader::deleteStored(self::SUBDIR, $row['stored_name']);
        Database::delete('form_templates', 'id = ?', [$id]);

        Helpers::log(Auth::id(), 'template_deleted', 'form_template', $id);

        Response::ok(null, 'Form template deleted');
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }
}
