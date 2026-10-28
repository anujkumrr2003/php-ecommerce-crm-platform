<?php
/* order-success.php - thank-you page */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$o = wc_one('SELECT * FROM web_orders WHERE order_no = ? AND web_user_id = ?', 'si', [(string)($_GET['no'] ?? ''), (int)$u['id']]);
if (!$o) {
    not_found('Order not found.');
}
$paid = in_array($o['status'], ['placed', 'processing', 'shipped', 'out_for_delivery', 'delivered'], true);
page_start(['title' => $paid ? 'Thank you' : 'Order status', 'robots' => 'noindex']);
?>
<div class="container"><div class="success">
  <?php if ($paid): ?>
    <div class="tick"><i class="bi bi-check-lg"></i></div><h1>Thank you, <?= e(explode(' ', $u['name'])[0]) ?>!</h1>
    <p>Your order <b>#<?= e($o['order_no']) ?></b> is confirmed. A confirmation email has been sent to <b><?= e($o['email']) ?></b>.</p>
    <p class="muted"><?= $o['payment_method'] === 'cod' ? 'Please keep ' . e(wc_money($o['total'])) . ' ready to pay on delivery.' : 'Payment of ' . e(wc_money($o['total'])) . ' received.' ?></p>
  <?php else: ?>
    <div class="tick" style="background:#fff1d6;color:#7a5a10"><i class="bi bi-hourglass-split"></i></div><h1>Order <?= e(strtolower(WC_STATUSES[$o['status']] ?? $o['status'])) ?></h1>
    <p>Order <b>#<?= e($o['order_no']) ?></b> is not confirmed yet. If you have just paid, it can take a minute - this page will show the result when you refresh.</p>
    <?php if (in_array($o['status'], ['pending_payment', 'payment_failed'], true)): ?><p><a class="btn btn-primary" href="<?= e(url('payment/pay?no=' . $o['order_no'])) ?>">Pay now</a></p><?php endif; ?>
  <?php endif; ?>
  <div class="box" style="text-align:left"><h3>Delivering to</h3><?= e($o['ship_name']) ?>, <?= e($o['ship_line1']) ?>, <?= e($o['ship_city']) ?>, <?= e($o['ship_state']) ?> - <?= e($o['ship_pin']) ?></div>
  <p><a class="btn btn-primary" href="<?= e(url('account/order/' . $o['order_no'])) ?>">Track order</a> <a class="btn btn-ghost" href="<?= e(url('shop')) ?>">Continue shopping</a></p>
</div></div>
<?php page_end();
