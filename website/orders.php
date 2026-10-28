<?php
/* orders.php - my orders */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/account.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$orders = wc_all('SELECT o.*, (SELECT COUNT(*) FROM web_order_items i WHERE i.order_id = o.id) AS items, (SELECT image FROM web_order_items i WHERE i.order_id = o.id ORDER BY id LIMIT 1) AS img FROM web_orders o WHERE o.web_user_id = ? ORDER BY o.id DESC LIMIT 200', 'i', [(int)$u['id']]);
page_start(['title' => 'My orders', 'robots' => 'noindex']);
echo breadcrumb(['My account' => url('account'), 'Orders' => '']);
?>
<div class="container page-pad"><div class="account-layout"><?= account_nav('orders') ?><div><h1>My orders</h1><?= flash_html() ?>
<?php if (!$orders): ?><div class="empty-big"><i class="bi bi-bag"></i><p>No orders yet.</p><a class="btn btn-primary" href="<?= e(url('shop')) ?>">Start shopping</a></div><?php else: ?>
  <?php foreach ($orders as $o): ?>
  <a class="box" href="<?= e(url('account/order/' . $o['order_no'])) ?>" style="display:grid;grid-template-columns:70px 1fr auto;gap:16px;align-items:center">
    <?= $o['img'] ? '<img src="' . e(img($o['img'])) . '" alt="" style="width:70px;height:88px;object-fit:cover;border-radius:2px">' : '<div class="noimg" style="width:70px;height:88px"><span style="font-size:30px">' . e(mb_substr($o['ship_name'], 0, 1)) . '</span></div>' ?>
    <div><b>#<?= e($o['order_no']) ?></b> <?= status_pill($o['status']) ?><br><span class="muted small"><?= e(date('j M Y, g:i a', strtotime((string)$o['created_at']))) ?> &middot; <?= (int)$o['items'] ?> item<?= (int)$o['items'] === 1 ? '' : 's' ?></span></div>
    <b><?= wc_money($o['total']) ?></b></a>
  <?php endforeach; endif; ?>
</div></div></div>
<?php page_end();
