<?php
/* addresses.php - saved delivery addresses */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/account.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$uid = (int)$u['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $do = post('do');
    $id = (int)($_POST['id'] ?? 0);
    if ($do === 'save') {
        [$a, $err] = address_validate(['label' => post('label'), 'name' => post('name'), 'mobile' => post('mobile'), 'line1' => post('line1', 200), 'line2' => post('line2', 200), 'landmark' => '', 'city' => post('city'), 'state' => post('state'), 'pin' => post('pin')]);
        if (!$a) {
            flash('danger', $err);
            redirect('account/addresses' . ($id ? '?edit=' . $id : ''));
        }
        address_save($uid, $a, !empty($_POST['is_default']), $id);
        flash('success', 'Address saved.');
    } elseif ($do === 'delete') {
        wc_run('DELETE FROM web_addresses WHERE id = ? AND web_user_id = ?', 'ii', [$id, $uid]);
        if (!wc_one('SELECT id FROM web_addresses WHERE web_user_id = ? AND is_default = 1', 'i', [$uid]) && ($first = wc_one('SELECT id FROM web_addresses WHERE web_user_id = ? LIMIT 1', 'i', [$uid]))) {
            wc_run('UPDATE web_addresses SET is_default = 1 WHERE id = ?', 'i', [(int)$first['id']]);
        }
        flash('success', 'Address removed.');
    } elseif ($do === 'default') {
        wc_run('UPDATE web_addresses SET is_default = 0 WHERE web_user_id = ?', 'i', [$uid]);
        wc_run('UPDATE web_addresses SET is_default = 1 WHERE id = ? AND web_user_id = ?', 'ii', [$id, $uid]);
    }
    redirect('account/addresses');
}
$list = addresses_of($uid);
$edit = null;
foreach ($list as $a) {
    if ((int)($_GET['edit'] ?? 0) === (int)$a['id']) {
        $edit = $a;
    }
}
page_start(['title' => 'My addresses', 'robots' => 'noindex']);
echo breadcrumb(['My account' => url('account'), 'Addresses' => '']);
?>
<div class="container page-pad"><div class="account-layout"><?= account_nav('addresses') ?><div><h1>Delivery addresses</h1><?= flash_html() ?>
  <div class="reviews-grid" style="margin-bottom:24px">
  <?php foreach ($list as $a): ?><div class="box" style="margin:0"><b><?= e($a['name']) ?></b> <span class="pill"><?= e($a['label']) ?></span> <?= $a['is_default'] ? '<span class="pill ok">Default</span>' : '' ?><br>
    <?= e($a['line1']) ?><?= $a['line2'] !== '' ? ', ' . e($a['line2']) : '' ?><br><?= e($a['city']) ?>, <?= e($a['state']) ?> - <?= e($a['pin']) ?><br><span class="muted small">Mobile: <?= e($a['mobile']) ?></span>
    <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap"><a class="btn btn-ghost btn-sm" href="<?= e(url('account/addresses?edit=' . $a['id'])) ?>">Edit</a>
      <?php if (!$a['is_default']): ?><form method="post" action="<?= e(url('account/addresses')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="default"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm">Make default</button></form><?php endif; ?>
      <form method="post" action="<?= e(url('account/addresses')) ?>" onsubmit="return confirm('Remove this address?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm">Remove</button></form></div></div><?php endforeach; ?>
  </div>
  <div class="box"><h3><?= $edit ? 'Edit address' : 'Add a new address' ?></h3>
    <form method="post" action="<?= e(url('account/addresses')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <?= address_form($edit ?: ['name' => $u['name'], 'mobile' => $u['mobile']]) ?>
      <label class="check" style="margin-bottom:16px"><input type="checkbox" name="is_default" value="1" <?= $edit && $edit['is_default'] ? 'checked' : '' ?>> Make this my default address</label>
      <button class="btn btn-primary"><?= $edit ? 'Update address' : 'Save address' ?></button> <?= $edit ? '<a class="btn btn-ghost" href="' . e(url('account/addresses')) . '">Cancel</a>' : '' ?></form></div>
</div></div></div>
<?php page_end();
