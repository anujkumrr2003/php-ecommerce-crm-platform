<?php
/* web-payments.php - every online payment attempt (paid / failed) and every refund */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

$pays = wc_all('SELECT p.*, o.order_no, o.ship_name, o.id AS oid FROM web_payments p JOIN web_orders o ON o.id = p.order_id ORDER BY p.id DESC LIMIT 5000');
$refs = wc_all('SELECT r.*, o.order_no, o.id AS oid FROM web_refunds r JOIN web_orders o ON o.id = r.order_id ORDER BY r.id DESC LIMIT 2000');
$k = wc_one("SELECT COALESCE(SUM(CASE WHEN status IN ('paid','refunded') THEN amount END),0) AS got, COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 END),0) AS failed,
             COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 END),0) AS ok FROM web_payments");
$ref = wc_one("SELECT COALESCE(SUM(amount),0) AS a FROM web_refunds WHERE status IN ('processed','manual')");
$tone = ['paid' => 'green', 'created' => 'gray', 'failed' => 'red', 'refunded' => 'amber', 'processed' => 'green', 'manual' => 'amber', 'pending' => 'gray'];
$cod = wc_one("SELECT COALESCE(SUM(total),0) AS a FROM web_orders WHERE payment_method = 'cod' AND payment_status = 'cod' AND status NOT IN ('cancelled','refunded')");
crm_page_start('Website payments');
crm_page_head('Website payments', 'Razorpay payments, failures and refunds.');
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="panel py-2"><small class="text-muted">Online payments received</small><div class="fs-5 fw-semibold"><?= wc_money($k['got']) ?></div><small><?= (int)$k['ok'] ?> successful</small></div></div>
  <div class="col-6 col-lg-3"><div class="panel py-2"><small class="text-muted">Failed attempts</small><div class="fs-5 fw-semibold"><?= (int)$k['failed'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="panel py-2"><small class="text-muted">Refunded</small><div class="fs-5 fw-semibold"><?= wc_money($ref['a']) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="panel py-2"><small class="text-muted">COD to collect</small><div class="fs-5 fw-semibold"><?= wc_money($cod['a']) ?></div></div></div>
</div>
<div class="panel mb-3"><div class="panel-title"><h5>Payments</h5></div><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table>
  <thead><tr><th>Date</th><th>Order</th><th>Customer</th><th>Razorpay payment</th><th data-filter="Method">Method</th><th>Amount</th><th data-filter="Status">Status</th><th>Error</th></tr></thead><tbody>
  <?php foreach ($pays as $p): ?>
    <tr><td data-sort="<?= crm_e($p['created_at']) ?>"><?= crm_e(date('j M Y, g:i a', strtotime((string)$p['created_at']))) ?></td>
      <td><a href="web-orders.php?id=<?= (int)$p['oid'] ?>">#<?= crm_e($p['order_no']) ?></a></td><td><?= crm_e($p['ship_name']) ?></td>
      <td><small><?= crm_e($p['gateway_payment_id'] ?: $p['gateway_order_id']) ?></small></td><td data-value="<?= crm_e($p['method'] ?: '-') ?>"><?= crm_e($p['method'] ?: '-') ?></td>
      <td><b><?= wc_money($p['amount']) ?></b></td><td data-value="<?= crm_e($p['status']) ?>"><span class="badge-s b-<?= $tone[$p['status']] ?? 'gray' ?>"><?= crm_e($p['status']) ?></span></td><td><small class="text-danger"><?= crm_e($p['error']) ?></small></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<div class="panel"><div class="panel-title"><h5>Refunds</h5></div><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table>
  <thead><tr><th>Date</th><th>Order</th><th>Refund id</th><th>Amount</th><th data-filter="Status">Status</th><th>Reason</th></tr></thead><tbody>
  <?php foreach ($refs as $r): ?>
    <tr><td data-sort="<?= crm_e($r['created_at']) ?>"><?= crm_e(date('j M Y, g:i a', strtotime((string)$r['created_at']))) ?></td><td><a href="web-orders.php?id=<?= (int)$r['oid'] ?>">#<?= crm_e($r['order_no']) ?></a></td>
      <td><small><?= crm_e($r['gateway_refund_id'] ?: '-') ?></small></td><td><b><?= wc_money($r['amount']) ?></b></td>
      <td data-value="<?= crm_e($r['status']) ?>"><span class="badge-s b-<?= $tone[$r['status']] ?? 'gray' ?>"><?= crm_e($r['status']) ?></span></td><td><?= crm_e($r['reason']) ?><?= $r['error'] !== '' ? '<br><small class="text-danger">' . crm_e($r['error']) . '</small>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php crm_page_end(); ?>
