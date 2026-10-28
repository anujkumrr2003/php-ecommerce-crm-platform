<?php
/* account.php - overview, profile and password (tab=profile) */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/account.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$tab = ($_GET['tab'] ?? '') === 'profile' ? 'profile' : 'account';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (post('do') === 'profile') {
        $name = post('name', 120);
        $mobile = substr(preg_replace('/\D+/', '', post('mobile', 15)), -10);
        if (mb_strlen($name) < 2 || !preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
            flash('danger', 'Please enter your name and a valid 10 digit mobile number.');
        } else {
            wc_run('UPDATE web_users SET name = ?, mobile = ? WHERE id = ?', 'ssi', [$name, $mobile, (int)$u['id']]);
            wc_log_activity((int)$u['id'], 'profile_update', '');
            flash('success', 'Profile updated.');
        }
    } elseif (post('do') === 'password') {
        $p1 = (string)($_POST['password'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$u['password_hash'])) {
            flash('danger', 'Your current password is not correct.');
        } elseif (($pe = password_error($p1)) !== '') {
            flash('danger', $pe);
        } elseif ($p1 !== (string)($_POST['password2'] ?? '')) {
            flash('danger', 'New passwords do not match.');
        } else {
            wc_run('UPDATE web_users SET password_hash = ? WHERE id = ?', 'si', [password_hash($p1, PASSWORD_DEFAULT), (int)$u['id']]);
            wc_log_activity((int)$u['id'], 'password_change', '');
            flash('success', 'Password changed.');
        }
    }
    redirect('account/profile');
}
$orders = wc_all('SELECT * FROM web_orders WHERE web_user_id = ? ORDER BY id DESC LIMIT 5', 'i', [(int)$u['id']]);
page_start(['title' => 'My account', 'robots' => 'noindex']);
echo breadcrumb(['My account' => '']);
?>
<div class="container page-pad"><div class="account-layout"><?= account_nav($tab === 'profile' ? 'profile' : 'account') ?><div><?= flash_html() ?>
<?php if ($tab === 'account'): ?>
  <h1>Hello, <?= e(explode(' ', $u['name'])[0]) ?></h1>
  <div class="reviews-grid" style="margin:18px 0 26px"><a class="box reveal in" href="<?= e(url('account/orders')) ?>"><i class="bi bi-bag" style="font-size:26px;color:var(--accent)"></i><h3 style="margin:6px 0 0">Orders</h3><span class="muted">Track, cancel or return</span></a>
    <a class="box reveal in" href="<?= e(url('wishlist')) ?>"><i class="bi bi-heart" style="font-size:26px;color:var(--accent)"></i><h3 style="margin:6px 0 0">Wishlist</h3><span class="muted"><?= count(wishlist_ids()) ?> saved items</span></a>
    <a class="box reveal in" href="<?= e(url('account/addresses')) ?>"><i class="bi bi-geo-alt" style="font-size:26px;color:var(--accent)"></i><h3 style="margin:6px 0 0">Addresses</h3><span class="muted">Delivery addresses</span></a></div>
  <h3>Recent orders</h3>
  <?php if ($orders): ?><div class="table-wrap" style="overflow:auto"><table class="table"><thead><tr><th>Order</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($orders as $o): ?><tr><td><b>#<?= e($o['order_no']) ?></b></td><td><?= e(date('j M Y', strtotime((string)$o['created_at']))) ?></td><td><?= wc_money($o['total']) ?></td><td><?= status_pill($o['status']) ?></td><td><a href="<?= e(url('account/order/' . $o['order_no'])) ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div>
  <?php else: ?><p class="muted">You have not placed any order yet. <a href="<?= e(url('shop')) ?>"><b>Start shopping</b></a></p><?php endif; ?>
<?php else: ?>
  <h1>Profile</h1>
  <div class="box"><h3>Your details</h3><form method="post" action="<?= e(url('account/profile')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="profile">
    <div class="row2"><div class="field"><label>Full name</label><input name="name" value="<?= e($u['name']) ?>" maxlength="120" required></div><div class="field"><label>Mobile</label><input name="mobile" value="<?= e($u['mobile']) ?>" inputmode="numeric" maxlength="12" required></div></div>
    <div class="field"><label>Email (cannot be changed)</label><input value="<?= e($u['email']) ?>" disabled></div><button class="btn btn-primary">Save changes</button></form></div>
  <div class="box"><h3>Change password</h3><form method="post" action="<?= e(url('account/profile')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="password">
    <div class="field"><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div>
    <div class="row2"><div class="field"><label>New password</label><input type="password" name="password" autocomplete="new-password" required></div><div class="field"><label>Confirm new password</label><input type="password" name="password2" autocomplete="new-password" required></div></div>
    <button class="btn btn-primary">Update password</button></form></div>
<?php endif; ?>
</div></div></div>
<?php page_end();
