<?php
/* forgot-password.php - email OTP reset (code is e-mailed, never shown on screen) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_session_start();
if (crm_user()) {
    header('Location: index.php');
    exit;
}

$step  = !empty($_SESSION['fp_email']) ? 2 : 1;
$error = '';
$info  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!crm_csrf_ok()) {
        $error = 'Session expired. Please try again.';
    } elseif (($_POST['do'] ?? '') === 'send') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $u = crm_one("SELECT id, name FROM users WHERE email = ? AND status = 'active' LIMIT 1", 's', [$email]);
            if ($u) {
                $otp = (string)random_int(100000, 999999);
                crm_run('UPDATE users SET reset_hash = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE), reset_tries = 0 WHERE id = ?', 'si', [password_hash($otp, PASSWORD_DEFAULT), (int)$u['id']]);
                $host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
                $ok = @mail($email, 'Your password reset code', "Hello {$u['name']},\n\nYour password reset code is: $otp\nIt is valid for 15 minutes. If you did not ask for it, ignore this email.\n",
                    "From: no-reply@$host\r\nContent-Type: text/plain; charset=UTF-8");
                if (!$ok) {
                    error_log('forgot-password: mail() failed for ' . $email);
                }
            }
            // same answer whether the email exists or not
            $_SESSION['fp_email'] = $email;
            $step = 2;
            $info = 'If this email belongs to an active account, a 6-digit code has been sent. It is valid for 15 minutes.';
        }
    } elseif (($_POST['do'] ?? '') === 'reset') {
        $step  = 2;
        $email = (string)($_SESSION['fp_email'] ?? '');
        $otp   = trim((string)($_POST['otp'] ?? ''));
        $p1    = (string)($_POST['pass'] ?? '');
        $p2    = (string)($_POST['pass2'] ?? '');
        $u     = $email !== '' ? crm_one("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1", 's', [$email]) : null;
        if (!$u || !$u['reset_hash'] || !$u['reset_expires'] || strtotime((string)$u['reset_expires']) < time()) {
            $error = 'The code has expired. Please request a new one.';
        } elseif ((int)$u['reset_tries'] >= 5) {
            $error = 'Too many wrong attempts. Please request a new code.';
        } elseif (!password_verify($otp, (string)$u['reset_hash'])) {
            crm_run('UPDATE users SET reset_tries = reset_tries + 1 WHERE id = ?', 'i', [(int)$u['id']]);
            $error = 'Wrong code. Please check and try again.';
        } elseif (($pe = crm_password_error($p1)) !== '') {
            $error = $pe;
        } elseif ($p1 !== $p2) {
            $error = 'Passwords do not match.';
        } else {
            crm_run('UPDATE users SET password_hash = ?, must_change_password = 0, reset_hash = NULL, reset_expires = NULL, reset_tries = 0, failed_attempts = 0, locked_until = NULL WHERE id = ?', 'si', [password_hash($p1, PASSWORD_DEFAULT), (int)$u['id']]);
            crm_log('auth', 'password_reset', 'Password reset by e-mail code', (int)$u['id'], (string)$u['name']);
            unset($_SESSION['fp_email']);
            header('Location: login.php?reset=1');
            exit;
        }
    } elseif (($_POST['do'] ?? '') === 'back') {
        unset($_SESSION['fp_email']);
        $step = 1;
    }
}
$brand = crm_brand();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Forgot password | <?= crm_e($brand['name']) ?> CRM</title>
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
  <h1>Forgot password</h1>
  <p class="sub-t"><?= $step === 1 ? 'Enter your email and we will send a 6-digit code.' : 'Enter the code from your email and choose a new password.' ?></p>
  <?php if ($error !== ''): ?><div class="alert alert-danger py-2 small"><i class="bi bi-x-circle me-1"></i><?= crm_e($error) ?></div><?php endif; ?>
  <?php if ($info !== ''): ?><div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i><?= crm_e($info) ?></div><?php endif; ?>

  <?php if ($step === 1): ?>
  <form method="post" action="forgot-password.php">
    <?= crm_csrf_field() ?><input type="hidden" name="do" value="send">
    <div class="mb-3"><label class="form-label" for="email"><i class="bi bi-envelope"></i>Email address</label>
      <input type="email" id="email" name="email" class="form-control" required autofocus /></div>
    <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-send me-1"></i>Send code</button>
  </form>
  <?php else: ?>
  <form method="post" action="forgot-password.php">
    <?= crm_csrf_field() ?><input type="hidden" name="do" value="reset">
    <p class="small text-muted">Code sent to <b><?= crm_e($_SESSION['fp_email'] ?? '') ?></b></p>
    <div class="mb-3"><label class="form-label" for="otp"><i class="bi bi-123"></i>6-digit code</label>
      <input id="otp" name="otp" class="form-control" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required autofocus /></div>
    <div class="mb-3"><label class="form-label" for="pass"><i class="bi bi-lock"></i>New password</label>
      <div class="input-group"><input type="password" id="pass" name="pass" class="form-control" autocomplete="new-password" required />
      <button type="button" class="btn btn-outline-secondary" data-eye aria-label="Show or hide password"><i class="bi bi-eye"></i></button></div>
      <div class="form-text">8+ characters with upper and lower case, a number and a symbol.</div></div>
    <div class="mb-3"><label class="form-label" for="pass2"><i class="bi bi-lock-fill"></i>Confirm password</label>
      <input type="password" id="pass2" name="pass2" class="form-control" autocomplete="new-password" required /></div>
    <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-check2 me-1"></i>Change password</button>
  </form>
  <form method="post" action="forgot-password.php" class="text-center mt-2"><?= crm_csrf_field() ?><input type="hidden" name="do" value="back">
    <button class="btn btn-link btn-sm" type="submit">Use a different email / send a new code</button></form>
  <?php endif; ?>
  <p class="text-center small mt-3 mb-0"><a href="login.php"><i class="bi bi-arrow-left me-1"></i>Back to login</a></p>
</main>
<script src="<?= crm_e(crm_asset('assets/js/auth.js')) ?>"></script>
</body>
</html>
