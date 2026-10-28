<?php
/* login.php - one login for Super Admin, Admin and Manager (role comes from the database) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_session_start();

/* only same-site relative addresses are accepted after login */
function login_next(): string
{
    $n = (string)($_POST['next'] ?? $_GET['next'] ?? '');
    if ($n === '' || preg_match('#^(https?:)?//#i', $n) || strpos($n, "\\") !== false || strpos($n, 'login.php') !== false) {
        return '';
    }
    return ltrim($n, '/');
}

if (crm_user()) {
    header('Location: ' . (login_next() ?: crm_home_url()));
    exit;
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if (!crm_csrf_ok()) {
        $error = 'Session expired. Please try again.';
    } else {
        [$ok, $msg] = crm_login($email, (string)($_POST['pass'] ?? ''), !empty($_POST['remember']));
        if ($ok) {
            header('Location: ' . (login_next() ?: crm_home_url()));
            exit;
        }
        $error = $msg;
    }
}
$brand = crm_brand();
$info  = isset($_GET['reset']) ? 'Password changed. Please log in with your new password.' : (isset($_GET['out']) ? 'You have been signed out.' : '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Login | <?= crm_e($brand['name']) ?> CRM</title>
<link rel="preconnect" href="https://fonts.googleapis.com" /><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
<link href="<?= crm_e(crm_asset('assets/css/style.css')) ?>" rel="stylesheet" />
<link href="<?= crm_e(crm_asset('assets/css/crm.css')) ?>" rel="stylesheet" />
</head>
<body class="auth-body">
<main class="auth-card">
  <div class="brand">
    <?php if ($brand['logo'] !== ''): ?><span class="brand-mark brand-logo"><img src="<?= crm_e($brand['logo']) ?>" alt=""></span>
    <?php else: ?><span class="brand-mark"><?= crm_e(mb_strtoupper(mb_substr($brand['name'], 0, 1))) ?></span><?php endif; ?>
    <span class="brand-text"><b><?= crm_e($brand['name']) ?></b><small>CRM</small></span>
  </div>
  <h1>Welcome back</h1>
  <p class="sub-t">Log in to manage your business</p>
  <?php if ($error !== ''): ?><div class="alert alert-danger py-2 small"><i class="bi bi-x-circle me-1"></i><?= crm_e($error) ?></div><?php endif; ?>
  <?php if ($info !== '' && $error === ''): ?><div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i><?= crm_e($info) ?></div><?php endif; ?>
  <form method="post" action="login.php" autocomplete="on">
    <?= crm_csrf_field() ?>
    <input type="hidden" name="next" value="<?= crm_e(login_next()) ?>">
    <div class="mb-3">
      <label class="form-label" for="email"><i class="bi bi-envelope"></i>Email address</label>
      <input type="email" id="email" name="email" class="form-control" value="<?= crm_e($email) ?>" placeholder="you@example.com" autocomplete="username" required autofocus />
    </div>
    <div class="mb-3">
      <label class="form-label" for="pass"><i class="bi bi-lock"></i>Password</label>
      <div class="input-group">
        <input type="password" id="pass" name="pass" class="form-control" placeholder="Enter your password" autocomplete="current-password" required />
        <button type="button" class="btn btn-outline-secondary" data-eye aria-label="Show or hide password"><i class="bi bi-eye"></i></button>
      </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="form-check"><input class="form-check-input" type="checkbox" id="remember" name="remember" value="1" /><label class="form-check-label" for="remember">Keep me signed in</label></div>
      <a href="forgot-password.php" class="fw-semibold small">Forgot password?</a>
    </div>
    <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Log in</button>
  </form>
  <p class="text-muted small text-center mt-3 mb-0">Super Admin, Admin and Manager all log in here.<br>What you see after login depends on your role.</p>
</main>
<script src="<?= crm_e(crm_asset('assets/js/auth.js')) ?>"></script>
</body>
</html>
