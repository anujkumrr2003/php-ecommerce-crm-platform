<?php
/* product.php - product detail: gallery, variants (size / colour), price, stock, reviews, similar products */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/layout.php';

$id = (int)($_GET['id'] ?? 0);
$p  = $id > 0 ? product_get($id) : null;
if (!$p) {
    not_found('This product is not available any more.');
}

$slugUrl = product_url($p);

$cur = rtrim(
    (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
    '/'
);

$slugPath = rtrim(
    (string) parse_url($slugUrl, PHP_URL_PATH),
    '/'
);

if ($cur !== '' && $cur !== $slugPath && !isset($_POST['_csrf'])) {
    redirect($slugUrl, 301);
}

// $slugUrl = product_url($p);
// $cur = rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
// if ($cur !== '' && $cur !== $slugUrl && !isset($_POST['_csrf'])) {                // old / wrong slug -> permanent redirect to the right address
//     redirect($slugUrl . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), 301);
// }

/* ---------- review submit ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $u = require_login();
    $rating = (int)($_POST['rating'] ?? 0);
    $body = post('body', 1500);
    if ($rating < 1 || $rating > 5) {
        flash('danger', 'Please choose a star rating.');
    } elseif (mb_strlen($body) < 5) {
        flash('danger', 'Please write a few words about the product.');
    } elseif (!wc_throttle('review:' . $u['id'], 5, 3600)) {
        flash('danger', 'Too many reviews. Please try later.');
    } else {
        $bought = wc_one("SELECT 1 AS x FROM web_order_items i JOIN web_orders o ON o.id = i.order_id WHERE o.web_user_id = ? AND i.product_id = ? AND o.status IN ('delivered','shipped','out_for_delivery','placed','processing')", 'ii', [(int)$u['id'], $id]) ? 1 : 0;
        try {
            wc_run("INSERT INTO web_reviews (product_id, web_user_id, name, rating, title, body, verified_buyer, status) VALUES (?,?,?,?,?,?,?,'pending')", 'iisissi', [$id, (int)$u['id'], $u['name'], $rating, post('title', 120), $body, $bought]);
            wc_log_activity((int)$u['id'], 'review', 'product ' . $id);
            wc_crm_notify('web_review', 'New review to approve', $u['name'] . ' rated ' . $p['name'] . ' ' . $rating . '/5', 'web-reviews.php', 'star', 'amber', 'web.marketing.manage', 'web_review:' . $id . ':' . $u['id']);
            flash('success', 'Thank you! Your review will appear after we approve it.');
        } catch (mysqli_sql_exception $ex) {
            flash('info', 'You have already reviewed this product.');
        }
    }
    redirect(ltrim($slugUrl, '/') ? substr($slugUrl, strlen(BASE_PATH) + 1) . '#reviews' : '');
}

$variants = product_variants($id);
$images   = product_images($id);
$pi       = price_info($p);
$reviews  = product_reviews($id);
$rb       = rating_breakdown($id);
$wish     = in_array($id, wishlist_ids(), true);
[$similar] = products_list(['category' => $p['category'], 'exclude' => $id, 'per' => 4]);
if (count($similar) < 4) {
    [$more] = products_list(['exclude' => $id, 'per' => 8, 'sort' => 'popular']);
    $have = array_column($similar, 'id');
    foreach ($more as $m) {
        if (count($similar) >= 4) {
            break;
        }
        if (!in_array($m['id'], $have, true)) {
            $similar[] = $m;
        }
    }
}
$hasOpt = [(bool)array_filter(array_column($variants, 'size')), (bool)array_filter(array_column($variants, 'color'))];
$desc = $p['short_description'] ?: mb_substr(trim(strip_tags((string)$p['description'])), 0, 160);
$inStock = (int)$p['stock'] > 0;
$ld = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['name'], 'sku' => $p['sku'], 'category' => $p['category'], 'description' => $desc ?: $p['name'],
    'image' => array_map(fn($f) => img($f), $images), 'brand' => $p['brand'] !== '' ? ['@type' => 'Brand', 'name' => $p['brand']] : null,
    'offers' => ['@type' => 'Offer', 'priceCurrency' => 'INR', 'price' => number_format($pi['price'], 2, '.', ''), 'availability' => 'https://schema.org/' . ($inStock ? 'InStock' : 'OutOfStock'), 'url' => abs_url(substr($slugUrl, strlen(BASE_PATH)))]];
if ((int)$p['rating_count'] > 0) {
    $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $p['rating'], 'reviewCount' => (int)$p['rating_count']];
}
$crumbLd = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => abs_url('')], ['@type' => 'ListItem', 'position' => 2, 'name' => $p['category'], 'item' => abs_url('shop')], ['@type' => 'ListItem', 'position' => 3, 'name' => $p['name']]]];
$catRow = null;
foreach (categories_all() as $c) {
    if ($c['name'] === $p['category']) {
        $catRow = $c;
    }
}
wc_log_activity(user() ? (int)user()['id'] : null, 'view_product', (string)$id);

page_start(['title' => $p['meta_title'] ?: $p['name'], 'description' => $p['meta_description'] ?: $desc, 'image' => $images ? img($images[0]) : '', 'og_type' => 'product', 'class' => 'pdp-page',
    'canonical' => abs_url(substr($slugUrl, strlen(BASE_PATH))), 'jsonld' => [json_encode(array_filter($ld), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), json_encode($crumbLd, JSON_UNESCAPED_SLASHES)]]);
echo breadcrumb(['Shop' => url('shop'), $p['category'] => $catRow ? url('category/' . $catRow['slug']) : url('shop'), $p['name'] => '']);
echo '<div class="container">' . flash_html() . '</div>';
?>
<div class="container pdp">
  <div class="gallery">
    <div class="thumbs"><?php foreach ($images as $i => $f): ?><button type="button" class="<?= $i === 0 ? 'on' : '' ?>" data-src="<?= e(img($f)) ?>" aria-label="Image <?= $i + 1 ?>"><img src="<?= e(img($f)) ?>" alt="" loading="lazy"></button><?php endforeach; ?></div>
    <div class="main-img"><?php if ($images): ?><img id="main-img" src="<?= e(img($images[0])) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><div class="noimg"><span><?= e(mb_substr($p['name'], 0, 1)) ?></span></div><?php endif; ?></div>
  </div>
  <div class="pdp-info">
    <div class="cat"><a href="<?= e($catRow ? url('category/' . $catRow['slug']) : url('shop')) ?>"><?= e($p['category']) ?></a><?= $p['brand'] !== '' ? ' &middot; ' . e($p['brand']) : '' ?></div>
    <h1><?= e($p['name']) ?></h1>
    <?php if ((int)$p['rating_count'] > 0): ?><a href="#reviews" class="rate"><?= stars((float)$p['rating'], true) ?> <small><?= e($p['rating']) ?> (<?= (int)$p['rating_count'] ?> reviews)</small></a><?php endif; ?>
    <div class="pdp-price" id="pdp-price"><b><?= wc_money($pi['price']) ?></b><?php if ($pi['mrp'] > 0): ?> <s><?= wc_money($pi['mrp']) ?></s> <span class="off"><?= $pi['off'] ?>% OFF</span><?php endif; ?></div>
    <div class="small muted"><?= S('web_prices_include_gst', '1') === '1' ? 'Inclusive of all taxes.' : 'GST extra as applicable.' ?></div>
    <?php if ($p['short_description'] !== ''): ?><p style="margin-top:12px"><?= e($p['short_description']) ?></p><?php endif; ?>
    <?php if ($hasOpt[0]): ?><div class="opt"><div class="opt-label"><?= e(S('variant_label_1', 'Size')) ?></div><div class="opts" data-opt="size"><?php foreach (array_unique(array_filter(array_column($variants, 'size'))) as $s): ?><button type="button" data-v="<?= e($s) ?>"><?= e($s) ?></button><?php endforeach; ?></div></div><?php endif; ?>
    <?php if ($hasOpt[1]): ?><div class="opt"><div class="opt-label"><?= e(S('variant_label_2', 'Color')) ?></div><div class="opts" data-opt="color"><?php foreach (array_unique(array_filter(array_column($variants, 'color'))) as $s): ?><button type="button" data-v="<?= e($s) ?>"><?= e($s) ?></button><?php endforeach; ?></div></div><?php endif; ?>
    <div class="stock" id="stock"></div>
    <div class="opt"><div class="opt-label">Quantity</div><div class="qty"><button type="button" id="q-minus" aria-label="Decrease">&minus;</button><span id="q-val">1</span><button type="button" id="q-plus" aria-label="Increase">+</button></div></div>
    <div class="buy"><button class="btn btn-primary" data-add="picker" <?= $inStock ? '' : 'disabled' ?>><i class="bi bi-bag"></i> Add to bag</button><button class="btn btn-accent" data-add="picker" data-buynow <?= $inStock ? '' : 'disabled' ?>>Buy now</button>
      <button class="btn btn-ghost" data-wish="<?= $id ?>" data-label="1" aria-pressed="<?= $wish ? 'true' : 'false' ?>"><i class="bi bi-heart<?= $wish ? '-fill' : '' ?>"></i> <?= $wish ? 'Saved' : 'Add to wishlist' ?></button></div>
    <div class="perks"><div><i class="bi bi-truck"></i>Delivery in 3-7 working days</div><div><i class="bi bi-arrow-repeat"></i><?= (int)S('web_return_days', '7') > 0 ? (int)S('web_return_days', '7') . '-day easy returns' : 'Please check our return policy' ?></div><div><i class="bi bi-shield-check"></i>100% secure checkout</div></div>
    <div class="pdp-meta">
      <details open><summary>Description</summary><div><?= $p['description'] !== '' ? nl2br(e($p['description'])) : '<span class="muted">Details coming soon.</span>' ?></div></details>
      <details><summary>Product details</summary><div><table class="table"><tbody><tr><td>SKU</td><td id="sku"><?= e($p['sku']) ?></td></tr><?php if ($p['brand'] !== ''): ?><tr><td>Brand</td><td><?= e($p['brand']) ?></td></tr><?php endif; ?>
        <?php if ($p['fabric'] !== ''): ?><tr><td><?= e(S('product_material_label', 'Material')) ?></td><td><?= e($p['fabric']) ?></td></tr><?php endif; ?><tr><td>Category</td><td><?= e($p['category']) ?></td></tr></tbody></table></div></details>
      <details><summary>Shipping &amp; returns</summary><div>Orders are packed in 1-2 working days. <?= (int)S('web_ship_free_above', '0') > 0 ? 'Free shipping above ' . wc_money(S('web_ship_free_above')) . '.' : '' ?> <a href="<?= e(url('page/return-policy')) ?>">Read return policy</a>.</div></details>
    </div>
  </div>
</div>
<script type="application/json" id="pdp-data"><?= json_encode(['variants' => array_map(fn($v) => ['id' => (int)$v['id'], 'size' => $v['size'], 'color' => $v['color'], 'sku' => $v['sku'], 'stock' => (int)$v['stock'], 'price' => (float)$v['price']], $variants), 'mrp' => (float)$p['mrp'], 'sku' => $p['sku']], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<section class="section alt" id="reviews"><div class="container">
  <div class="sec-head"><h2>Customer reviews</h2></div>
  <div class="row2" style="gap:50px;align-items:start">
    <div>
      <?php if ((int)$p['rating_count'] > 0): ?>
        <div style="font-size:46px;font-family:var(--font-head)"><?= e($p['rating']) ?> <small style="font-size:16px" class="muted">out of 5</small></div><?= stars((float)$p['rating'], true) ?>
        <div class="bars" style="margin-top:12px"><?php foreach ($rb as $s => $c): ?><div class="bar"><span><?= $s ?>&#9733;</span><i><em style="width:<?= (int)$p['rating_count'] ? round($c * 100 / (int)$p['rating_count']) : 0 ?>%"></em></i><span><?= $c ?></span></div><?php endforeach; ?></div>
      <?php else: ?><p class="muted">No reviews yet. Be the first to review this product.</p><?php endif; ?>
      <?php foreach ($reviews as $r): ?><div class="review"><?= stars((float)$r['rating']) ?> <b><?= e($r['title']) ?></b><p style="margin:4px 0"><?= e($r['body']) ?></p><small class="muted"><?= e($r['name']) ?><?= $r['verified_buyer'] ? ' &middot; <span class="green"><i class="bi bi-patch-check-fill"></i> Verified buyer</span>' : '' ?> &middot; <?= e(date('j M Y', strtotime((string)$r['created_at']))) ?></small></div><?php endforeach; ?>
    </div>
    <div class="box"><h3>Write a review</h3>
      <?php if (user()): ?>
      <form method="post" action="<?= e($slugUrl) ?>"><?= csrf_field() ?>
        <div class="field"><label>Your rating</label><div class="star-input"><?php for ($s = 5; $s >= 1; $s--): ?><input type="radio" name="rating" id="st<?= $s ?>" value="<?= $s ?>"><label for="st<?= $s ?>" title="<?= $s ?> stars">&#9733;</label><?php endfor; ?></div></div>
        <div class="field"><label for="rt">Title</label><input id="rt" name="title" maxlength="120"></div>
        <div class="field"><label for="rb">Your review</label><textarea id="rb" name="body" rows="4" maxlength="1500" required></textarea></div>
        <button class="btn btn-primary" type="submit">Submit review</button>
      </form>
      <?php else: ?><p>Please <a href="<?= e(url('login')) ?>"><b>log in</b></a> to write a review.</p><?php endif; ?>
    </div>
  </div>
</div></section>

<?php if ($similar): ?><section class="section"><div class="container"><div class="sec-head"><h2>You may also like</h2><p>Similar pieces picked for you</p></div><div class="grid-p"><?php foreach ($similar as $s) { echo product_card($s); } ?></div></div></section><?php endif; ?>
<?php page_end();
