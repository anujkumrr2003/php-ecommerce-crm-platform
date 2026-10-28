<?php
/* change-password.php - any logged-in user (also used for the forced first-login change) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
$u      = crm_user();
$forced = (int)$u['must_change_password'] === 1;
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $cur = (string)($_POST['cur'] ?? '');
    $p1  = (string)($_POST['pass'] ?? '');
    $p2  = (string)($_POST['pass2'] ?? '');
    if (!password_verify($cur, (string)$u['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (($pe = crm_password_error($p1)) !== '') {
        $error = $pe;
    } elseif ($p1 === $cur) {
        $error = 'New password must be different from the current one.';
    } elseif ($p1 !== $p2) {
        $error = 'New password and confirm password do not match.';
    } else {
        crm_run('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', 'si', [password_hash($p1, PASSWORD_DEFAULT), (int)$u['id']]);
        crm_log('auth', 'password_change', 'Changed own password');
        crm_flash('success', 'Password changed successfully.');
        header('Location: ' . ($forced ? crm_home_url() : 'change-password.php'));
        exit;
    }
}

crm_page_start('Change password');
crm_page_head('Change password', 'Use a strong password that you do not use anywhere else.');
echo crm_flash_html();
if ($forced) {
    echo '<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>For your security you must set a new password before you continue.</div>';
}
if ($error !== '') {
    echo '<div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i>' . crm_e($error) . '</div>';
}
?>
<div class="panel" style="max-width:520px;height:auto">
  <form method="post" action="change-password.php" autocomplete="off">
    <?= crm_csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="cur">Current password</label>
      <div class="input-group"><input type="password" id="cur" name="cur" class="form-control" autocomplete="current-password" required />
      <button type="button" class="btn btn-outline-secondary" data-eye aria-label="Show or hide"><i class="bi bi-eye"></i></button></div></div>
    <div class="mb-3"><label class="form-label" for="pass">New password</label>
      <div class="input-group"><input type="password" id="pass" name="pass" class="form-control" autocomplete="new-password" required />
      <button type="button" class="btn btn-outline-secondary" data-eye aria-label="Show or hide"><i class="bi bi-eye"></i></button></div>
      <div class="form-text">At least 8 characters with upper and lower case, a number and a symbol.</div></div>
    <div class="mb-3"><label class="form-label" for="pass2">Confirm new password</label>
      <input type="password" id="pass2" name="pass2" class="form-control" autocomplete="new-password" required /></div>
    <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Update password</button>
  </form>
</div>
<?php crm_page_end(); ?>
