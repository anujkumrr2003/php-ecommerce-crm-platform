<?php
/* profile.php - my details, my role and what I am allowed to do */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
$u = crm_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $name   = trim((string)($_POST['name'] ?? ''));
    $mobile = preg_replace('/\D+/', '', (string)($_POST['mobile'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        crm_flash('danger', 'Please enter your name.');
    } elseif ($mobile !== '' && !preg_match('/^[0-9]{10}$/', $mobile)) {
        crm_flash('danger', 'Mobile number must be 10 digits.');
    } else {
        crm_run('UPDATE users SET name = ?, mobile = ? WHERE id = ?', 'ssi', [$name, $mobile, (int)$u['id']]);
        crm_log('auth', 'profile_update', 'Updated own profile');
        crm_flash('success', 'Profile updated.');
    }
    header('Location: profile.php');
    exit;
}

$rows = crm_all('SELECT p.module, p.label FROM permissions p ORDER BY p.sort_order');
$mods = [];
foreach ($rows as $r) {
    $key = crm_is_super() ? true : false;
    $mods[$r['module']][] = $r['label'];
}
$mine = crm_is_super() ? null : crm_all('SELECT p.module, p.label FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.sort_order', 'i', [(int)$u['role_id']]);

crm_page_start('My profile');
crm_page_head('My profile', 'Your account details and access.');
echo crm_flash_html();
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-title"><h5>Details</h5><span class="badge-s b-gold"><?= crm_e($u['role_name']) ?></span></div>
      <form method="post" action="profile.php">
        <?= crm_csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" value="<?= crm_e($u['name']) ?>" maxlength="120" required></div>
        <div class="mb-3"><label class="form-label" for="mobile">Mobile</label><input class="form-control" id="mobile" name="mobile" value="<?= crm_e($u['mobile']) ?>" inputmode="numeric" maxlength="10"></div>
        <div class="mb-3"><label class="form-label">Email (login)</label><input class="form-control" value="<?= crm_e($u['email']) ?>" disabled></div>
        <div class="row g-2 mb-3 small text-muted">
          <div class="col-6">Designation<div class="text-dark"><?= crm_e($u['designation'] ?: '-') ?></div></div>
          <div class="col-6">Joined<div class="text-dark"><?= $u['joining_date'] ? crm_e(date('j M Y', strtotime((string)$u['joining_date']))) : '-' ?></div></div>
          <div class="col-6">Last login<div class="text-dark"><?= $u['last_login_at'] ? crm_e(date('j M Y, g:i a', strtotime((string)$u['last_login_at']))) : '-' ?></div></div>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Save</button>
        <a class="btn btn-light border" href="change-password.php"><i class="bi bi-key me-1"></i>Change password</a>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-title"><h5>What I can do</h5></div>
      <?php if ($mine === null): ?>
        <p class="mb-0"><i class="bi bi-stars text-gold me-1"></i>Super Admin has <b>complete access</b> to every module, employee, role and setting.</p>
      <?php else:
        $g = [];
        foreach ($mine as $r) { $g[$r['module']][] = $r['label']; }
        if (!$g) { echo '<p class="text-muted mb-0">No permissions have been given to your role yet.</p>'; }
        foreach ($g as $m => $labels): ?>
          <div class="mb-2"><b><?= crm_e($m) ?></b>
            <div class="small text-muted"><?php foreach ($labels as $l): ?><i class="bi bi-check2 text-success me-1"></i><?= crm_e($l) ?><br><?php endforeach; ?></div></div>
        <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php crm_page_end(); ?>
