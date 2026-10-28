<?php
/* cart.php - shopping bag page */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/layout.php';

$p = cart_priced();
page_start(['title' => 'Shopping bag', 'robots' => 'noindex']);
echo breadcrumb(['Shopping bag' => '']);
?>
<div class="container page-pad">
  <h1>Shopping bag</h1>
  <?= $p['coupon_notice'] !== '' ? '<div class="alert alert-warning">' . e($p['coupon_notice']) . '</div>' : '' ?>
  <div class="cart-wrap">
    <div id="cart-page"><?= cart_html($p, true) ?></div>
    <aside class="summary" id="cart-summary"><?= $p['lines'] ? cart_summary_html($p) : '<h3>Order summary</h3><p class="muted">Your bag is empty.</p>' ?></aside>
  </div>
</div>
<?php page_end();
