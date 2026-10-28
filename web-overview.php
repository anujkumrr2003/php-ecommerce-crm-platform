<?php
/* web-overview.php - one look at the online store */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

$k = wc_one("SELECT
   COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND status NOT IN ('pending_payment','payment_failed','cancelled') THEN 1 END),0) AS today_orders,
   COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND status NOT IN ('pending_payment','payment_failed','cancelled') THEN total END),0) AS today_sales,
   COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND status NOT IN ('pending_payment','payment_failed','cancelled','refunded') THEN total END),0) AS sales30,
   COALESCE(SUM(CASE WHEN status IN ('placed','processing') THEN 1 END),0) AS to_ship,
   COALESCE(SUM(CASE WHEN status = 'return_requested' THEN 1 END),0) AS returns,
   COALESCE(SUM(CASE WHEN status = 'payment_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END),0) AS failed
   FROM web_orders");
$cust = (int)wc_one("SELECT COUNT(*) c FROM web_users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['c'];
$rev  = (int)wc_one("SELECT COUNT(*) c FROM web_reviews WHERE status = 'pending'")['c'];
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-$i day"))] = 0.0;
}
foreach (wc_all("SELECT DATE(created_at) d, SUM(total) s FROM web_orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND status NOT IN ('pending_payment','payment_failed','cancelled','refunded') GROUP BY d") as $r) {
    $days[$r['d']] = (float)$r['s'];
}
$recent = wc_all('SELECT * FROM web_orders ORDER BY id DESC LIMIT 8');
$site = wc_site_url();
$cards = [['Orders today', (string)(int)$k['today_orders'], 'bag-check', 'web-orders.php'], ['Sales today', wc_money($k['today_sales']), 'currency-rupee', 'web-orders.php'], ['Sales (30 days)', wc_money($k['sales30']), 'graph-up-arrow', 'web-orders.php'],
    ['To pack & ship', (string)(int)$k['to_ship'], 'box-seam', 'web-orders.php?status=placed'], ['Return requests', (string)(int)$k['returns'], 'arrow-return-left', 'web-orders.php?status=return_requested'],
    ['Failed payments (7 d)', (string)(int)$k['failed'], 'x-octagon', 'web-orders.php?status=payment_failed'], ['New customers (7 d)', (string)$cust, 'person-plus', 'web-customers.php'], ['Reviews to approve', (string)$rev, 'star', 'web-reviews.php']];
crm_page_start('Website overview');
crm_page_head('Website overview', 'How your online store is doing.', ($site !== '' ? '<a class="btn btn-outline-primary" target="_blank" href="' . crm_e($site) . '"><i class="bi bi-box-arrow-up-right me-1"></i>Open website</a>' : '<a class="btn btn-outline-danger" href="web-settings.php">Set your website address</a>'));
if (wc_setting('web_rzp_enabled') !== '1' && wc_setting('web_cod_enabled') !== '1') {
    echo '<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>No payment method is switched on. Customers cannot check out. <a href="web-settings.php#payments">Open payment settings</a></div>';
}
echo '<div class="row g-3 mb-3">';
foreach ($cards as $c) {
    echo '<div class="col-6 col-lg-3"><a class="panel py-2 d-block text-decoration-none" href="' . $c[3] . '"><small class="text-muted"><i class="bi bi-' . $c[2] . ' me-1"></i>' . $c[0] . '</small><div class="fs-5 fw-semibold text-dark">' . $c[1] . '</div></a></div>';
}
echo '</div>'; ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="panel"><div class="panel-title"><h5>Sales - last 14 days</h5></div><div class="chart-box"><canvas id="chWeb"></canvas></div></div></div>
  <div class="col-lg-5"><div class="panel"><div class="panel-title"><h5>Latest orders</h5><a href="web-orders.php">All orders</a></div>
    <?php foreach ($recent as $o): ?><div class="d-flex justify-content-between border-bottom py-1 small"><span><a href="web-orders.php?id=<?= (int)$o['id'] ?>">#<?= crm_e($o['order_no']) ?></a> <?= crm_e($o['ship_name']) ?></span><span><b><?= wc_money($o['total']) ?></b> <span class="text-muted"><?= crm_e(WC_STATUSES[$o['status']] ?? $o['status']) ?></span></span></div><?php endforeach; ?>
    <?php if (!$recent): ?><span class="text-muted small">No orders yet.</span><?php endif; ?></div></div>
</div>
<?php crm_page_end('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script><script>(function(){if(!window.Chart)return;var d=' . json_encode(array_values($days)) . ',l=' . json_encode(array_map(fn($x) => date('j M', strtotime($x)), array_keys($days))) . ';Chart.defaults.font.size=11;new Chart(document.getElementById("chWeb"),{type:"bar",data:{labels:l,datasets:[{data:d,backgroundColor:"#0B1F3A",borderRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}}}});})();</script>'); ?>
