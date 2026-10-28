<?php
/* web-customers.php - website customers: accounts, orders, purchases, activity, newsletter */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $id = (int)($_POST['id'] ?? 0);
    if (!crm_can('web.orders.manage')) {
        crm_flash('danger', 'You do not have permission to change customers.');
    } elseif (($_POST['do'] ?? '') === 'block') {
        $to = ($_POST['to'] ?? '') === 'blocked' ? 'blocked' : 'active';
        wc_run('UPDATE web_users SET status = ? WHERE id = ?', 'si', [$to, $id]);
        crm_log('website', $to === 'blocked' ? 'block_customer' : 'unblock_customer', '#' . $id);
        crm_flash('success', $to === 'blocked' ? 'Customer blocked. They can no longer log in.' : 'Customer unblocked.');
    }
    header('Location: web-customers.php?id=' . $id);
    exit;
}

if (isset($_GET['id'])) {
    $u = wc_one('SELECT * FROM web_users WHERE id = ?', 'i', [(int)$_GET['id']]);
    if (!$u) {
        header('Location: web-customers.php');
        exit;
    }
    $orders = wc_all('SELECT * FROM web_orders WHERE web_user_id = ? ORDER BY id DESC', 'i', [(int)$u['id']]);
    $addr   = wc_all('SELECT * FROM web_addresses WHERE web_user_id = ? ORDER BY is_default DESC, id', 'i', [(int)$u['id']]);
    $wish   = wc_all('SELECT p.id, p.name, p.price FROM web_wishlist w JOIN products p ON p.id = w.product_id WHERE w.web_user_id = ? ORDER BY w.id DESC', 'i', [(int)$u['id']]);
    $act    = wc_all('SELECT * FROM web_activity WHERE web_user_id = ? ORDER BY id DESC LIMIT 60', 'i', [(int)$u['id']]);
    $spent  = 0.0;
    foreach ($orders as $o) {
        if (in_array($o['status'], ['placed', 'processing', 'shipped', 'out_for_delivery', 'delivered', 'return_requested', 'return_approved'], true)) {
            $spent += (float)$o['total'];
        }
    }
    crm_page_start($u['name']);
    crm_page_head($u['name'], 'Website customer since ' . date('j M Y', strtotime((string)$u['created_at'])), '<a class="btn btn-light border" href="web-customers.php"><i class="bi bi-arrow-left me-1"></i>All customers</a>');
    echo crm_flash_html(); ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel mb-3"><div class="panel-title"><h5>Account</h5><span class="badge-s b-<?= $u['status'] === 'active' ? 'green' : 'red' ?>"><?= crm_e(ucfirst($u['status'])) ?></span></div>
      <?= crm_e($u['email']) ?> <?= $u['email_verified_at'] ? '<i class="bi bi-patch-check-fill text-success" title="Email verified"></i>' : '<span class="badge-s b-amber">Not verified</span>' ?><br>
      Mobile: <?= crm_e($u['mobile'] ?: '-') ?><br>Last login: <?= $u['last_login_at'] ? crm_e(date('j M Y, g:i a', strtotime((string)$u['last_login_at']))) : 'Never' ?> <small class="text-muted"><?= crm_e($u['last_login_ip']) ?></small><br>
      Orders: <b><?= count($orders) ?></b> &middot; Purchases: <b><?= wc_money($spent) ?></b><br>Wishlist items: <b><?= count($wish) ?></b>
      <?php if ($u['customer_id'] && crm_can('customers.view')): ?><br><a href="customers.php?q=<?= urlencode($u['mobile']) ?>">Open in CRM customers</a><?php endif; ?>
      <?php if (crm_can('web.orders.manage')): ?>
        <form method="post" class="mt-3" data-confirm="<?= $u['status'] === 'active' ? 'Block this customer?' : 'Unblock this customer?' ?>"><?= crm_csrf_field() ?><input type="hidden" name="do" value="block"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="to" value="<?= $u['status'] === 'active' ? 'blocked' : 'active' ?>">
          <button class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-success' ?>"><?= $u['status'] === 'active' ? 'Block customer' : 'Unblock customer' ?></button></form>
      <?php endif; ?></div>
    <div class="panel"><div class="panel-title"><h5>Saved addresses</h5></div>
      <?php foreach ($addr as $a): ?><div class="mb-2 small"><b><?= crm_e($a['label']) ?></b><?= $a['is_default'] ? ' <span class="badge-s b-gold">Default</span>' : '' ?><br><?= crm_e($a['name']) ?>, <?= crm_e($a['line1']) ?>, <?= crm_e($a['city']) ?>, <?= crm_e($a['state']) ?> - <?= crm_e($a['pin']) ?></div><?php endforeach; ?>
      <?php if (!$addr): ?><span class="text-muted small">No saved address.</span><?php endif; ?></div>
  </div>
  <div class="col-lg-8">
    <div class="panel mb-3"><div class="panel-title"><h5>Orders &amp; payments</h5></div><div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Order</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Invoice</th></tr></thead><tbody>
      <?php foreach ($orders as $o): ?><tr><td><a href="web-orders.php?id=<?= (int)$o['id'] ?>">#<?= crm_e($o['order_no']) ?></a></td><td><?= crm_e(date('j M Y', strtotime((string)$o['created_at']))) ?></td><td><b><?= wc_money($o['total']) ?></b></td>
        <td><?= $o['payment_method'] === 'cod' ? 'COD' : 'Online' ?> / <?= crm_e($o['payment_status']) ?></td><td><?= crm_e(WC_STATUSES[$o['status']] ?? $o['status']) ?></td><td><?= crm_e($o['invoice_no'] ?: '-') ?></td></tr><?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="6" class="text-muted text-center">No orders yet.</td></tr><?php endif; ?></tbody></table></div></div>
    <div class="row g-3"><div class="col-md-6"><div class="panel h-100"><div class="panel-title"><h5>Wishlist</h5></div>
      <?php foreach ($wish as $w): ?><div class="small mb-1"><?= crm_e($w['name']) ?> <span class="text-muted"><?= wc_money($w['price']) ?></span></div><?php endforeach; ?><?php if (!$wish): ?><span class="text-muted small">Empty.</span><?php endif; ?></div></div>
    <div class="col-md-6"><div class="panel h-100"><div class="panel-title"><h5>Recent activity</h5></div><div style="max-height:260px;overflow:auto">
      <?php foreach ($act as $a): ?><div class="small mb-1"><b><?= crm_e(str_replace('_', ' ', $a['action'])) ?></b> <span class="text-muted"><?= crm_e($a['detail']) ?></span><br><small class="text-muted"><?= crm_e(date('j M, g:i a', strtotime((string)$a['created_at']))) ?></small></div><?php endforeach; ?>
      <?php if (!$act): ?><span class="text-muted small">Nothing yet.</span><?php endif; ?></div></div></div></div>
  </div>
</div>
<?php crm_page_end(); exit; }

$tab = ($_GET['tab'] ?? '') === 'subscribers' ? 'subscribers' : 'customers';
crm_page_start('Website customers');
crm_page_head('Website customers', 'People who created an account on your online store.');
echo '<ul class="nav nav-pills mb-3"><li class="nav-item"><a class="nav-link py-1 ' . ($tab === 'customers' ? 'active' : '') . '" href="web-customers.php">Accounts</a></li><li class="nav-item"><a class="nav-link py-1 ' . ($tab === 'subscribers' ? 'active' : '') . '" href="web-customers.php?tab=subscribers">Newsletter subscribers</a></li></ul>';
if ($tab === 'subscribers') {
    $rows = wc_all('SELECT * FROM web_subscribers ORDER BY id DESC LIMIT 5000'); ?>
<div class="panel"><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table><thead><tr><th>Email</th><th>Subscribed on</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= crm_e($r['email']) ?></td><td data-sort="<?= crm_e($r['created_at']) ?>"><?= crm_e(date('j M Y, g:i a', strtotime((string)$r['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php } else {
    $rows = wc_all("SELECT u.*, (SELECT COUNT(*) FROM web_orders o WHERE o.web_user_id = u.id AND o.status NOT IN ('pending_payment','payment_failed','cancelled')) AS orders,
                           (SELECT COALESCE(SUM(o.total),0) FROM web_orders o WHERE o.web_user_id = u.id AND o.status IN ('placed','processing','shipped','out_for_delivery','delivered')) AS spent
                    FROM web_users u ORDER BY u.id DESC LIMIT 5000"); ?>
<div class="panel"><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table>
  <thead><tr><th>Customer</th><th>Mobile</th><th data-filter="Email">Email verified</th><th>Orders</th><th>Purchases</th><th>Last login</th><th data-filter="Status">Status</th><th>Joined</th><th class="act">View</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><b><?= crm_e($r['name']) ?></b><br><small class="text-muted"><?= crm_e($r['email']) ?></small></td><td><?= crm_e($r['mobile'] ?: '-') ?></td>
      <td data-value="<?= $r['email_verified_at'] ? 'Verified' : 'Not verified' ?>"><?= $r['email_verified_at'] ? '<span class="badge-s b-green">Verified</span>' : '<span class="badge-s b-amber">Not verified</span>' ?></td>
      <td><?= (int)$r['orders'] ?></td><td><?= wc_money($r['spent']) ?></td><td data-sort="<?= crm_e((string)$r['last_login_at']) ?>"><?= $r['last_login_at'] ? crm_e(date('j M Y', strtotime((string)$r['last_login_at']))) : '-' ?></td>
      <td data-value="<?= crm_e(ucfirst($r['status'])) ?>"><span class="badge-s b-<?= $r['status'] === 'active' ? 'green' : 'red' ?>"><?= crm_e(ucfirst($r['status'])) ?></span></td>
      <td data-sort="<?= crm_e($r['created_at']) ?>"><?= crm_e(date('j M Y', strtotime((string)$r['created_at']))) ?></td>
      <td class="act"><a class="btn btn-sm btn-icon" href="web-customers.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-eye"></i></a></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php }
crm_page_end(); ?>
