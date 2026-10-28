<?php
/* forgot-password.php + the code / new password step (same page) */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/account.php';
require_once __DIR__ . '/inc/layout.php';

if (user()) {
    redirect('account');
}
$err = '';
$step = !empty($_SESSION['reset_email']) ? 2 : 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $do = post('do');
    if ($do === 'send') {
        $em = strtolower(post('email', 190));
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid email address.';
        } elseif (!wc_throttle('forgot:' . wc_ip(), 8, 3600)) {
            $err = 'Too many requests. Please try later.';
        } else {
            $u = wc_one("SELECT * FROM web_users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL", 's', [$em]);
            if ($u) {
                otp_send($u, 'reset');
            }
            $_SESSION['reset_email'] = $em;                                // same answer whether the account exists or not
            flash('info', 'If this email has an account, a 6-digit code has been sent to it.');
            redirect('forgot-password');
        }
    } elseif ($do === 'reset') {
        $em = (string)($_SESSION['reset_email'] ?? '');
        $u = wc_one('SELECT * FROM web_users WHERE email = ?', 's', [$em]);
        $p1 = (string)($_POST['password'] ?? '');
        if (($pe = password_error($p1)) !== '') {
            $err = $pe;
        } elseif ($p1 !== (string)($_POST['password2'] ?? '')) {
            $err = 'Passwords do not match.';
        } elseif (!$u) {
            $err = 'That code is not correct.';
        } else {
            [$ok, $msg] = otp_check((int)$u['id'], 'reset', preg_replace('/\D/', '', post('code', 10)));
            if ($ok) {
                wc_run('UPDATE web_users SET password_hash = ? WHERE id = ?', 'si', [password_hash($p1, PASSWORD_DEFAULT), (int)$u['id']]);
                wc_log_activity((int)$u['id'], 'password_reset', '');
                unset($_SESSION['reset_email']);
                flash('success', 'Your password has been changed. Please log in.');
                redirect('login');
            }
            $err = $msg;
        }
        $step = 2;
    } elseif ($do === 'restart') {
        unset($_SESSION['reset_email']);
        redirect('forgot-password');
    }
}
page_start(['title' => 'Forgot password', 'robots' => 'noindex']);
?>
<div class="container auth-box"><div class="box"><h1>Reset password</h1>
  <?= $err !== '' ? '<div class="alert alert-danger">' . e($err) . '</div>' : flash_html() ?>
  <?php if ($step === 1): ?>
    <p class="muted" style="text-align:center">Enter your email and we will send you a 6-digit code.</p>
    <form method="post" action="<?= e(url('forgot-password')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="send"><div class="field"><label for="e">Email address</label><input id="e" type="email" name="email" required autofocus></div><button class="btn btn-primary btn-block">Send code</button></form>
  <?php else: ?>
    <form method="post" action="<?= e(url('forgot-password')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="reset">
      <div class="field"><label for="c">6-digit code</label><input id="c" class="otp-input" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required autofocus></div>
      <div class="field"><label for="p">New password</label><input id="p" type="password" name="password" autocomplete="new-password" required></div>
      <div class="field"><label for="p2">Confirm new password</label><input id="p2" type="password" name="password2" autocomplete="new-password" required></div>
      <button class="btn btn-primary btn-block">Change password</button></form>
    <form method="post" action="<?= e(url('forgot-password')) ?>" style="text-align:center;margin-top:14px"><?= csrf_field() ?><input type="hidden" name="do" value="restart"><button class="link" type="submit">Use a different email / send a new code</button></form>
  <?php endif; ?>
  <p style="text-align:center;margin:16px 0 0"><a class="small" href="<?= e(url('login')) ?>">Back to login</a></p></div></div>
<?php page_end();
