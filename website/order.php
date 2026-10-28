<?php
/* order.php - one order: tracking, cancel, return, pay now */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/account.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$o = wc_one('SELECT * FROM web_orders WHERE order_no = ? AND web_user_id = ?', 'si', [(string)($_GET['no'] ?? ''), (int)$u['id']]);
if (!$o) {
    not_found('We could not find this order.');
}
$self = 'account/order/' . $o['order_no'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $do = post('do');
    if ($do === 'cancel') {
        [$ok, $msg] = wc_order_cancel((int)$o['id'], post('reason', 200) ?: 'Cancelled by customer', 'Customer');
        flash($ok ? 'success' : 'danger', $msg);
        wc_log_activity((int)$u['id'], 'order_cancel', $o['order_no']);
    } elseif ($do === 'return') {
        $reason = post('reason', 120);
        if ($reason === '') {
            flash('danger', 'Please choose a reason for the return.');
        } else {
            [$ok, $msg] = wc_order_request_return((int)$o['id'], $reason, post('details', 800));
            flash($ok ? 'success' : 'danger', $msg);
        }
    }
    redirect($self);
}
$items = wc_order_items((int)$o['id']);
$hist = wc_all('SELECT * FROM web_order_history WHERE order_id = ? ORDER BY id', 'i', [(int)$o['id']]);
$ret = wc_one('SELECT * FROM web_returns WHERE order_id = ? ORDER BY id DESC LIMIT 1', 'i', [(int)$o['id']]);

/* progress bar */
$flow = ['placed' => 'Placed', 'processing' => 'Packed', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];
$rank = ['placed' => 0, 'processing' => 1, 'shipped' => 2, 'out_for_delivery' => 2, 'delivered' => 3, 'return_requested' => 3, 'return_approved' => 3, 'returned' => 3, 'refunded' => 3];
$cur = $rank[$o['status']] ?? -1;
page_start(['title' => 'Order #' . $o['order_no'], 'robots' => 'noindex']);
echo breadcrumb(['My account' => url('account'), 'Orders' => url('account/orders'), '#' . $o['order_no'] => '']);
?>
<div class="container page-pad"><div class="account-layout"><?= account_nav('orders') ?><div>
  <h1>Order #<?= e($o['order_no']) ?> <?= status_pill($o['status']) ?></h1><?= flash_html() ?>
  <p class="muted">Placed on <?= e(date('j M Y, g:i a', strtotime((string)$o['created_at']))) ?><?= $o['invoice_no'] !== '' ? ' &middot; Invoice ' . e($o['invoice_no']) : '' ?></p>
  <?php if ($cur >= 0 && $o['status'] !== 'cancelled'): ?><div class="steps"><?php $i = 0; foreach ($flow as $k => $l): ?><div class="stp <?= $i <= $cur ? 'done' : '' ?>"><i><?= $i <= $cur ? '&#10003;' : $i + 1 ?></i><?= e($l) ?></div><?php $i++; endforeach; ?></div><?php endif; ?>
  <?php if (in_array($o['status'], ['pending_payment', 'payment_failed'], true)): ?><div class="alert alert-warning">Your payment is not complete. <a href="<?= e(url('payment/pay?no=' . $o['order_no'])) ?>"><b>Pay <?= e(wc_money($o['total'])) ?> now</b></a> - your items are held for a short time only.</div><?php endif; ?>
  <?php if ($o['status'] === 'cancelled'): ?><div class="alert alert-danger">This order was cancelled. <?= $o['cancel_reason'] !== '' ? 'Reason: ' . e($o['cancel_reason']) . '.' : '' ?> <?= $o['payment_status'] === 'refunded' ? 'Your payment has been refunded.' : ($o['payment_status'] === 'paid' ? 'Your refund is being processed.' : '') ?></div><?php endif; ?>
  <?php if ($o['tracking_no'] !== '' || $o['courier'] !== ''): ?><div class="box"><h3>Tracking</h3>Courier: <b><?= e($o['courier'] ?: '-') ?></b> &nbsp; Tracking no: <b><?= e($o['tracking_no'] ?: '-') ?></b><?= $o['tracking_url'] !== '' && preg_match('#^https?://#i', $o['tracking_url']) ? ' &nbsp; <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="' . e($o['tracking_url']) . '">Track shipment</a>' : '' ?></div><?php endif; ?>
  <div class="box"><h3>Items</h3>
    <?php foreach ($items as $it): $var = trim($it['size'] . ($it['size'] !== '' && $it['color'] !== '' ? ' / ' : '') . $it['color']); ?>
      <div class="cart-line" style="grid-template-columns:70px 1fr auto"><div class="cl-img"><?= $it['image'] !== '' ? '<img src="' . e(img($it['image'])) . '" alt="" style="width:70px;height:88px;object-fit:cover">' : '' ?></div>
        <div><a class="cl-name" href="<?= e($it['product_id'] ? product_url(['id' => $it['product_id'], 'name' => $it['name']]) : '#') ?>"><?= e($it['name']) ?></a><div class="small muted"><?= e($var) ?> &middot; Qty <?= (int)$it['qty'] ?></div></div><b><?= wc_money($it['line_total']) ?></b></div>
    <?php endforeach; ?>
    <div class="cart-sum" style="margin-top:12px"><div class="row"><span>Subtotal</span><span><?= wc_money($o['subtotal']) ?></span></div>
      <?php if ((float)$o['discount'] > 0): ?><div class="row green"><span>Discount <?= e($o['coupon_code']) ?></span><span>-<?= wc_money($o['discount']) ?></span></div><?php endif; ?>
      <div class="row"><span>Shipping</span><span><?= (float)$o['shipping_fee'] > 0 ? wc_money($o['shipping_fee']) : 'Free' ?></span></div>
      <?php if ((float)$o['cod_fee'] > 0): ?><div class="row"><span>COD fee</span><span><?= wc_money($o['cod_fee']) ?></span></div><?php endif; ?>
      <div class="row total" style="border-top:1px solid #e4dccb;margin-top:6px;padding-top:10px;font-size:17px"><b>Total</b><b><?= wc_money($o['total']) ?></b></div>
      <div class="small muted">Payment: <?= $o['payment_method'] === 'cod' ? 'Cash on delivery' : 'Online (Razorpay)' ?> &middot; <?= e(ucfirst($o['payment_status'])) ?></div></div></div>
  <div class="row2"><div class="box"><h3>Delivery address</h3><b><?= e($o['ship_name']) ?></b><br><?= e($o['ship_line1']) ?><?= $o['ship_line2'] !== '' ? ', ' . e($o['ship_line2']) : '' ?><br><?= e($o['ship_city']) ?>, <?= e($o['ship_state']) ?> - <?= e($o['ship_pin']) ?><br><span class="muted">Mobile: <?= e($o['ship_mobile']) ?></span></div>
    <div class="box"><h3>Order updates</h3><div class="timeline"><?php foreach (array_reverse($hist) as $h): ?><div class="tl done"><i></i><div><b><?= e(WC_STATUSES[$h['status']] ?? $h['status']) ?></b><br><small><?= e(date('j M, g:i a', strtotime((string)$h['created_at']))) ?></small></div></div><?php endforeach; ?></div></div></div>
  <?php if ($ret): ?><div class="box"><h3>Return request</h3><?= e($ret['reason']) ?> &middot; <span class="pill info"><?= e(ucfirst($ret['status'])) ?></span><?= $ret['admin_note'] !== '' ? '<p class="small muted" style="margin:8px 0 0">' . e($ret['admin_note']) . '</p>' : '' ?></div><?php endif; ?>
  <?php if (wc_order_can_cancel($o)): ?><details class="box"><summary style="cursor:pointer"><b>Cancel this order</b></summary><form method="post" action="<?= e(url($self)) ?>" data-confirm style="margin-top:12px" onsubmit="return confirm('Cancel this order?')"><?= csrf_field() ?><input type="hidden" name="do" value="cancel">
    <div class="field"><label>Reason</label><select name="reason"><option>Ordered by mistake</option><option>Found a better price</option><option>Want to change size / colour</option><option>Delivery is taking too long</option><option>Other</option></select></div><button class="btn btn-ghost">Cancel order</button></form></details><?php endif; ?>
  <?php if (wc_order_can_return($o)): ?><details class="box"><summary style="cursor:pointer"><b>Return or exchange</b> <span class="muted small">(within <?= (int)S('web_return_days', '7') ?> days of delivery)</span></summary><form method="post" action="<?= e(url($self)) ?>" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="do" value="return">
    <div class="field"><label>Reason</label><select name="reason"><option>Size does not fit</option><option>Product is damaged / defective</option><option>Wrong item received</option><option>Not as shown in the picture</option><option>Changed my mind</option></select></div>
    <div class="field"><label>Tell us more (optional)</label><textarea name="details" rows="3" maxlength="800"></textarea></div><button class="btn btn-primary">Request return</button></form></details><?php endif; ?>
</div></div></div>
<?php page_end();
