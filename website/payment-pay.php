<?php
/* payment-pay.php - opens the Razorpay checkout for an order that is waiting for payment */
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
if (!in_array($o['status'], ['pending_payment', 'payment_failed'], true)) {
    redirect('account/order/' . $o['order_no']);
}
if (!wc_rzp_enabled()) {
    flash('danger', 'Online payment is not available right now.');
    redirect('account/order/' . $o['order_no']);
}
$pay = wc_one("SELECT * FROM web_payments WHERE order_id = ? AND status IN ('created','failed') ORDER BY id DESC LIMIT 1", 'i', [(int)$o['id']]);
if (!$pay) {                                                         // first payment record missing -> create a fresh Razorpay order
    [$ok, $res] = wc_rzp_create_order($o);
    if (!$ok) {
        flash('danger', 'We could not start the payment (' . $res . '). Please try again in a moment.');
        redirect('account/order/' . $o['order_no']);
    }
    wc_run("INSERT INTO web_payments (order_id, gateway, gateway_order_id, amount, status) VALUES (?,'razorpay',?,?,'created')", 'isd', [(int)$o['id'], (string)$res['id'], $o['total']]);
    $pay = wc_one('SELECT * FROM web_payments WHERE gateway_order_id = ?', 's', [(string)$res['id']]);
}
$opts = ['key' => wc_rzp_key_id(), 'amount' => (int)round((float)$o['total'] * 100), 'currency' => 'INR', 'name' => wc_brand()['name'], 'description' => 'Order #' . $o['order_no'], 'order_id' => $pay['gateway_order_id'],
    'prefill' => ['name' => $o['ship_name'], 'email' => $o['email'], 'contact' => $o['ship_mobile']], 'theme' => ['color' => S('web_color_primary', '#1b1b1f')], 'notes' => ['order_no' => $o['order_no']]];
page_start(['title' => 'Complete your payment', 'robots' => 'noindex']);
?>
<div class="container"><div class="success">
  <div class="tick" style="background:#f6f0e2;color:var(--accent)"><i class="bi bi-shield-lock"></i></div>
  <h1>Complete your payment</h1>
  <p>Order <b>#<?= e($o['order_no']) ?></b> &middot; Amount <b><?= wc_money($o['total']) ?></b></p>
  <p class="muted">A secure Razorpay window will open. Please do not close or refresh this page until the payment is finished.</p>
  <div id="msg"></div>
  <button class="btn btn-primary" id="payBtn" type="button">Pay <?= e(wc_money($o['total'])) ?></button>
  <p class="small muted" style="margin-top:14px">Your items are reserved for <?= (int)S('web_unpaid_expiry_min', '45') ?> minutes. <a href="<?= e(url('account/order/' . $o['order_no'])) ?>">Back to order</a></p>
</div></div>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
  var O = <?= json_encode($opts, JSON_HEX_TAG | JSON_HEX_AMP) ?>, NO = <?= json_encode($o['order_no']) ?>, btn = document.getElementById('payBtn'), msg = document.getElementById('msg'), busy = false;
  function post(path, d) { var b = new URLSearchParams(d); return fetch(Shop.url(path), { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf]').content, Accept: 'application/json' }, body: b }).then(function (r) { return r.json(); }); }
  function say(t, cls) { msg.innerHTML = '<div class="alert alert-' + cls + '"></div>'; msg.firstChild.textContent = t; }
  function start() {
    if (busy) return; if (!window.Razorpay) { say('Payment window could not load. Please check your internet and refresh.', 'danger'); return; }
    var rz = new Razorpay(Object.assign({}, O, {
      handler: function (r) { busy = true; btn.disabled = true; say('Confirming your payment... please wait.', 'info');
        post('payment/verify', { razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id, razorpay_signature: r.razorpay_signature, order_no: NO }).then(function (j) {
          if (j.success) { location.href = j.redirect; } else { busy = false; btn.disabled = false; say(j.message || 'We could not confirm the payment.', 'danger'); }
        }).catch(function () { say('Network problem while confirming. Do not pay again - check your order page in a minute.', 'danger'); }); },
      modal: { ondismiss: function () { if (!busy) say('Payment was not completed. You can try again.', 'warning'); } }
    }));
    rz.on('payment.failed', function (r) { post('payment/failed', { razorpay_order_id: O.order_id, order_no: NO, error: (r.error && r.error.description) || 'Payment failed' }); say((r.error && r.error.description) || 'Payment failed. Please try again.', 'danger'); });
    rz.open();
  }
  btn.addEventListener('click', start); setTimeout(start, 600);
})();
</script>
<?php page_end();
