<?php

/**
 * Shared includes + view helpers for every admin panel page.
 */

declare(strict_types=1);

// Works whether public_html/ is part of the project or a separate cPanel
// document root. See bootstrap-locator.php.
require (require dirname(__DIR__) . '/bootstrap-locator.php');

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

Session::start();

/** HTML-escape. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Session::csrfToken()) . '">';
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Query-string value with a default. */
function q(string $key, $default = null)
{
    $value = $_GET[$key] ?? $default;

    return is_string($value) && trim($value) === '' ? $default : $value;
}

/** Rebuild the current query string with some keys replaced. */
function query_with(array $replace): string
{
    $params = array_merge($_GET, $replace);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');

    return http_build_query($params);
}

/** Human label for an enum-ish value. */
function label($value): string
{
    return ucwords(str_replace('_', ' ', (string) $value));
}

/** Bootstrap badge colour per lead status. */
function status_badge(string $status): string
{
    $map = [
        'new'               => 'secondary',
        'contacted'         => 'info',
        'interested'        => 'primary',
        'follow_up'         => 'warning',
        'documents_pending' => 'warning',
        'converted'         => 'success',
        'not_interested'    => 'dark',
        'lost'              => 'danger',
        'invalid'           => 'danger',
        'dnd'               => 'danger',
        // project statuses
        'initiated'           => 'secondary',
        'documents_verified'  => 'info',
        'interview_scheduled' => 'info',
        'selected'            => 'primary',
        'medical_pending'     => 'warning',
        'medical_cleared'     => 'info',
        'pcc_pending'         => 'warning',
        'visa_processing'     => 'warning',
        'visa_approved'       => 'primary',
        'ticket_booked'       => 'primary',
        'deployed'            => 'success',
        'completed'           => 'success',
        'on_hold'             => 'dark',
        'cancelled'           => 'danger',
        // document verification
        'pending'  => 'warning',
        'verified' => 'success',
        'rejected' => 'danger',
    ];

    $colour = $map[$status] ?? 'secondary';

    return '<span class="badge bg-' . $colour . '">' . e(label($status)) . '</span>';
}

function priority_badge(string $priority): string
{
    $map = ['high' => 'danger', 'medium' => 'secondary', 'low' => 'light text-dark'];

    return '<span class="badge bg-' . ($map[$priority] ?? 'secondary') . '">' . e(ucfirst($priority)) . '</span>';
}

/** "23 Aug 2026, 6:30 pm" */
function dt($value, bool $withTime = true): string
{
    if (empty($value)) {
        return '<span class="text-muted">&mdash;</span>';
    }

    $ts = strtotime((string) $value);

    if ($ts === false) {
        return e($value);
    }

    return e(date($withTime ? 'd M Y, g:i a' : 'd M Y', $ts));
}

/** Relative time, e.g. "in 2 hours" / "3 days ago". */
function ago($value): string
{
    if (empty($value)) {
        return '';
    }

    $ts    = strtotime((string) $value);
    $diff  = $ts - time();
    $past  = $diff < 0;
    $diff  = abs($diff);

    if ($diff < 60)      { $text = 'just now'; return e($text); }
    if ($diff < 3600)    { $text = intdiv($diff, 60) . 'm'; }
    elseif ($diff < 86400)  { $text = intdiv($diff, 3600) . 'h'; }
    elseif ($diff < 2592000) { $text = intdiv($diff, 86400) . 'd'; }
    else { $text = intdiv($diff, 2592000) . 'mo'; }

    return e($past ? $text . ' ago' : 'in ' . $text);
}

function money($value): string
{
    return $value === null ? '&mdash;' : '₹' . number_format((float) $value, 2);
}

function file_size_display(int $bytes): string
{
    return \App\Controllers\FormTemplateController::humanSize($bytes);
}

/** Simple offset pagination helper. */
function paginate(int $total, int $perPage = 25): array
{
    $page  = max(1, (int) q('page', 1));
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);

    return [
        'page'    => $page,
        'pages'   => $pages,
        'offset'  => ($page - 1) * $perPage,
        'perPage' => $perPage,
        'total'   => $total,
    ];
}

function render_pagination(array $p): void
{
    if ($p['pages'] <= 1) {
        return;
    }

    $start = max(1, $p['page'] - 2);
    $end   = min($p['pages'], $p['page'] + 2);

    echo '<nav><ul class="pagination pagination-sm mb-0">';

    if ($p['page'] > 1) {
        echo '<li class="page-item"><a class="page-link" href="?' . e(query_with(['page' => $p['page'] - 1])) . '">&laquo;</a></li>';
    }

    if ($start > 1) {
        echo '<li class="page-item"><a class="page-link" href="?' . e(query_with(['page' => 1])) . '">1</a></li>';
        if ($start > 2) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $p['page'] ? ' active' : '';
        echo '<li class="page-item' . $active . '"><a class="page-link" href="?' . e(query_with(['page' => $i])) . '">' . $i . '</a></li>';
    }

    if ($end < $p['pages']) {
        if ($end < $p['pages'] - 1) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        echo '<li class="page-item"><a class="page-link" href="?' . e(query_with(['page' => $p['pages']])) . '">' . $p['pages'] . '</a></li>';
    }

    if ($p['page'] < $p['pages']) {
        echo '<li class="page-item"><a class="page-link" href="?' . e(query_with(['page' => $p['page'] + 1])) . '">&raquo;</a></li>';
    }

    echo '</ul></nav>';
}

/** Options for a <select> built from id/name rows. */
function select_options(array $rows, $selected, string $idKey = 'id', string $nameKey = 'name'): string
{
    $html = '';

    foreach ($rows as $row) {
        $value = (string) $row[$idKey];
        $isSel = (string) $selected === $value ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $isSel . '>' . e($row[$nameKey]) . '</option>';
    }

    return $html;
}

/** Lookup lists cached per request. */
function lookup(string $what): array
{
    static $cache = [];

    if (isset($cache[$what])) {
        return $cache[$what];
    }

    $cache[$what] = match ($what) {
        'sources'    => Database::all('SELECT id, name FROM lead_sources WHERE is_active = 1 ORDER BY name'),
        'categories' => Database::all('SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY name'),
        'doc_types'  => Database::all('SELECT id, name, code, is_required FROM document_types WHERE is_active = 1 ORDER BY sort_order'),
        'partners'   => Database::all("SELECT id, name, agency_name FROM users WHERE role = 'partner' AND is_active = 1 ORDER BY name"),
        default      => [],
    };

    return $cache[$what];
}

/** Users this admin/partner may assign leads to. */
function assignable_users(): array
{
    if (Auth::isPartner()) {
        return Database::all(
            "SELECT id, name, role FROM users
              WHERE is_active = 1 AND (id = ? OR (parent_id = ? AND role = 'telecaller'))
              ORDER BY role DESC, name",
            [Auth::id(), Auth::id()]
        );
    }

    return Database::all(
        "SELECT u.id, u.name, u.role, p.name AS parent_name
           FROM users u LEFT JOIN users p ON p.id = u.parent_id
          WHERE u.is_active = 1 AND u.role IN ('partner','telecaller')
          ORDER BY u.role, u.name"
    );
}
