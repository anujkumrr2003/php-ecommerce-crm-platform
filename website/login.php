<?php
/* login.php */
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
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = strtolower(post('email', 190));
    $pass  = (string)($_POST['password'] ?? '');
    if (!wc_throttle('login:' . wc_ip(), 20, 900) || !wc_throttle('loginmail:' . $email, 8, 900)) {
        $err = 'Too many attempts. Please wait 15 minutes and try again.';
    } else {
        $u = $email !== '' ? wc_one('SELECT * FROM web_users WHERE email = ?', 's', [$email]) : null;
        if (!$u || !password_verify($pass, (string)$u['password_hash'])) {
            usleep(250000);
            $err = 'Incorrect email or password.';
        } elseif ($u['status'] !== 'active') {
            $err = 'Your account has been blocked. Please contact us.';
        } elseif (!$u['email_verified_at']) {
            $_SESSION['verify_uid'] = (int)$u['id'];
            $e = otp_send($u, 'register');
            flash('info', 'Please verify your email first. ' . ($e === '' ? 'We have sent you a new code.' : $e));
            redirect('verify');
        } else {
            login_user($u);
            $next = safe_next((string)($_POST['next'] ?? ($_SESSION['after_login'] ?? '')));
            unset($_SESSION['after_login']);
            redirect($next !== '' ? $next : 'account');
        }
    }
}
page_start(['title' => 'Login', 'robots' => 'noindex']);
?>
<div class="container auth-box"><div class="box"><h1>Welcome back</h1><p class="muted" style="text-align:center">Log in to your account</p>
  <?= $err !== '' ? '<div class="alert alert-danger">' . e($err) . '</div>' : flash_html() ?>
  <form method="post" action="<?= e(url('login')) ?>"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e((string)($_GET['next'] ?? '')) ?>">
    <div class="field"><label for="e">Email address</label><input id="e" type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></div>
    <div class="field"><label for="p">Password</label><input id="p" type="password" name="password" autocomplete="current-password" required></div>
    <p style="text-align:right;margin:-6px 0 16px"><a class="small" href="<?= e(url('forgot-password')) ?>">Forgot password?</a></p>
    <button class="btn btn-primary btn-block" type="submit">Log in</button></form>
  <p style="text-align:center;margin:18px 0 0">New here? <a href="<?= e(url('register')) ?>"><b>Create an account</b></a></p></div></div>
<?php page_end();
