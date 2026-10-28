<?php
/* index.php - home page. Sections, order and headings are controlled from the CRM (Online Store -> Home page sections / Banners). */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/layout.php';

$today = date('Y-m-d');
$banners = wc_all("SELECT * FROM web_banners WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?) ORDER BY sort_order, id", 'ss', [$today, $today]);
$byPos = ['hero' => [], 'promo' => [], 'strip' => []];
foreach ($banners as $b) {
    $byPos[$b['position']][] = $b;
}
$sections = wc_all('SELECT * FROM web_home_sections WHERE is_active = 1 ORDER BY sort_order, id');

page_start(['class' => 'home', 'jsonld' => [json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => wc_brand()['name'], 'url' => abs_url(''), 'logo' => wc_brand()['logo'] ?: null], JSON_UNESCAPED_SLASHES)]]);

/* ---- hero ---- */
$heroes = array_values(array_filter($byPos['hero'], fn($b) => $b['image_desktop'] !== ''));
if ($heroes) {
    echo '<section class="hero" aria-label="Featured"><div class="slides">';
    foreach ($heroes as $b) {
        $d = img($b['image_desktop'], 'web');
        $m = $b['image_mobile'] !== '' ? img($b['image_mobile'], 'web') : '';
        echo '<div class="slide"><picture>' . ($m !== '' ? '<source media="(max-width:720px)" srcset="' . e($m) . '">' : '') . '<img src="' . e($d) . '" alt="' . e($b['title']) . '"' . ($b === $heroes[0] ? '' : ' loading="lazy"') . '></picture>';
        if ($b['title'] !== '' || $b['subtitle'] !== '') {
            echo '<div class="slide-text" style="color:' . e($b['text_color']) . '">' . ($b['title'] !== '' ? '<h2>' . e($b['title']) . '</h2>' : '') . ($b['subtitle'] !== '' ? '<p>' . e($b['subtitle']) . '</p>' : '')
                . ($b['button_text'] !== '' ? '<a class="btn" href="' . e(link_url($b['link'])) . '">' . e($b['button_text']) . '</a>' : '') . '</div>';
        } elseif ($b['link'] !== '') {
            echo '<a class="slide-text" href="' . e(link_url($b['link'])) . '" aria-label="' . e($b['title'] ?: 'Shop') . '"></a>';
        }
        echo '</div>';
    }
    echo '</div>';
    if (count($heroes) > 1) {
        echo '<button class="arrow prev" aria-label="Previous"><i class="bi bi-chevron-left"></i></button><button class="arrow next" aria-label="Next"><i class="bi bi-chevron-right"></i></button><div class="dots">' . str_repeat('<button aria-label="Slide"></button>', count($heroes)) . '</div>';
    }
    echo '</section>';
} else {
    echo '<section class="hero-fallback"><div class="container"><h1>' . e(wc_brand()['name']) . '</h1><p style="font-size:18px;letter-spacing:1px">' . e(S('web_tagline')) . '</p><a class="btn" style="background:#fff;color:var(--primary);border-color:#fff" href="' . e(url('shop')) . '">Shop the collection</a></div></section>';
}

foreach ($byPos['strip'] as $b) {
    if ($b['image_desktop'] !== '') {
        echo '<a href="' . e(link_url($b['link'])) . '" style="display:block"><picture>' . ($b['image_mobile'] !== '' ? '<source media="(max-width:720px)" srcset="' . e(img($b['image_mobile'], 'web')) . '">' : '') . '<img style="width:100%" src="' . e(img($b['image_desktop'], 'web')) . '" alt="' . e($b['title']) . '"></picture></a>';
    }
}

$head = fn(array $s) => '<div class="sec-head reveal"><h2>' . e($s['title']) . '</h2>' . ($s['subtitle'] !== '' ? '<p>' . e($s['subtitle']) . '</p>' : '') . '</div>';
$alt = false;
foreach ($sections as $s) {
    $lim = max(1, (int)$s['item_limit']);
    $k = $s['section_key'];
    if ($k === 'categories') {
        $cats = array_slice(categories_all(), 0, $lim);
        if (!$cats) {
            continue;
        }
        echo '<section class="section"><div class="container">' . $head($s) . '<div class="cat-grid">';
        foreach ($cats as $c) {
            echo '<a class="cat-tile reveal" href="' . e(url('category/' . $c['slug'])) . '">' . ($c['image'] !== '' ? '<img loading="lazy" src="' . e(img($c['image'], 'web')) . '" alt="' . e($c['name']) . '">' : '<div class="noimg"><span>' . e(mb_substr($c['name'], 0, 1)) . '</span></div>') . '<span>' . e($c['name']) . '</span></a>';
        }
        echo '</div></div></section>';
    } elseif (in_array($k, ['featured', 'new_arrivals', 'bestsellers'], true)) {
        $f = ['featured' => ['flag' => 'featured'], 'new_arrivals' => ['flag' => 'new'], 'bestsellers' => ['flag' => 'best']][$k] + ['per' => $lim];
        [$rows] = products_list($f);
        if (!$rows) {                                           // nothing marked yet: show sensible defaults so the page is never empty
            [$rows] = products_list(['per' => $lim, 'sort' => $k === 'bestsellers' ? 'popular' : 'new']);
            if ($k === 'featured' && count(products_list(['flag' => 'new', 'per' => 1])[0]) > 0) {
                continue;
            }
            if ($k === 'bestsellers' && !array_filter($rows, fn($r) => (int)$r['sold'] > 0)) {
                continue;
            }
        }
        if (!$rows) {
            continue;
        }
        $alt = !$alt;
        echo '<section class="section' . ($alt ? ' alt' : '') . '"><div class="container">' . $head($s) . '<div class="grid-p">';
        foreach ($rows as $r) {
            echo product_card($r);
        }
        echo '</div><div class="center"><a class="btn btn-ghost" href="' . e(url('shop' . ($k === 'new_arrivals' ? '?flag=new' : ''))) . '">View all</a></div></div></section>';
    } elseif ($k === 'promo') {
        $pr = array_slice(array_filter($byPos['promo'], fn($b) => $b['image_desktop'] !== ''), 0, $lim);
        if (!$pr) {
            continue;
        }
        echo '<section class="section"><div class="container">' . ($s['title'] !== '' ? $head($s) : '') . '<div class="promo-grid">';
        foreach ($pr as $b) {
            echo '<a class="promo reveal" href="' . e(link_url($b['link'])) . '" style="color:' . e($b['text_color']) . '"><img loading="lazy" src="' . e(img($b['image_desktop'], 'web')) . '" alt="' . e($b['title']) . '"><div><h3>' . e($b['title']) . '</h3>' . ($b['subtitle'] !== '' ? '<p class="small" style="margin:0">' . e($b['subtitle']) . '</p>' : '') . '</div></a>';
        }
        echo '</div></div></section>';
    } elseif ($k === 'features') {
        $free = (float)S('web_ship_free_above', '0');
        $f = [['truck', 'Free shipping', $free > 0 ? 'On all orders above ' . wc_money($free) : 'Delivered safely to your door'], ['arrow-repeat', 'Easy returns', (int)S('web_return_days', '7') . '-day hassle free returns'],
            ['shield-check', 'Secure payments', 'UPI, cards, net banking' . (S('web_cod_enabled', '1') === '1' ? ' & COD' : '')], ['headset', 'Friendly support', 'We are here to help you']];
        echo '<section class="section alt"><div class="container"><div class="features">';
        foreach ($f as $x) {
            echo '<div class="reveal"><i class="bi bi-' . $x[0] . '"></i><h4>' . e($x[1]) . '</h4><p>' . e($x[2]) . '</p></div>';
        }
        echo '</div></div></section>';
    } elseif ($k === 'reviews') {
        $rv = wc_all("SELECT r.*, p.name AS product FROM web_reviews r JOIN products p ON p.id = r.product_id WHERE r.status = 'approved' AND r.rating >= 4 AND r.body <> '' ORDER BY r.id DESC LIMIT " . $lim);
        if (!$rv) {
            continue;
        }
        echo '<section class="section"><div class="container">' . $head($s) . '<div class="reviews-grid">';
        foreach ($rv as $r) {
            echo '<div class="quote reveal">' . stars((float)$r['rating']) . '<p>&ldquo;' . e($r['body']) . '&rdquo;</p><b>' . e($r['name']) . '</b> <span class="muted small">on ' . e($r['product']) . '</span></div>';
        }
        echo '</div></div></section>';
    } elseif ($k === 'newsletter') {
        echo '<section class="section newsletter"><div class="container"><h2>' . e($s['title']) . '</h2><p>' . e($s['subtitle']) . '</p><form class="news-form" data-newsletter><input type="email" name="email" placeholder="Your email address" required aria-label="Email"><button class="btn btn-accent" type="submit">Subscribe</button></form></div></section>';
    }
}
page_end();
