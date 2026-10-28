<?php
/* web-orders.php - website orders: list, details, status / tracking, cancel, returns, refunds */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';
$me = crm_user();
$canManage = crm_can('web.orders.manage');
try {
    wc_expire_unpaid_orders();
} catch (Throwable $e) {
    error_log('expire: ' . $e->getMessage());
}

$tone = ['pending_payment' => 'gray', 'payment_failed' => 'red', 'placed' => 'blue', 'processing' => 'amber', 'shipped' => 'gold', 'out_for_delivery' => 'gold', 'delivered' => 'green',
    'cancelled' => 'red', 'return_requested' => 'amber', 'return_approved' => 'amber', 'returned' => 'gray', 'refunded' => 'gray'];
$payTone = ['paid' => 'green', 'cod' => 'amber', 'pending' => 'gray', 'failed' => 'red', 'refunded' => 'gray'];
$badge = fn(string $t, string $l) => '<span class="badge-s b-' . $t . '">' . crm_e($l) . '</span>';

/* ------------------------------------------------------------ actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $id = (int)($_POST['id'] ?? 0);
    try {
        if (!$canManage) {
            throw new RuntimeException('You do not have permission to change orders.');
        }
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'status') {
            [$ok, $msg] = wc_order_set_status($id, (string)($_POST['to'] ?? ''), [
                'by' => $me['name'], 'note' => (string)($_POST['note'] ?? ''), 'courier' => (string)($_POST['courier'] ?? ''), 'tracking_no' => (string)($_POST['tracking_no'] ?? ''),
                'tracking_url' => (string)($_POST['tracking_url'] ?? ''), 'restock' => !empty($_POST['restock']),
            ]);
            crm_flash($ok ? 'success' : 'danger', $msg);
            if ($ok) {
                crm_log('website', 'order_status', '#' . $id . ' -> ' . $_POST['to']);
            }
        } elseif ($do === 'refund_retry') {
            [$ok, $msg] = wc_order_refund($id, 'Refund retried by ' . $me['name'], $me['name']);
            crm_flash($ok ? 'success' : 'danger', $msg);
        } elseif ($do === 'track') {                           // only update tracking text (no status change)
            wc_run('UPDATE web_orders SET courier = ?, tracking_no = ?, tracking_url = ? WHERE id = ?', 'sssi',
                [mb_substr(trim((string)$_POST['courier']), 0, 80), mb_substr(trim((string)$_POST['tracking_no']), 0, 80), mb_substr(trim((string)$_POST['tracking_url']), 0, 255), $id]);
            crm_flash('success', 'Tracking details saved.');
        }
    } catch (Throwable $e) {
        error_log('web-orders: ' . $e->getMessage());
        crm_flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.');
    }
    header('Location: web-orders.php?id=' . $id);
    exit;
}

/* --------------------------------------------------------- detail view */
if (isset($_GET['id'])) {
    $o = wc_order_get((int)$_GET['id']);
    if (!$o) {
        crm_flash('danger', 'Order not found.');
        header('Location: web-orders.php');
        exit;
    }
    $items = wc_order_items((int)$o['id']);
    $hist  = wc_all('SELECT * FROM web_order_history WHERE order_id = ? ORDER BY id DESC', 'i', [(int)$o['id']]);
    $pays  = wc_all('SELECT * FROM web_payments WHERE order_id = ? ORDER BY id DESC', 'i', [(int)$o['id']]);
    $refs  = wc_all('SELECT * FROM web_refunds WHERE order_id = ? ORDER BY id DESC', 'i', [(int)$o['id']]);
    $rets  = wc_all('SELECT * FROM web_returns WHERE order_id = ? ORDER BY id DESC', 'i', [(int)$o['id']]);
    $next  = wc_order_next_statuses($o);
    crm_page_start('Order #' . $o['order_no']);
    crm_page_head('Order #' . $o['order_no'], 'Placed ' . date('j M Y, g:i a', strtotime((string)$o['created_at'])), '<a class="btn btn-light border" href="web-orders.php"><i class="bi bi-arrow-left me-1"></i>All orders</a>');
    echo crm_flash_html(); ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="panel mb-3">
      <div class="panel-title"><h5>Items</h5><span><?= $badge($tone[$o['status']] ?? 'gray', WC_STATUSES[$o['status']] ?? $o['status']) ?></span></div>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Product</th><th>Variant</th><th>Qty</th><th>Price</th><th>Discount</th><th>Total</th></tr></thead><tbody>
        <?php foreach ($items as $it): ?>
          <tr><td><b><?= crm_e($it['name']) ?></b><br><small class="text-muted"><?= crm_e($it['sku']) ?></small></td>
            <td><?= crm_e(trim($it['size'] . ' / ' . $it['color'], ' /') ?: '-') ?></td><td><?= (int)$it['qty'] ?></td>
            <td><?= wc_money($it['unit_price']) ?></td><td><?= (float)$it['line_discount'] > 0 ? wc_money($it['line_discount']) : '-' ?></td><td><b><?= wc_money($it['line_total']) ?></b></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="5" class="text-end">Subtotal</td><td><?= wc_money($o['subtotal']) ?></td></tr>
          <?php if ((float)$o['discount'] > 0): ?><tr><td colspan="5" class="text-end">Discount <?= $o['coupon_code'] !== '' ? '(' . crm_e($o['coupon_code']) . ')' : '' ?></td><td>-<?= wc_money($o['discount']) ?></td></tr><?php endif; ?>
          <tr><td colspan="5" class="text-end">Shipping</td><td><?= (float)$o['shipping_fee'] > 0 ? wc_money($o['shipping_fee']) : 'Free' ?></td></tr>
          <?php if ((float)$o['cod_fee'] > 0): ?><tr><td colspan="5" class="text-end">COD fee</td><td><?= wc_money($o['cod_fee']) ?></td></tr><?php endif; ?>
          <tr><td colspan="5" class="text-end"><b>Total</b> <small class="text-muted">(GST included: <?= wc_money($o['tax_total']) ?>)</small></td><td><b><?= wc_money($o['total']) ?></b></td></tr>
        </tfoot></table></div>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-md-6"><div class="panel h-100"><div class="panel-title"><h5>Delivery address</h5></div>
        <b><?= crm_e($o['ship_name']) ?></b><br><?= crm_e($o['ship_line1']) ?><?= $o['ship_line2'] !== '' ? ', ' . crm_e($o['ship_line2']) : '' ?><br><?= $o['ship_landmark'] !== '' ? crm_e($o['ship_landmark']) . '<br>' : '' ?>
        <?= crm_e($o['ship_city']) ?>, <?= crm_e($o['ship_state']) ?> - <?= crm_e($o['ship_pin']) ?><br>Mobile: <?= crm_e($o['ship_mobile']) ?><br>Email: <?= crm_e($o['email']) ?>
        <?php if ($o['customer_note'] !== ''): ?><hr class="my-2"><small class="text-muted">Customer note:</small><br><?= crm_e($o['customer_note']) ?><?php endif; ?></div></div>
      <div class="col-md-6"><div class="panel h-100"><div class="panel-title"><h5>Payment &amp; invoice</h5></div>
        Method: <b><?= $o['payment_method'] === 'cod' ? 'Cash on delivery' : 'Razorpay (online)' ?></b><br>
        Status: <?= $badge($payTone[$o['payment_status']] ?? 'gray', ucfirst($o['payment_status'])) ?><br>
        Invoice: <?= $o['invoice_no'] !== '' ? '<b>' . crm_e($o['invoice_no']) . '</b>' . (crm_can('billing.view') ? ' &nbsp;<a href="billing.php">open billing</a>' : '') : '<span class="text-muted">created when order is confirmed</span>' ?><br>
        <?php if ($o['customer_id']): ?>CRM customer: <?= crm_can('customers.view') ? '<a href="customers.php?q=' . urlencode($o['ship_mobile']) . '">' . crm_e($o['ship_name']) . '</a>' : crm_e($o['ship_name']) ?><br><?php endif; ?>
        <?php foreach ($pays as $p): ?><hr class="my-2"><small class="text-muted">Razorpay</small><br><small>Order: <?= crm_e($p['gateway_order_id']) ?><br>Payment: <?= crm_e($p['gateway_payment_id'] ?: '-') ?> &middot; <?= crm_e($p['method'] ?: '-') ?> &middot; <b><?= crm_e($p['status']) ?></b><?= $p['error'] !== '' ? '<br><span class="text-danger">' . crm_e($p['error']) . '</span>' : '' ?></small><?php endforeach; ?>
        <?php foreach ($refs as $r): ?><hr class="my-2"><small>Refund <?= wc_money($r['amount']) ?>: <b><?= crm_e($r['status']) ?></b> <?= crm_e($r['gateway_refund_id']) ?><?= $r['error'] !== '' ? '<br><span class="text-danger">' . crm_e($r['error']) . '</span>' : '' ?></small><?php endforeach; ?>
        <?php if ($canManage && $o['status'] === 'cancelled' && $o['payment_status'] === 'paid'): ?>
          <form method="post" class="mt-2"><?= crm_csrf_field() ?><input type="hidden" name="do" value="refund_retry"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sm btn-outline-danger">Retry refund</button></form>
        <?php endif; ?></div></div>
    </div>
    <?php if ($rets): ?><div class="panel mb-3"><div class="panel-title"><h5>Return request</h5></div>
      <?php foreach ($rets as $r): ?><p class="mb-1"><b><?= crm_e($r['reason']) ?></b> &middot; <?= $badge('amber', ucfirst($r['status'])) ?><br><?= crm_e($r['details']) ?><?= $r['admin_note'] !== '' ? '<br><small class="text-muted">Your note: ' . crm_e($r['admin_note']) . '</small>' : '' ?></p><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="col-lg-4">
    <?php if ($canManage && $next): ?>
    <div class="panel mb-3"><div class="panel-title"><h5>Update order</h5></div>
      <form method="post"><?= crm_csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
        <label class="form-label">Move to</label>
        <select class="form-select mb-2" name="to" required><?php foreach ($next as $s): ?><option value="<?= $s ?>"><?= crm_e(WC_STATUSES[$s] ?? $s) ?></option><?php endforeach; ?></select>
        <div class="row g-2 mb-2"><div class="col-6"><label class="form-label">Courier</label><input class="form-control" name="courier" maxlength="80" value="<?= crm_e($o['courier']) ?>" placeholder="Delhivery"></div>
          <div class="col-6"><label class="form-label">Tracking no.</label><input class="form-control" name="tracking_no" maxlength="80" value="<?= crm_e($o['tracking_no']) ?>"></div>
          <div class="col-12"><label class="form-label">Tracking link (optional)</label><input class="form-control" name="tracking_url" maxlength="255" value="<?= crm_e($o['tracking_url']) ?>" placeholder="https://..."></div></div>
        <label class="form-label">Note / reason (sent to customer for cancel, return)</label>
        <textarea class="form-control mb-2" name="note" rows="2" maxlength="400"></textarea>
        <?php if (in_array('returned', $next, true)): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="restock" id="rs" value="1" checked><label class="form-check-label" for="rs">Put returned items back in stock</label></div><?php endif; ?>
        <button class="btn btn-gold w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>Update &amp; email customer</button>
        <div class="form-text">Courier or tracking number is required for <b>Shipped</b>.</div>
      </form></div>
    <?php elseif ($canManage && in_array($o['status'], ['shipped', 'out_for_delivery'], true) === false): ?>
    <?php endif; ?>
    <?php if ($canManage && ($o['courier'] !== '' || $o['tracking_no'] !== '') && !$next): ?>
    <?php endif; ?>
    <div class="panel"><div class="panel-title"><h5>History</h5></div>
      <?php foreach ($hist as $h): ?>
        <div class="mb-2 pb-2 border-bottom"><?= $badge($tone[$h['status']] ?? 'gray', WC_STATUSES[$h['status']] ?? $h['status']) ?> <small class="text-muted"><?= crm_e(date('j M, g:i a', strtotime((string)$h['created_at']))) ?></small>
          <?php if ($h['note'] !== ''): ?><br><small><?= crm_e($h['note']) ?></small><?php endif; ?><br><small class="text-muted">by <?= crm_e($h['by_name'] ?: 'System') ?></small></div>
      <?php endforeach; ?>
      <?php if ($o['tracking_no'] !== ''): ?><small class="text-muted">Tracking: <?= crm_e($o['courier']) ?> <?= crm_e($o['tracking_no']) ?></small><?php endif; ?>
    </div>
  </div>
</div>
<?php crm_page_end(); exit; }

/* ------------------------------------------------------------ list view */
$sf = isset(WC_STATUSES[$_GET['status'] ?? '']) ? (string)$_GET['status'] : '';
$rows = wc_all('SELECT o.*, (SELECT COUNT(*) FROM web_order_items i WHERE i.order_id = o.id) AS items FROM web_orders o ' . ($sf !== '' ? 'WHERE o.status = ? ' : '') . 'ORDER BY o.id DESC LIMIT 5000', $sf !== '' ? 's' : '', $sf !== '' ? [$sf] : []);
$counts = [];
foreach (wc_all('SELECT status, COUNT(*) c FROM web_orders GROUP BY status') as $c) {
    $counts[$c['status']] = (int)$c['c'];
}
crm_page_start('Website orders');
crm_page_head('Website orders', 'Orders placed on your online store. Each confirmed order also creates an invoice in Billing.');
echo crm_flash_html();
echo '<div class="d-flex flex-wrap gap-1 mb-3"><a class="btn btn-sm ' . ($sf === '' ? 'btn-primary' : 'btn-outline-primary') . '" href="web-orders.php">All</a>';
foreach (['placed', 'processing', 'shipped', 'out_for_delivery', 'delivered', 'return_requested', 'cancelled', 'payment_failed', 'pending_payment', 'refunded'] as $s) {
    if (!empty($counts[$s]) || $sf === $s) {
        echo '<a class="btn btn-sm ' . ($sf === $s ? 'btn-primary' : 'btn-outline-primary') . '" href="web-orders.php?status=' . $s . '">' . crm_e(WC_STATUSES[$s]) . ' <span class="badge bg-light text-dark">' . (int)($counts[$s] ?? 0) . '</span></a>';
    }
}
echo '</div>'; ?>
<div class="panel"><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table>
  <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Items</th><th>Total</th><th data-filter="Payment">Payment</th><th data-filter="Status">Status</th><th class="act">View</th></tr></thead><tbody>
  <?php foreach ($rows as $o): ?>
    <tr><td><b>#<?= crm_e($o['order_no']) ?></b></td>
      <td data-sort="<?= crm_e($o['created_at']) ?>"><?= crm_e(date('j M Y', strtotime((string)$o['created_at']))) ?><br><small class="text-muted"><?= crm_e(date('g:i a', strtotime((string)$o['created_at']))) ?></small></td>
      <td><?= crm_e($o['ship_name']) ?><br><small class="text-muted"><?= crm_e($o['ship_mobile']) ?> &middot; <?= crm_e($o['ship_city']) ?></small></td>
      <td><?= (int)$o['items'] ?></td><td><b><?= wc_money($o['total']) ?></b></td>
      <td data-value="<?= crm_e(($o['payment_method'] === 'cod' ? 'COD ' : 'Online ') . ucfirst($o['payment_status'])) ?>"><?= $o['payment_method'] === 'cod' ? 'COD' : 'Online' ?> <?= $badge($payTone[$o['payment_status']] ?? 'gray', ucfirst($o['payment_status'])) ?></td>
      <td data-value="<?= crm_e(WC_STATUSES[$o['status']] ?? $o['status']) ?>"><?= $badge($tone[$o['status']] ?? 'gray', WC_STATUSES[$o['status']] ?? $o['status']) ?></td>
      <td class="act"><a class="btn btn-sm btn-icon" href="web-orders.php?id=<?= (int)$o['id'] ?>" title="Open order"><i class="bi bi-eye"></i></a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php crm_page_end(); ?>
