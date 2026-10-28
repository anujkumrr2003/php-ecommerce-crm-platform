<?php
/* inc/pricing.php - totals: coupon, GST, shipping, COD fee.  And creating the order from a priced cart.
   Prices ALWAYS come from the database here - the browser can never decide a price. */

function r2($n): float
{
    return round((float)$n, 2);
}

/** @return array{0:?array,1:string} [coupon row, error] */
function coupon_check(string $code, float $subtotal, ?int $userId): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return [null, ''];
    }
    $c = wc_one('SELECT * FROM web_coupons WHERE code = ?', 's', [$code]);
    if (!$c || !(int)$c['is_active']) {
        return [null, 'This coupon code is not valid.'];
    }
    $today = date('Y-m-d');
    if ($c['starts_at'] && $today < $c['starts_at']) {
        return [null, 'This coupon is not active yet.'];
    }
    if ($c['ends_at'] && $today > $c['ends_at']) {
        return [null, 'This coupon has expired.'];
    }
    if ((float)$c['min_order'] > $subtotal) {
        return [null, 'Add items worth ' . wc_money((float)$c['min_order'] - $subtotal) . ' more to use this coupon (minimum order ' . wc_money($c['min_order']) . ').'];
    }
    if ((int)$c['usage_limit'] > 0 && (int)$c['used_count'] >= (int)$c['usage_limit']) {
        return [null, 'This coupon has been fully used.'];
    }
    if ((int)$c['per_user_limit'] > 0) {
        if (!$userId) {
            return [null, 'Please log in to use this coupon.'];
        }
        $n = (int)(wc_one('SELECT COUNT(*) AS n FROM web_coupon_usage WHERE coupon_id = ? AND web_user_id = ?', 'ii', [(int)$c['id'], $userId])['n'] ?? 0);
        if ($n >= (int)$c['per_user_limit']) {
            return [null, 'You have already used this coupon.'];
        }
    }
    return [$c, ''];
}

function shipping_for(string $state, float $payable): float
{
    $flat = (float)S('web_ship_flat', '0');
    $free = (float)S('web_ship_free_above', '0');
    if ($state !== '') {
        $r = wc_one('SELECT charge, free_above FROM web_shipping_rates WHERE state = ? AND is_active = 1', 's', [$state]);
        if ($r) {
            $flat = (float)$r['charge'];
            if ($r['free_above'] !== null) {
                $free = (float)$r['free_above'];
            }
        }
    }
    return ($free > 0 && $payable >= $free) ? 0.0 : $flat;
}

/**
 * @param array  $lines  from cart_lines()
 * @param string $state  delivery state ('' = unknown yet)
 * @param string $method 'razorpay' | 'cod'
 */
function price_cart(array $lines, string $state = '', string $method = 'razorpay', string $couponCode = ''): array
{
    $incl = S('web_prices_include_gst', '1') === '1';
    $sub = 0.0;
    $count = 0;
    foreach ($lines as &$l) {
        $l['gross'] = r2($l['unit'] * $l['qty']);
        $sub += $l['gross'];
        $count += $l['qty'];
    }
    unset($l);
    $sub = r2($sub);
    $uid = user() ? (int)user()['id'] : null;
    [$coupon, $cerr] = coupon_check($couponCode, $sub, $uid);
    $disc = 0.0;
    if ($coupon) {
        $disc = $coupon['type'] === 'percent' ? $sub * (float)$coupon['value'] / 100 : (float)$coupon['value'];
        if ((float)$coupon['max_discount'] > 0) {
            $disc = min($disc, (float)$coupon['max_discount']);
        }
        $disc = r2(min($disc, $sub));
    }
    $left = $disc;
    $tax = 0.0;
    $goods = 0.0;
    $n = count($lines);
    foreach ($lines as $i => &$l) {
        $share = $sub > 0 ? ($i === $n - 1 ? $left : r2($disc * $l['gross'] / $sub)) : 0.0;
        $share = min($share, $left);
        $left = r2($left - $share);
        $after = r2($l['gross'] - $share);
        $g = $l['gst'];
        $lineTax = $incl ? r2($after - $after / (1 + $g / 100)) : r2($after * $g / 100);
        $l['discount'] = $share;
        $l['tax'] = $lineTax;
        $l['line_total'] = $incl ? $after : r2($after + $lineTax);
        $tax += $lineTax;
        $goods += $l['line_total'];
    }
    unset($l);
    $payable = r2($goods);
    $shipping = $count > 0 ? shipping_for($state, $payable - ($incl ? 0 : $tax)) : 0.0;
    $codFee = ($method === 'cod' && $count > 0) ? (float)S('web_cod_fee', '0') : 0.0;
    $free = (float)S('web_ship_free_above', '0');
    return [
        'lines' => $lines, 'count' => $count, 'subtotal' => $sub, 'discount' => $disc, 'coupon' => $coupon ? $coupon['code'] : '', 'coupon_error' => $cerr,
        'tax' => r2($tax), 'shipping' => r2($shipping), 'cod_fee' => r2($codFee), 'total' => r2($goods + $shipping + $codFee), 'incl_gst' => $incl,
        'free_left' => ($free > 0 && $shipping > 0) ? max(0, r2($free - $payable)) : 0.0,
    ];
}

function cod_allowed(float $total): bool
{
    if (S('web_cod_enabled', '1') !== '1') {
        return false;
    }
    $max = (float)S('web_cod_max', '0');
    return $max <= 0 || $total <= $max;
}

function order_no(): string
{
    for ($i = 0; $i < 20; $i++) {
        $no = 'W' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        if (!wc_one('SELECT id FROM web_orders WHERE order_no = ?', 's', [$no])) {
            return $no;
        }
    }
    return 'W' . date('ymdHis') . random_int(10, 99);
}

/**
 * Create the order, hold the stock. Throws RuntimeException with a message for the customer.
 * @return array{0:int,1:string} [order id, order no]
 */
function order_create(array $user, array $addr, string $method, string $note, array $priced): array
{
    if (!$priced['lines']) {
        throw new RuntimeException('Your cart is empty.');
    }
    $min = (float)S('web_min_order', '0');
    if ($min > 0 && $priced['subtotal'] < $min) {
        throw new RuntimeException('Minimum order value is ' . wc_money($min) . '.');
    }
    foreach ($priced['lines'] as $l) {
        if ($l['stock'] < $l['qty']) {
            throw new RuntimeException($l['stock'] <= 0 ? '"' . $l['name'] . '" just went out of stock. Please remove it from your cart.' : 'Only ' . $l['stock'] . ' of "' . $l['name'] . '" left. Please reduce the quantity.');
        }
    }
    $no = order_no();
    $id = wc_tx(function () use ($user, $addr, $method, $note, $priced, $no) {
        foreach ($priced['lines'] as $l) {
            if (!wc_stock_take((int)$l['variant_id'], (int)$l['qty'])) {
                throw new RuntimeException('"' . $l['name'] . '" just went out of stock. Please update your cart.');
            }
        }
        wc_run("INSERT INTO web_orders (order_no, web_user_id, customer_id, status, payment_method, payment_status, subtotal, discount, coupon_code, shipping_fee, cod_fee, tax_total, total, email,
                    ship_name, ship_mobile, ship_line1, ship_line2, ship_landmark, ship_city, ship_state, ship_pin, customer_note, stock_reserved, ip_address)
                VALUES (?,?,?,'pending_payment',?,'pending',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)", 'siisdddsdddsssssssssss',
            [$no, (int)$user['id'], $user['customer_id'] ?: null, $method, $priced['subtotal'], $priced['discount'], $priced['coupon'], $priced['shipping'], $priced['cod_fee'], $priced['tax'], $priced['total'], $user['email'],
             $addr['name'], $addr['mobile'], $addr['line1'], $addr['line2'], $addr['landmark'], $addr['city'], $addr['state'], $addr['pin'], mb_substr($note, 0, 500), wc_ip()]);
        $oid = wc_insert_id();
        foreach ($priced['lines'] as $l) {
            wc_run('INSERT INTO web_order_items (order_id, product_id, variant_id, name, sku, size, color, image, qty, mrp, unit_price, gst_percent, line_total, line_discount, line_tax) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                'iiisssssidddddd', [$oid, $l['product_id'], $l['variant_id'], $l['name'], $l['vsku'] ?: $l['sku'], $l['size'], $l['color'], (string)$l['image'], $l['qty'], $l['mrp'], $l['unit'], $l['gst'], $l['line_total'], $l['discount'], $l['tax']]);
        }
        wc_order_history($oid, 'pending_payment', $method === 'cod' ? 'Order created (cash on delivery)' : 'Order created, waiting for payment', 'Customer');
        return $oid;
    });
    return [(int)$id, $no];
}
