<?php
/* checkout.php - address, payment method, place order */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/account.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$addrs = addresses_of((int)$u['id']);
$rzp = wc_rzp_enabled();
$codOn = S('web_cod_enabled', '1') === '1';
if (!$rzp && !$codOn) {
    page_start(['title' => 'Checkout', 'robots' => 'noindex']);
    echo '<div class="container"><div class="empty-big"><i class="bi bi-credit-card"></i><h2>Checkout is not available right now</h2><p>The store has not switched on any payment method yet. Please contact us.</p></div></div>';
    page_end();
    exit;
}

$sel = $_POST['address_id'] ?? $_GET['addr'] ?? ($addrs ? $addrs[0]['id'] : '0');
$sel = (string)(int)$sel;
$chosen = null;
foreach ($addrs as $a) {
    if ((string)$a['id'] === $sel) {
        $chosen = $a;
    }
}
if ($sel !== '0' && !$chosen) {
    $sel = $addrs ? (string)$addrs[0]['id'] : '0';
    $chosen = $addrs[0] ?? null;
}
$method = (string)($_POST['payment'] ?? $_GET['pay'] ?? ($rzp ? 'razorpay' : 'cod'));
if (!in_array($method, ['razorpay', 'cod'], true) || ($method === 'razorpay' && !$rzp) || ($method === 'cod' && !$codOn)) {
    $method = $rzp ? 'razorpay' : 'cod';
}
$state = $chosen['state'] ?? ($_POST['n_state'] ?? '');
$priced = cart_priced($state, $method);
if (!$priced['lines']) {
    flash('info', 'Your bag is empty.');
    redirect('cart');
}
if ($method === 'cod' && !cod_allowed($priced['total'])) {
    $method = 'razorpay';
    if (!$rzp) {
        flash('danger', 'Cash on delivery is not available for this order value.');
        redirect('cart');
    }
    $priced = cart_priced($state, $method);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        if (!wc_throttle('checkout:' . $u['id'], 15, 600)) {
            throw new RuntimeException('Too many attempts. Please wait a few minutes.');
        }
        if ($sel === '0') {
            [$addr, $err] = address_validate(['label' => post('n_label'), 'name' => post('n_name'), 'mobile' => post('n_mobile'), 'line1' => post('n_line1', 200), 'line2' => post('n_line2', 200), 'landmark' => '', 'city' => post('n_city'), 'state' => post('n_state'), 'pin' => post('n_pin')]);
            if (!$addr) {
                throw new RuntimeException($err);
            }
            address_save((int)$u['id'], $addr, !empty($_POST['n_default']));
        } else {
            $addr = $chosen;
        }
        $priced = cart_priced($addr['state'], $method);                  // price again with the real state
        if ($method === 'cod' && !cod_allowed($priced['total'])) {
            throw new RuntimeException('Cash on delivery is not available for this order value. Please pay online.');
        }
        [$oid, $no] = order_create($u, $addr, $method, post('note', 500), $priced);
        wc_log_activity((int)$u['id'], 'order_created', $no);
        if ($method === 'cod') {
            cart_clear();
            wc_order_confirm_cod($oid);
            redirect('order-success/' . $no);
        }
        $order = wc_order_get($oid);
        [$ok, $res] = wc_rzp_create_order($order);
        if (!$ok) {
            wc_order_cancel($oid, 'Payment gateway error', 'System', true);
            throw new RuntimeException('We could not start the payment (' . $res . '). Please try again or choose another method.');
        }
        wc_run("INSERT INTO web_payments (order_id, gateway, gateway_order_id, amount, status) VALUES (?,'razorpay',?,?,'created')", 'isd', [$oid, (string)$res['id'], $order['total']]);
        cart_clear();
        redirect('payment/pay?no=' . $no);
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
        redirect('checkout?addr=' . $sel . '&pay=' . $method);
    } catch (mysqli_sql_exception $ex) {
        error_log('checkout: ' . $ex->getMessage());
        flash('danger', 'Something went wrong while placing your order. Please try again.');
        redirect('checkout');
    }
}

page_start(['title' => 'Checkout', 'robots' => 'noindex']);
echo breadcrumb(['Bag' => url('cart'), 'Checkout' => '']);
?>
<div class="container page-pad">
  <h1>Checkout</h1><?= flash_html() ?>
  <form method="post" action="<?= e(url('checkout')) ?>?addr=<?= e($sel) ?>&pay=<?= e($method) ?>" class="cart-wrap" novalidate>
    <?= csrf_field() ?>
    <div>
      <div class="box"><h3>1. Delivery address</h3>
        <div class="addr-list">
          <?php foreach ($addrs as $a): ?>
            <label class="addr"><input type="radio" name="address_id" value="<?= (int)$a['id'] ?>" <?= $sel === (string)$a['id'] ? 'checked' : '' ?> data-recalc>
              <span><b><?= e($a['name']) ?></b> <span class="pill"><?= e($a['label']) ?></span><br><?= e($a['line1']) ?><?= $a['line2'] !== '' ? ', ' . e($a['line2']) : '' ?>, <?= e($a['city']) ?>, <?= e($a['state']) ?> - <?= e($a['pin']) ?><br><small class="muted">Mobile: <?= e($a['mobile']) ?></small></span></label>
          <?php endforeach; ?>
          <label class="addr"><input type="radio" name="address_id" value="0" <?= $sel === '0' ? 'checked' : '' ?> data-recalc><span><b><i class="bi bi-plus-circle"></i> Deliver to a new address</b></span></label>
        </div>
        <div id="new-addr" style="margin-top:16px;<?= $sel === '0' ? '' : 'display:none' ?>"><?= address_form(['name' => $u['name'], 'mobile' => $u['mobile']], 'n_') ?>
          <label class="check"><input type="checkbox" name="n_default" value="1"> Save as my default address</label></div>
      </div>
      <div class="box"><h3>2. Payment method</h3>
        <?php if ($rzp): ?><label class="pay-opt"><input type="radio" name="payment" value="razorpay" <?= $method === 'razorpay' ? 'checked' : '' ?> data-recalc><span><b>Pay online</b><br><small class="muted">UPI, debit / credit card, net banking, wallets - secured by Razorpay</small></span></label><?php endif; ?>
        <?php if ($codOn && cod_allowed($priced['total'])): ?><label class="pay-opt"><input type="radio" name="payment" value="cod" <?= $method === 'cod' ? 'checked' : '' ?> data-recalc><span><b>Cash on delivery</b><?= (float)S('web_cod_fee', '0') > 0 ? ' <small>(+ ' . wc_money(S('web_cod_fee')) . ' handling fee)</small>' : '' ?><br><small class="muted">Pay when your order arrives</small></span></label><?php endif; ?>
      </div>
      <div class="box"><h3>3. Notes (optional)</h3><textarea name="note" rows="2" maxlength="500" placeholder="Any instruction for delivery?"></textarea></div>
    </div>
    <aside class="summary"><h3>Your order</h3>
      <?php foreach ($priced['lines'] as $l): ?><div class="row"><span><?= e($l['name']) ?> <small class="muted">&times; <?= (int)$l['qty'] ?></small></span><span><?= wc_money($l['gross']) ?></span></div><?php endforeach; ?>
      <hr style="border:0;border-top:1px solid #e4dccb">
      <div class="row"><span>Subtotal</span><span><?= wc_money($priced['subtotal']) ?></span></div>
      <?php if ($priced['discount'] > 0): ?><div class="row green"><span>Coupon <?= e($priced['coupon']) ?></span><span>-<?= wc_money($priced['discount']) ?></span></div><?php endif; ?>
      <div class="row"><span>Shipping</span><span><?= $priced['shipping'] > 0 ? wc_money($priced['shipping']) : 'Free' ?></span></div>
      <?php if ($priced['cod_fee'] > 0): ?><div class="row"><span>COD fee</span><span><?= wc_money($priced['cod_fee']) ?></span></div><?php endif; ?>
      <div class="row total"><span>Total</span><b><?= wc_money($priced['total']) ?></b></div>
      <?php if ($priced['incl_gst']): ?><p class="small muted">Includes GST of <?= wc_money($priced['tax']) ?></p><?php endif; ?>
      <button class="btn btn-primary btn-block" type="submit"><?= $method === 'cod' ? 'Place order' : 'Pay ' . wc_money($priced['total']) ?></button>
      <p class="small muted" style="margin-top:10px"><i class="bi bi-lock"></i> Your details are safe with us. <a href="<?= e(url('page/return-policy')) ?>">Return policy</a></p>
    </aside>
  </form>
</div>
<script>(function(){var r=document.querySelectorAll('input[name=address_id]'),b=document.getElementById('new-addr');r.forEach(function(x){x.addEventListener('change',function(){b.style.display=x.value==='0'?'block':'none'})})})();</script>
<?php page_end();
