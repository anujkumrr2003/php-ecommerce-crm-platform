<?php
/* api.php - the AJAX endpoint of the website (cart, wishlist, coupon, search, newsletter). POST only, CSRF protected. */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/layout.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Invalid request.'], 405);
}
csrf_require(true);
$action = (string)($_POST['action'] ?? '');

/** common reply parts: counts + both cart renderings */
function cart_reply(bool $ok = true, string $msg = ''): array
{
    $p = cart_priced();
    $r = ['success' => $ok, 'message' => $msg, 'cart_count' => $p['count'], 'wish_count' => count(wishlist_ids()),
        'html' => cart_html($p), 'page_html' => cart_html($p, true), 'summary_html' => $p['lines'] ? cart_summary_html($p) : ''];
    if ($p['coupon_notice'] !== '') {
        $r['message'] = $p['coupon_notice'];
        $r['success'] = false;
    }
    return $r;
}

try {
    switch ($action) {
        case 'cart_add':
            [$ok, $msg] = cart_add((int)($_POST['variant_id'] ?? 0), (int)($_POST['qty'] ?? 1));
            wc_log_activity(user() ? (int)user()['id'] : null, 'cart_add', 'variant ' . (int)($_POST['variant_id'] ?? 0));
            json_out(cart_reply($ok, $msg));

        case 'cart_qty':
            cart_set_qty((int)($_POST['item_id'] ?? 0), (int)($_POST['qty'] ?? 0));
            json_out(cart_reply());

        case 'cart_drawer':
            json_out(cart_reply());

        case 'coupon_apply':
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $p = price_cart(cart_lines(), '', 'razorpay', $code);
            if ($code === '' || $p['coupon'] === '') {
                json_out(['success' => false, 'message' => $p['coupon_error'] ?: 'Please enter a coupon code.']);
            }
            cart_coupon_set($code);
            json_out(['success' => true, 'message' => 'Coupon ' . $code . ' applied - you save ' . wc_money($p['discount']) . '.']);

        case 'coupon_remove':
            cart_coupon_set('');
            json_out(cart_reply(true, 'Coupon removed.'));

        case 'wishlist_toggle':
            $u = user();
            if (!$u) {
                json_out(['success' => false, 'login' => true]);
            }
            $pid = (int)($_POST['product_id'] ?? 0);
            if (!wc_one('SELECT id FROM products WHERE id = ? AND status = \'Active\'', 'i', [$pid])) {
                json_out(['success' => false, 'message' => 'Product not found.']);
            }
            if (wc_run('DELETE FROM web_wishlist WHERE web_user_id = ? AND product_id = ?', 'ii', [(int)$u['id'], $pid]) > 0) {
                $on = false;
            } else {
                wc_run('INSERT IGNORE INTO web_wishlist (web_user_id, product_id) VALUES (?,?)', 'ii', [(int)$u['id'], $pid]);
                $on = true;
                wc_log_activity((int)$u['id'], 'wishlist_add', 'product ' . $pid);
            }
            $n = (int)wc_one('SELECT COUNT(*) c FROM web_wishlist WHERE web_user_id = ?', 'i', [(int)$u['id']])['c'];
            json_out(['success' => true, 'on' => $on, 'wish_count' => $n]);

        case 'suggest':
            $q = trim((string)($_POST['q'] ?? ''));
            [$rows] = mb_strlen($q) >= 2 ? products_list(['q' => $q, 'per' => 6]) : [[]];
            $h = '';
            foreach ($rows as $r) {
                $pi = price_info($r);
                $h .= '<a href="' . e(product_url($r)) . '">' . ($r['img1'] ? '<img src="' . e(img($r['img1'])) . '" alt="">' : '') . '<span>' . e($r['name']) . '<br><small>' . e($r['category']) . ' &middot; ' . wc_money($pi['price']) . '</small></span></a>';
            }
            json_out(['success' => true, 'html' => $h !== '' ? $h . '<a href="' . e(url('shop?q=' . urlencode($q))) . '"><b>See all results</b></a>' : '<p class="muted small" style="padding:8px">No products found.</p>']);

        case 'newsletter':
            $em = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                json_out(['success' => false, 'message' => 'Please enter a valid email address.']);
            }
            if (!wc_throttle('news:' . wc_ip(), 6, 3600)) {
                json_out(['success' => false, 'message' => 'Too many requests. Please try later.']);
            }
            wc_run('INSERT IGNORE INTO web_subscribers (email) VALUES (?)', 's', [$em]);
            json_out(['success' => true, 'message' => 'Thank you for subscribing!']);

        default:
            json_out(['success' => false, 'message' => 'Unknown action.'], 404);
    }
} catch (Throwable $e) {
    error_log('api.php ' . $action . ': ' . $e->getMessage());
    json_out(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
