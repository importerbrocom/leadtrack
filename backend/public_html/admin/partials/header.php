<?php

/**
 * Shared page chrome. Expects $pageTitle and $currentUser to be set.
 *
 * @var string $pageTitle
 * @var array  $currentUser
 */

use App\Admin\Session;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Helpers;

$agencyName = Helpers::setting('agency_name', 'Recruitment Agency');
$isAdmin    = $currentUser['role'] === Auth::ADMIN;

$unread = (int) Database::scalar(
    'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
    [(int) $currentUser['id']]
);

$pendingDocs = $isAdmin
    ? (int) Database::scalar("SELECT COUNT(*) FROM documents WHERE verification_status = 'pending'")
    : 0;

$nav = [
    ['file' => 'index.php',     'label' => 'Dashboard',  'icon' => 'speedometer2'],
    ['file' => 'leads.php',     'label' => 'Leads',      'icon' => 'person-lines-fill'],
    ['file' => 'followups.php', 'label' => 'Callbacks',  'icon' => 'telephone-forward'],
    ['file' => 'projects.php',  'label' => 'Projects',   'icon' => 'briefcase'],
    ['file' => 'documents.php', 'label' => 'Documents',  'icon' => 'file-earmark-text', 'badge' => $pendingDocs],
    ['file' => 'templates.php', 'label' => 'Forms',      'icon' => 'file-earmark-arrow-down'],
    ['file' => 'calls.php',     'label' => 'Call Report', 'icon' => 'graph-up'],
    ['file' => 'users.php',     'label' => $isAdmin ? 'Partners & Team' : 'My Telecallers', 'icon' => 'people'],
];

if ($isAdmin) {
    $nav[] = ['file' => 'mobileapp.php', 'label' => 'Mobile App', 'icon' => 'phone'];
}

$current = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?> &middot; <?= e($agencyName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark sticky-top flex-md-nowrap p-0 shadow app-navbar">
  <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3 fs-6 text-truncate" href="index.php">
    <i class="bi bi-building me-1"></i><?= e($agencyName) ?>
  </a>
  <button class="navbar-toggler d-md-none collapsed me-2" type="button"
          data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="ms-auto d-flex align-items-center pe-3 text-white">
    <a href="notifications.php" class="text-white me-3 position-relative" title="Notifications">
      <i class="bi bi-bell fs-5"></i>
      <?php if ($unread > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread ?></span>
      <?php endif; ?>
    </a>
    <div class="dropdown">
      <a href="#" class="text-white text-decoration-none dropdown-toggle small" data-bs-toggle="dropdown">
        <i class="bi bi-person-circle me-1"></i><?= e($currentUser['name']) ?>
        <span class="badge bg-light text-dark ms-1"><?= e(ucfirst($currentUser['role'])) ?></span>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-key me-2"></i>Change password</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container-fluid">
<div class="row">
  <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block sidebar collapse">
    <div class="position-sticky pt-3">
      <ul class="nav flex-column">
        <?php foreach ($nav as $item): ?>
          <li class="nav-item">
            <a class="nav-link<?= $current === $item['file'] ? ' active' : '' ?>" href="<?= e($item['file']) ?>">
              <i class="bi bi-<?= e($item['icon']) ?> me-2"></i><?= e($item['label']) ?>
              <?php if (!empty($item['badge'])): ?>
                <span class="badge bg-danger rounded-pill float-end"><?= (int) $item['badge'] ?></span>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
        <?php if ($isAdmin): ?>
          <li class="nav-item mt-3"><h6 class="sidebar-heading px-3 text-muted text-uppercase small">Head office</h6></li>
          <li class="nav-item">
            <a class="nav-link<?= $current === 'settings.php' ? ' active' : '' ?>" href="settings.php">
              <i class="bi bi-gear me-2"></i>Settings
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </nav>

  <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-3">
    <?php foreach (Session::takeFlashes() as $flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>
