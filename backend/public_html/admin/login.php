<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;
use App\Core\Helpers;

// Already signed in? Go straight through.
if (Session::user() !== null) {
    redirect('index.php');
}

$error = null;
$login = '';

if (is_post()) {
    Session::verifyCsrf();

    $login    = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $error = 'Enter your phone/email and password';
    } else {
        [$ok, $message] = Session::login($login, $password);

        if ($ok) {
            $next = (string) ($_GET['next'] ?? 'index.php');

            // Only ever redirect within the panel.
            if (!preg_match('#^[A-Za-z0-9_\-]+\.php(\?.*)?$#', $next)) {
                $next = 'index.php';
            }

            redirect($next);
        }

        $error = $message;
    }
}

$agencyName = Helpers::setting('agency_name', 'Recruitment Agency');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e($agencyName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body>

<div class="login-wrap px-3">
  <div class="text-center mb-4">
    <i class="bi bi-building fs-1 text-primary"></i>
    <h1 class="h4 mt-2"><?= e($agencyName) ?></h1>
    <p class="text-muted small mb-0">Lead Management &mdash; Head Office Panel</p>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-4">
      <?php if ($error !== null): ?>
        <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Phone or email</label>
          <input type="text" name="login" class="form-control" value="<?= e($login) ?>" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-app w-100">Sign in</button>
      </form>
    </div>
  </div>

  <p class="text-center text-muted small mt-3">
    Telecallers sign in through the mobile app.
  </p>
</div>

</body>
</html>
