<?php
/* inc/layout.php - header, footer, product card and other page pieces shared by every page */

function stars(float $r, bool $big = false): string
{
    $o = '<span class="stars' . ($big ? ' stars-lg' : '') . '" aria-label="' . e($r) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $o .= '<i class="bi bi-star' . ($r >= $i ? '-fill' : ($r >= $i - 0.5 ? '-half' : '')) . '"></i>';
    }
    return $o . '</span>';
}

function footer_pages(): array
{
    static $p = null;
    return $p ??= wc_all('SELECT slug, title FROM web_pages WHERE is_active = 1 AND show_in_footer = 1 ORDER BY sort_order, id');
}

/** text of a CMS page with {placeholders} replaced */
function page_text(string $html): string
{
    $b = wc_brand();
    return strtr($html, ['{store_name}' => e($b['name']), '{store_email}' => e($b['email']), '{store_phone}' => e($b['phone']), '{store_address}' => e($b['addr']), '{return_days}' => e(S('web_return_days', '7'))]);
}

function google_fonts_url(): string
{
    $h = S('web_font_heading', 'Cormorant Garamond');
    $b = S('web_font_body', 'Jost');
    return 'https://fonts.googleapis.com/css2?family=' . str_replace(' ', '+', $h) . ':wght@400;500;600;700&family=' . str_replace(' ', '+', $b) . ':wght@300;400;500;600&display=swap';
}

/**
 * Start a page.  $m: title, description, canonical, image, robots ('noindex'), jsonld (array of JSON strings), class
 */
function page_start(array $m = []): void
{
    $brand = wc_brand();
    $title = ($m['title'] ?? '') !== '' ? $m['title'] . ' | ' . $brand['name'] : (S('web_meta_title') ?: $brand['name'] . ' - ' . S('web_tagline', 'Online Store'));
    $desc  = $m['description'] ?? (S('web_meta_description') ?: S('web_tagline'));
    $canon = $m['canonical'] ?? abs_url(ltrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/'));
    if (BASE_PATH !== '' && strpos($canon, BASE_PATH . BASE_PATH) !== false) {
        $canon = str_replace(BASE_PATH . BASE_PATH, BASE_PATH, $canon);
    }
    $ogimg = $m['image'] ?? (S('web_og_image') !== '' ? img(S('web_og_image'), 'web') : ($brand['logo'] ?? ''));
    $fav   = S('web_favicon') !== '' ? img(S('web_favicon'), 'web') : '';
    $user  = user();
    $wc    = count(wishlist_ids());
    $cc    = cart_count();
    $css   = ':root{--primary:' . e(S('web_color_primary', '#1b1b1f')) . ';--accent:' . e(S('web_color_accent', '#b8935a')) . ';--bg:' . e(S('web_color_bg', '#faf7f2')) . ';--text:' . e(S('web_color_text', '#2a2a2e')) . ';--footer:' . e(S('web_color_footer', '#141417'))
        . ';--font-head:"' . e(S('web_font_heading', 'Cormorant Garamond')) . '",Georgia,serif;--font-body:"' . e(S('web_font_body', 'Jost')) . '",system-ui,sans-serif}';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title><meta name="description" content="' . e(mb_substr($desc, 0, 300)) . '"><link rel="canonical" href="' . e($canon) . '">'
        . (($m['robots'] ?? '') !== '' ? '<meta name="robots" content="' . e($m['robots']) . '">' : '')
        . '<meta property="og:type" content="' . e($m['og_type'] ?? 'website') . '"><meta property="og:title" content="' . e($m['title'] ?? $brand['name']) . '"><meta property="og:description" content="' . e(mb_substr($desc, 0, 200)) . '"><meta property="og:url" content="' . e($canon) . '"><meta property="og:site_name" content="' . e($brand['name']) . '">'
        . ($ogimg !== '' ? '<meta property="og:image" content="' . e($ogimg) . '">' : '')
        . '<meta name="theme-color" content="' . e(S('web_color_primary', '#1b1b1f')) . '"><meta name="csrf" content="' . e(csrf()) . '"><meta name="base" content="' . e(BASE_PATH) . '">'
        . ($fav !== '' ? '<link rel="icon" href="' . e($fav) . '">' : '')
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="' . e(google_fonts_url()) . '" rel="stylesheet">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
        . '<style>' . $css . '</style><link href="' . e(asset('assets/css/site.css')) . '" rel="stylesheet">';
    foreach ($m['jsonld'] ?? [] as $j) {
        echo '<script type="application/ld+json">' . $j . '</script>';
    }
    if (preg_match('/^G-[A-Z0-9]+$/', S('web_analytics_id'))) {
        echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e(S('web_analytics_id')) . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config","' . e(S('web_analytics_id')) . '");</script>';
    }
    echo '</head><body class="' . e($m['class'] ?? '') . '">';
    if (S('web_announcement_on', '1') === '1' && S('web_announcement') !== '') {
        echo '<div class="announce">' . e(S('web_announcement')) . '</div>';
    }
    $cats = categories_all(true);
    $logo = $brand['logo'] !== '' ? '<img src="' . e($brand['logo']) . '" alt="' . e($brand['name']) . '">' : '<span>' . e($brand['name']) . '</span>';
    echo '<header class="site-header"><div class="container hdr">'
        . '<div class="hdr-left"><button class="icon-btn menu-btn" data-open="menu" aria-label="Open menu"><i class="bi bi-list"></i></button><button class="icon-btn" data-open="search" aria-label="Search"><i class="bi bi-search"></i></button></div>'
        . '<a class="logo" href="' . e(url('')) . '" aria-label="' . e($brand['name']) . ' home">' . $logo . '</a>'
        . '<div class="hdr-right"><a class="icon-btn d-none-sm" href="' . e(url($user ? 'account' : 'login')) . '" aria-label="Account"><i class="bi bi-person"></i></a>'
        . '<a class="icon-btn" href="' . e(url('wishlist')) . '" aria-label="Wishlist"><i class="bi bi-heart"></i><b class="badge" data-wish-count' . ($wc ? '' : ' hidden') . '>' . $wc . '</b></a>'
        . '<button class="icon-btn" data-open="cart" aria-label="Cart"><i class="bi bi-bag"></i><b class="badge" data-cart-count' . ($cc ? '' : ' hidden') . '>' . $cc . '</b></button></div></div>'
        . '<nav class="main-nav" aria-label="Main"><div class="container"><a href="' . e(url('shop')) . '">All</a><a href="' . e(url('shop?flag=new')) . '">New</a>';
    foreach ($cats as $c) {
        echo '<a href="' . e(url('category/' . $c['slug'])) . '">' . e($c['name']) . '</a>';
    }
    echo '<a href="' . e(url('shop?sale=1')) . '" class="sale-link">Offers</a></div></nav></header>';

    /* search overlay, mobile menu, cart drawer */
    echo '<div class="overlay" data-close></div>'
        . '<div class="search-panel" id="panel-search" hidden><form action="' . e(url('shop')) . '" method="get" class="container"><i class="bi bi-search"></i><input type="search" name="q" placeholder="Search for products, categories..." autocomplete="off" aria-label="Search" data-suggest><button type="button" class="icon-btn" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></form><div class="container"><div class="suggest" id="suggest"></div></div></div>'
        . '<aside class="drawer drawer-left" id="panel-menu" hidden aria-label="Menu"><div class="drawer-head"><b>Menu</b><button class="icon-btn" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div><div class="drawer-body menu-list">'
        . '<a href="' . e(url('shop')) . '">Shop all</a><a href="' . e(url('shop?flag=new')) . '">New arrivals</a>';
    foreach (categories_all() as $c) {
        echo '<a href="' . e(url('category/' . $c['slug'])) . '">' . e($c['name']) . '</a>';
    }
    echo '<a href="' . e(url('shop?sale=1')) . '">Offers</a><hr><a href="' . e(url($user ? 'account' : 'login')) . '"><i class="bi bi-person"></i> ' . ($user ? 'My account' : 'Login / Register') . '</a><a href="' . e(url('wishlist')) . '"><i class="bi bi-heart"></i> Wishlist</a><a href="' . e(url('faq')) . '">FAQ</a><a href="' . e(url('contact')) . '">Contact us</a></div></aside>'
        . '<aside class="drawer drawer-right" id="panel-cart" hidden aria-label="Shopping cart"><div class="drawer-head"><b>Your bag</b><button class="icon-btn" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div><div class="drawer-body" id="cart-drawer-body"><div class="empty-note">Loading...</div></div></aside>'
        . '<div class="toasts" id="toasts" aria-live="polite"></div><main id="main">';
}

function page_end(): void
{
    $brand = wc_brand();
    echo '</main><footer class="site-footer"><div class="container foot-grid"><div><h4>' . e($brand['name']) . '</h4><p>' . e(S('web_tagline')) . '</p>';
    if ($brand['addr'] !== '') {
        echo '<p class="small"><i class="bi bi-geo-alt"></i> ' . e($brand['addr']) . '</p>';
    }
    if ($brand['phone'] !== '') {
        echo '<p class="small"><i class="bi bi-telephone"></i> ' . e($brand['phone']) . '</p>';
    }
    if ($brand['email'] !== '') {
        echo '<p class="small"><i class="bi bi-envelope"></i> <a href="mailto:' . e($brand['email']) . '">' . e($brand['email']) . '</a></p>';
    }
    echo '<div class="social">';
    foreach (['instagram' => 'instagram', 'facebook' => 'facebook', 'youtube' => 'youtube', 'twitter' => 'twitter-x', 'pinterest' => 'pinterest'] as $k => $ic) {
        if (S('web_social_' . $k) !== '') {
            echo '<a href="' . e(S('web_social_' . $k)) . '" target="_blank" rel="noopener" aria-label="' . e(ucfirst($k)) . '"><i class="bi bi-' . $ic . '"></i></a>';
        }
    }
    if (S('web_social_whatsapp') !== '') {
        echo '<a href="https://wa.me/' . e(preg_replace('/\D/', '', S('web_social_whatsapp'))) . '" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>';
    }
    echo '</div></div><div><h4>Shop</h4><ul><li><a href="' . e(url('shop')) . '">All products</a></li><li><a href="' . e(url('shop?flag=new')) . '">New arrivals</a></li>';
    foreach (array_slice(categories_all(), 0, 6) as $c) {
        echo '<li><a href="' . e(url('category/' . $c['slug'])) . '">' . e($c['name']) . '</a></li>';
    }
    echo '</ul></div><div><h4>Help</h4><ul><li><a href="' . e(url('account/orders')) . '">Track your order</a></li><li><a href="' . e(url('faq')) . '">FAQ</a></li><li><a href="' . e(url('contact')) . '">Contact us</a></li>';
    foreach (footer_pages() as $p) {
        echo '<li><a href="' . e(url('page/' . $p['slug'])) . '">' . html_entity_decode(e($p['title'])) . '</a></li>';
    }
    echo '</ul></div><div><h4>Stay in touch</h4><p class="small">Early access to new arrivals and private offers.</p>'
        . '<form class="news-form" data-newsletter><input type="email" name="email" placeholder="Your email address" required aria-label="Email"><button class="btn btn-accent" type="submit">Join</button></form>'
        . '<p class="small pay-note"><i class="bi bi-shield-lock"></i> Secure payments: UPI, cards, net banking' . (S('web_cod_enabled', '1') === '1' ? ', cash on delivery' : '') . '</p></div></div>'
        . '<div class="foot-bottom"><div class="container">&copy; ' . date('Y') . ' ' . e($brand['name']) . '. All rights reserved.</div></div></footer>';
    if (S('web_social_whatsapp') !== '') {
        echo '<a class="wa-float" href="https://wa.me/' . e(preg_replace('/\D/', '', S('web_social_whatsapp'))) . '" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><i class="bi bi-whatsapp"></i></a>';
    }
    echo '<script src="' . e(asset('assets/js/site.js')) . '"></script></body></html>';
}

/** one product card */
function product_card(array $p): string
{
    $pi   = price_info($p);
    $wish = in_array((int)$p['id'], wishlist_ids(), true);
    $out  = (int)$p['stock'] <= 0;
    $h = '<article class="card-p reveal"><div class="card-img"><a href="' . e(product_url($p)) . '" aria-label="' . e($p['name']) . '">';
    if ($p['img1']) {
        $h .= '<img class="i1" loading="lazy" src="' . e(img($p['img1'])) . '" alt="' . e($p['name']) . '">' . ($p['img2'] ? '<img class="i2" loading="lazy" src="' . e(img($p['img2'])) . '" alt="">' : '');
    } else {
        $h .= '<div class="noimg"><span>' . e(mb_strtoupper(mb_substr($p['name'], 0, 1))) . '</span></div>';
    }
    $h .= '</a><div class="tags">' . ($out ? '<span class="tag tag-dark">Sold out</span>' : ($pi['off'] > 0 ? '<span class="tag">' . $pi['off'] . '% off</span>' : '')) . (!$out && $p['is_new'] ? '<span class="tag tag-light">New</span>' : '') . '</div>'
        . '<button class="wish' . ($wish ? ' on' : '') . '" data-wish="' . (int)$p['id'] . '" aria-label="Add to wishlist" aria-pressed="' . ($wish ? 'true' : 'false') . '"><i class="bi bi-heart' . ($wish ? '-fill' : '') . '"></i></button></div>'
        . '<div class="card-body"><div class="cat">' . e($p['category']) . '</div><h3><a href="' . e(product_url($p)) . '">' . e($p['name']) . '</a></h3>';
    if ((int)$p['rating_count'] > 0) {
        $h .= '<div class="rate">' . stars((float)$p['rating']) . ' <small>(' . (int)$p['rating_count'] . ')</small></div>';
    }
    $h .= '<div class="price">' . ($pi['from'] ? '<small>From </small>' : '') . '<b>' . wc_money($pi['price']) . '</b>' . ($pi['mrp'] > 0 ? ' <s>' . wc_money($pi['mrp']) . '</s>' : '') . '</div></div></article>';
    return $h;
}

function pagination(int $page, int $total, int $per, array $params = []): string
{
    $pages = (int)ceil($total / max(1, $per));
    if ($pages <= 1) {
        return '';
    }
    $path = ltrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if (BASE_PATH !== '' && strpos('/' . $path, BASE_PATH . '/') === 0) {
        $path = substr('/' . $path, strlen(BASE_PATH) + 1);
    }
    $link = function (int $p, string $label, string $cls = '') use ($params, $path) {
        return '<a class="pg ' . $cls . '" href="' . e(url($path) . '?' . http_build_query($params + ['page' => $p])) . '">' . $label . '</a>';
    };
    $o = '<nav class="pagination" aria-label="Pages">';
    $o .= $page > 1 ? $link($page - 1, '<i class="bi bi-chevron-left"></i>') : '';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= 1) {
            $o .= $link($i, (string)$i, $i === $page ? 'on' : '');
        } elseif (abs($i - $page) === 2) {
            $o .= '<span class="pg dots">&hellip;</span>';
        }
    }
    $o .= $page < $pages ? $link($page + 1, '<i class="bi bi-chevron-right"></i>') : '';
    return $o . '</nav>';
}

function breadcrumb(array $trail): string
{
    $o = '<nav class="crumbs container" aria-label="Breadcrumb"><a href="' . e(url('')) . '">Home</a>';
    foreach ($trail as $label => $href) {
        $o .= ' <i class="bi bi-chevron-right"></i> ' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : '<span>' . e($label) . '</span>');
    }
    return $o . '</nav>';
}

/** HTML of the cart (used by drawer and cart page) */
function cart_html(array $priced, bool $page = false): string
{
    if (!$priced['lines']) {
        return '<div class="empty-note"><i class="bi bi-bag"></i><p>Your bag is empty</p><a class="btn btn-primary" href="' . e(url('shop')) . '">Continue shopping</a></div>';
    }
    $h = '<ul class="cart-lines">';
    foreach ($priced['lines'] as $l) {
        $var = trim($l['size'] . ($l['size'] !== '' && $l['color'] !== '' ? ' / ' : '') . $l['color']);
        $h .= '<li class="cart-line' . (!$l['in_stock'] ? ' oos' : '') . '"><a class="cl-img" href="' . e($l['url']) . '">' . ($l['image'] ? '<img loading="lazy" src="' . e(img((string)$l['image'])) . '" alt="">' : '<div class="noimg"><span>' . e(mb_substr($l['name'], 0, 1)) . '</span></div>') . '</a>'
            . '<div class="cl-info"><a class="cl-name" href="' . e($l['url']) . '">' . e($l['name']) . '</a>' . ($var !== '' ? '<div class="small muted">' . e($var) . '</div>' : '')
            . (!$l['in_stock'] ? '<div class="small err">' . ($l['stock'] > 0 ? 'Only ' . $l['stock'] . ' left' : 'Out of stock') . '</div>' : '')
            . '<div class="cl-row"><div class="qty" role="group" aria-label="Quantity"><button type="button" data-qty="' . (int)$l['item_id'] . '" data-to="' . ($l['qty'] - 1) . '" aria-label="Decrease">&minus;</button><span>' . (int)$l['qty'] . '</span><button type="button" data-qty="' . (int)$l['item_id'] . '" data-to="' . ($l['qty'] + 1) . '" aria-label="Increase">+</button></div>'
            . '<b class="cl-price">' . wc_money($l['gross']) . '</b></div></div><button class="cl-x" data-qty="' . (int)$l['item_id'] . '" data-to="0" aria-label="Remove"><i class="bi bi-x-lg"></i></button></li>';
    }
    $h .= '</ul><div class="cart-sum">';
    if (!$page) {
        $h .= '<div class="row"><span>Subtotal</span><b>' . wc_money($priced['subtotal']) . '</b></div>';
    }
    if ($priced['discount'] > 0) {
        $h .= '<div class="row green"><span>Coupon ' . e($priced['coupon']) . ' <button class="link" data-coupon-remove>remove</button></span><b>-' . wc_money($priced['discount']) . '</b></div>';
    }
    if ($priced['free_left'] > 0) {
        $h .= '<div class="note"><i class="bi bi-truck"></i> Add ' . wc_money($priced['free_left']) . ' more for free shipping</div>';
    }
    if (!$page) {
        $h .= '<p class="small muted">Shipping and taxes are calculated at checkout.</p><a class="btn btn-primary btn-block" href="' . e(url('checkout')) . '">Checkout</a><a class="btn btn-ghost btn-block" href="' . e(url('cart')) . '">View bag</a>';
    }
    return $h . '</div>';
}

/** order summary box used on the cart page */
function cart_summary_html(array $p): string
{
    $h = '<h3>Order summary</h3><div class="row"><span>Subtotal (' . (int)$p['count'] . ' items)</span><b>' . wc_money($p['subtotal']) . '</b></div>';
    if ($p['discount'] > 0) {
        $h .= '<div class="row green"><span>Coupon ' . e($p['coupon']) . '</span><b>-' . wc_money($p['discount']) . '</b></div>';
    }
    $h .= '<div class="row"><span>Shipping</span><span class="muted">calculated at checkout</span></div>';
    $h .= '<div class="row total"><span>Estimated total</span><b>' . wc_money($p['total'] - $p['shipping'] - $p['cod_fee']) . '</b></div>';
    if ($p['incl_gst']) {
        $h .= '<p class="small muted">Prices include GST.</p>';
    }
    if ($p['free_left'] > 0) {
        $h .= '<div class="cart-sum"><div class="note"><i class="bi bi-truck"></i> Add ' . wc_money($p['free_left']) . ' more for free shipping</div></div>';
    }
    $h .= '<form class="coupon-box" data-coupon><input name="code" placeholder="Coupon code" maxlength="40" aria-label="Coupon code"' . ($p['coupon'] !== '' ? ' value="' . e($p['coupon']) . '"' : '') . '><button class="btn btn-ghost btn-sm" type="submit">Apply</button></form>';
    return $h . '<a class="btn btn-primary btn-block" href="' . e(url('checkout')) . '">Proceed to checkout</a><a class="btn btn-ghost btn-block" href="' . e(url('shop')) . '">Continue shopping</a>';
}

function not_found(string $msg = 'The page you are looking for does not exist.'): void
{
    http_response_code(404);
    page_start(['title' => 'Page not found', 'robots' => 'noindex']);
    echo '<div class="container"><div class="empty-big"><i class="bi bi-compass"></i><h1>404</h1><p>' . e($msg) . '</p><a class="btn btn-primary" href="' . e(url('shop')) . '">Continue shopping</a></div></div>';
    page_end();
    exit;
}

function link_url(string $l): string
{
    $l = trim($l);
    return $l === '' ? url('shop') : (preg_match('#^(https?:)?//#i', $l) ? $l : url($l));
}
