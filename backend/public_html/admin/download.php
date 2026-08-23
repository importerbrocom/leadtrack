<?php

/**
 * Authenticated file downloads for the admin panel.
 *
 * Uploads live outside public_html, so every file is streamed through here
 * after a permission check. Nobody can guess a URL and pull a passport scan.
 */

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Response;
use App\Core\Uploader;

$currentUser = Session::requireLogin();

$type = (string) ($_GET['type'] ?? '');
$id   = (int) ($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['document', 'template'], true)) {
    http_response_code(400);
    exit('Bad download request');
}

try {
    if ($type === 'template') {
        $row = Database::first('SELECT * FROM form_templates WHERE id = ?', [$id]);

        if ($row === null) {
            http_response_code(404);
            exit('Form not found');
        }

        if ((int) $row['is_active'] !== 1 && !Auth::isAdmin()) {
            http_response_code(404);
            exit('This form is no longer available');
        }

        $path = Uploader::resolve('templates', $row['stored_name']);

        if ($path === null) {
            http_response_code(404);
            exit('The file is missing on the server');
        }

        Database::query('UPDATE form_templates SET download_count = download_count + 1 WHERE id = ?', [$id]);
        Helpers::log(Auth::id(), 'template_downloaded', 'form_template', $id);

        Response::download($path, $row['file_name'], $row['mime_type']);
    }

    // ---- candidate document
    $row = Database::first(
        'SELECT d.*, p.partner_id AS project_partner_id, p.assigned_to AS project_assigned_to,
                l.partner_id AS lead_partner_id, l.assigned_to AS lead_assigned_to
           FROM documents d
           LEFT JOIN projects p ON p.id = d.project_id
           LEFT JOIN leads l ON l.id = d.lead_id
          WHERE d.id = ?',
        [$id]
    );

    if ($row === null) {
        http_response_code(404);
        exit('Document not found');
    }

    // Admin sees everything; the uploader sees their own; otherwise fall back to
    // the same lead/project scope rules the API uses.
    if (!Auth::isAdmin() && (int) $row['uploaded_by'] !== Auth::id()) {
        Auth::assertCanAccessLead([
            'partner_id'  => $row['project_partner_id'] ?? $row['lead_partner_id'],
            'assigned_to' => $row['project_assigned_to'] ?? $row['lead_assigned_to'],
        ]);
    }

    $path = Uploader::resolve('documents', $row['stored_name']);

    if ($path === null) {
        http_response_code(404);
        exit('The file is missing on the server');
    }

    Helpers::log(Auth::id(), 'document_downloaded', 'document', $id);

    Response::download($path, $row['file_name'], $row['mime_type']);
} catch (Throwable $e) {
    http_response_code(403);
    exit('You do not have permission to download this file');
}
