<?php
/* inc/cart.php - the shopping cart (stored in the database, remembered with a cookie, merged on login) */

function cart_token(bool $create = false): string
{
    $t = (string)($_COOKIE['cart'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/', $t)) {
        if (!$create) {
            return '';
        }
        $t = bin2hex(random_bytes(20));
        setcookie('cart', $t, ['expires' => time() + 86400 * 60, 'path' => '/', 'secure' => shop_https(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['cart'] = $t;
    }
    return $t;
}

function cart_id(bool $create = false): int
{
    static $id = null;
    if ($id !== null && $id > 0) {
        return $id;
    }
    $t = cart_token($create);
    $row = $t !== '' ? wc_one('SELECT id FROM web_carts WHERE token = ?', 's', [$t]) : null;
    if (!$row && $create) {
        wc_run('INSERT INTO web_carts (token, web_user_id) VALUES (?, ?)', 'si', [$t, user() ? (int)user()['id'] : null]);
        return $id = wc_insert_id();
    }
    return $id = $row ? (int)$row['id'] : 0;
}

/** lines of the current cart with live price / stock */
function cart_lines(): array
{
    $cid = cart_id();
    if (!$cid) {
        return [];
    }
    $rows = wc_all("SELECT ci.id AS item_id, ci.qty, v.id AS variant_id, v.size, v.color, v.sku AS vsku, v.stock, COALESCE(v.price, p.price) AS unit, p.id AS product_id, p.name, p.sku, p.mrp, p.gst, p.status,
                           COALESCE(m.show_on_website,1) AS shown, (SELECT i.file_name FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image
                      FROM web_cart_items ci JOIN product_variants v ON v.id = ci.variant_id JOIN products p ON p.id = v.product_id LEFT JOIN web_product_meta m ON m.product_id = p.id
                     WHERE ci.cart_id = ? ORDER BY ci.id", 'i', [$cid]);
    $out = [];
    foreach ($rows as $r) {
        if ($r['status'] !== 'Active' || !(int)$r['shown']) {
            wc_run('DELETE FROM web_cart_items WHERE id = ?', 'i', [(int)$r['item_id']]);
            continue;
        }
        $r['qty']      = (int)$r['qty'];
        $r['stock']    = (int)$r['stock'];
        $r['unit']     = (float)$r['unit'];
        $r['mrp']      = (float)$r['mrp'];
        $r['gst']      = (float)$r['gst'];
        $r['in_stock'] = $r['stock'] >= $r['qty'];
        $r['url']      = product_url(['id' => $r['product_id'], 'name' => $r['name']]);
        $out[] = $r;
    }
    return $out;
}

function cart_count(): int
{
    $cid = cart_id();
    return $cid ? (int)(wc_one('SELECT COALESCE(SUM(qty),0) AS c FROM web_cart_items WHERE cart_id = ?', 'i', [$cid])['c'] ?? 0) : 0;
}

/** @return array{0:bool,1:string} */
function cart_add(int $variantId, int $qty): array
{
    $qty = max(1, min(10, $qty));
    $v = wc_one("SELECT v.id, v.stock, p.name, p.status, COALESCE(m.show_on_website,1) AS shown FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN web_product_meta m ON m.product_id = p.id WHERE v.id = ?", 'i', [$variantId]);
    if (!$v || $v['status'] !== 'Active' || !(int)$v['shown']) {
        return [false, 'This product is no longer available.'];
    }
    if ((int)$v['stock'] <= 0) {
        return [false, 'Sorry, this option is out of stock.'];
    }
    $cid = cart_id(true);
    $have = (int)(wc_one('SELECT qty FROM web_cart_items WHERE cart_id = ? AND variant_id = ?', 'ii', [$cid, $variantId])['qty'] ?? 0);
    $new = min($have + $qty, 10, (int)$v['stock']);
    if ($new <= $have) {
        return [false, 'Only ' . (int)$v['stock'] . ' available - you already have the maximum in your cart.'];
    }
    wc_run('INSERT INTO web_cart_items (cart_id, variant_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)', 'iii', [$cid, $variantId, $new]);
    return [true, $new < $have + $qty ? 'Added (only ' . $new . ' available).' : 'Added to your cart.'];
}

function cart_set_qty(int $itemId, int $qty): void
{
    $cid = cart_id();
    if (!$cid) {
        return;
    }
    if ($qty <= 0) {
        wc_run('DELETE FROM web_cart_items WHERE id = ? AND cart_id = ?', 'ii', [$itemId, $cid]);
        return;
    }
    $row = wc_one('SELECT v.stock FROM web_cart_items ci JOIN product_variants v ON v.id = ci.variant_id WHERE ci.id = ? AND ci.cart_id = ?', 'ii', [$itemId, $cid]);
    if ($row) {
        wc_run('UPDATE web_cart_items SET qty = ? WHERE id = ? AND cart_id = ?', 'iii', [max(1, min(10, $qty, max(1, (int)$row['stock']))), $itemId, $cid]);
    }
}

function cart_clear(): void
{
    if ($cid = cart_id()) {
        wc_run('DELETE FROM web_cart_items WHERE cart_id = ?', 'i', [$cid]);
        wc_run("UPDATE web_carts SET coupon_code = '' WHERE id = ?", 'i', [$cid]);
    }
}

/** after login: the browser cart becomes the customer's cart; an older saved cart is merged into it */
function cart_adopt(int $userId): void
{
    $cid = cart_id();
    $old = wc_all('SELECT id FROM web_carts WHERE web_user_id = ? AND id <> ? ORDER BY id DESC', 'ii', [$userId, $cid ?: 0]);
    if (!$cid) {
        if ($old) {                                       // browser had no cart: continue with the saved one
            $row = wc_one('SELECT token FROM web_carts WHERE id = ?', 'i', [(int)$old[0]['id']]);
            setcookie('cart', $row['token'], ['expires' => time() + 86400 * 60, 'path' => '/', 'secure' => shop_https(), 'httponly' => true, 'samesite' => 'Lax']);
            $_COOKIE['cart'] = $row['token'];
        }
        return;
    }
    foreach ($old as $o) {
        wc_run('INSERT IGNORE INTO web_cart_items (cart_id, variant_id, qty) SELECT ?, variant_id, qty FROM web_cart_items WHERE cart_id = ?', 'ii', [$cid, (int)$o['id']]);
        wc_run('DELETE FROM web_carts WHERE id = ?', 'i', [(int)$o['id']]);
    }
    wc_run('UPDATE web_carts SET web_user_id = ? WHERE id = ?', 'ii', [$userId, $cid]);
}

/* ---------------------------------------------------------------- coupon */
function cart_coupon_get(): string
{
    $cid = cart_id();
    return $cid ? (string)(wc_one('SELECT coupon_code FROM web_carts WHERE id = ?', 'i', [$cid])['coupon_code'] ?? '') : '';
}

function cart_coupon_set(string $code): void
{
    if ($cid = cart_id(true)) {
        wc_run('UPDATE web_carts SET coupon_code = ? WHERE id = ?', 'si', [strtoupper(mb_substr($code, 0, 40)), $cid]);
    }
}

/** priced cart; a coupon that is no longer valid is removed and the reason is returned in ['coupon_notice'] */
function cart_priced(string $state = '', string $method = 'razorpay'): array
{
    $code = cart_coupon_get();
    $p = price_cart(cart_lines(), $state, $method, $code);
    $p['coupon_notice'] = '';
    if ($code !== '' && $p['coupon'] === '') {
        cart_coupon_set('');
        $p['coupon_notice'] = $p['coupon_error'];
    }
    return $p;
}
