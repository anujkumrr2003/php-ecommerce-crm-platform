<?php
/*
| webcore/orders.php - everything that happens to an order after it is created:
|   payment confirmed -> placed -> processing -> shipped -> delivered
|   cancel / return / refund, stock give-back, CRM invoice (bill) create / remove, e-mails, bell notification.
| Used by the website (checkout, payment verify, webhook, customer cancel / return)
| and by the CRM (admin changes status, tracking, refund, return decision).
*/

function wc_order_get($idOrNo): ?array
{
    return is_numeric($idOrNo) && (int)$idOrNo == $idOrNo
        ? wc_one('SELECT * FROM web_orders WHERE id = ?', 'i', [(int)$idOrNo])
        : wc_one('SELECT * FROM web_orders WHERE order_no = ?', 's', [(string)$idOrNo]);
}

function wc_order_items(int $orderId): array
{
    return wc_all('SELECT * FROM web_order_items WHERE order_id = ? ORDER BY id', 'i', [$orderId]);
}

function wc_order_history(int $orderId, string $status, string $note = '', string $by = ''): void
{
    wc_run('INSERT INTO web_order_history (order_id, status, note, by_name) VALUES (?,?,?,?)', 'isss', [$orderId, $status, mb_substr($note, 0, 500), mb_substr($by, 0, 120)]);
}

function wc_order_mail(string $tpl, array $order, array $extra = []): void
{
    $order = wc_order_get((int)$order['id']) ?: $order;      // fresh data (tracking etc.)
    wc_after_response(function () use ($tpl, $order, $extra) {
        wc_send_template($tpl, $order['email'], wc_order_email_vars($order, $extra));
    });
}

/* --------------------------------------------------------------- stock */

function wc_stock_take(int $variantId, int $qty): bool
{
    return wc_run('UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?', 'iii', [$qty, $variantId, $qty]) === 1;
}

function wc_order_restore_stock(int $orderId): void
{
    $o = wc_one('SELECT stock_reserved FROM web_orders WHERE id = ? FOR UPDATE', 'i', [$orderId]);
    if (!$o || !(int)$o['stock_reserved']) {
        return;
    }
    foreach (wc_order_items($orderId) as $it) {
        if ($it['variant_id']) {
            wc_run('UPDATE product_variants SET stock = stock + ? WHERE id = ?', 'ii', [(int)$it['qty'], (int)$it['variant_id']]);
        }
    }
    wc_run('UPDATE web_orders SET stock_reserved = 0 WHERE id = ?', 'i', [$orderId]);
}

/* ---------------------------------------------------- CRM invoice (bill) */

function wc_invoice_no(): string
{
    wc_run("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('invoice_prefix','INV-'), ('next_invoice_number','1001')");
    $v = [];
    foreach (wc_all("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('invoice_prefix','next_invoice_number') FOR UPDATE") as $r) {
        $v[$r['setting_key']] = (string)$r['setting_value'];
    }
    $prefix = $v['invoice_prefix'] ?? 'INV-';
    $n = max(1, (int)($v['next_invoice_number'] ?? 1001));
    for ($i = 0; $i < 1000 && wc_one('SELECT 1 AS x FROM bills WHERE invoice_no = ?', 's', [$prefix . $n]); $i++) {
        $n++;
    }
    wc_run("UPDATE settings SET setting_value = ? WHERE setting_key = 'next_invoice_number'", 's', [(string)($n + 1)]);
    wc_settings_all(true);
    return $prefix . $n;
}

function wc_bill_method(string $gw): string
{
    $m = strtolower($gw);
    return $m === 'card' ? 'Card' : ($m === 'netbanking' ? 'Bank Transfer' : ($m === 'cod' ? 'Cash' : 'UPI'));
}

/** creates the CRM bill for a confirmed order (once). Returns bill id. */
function wc_order_create_bill(int $orderId, string $payMethod, bool $paid): int
{
    $o = wc_one('SELECT * FROM web_orders WHERE id = ? FOR UPDATE', 'i', [$orderId]);
    if (!$o) {
        throw new RuntimeException('Order not found');
    }
    if ((int)$o['bill_id'] > 0 && wc_one('SELECT id FROM bills WHERE id = ?', 'i', [(int)$o['bill_id']])) {
        return (int)$o['bill_id'];
    }
    $incl  = wc_setting('web_prices_include_gst', '1') === '1';
    $items = wc_order_items($orderId);
    $sub = $disc = $gst = 0.0;
    $rows = [];
    foreach ($items as $it) {
        $g      = (float)$it['gst_percent'];
        $netAft = round((float)$it['line_total'] - (float)$it['line_tax'], 2);
        $dNet   = $incl && $g > 0 ? round((float)$it['line_discount'] / (1 + $g / 100), 2) : (float)$it['line_discount'];
        $gross  = round($netAft + $dNet, 2);
        $sub += $gross;
        $disc += $dNet;
        $gst += (float)$it['line_tax'];
        $rows[] = [$it, $gross, $dNet, $gross > 0 ? round($dNet * 100 / $gross, 2) : 0];
    }
    $ship = round((float)$o['shipping_fee'] + (float)$o['cod_fee'], 2);
    $total = round($sub - $disc + $gst + $ship, 2);
    $inv = wc_invoice_no();
    wc_run('INSERT INTO bills (invoice_no, invoice_date, customer_id, customer_name, customer_mobile, customer_city, payment_method, payment_status, subtotal, discount, gst, total)
            VALUES (?,CURDATE(),?,?,?,?,?,?,?,?,?,?)', 'sisssssdddd',
        [$inv, $o['customer_id'] ?: null, $o['ship_name'], $o['ship_mobile'], $o['ship_city'], wc_bill_method($payMethod), $paid ? 'Paid' : 'Pending', round($sub + $ship, 2), $disc, $gst, $total]);
    $billId = wc_insert_id();
    foreach ($rows as [$it, $gross, $dNet, $dPct]) {
        $qty  = max(1, (int)$it['qty']);
        wc_run('INSERT INTO bill_items (bill_id, product_id, variant_id, item_name, sku, size, color, qty, rate, disc_percent, gst_percent, line_subtotal, line_discount, line_gst, line_total)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iiissssiddddddd',
            [$billId, $it['product_id'], $it['variant_id'], $it['name'], $it['sku'], $it['size'], $it['color'], $qty, round($gross / $qty, 2), $dPct, $it['gst_percent'], $gross, $dNet, $it['line_tax'], $it['line_total']]);
    }
    if ($ship > 0) {
        wc_run("INSERT INTO bill_items (bill_id, item_name, sku, qty, rate, disc_percent, gst_percent, line_subtotal, line_discount, line_gst, line_total) VALUES (?,?,?,?,?,0,0,?,0,0,?)",
            'issiddd', [$billId, 'Shipping & handling', 'SHIP', 1, $ship, $ship, $ship]);
    }
    wc_run('UPDATE web_orders SET bill_id = ?, invoice_no = ? WHERE id = ?', 'isi', [$billId, $inv, $orderId]);
    return $billId;
}

function wc_order_delete_bill(array $o): void
{
    if ((int)$o['bill_id'] > 0) {
        wc_run('DELETE FROM bills WHERE id = ?', 'i', [(int)$o['bill_id']]);     // bill_items go with it (CASCADE)
        wc_run("UPDATE web_orders SET bill_id = NULL WHERE id = ?", 'i', [(int)$o['id']]);
    }
}

function wc_coupon_commit(array $o): void
{
    if ($o['coupon_code'] === '') {
        return;
    }
    $c = wc_one('SELECT id FROM web_coupons WHERE code = ?', 's', [$o['coupon_code']]);
    if ($c && wc_run('INSERT IGNORE INTO web_coupon_usage (coupon_id, order_id, web_user_id) VALUES (?,?,?)', 'iii', [(int)$c['id'], (int)$o['id'], $o['web_user_id'] ?: null]) === 1) {
        wc_run('UPDATE web_coupons SET used_count = used_count + 1 WHERE id = ?', 'i', [(int)$c['id']]);
    }
}

function wc_coupon_release(array $o): void
{
    if (wc_run('DELETE FROM web_coupon_usage WHERE order_id = ?', 'i', [(int)$o['id']]) === 1 && $o['coupon_code'] !== '') {
        wc_run('UPDATE web_coupons SET used_count = GREATEST(used_count - 1, 0) WHERE code = ?', 's', [$o['coupon_code']]);
    }
}

/* ------------------------------------------------------- confirm / pay */

function wc_order_announce(array $o, bool $paidOnline): void
{
    wc_crm_notify('web_order', 'New website order #' . $o['order_no'], wc_money($o['total']) . ' - ' . $o['ship_name'] . ' (' . ($paidOnline ? 'paid online' : 'cash on delivery') . ')',
        'web-orders.php?id=' . (int)$o['id'], 'bag-check', 'green', 'web.orders.view', 'web_order:' . (int)$o['id']);
    wc_order_mail('order_placed', $o);
    if ($paidOnline) {
        $p = wc_one("SELECT gateway_payment_id FROM web_payments WHERE order_id = ? AND status = 'paid' ORDER BY id DESC LIMIT 1", 'i', [(int)$o['id']]);
        wc_order_mail('payment_success', $o, ['payment_id' => $p['gateway_payment_id'] ?? '']);
    }
    $admin = wc_brand()['email'];
    if ($admin !== '') {
        wc_after_response(function () use ($o, $admin) {
            wc_send_template('admin_new_order', $admin, wc_order_email_vars(wc_order_get((int)$o['id']) ?: $o));
        });
    }
}

/** online payment succeeded (called from browser verify AND from webhook - safe to call twice) */
function wc_order_mark_paid(int $orderId, string $gwOrderId, string $gwPaymentId, string $method): bool
{
    $done = wc_tx(function () use ($orderId, $gwOrderId, $gwPaymentId, $method) {
        $o = wc_one('SELECT * FROM web_orders WHERE id = ? FOR UPDATE', 'i', [$orderId]);
        if (!$o) {
            return false;
        }
        if ($o['payment_status'] === 'paid') {
            return null;                                                       // already done
        }
        if ($o['status'] === 'cancelled') {                                    // paid after we cancelled it -> money must go back
            return 'late';
        }
        if (!(int)$o['stock_reserved']) {                                      // reservation expired: take stock again
            $taken = [];
            foreach (wc_order_items($orderId) as $it) {
                if ($it['variant_id'] && !wc_stock_take((int)$it['variant_id'], (int)$it['qty'])) {
                    foreach ($taken as [$v, $q]) {
                        wc_run('UPDATE product_variants SET stock = stock + ? WHERE id = ?', 'ii', [$q, $v]);
                    }
                    return 'late';
                }
                if ($it['variant_id']) {
                    $taken[] = [(int)$it['variant_id'], (int)$it['qty']];
                }
            }
            wc_run('UPDATE web_orders SET stock_reserved = 1 WHERE id = ?', 'i', [$orderId]);
        }
        wc_run("UPDATE web_orders SET payment_status = 'paid', paid_at = NOW(), placed_at = COALESCE(placed_at, NOW()), status = IF(status IN ('pending_payment','payment_failed'), 'placed', status) WHERE id = ?", 'i', [$orderId]);
        wc_run("UPDATE web_payments SET status = 'paid', gateway_payment_id = ?, method = ?, error = '' WHERE gateway_order_id = ?", 'sss', [$gwPaymentId, $method, $gwOrderId]);
        wc_order_create_bill($orderId, $method, true);
        wc_coupon_commit($o);
        wc_order_history($orderId, 'placed', 'Payment received (' . $gwPaymentId . ')', 'System');
        return true;
    });
    if ($done === 'late') {
        $o = wc_order_get($orderId);
        wc_run("UPDATE web_payments SET status = 'paid', gateway_payment_id = ?, method = ? WHERE gateway_order_id = ?", 'sss', [$gwPaymentId, $method, $gwOrderId]);
        wc_run("UPDATE web_orders SET payment_status = 'paid', paid_at = NOW() WHERE id = ?", 'i', [$orderId]);
        [$ok, $msg] = wc_order_refund($orderId, 'Payment arrived after the order expired or stock ended', 'System');
        wc_order_history($orderId, 'cancelled', 'Late payment ' . ($ok ? 'refunded automatically' : 'needs manual refund: ' . $msg), 'System');
        wc_crm_notify('web_order', 'Late payment needs attention - #' . $o['order_no'], 'Payment arrived after expiry. ' . ($ok ? 'Refunded automatically.' : 'Please refund manually.'), 'web-orders.php?id=' . $orderId, 'exclamation-triangle', 'red', 'web.orders.view', 'web_late:' . $orderId);
        return false;
    }
    if ($done === true) {
        wc_order_announce(wc_order_get($orderId), true);
    }
    return $done !== false;
}

function wc_order_confirm_cod(int $orderId): void
{
    $o = wc_tx(function () use ($orderId) {
        $o = wc_one('SELECT * FROM web_orders WHERE id = ? FOR UPDATE', 'i', [$orderId]);
        if (!$o || $o['status'] !== 'pending_payment') {
            return null;
        }
        wc_run("UPDATE web_orders SET status = 'placed', payment_status = 'cod', placed_at = NOW() WHERE id = ?", 'i', [$orderId]);
        wc_order_create_bill($orderId, 'cod', false);
        wc_coupon_commit($o);
        wc_order_history($orderId, 'placed', 'Cash on delivery order placed', 'Customer');
        return $o;
    });
    if ($o) {
        wc_order_announce(wc_order_get($orderId), false);
    }
}

function wc_order_payment_failed(int $orderId, string $gwOrderId, string $error): void
{
    $o = wc_order_get($orderId);
    if (!$o || $o['payment_status'] === 'paid' || in_array($o['status'], ['cancelled', 'placed', 'processing', 'shipped', 'delivered'], true)) {
        return;
    }
    wc_run("UPDATE web_payments SET status = 'failed', error = ? WHERE gateway_order_id = ? AND status <> 'paid'", 'ss', [mb_substr($error, 0, 300), $gwOrderId]);
    $first = $o['status'] !== 'payment_failed';
    wc_run("UPDATE web_orders SET status = 'payment_failed', payment_status = 'failed' WHERE id = ?", 'i', [$orderId]);
    wc_order_history($orderId, 'payment_failed', $error, 'System');
    if ($first) {
        wc_order_mail('payment_failed', $o);
        wc_crm_notify('web_order', 'Payment failed - #' . $o['order_no'], wc_money($o['total']) . ' - ' . $o['ship_name'], 'web-orders.php?id=' . $orderId, 'x-octagon', 'red', 'web.orders.view', 'web_payfail:' . $orderId . ':' . time());
    }
}

/* --------------------------------------------------- cancel / refund */

/** refund the whole order amount through Razorpay. @return array{0:bool,1:string} */
function wc_order_refund(int $orderId, string $reason, string $by): array
{
    $o = wc_order_get($orderId);
    if (!$o) {
        return [false, 'Order not found'];
    }
    $pay = wc_one("SELECT * FROM web_payments WHERE order_id = ? AND status = 'paid' ORDER BY id DESC LIMIT 1", 'i', [$orderId]);
    if (!$pay || $pay['gateway_payment_id'] === '') {
        // cash on delivery / manual: nothing to send through the gateway
        wc_run("INSERT INTO web_refunds (order_id, amount, status, reason) VALUES (?,?, 'manual', ?)", 'ids', [$orderId, $o['total'], mb_substr($reason, 0, 300)]);
        wc_run("UPDATE web_orders SET payment_status = 'refunded' WHERE id = ?", 'i', [$orderId]);
        wc_order_mail('refund_processed', $o, ['refund_amount' => wc_money($o['total']), 'refund_note' => 'We will send the amount to your bank account / UPI within 5-7 working days. Please keep your payment details handy in case we need to contact you.']);
        return [true, 'Marked as refunded (no online payment to reverse - send the money to the customer yourself).'];
    }
    if (!wc_rzp_enabled() && wc_setting('web_rzp_key_secret') === '') {
        return [false, 'Razorpay keys are not set, cannot refund automatically.'];
    }
    [$ok, $res] = wc_rzp_refund($pay['gateway_payment_id'], (float)$o['total'], ['order_no' => $o['order_no'], 'reason' => mb_substr($reason, 0, 100)]);
    if (!$ok) {
        wc_run("INSERT INTO web_refunds (order_id, amount, status, reason, error) VALUES (?,?, 'failed', ?, ?)", 'idss', [$orderId, $o['total'], mb_substr($reason, 0, 300), mb_substr((string)$res, 0, 300)]);
        return [false, (string)$res];
    }
    wc_run("INSERT INTO web_refunds (order_id, gateway_refund_id, amount, status, reason) VALUES (?,?,?,'processed',?)", 'isds', [$orderId, (string)($res['id'] ?? ''), $o['total'], mb_substr($reason, 0, 300)]);
    wc_run("UPDATE web_payments SET status = 'refunded' WHERE id = ?", 'i', [(int)$pay['id']]);
    wc_run("UPDATE web_orders SET payment_status = 'refunded' WHERE id = ?", 'i', [$orderId]);
    wc_order_history($orderId, $o['status'], 'Refund of ' . wc_money($o['total']) . ' sent to Razorpay (' . ($res['id'] ?? '') . ')', $by);
    wc_order_mail('refund_processed', $o, ['refund_amount' => wc_money($o['total']), 'refund_note' => 'The amount will reach your original payment method within 5-7 working days.']);
    return [true, 'Refund of ' . wc_money($o['total']) . ' sent to Razorpay.'];
}

/** @return array{0:bool,1:string} */
function wc_order_cancel(int $orderId, string $reason, string $by, bool $silent = false): array
{
    $o = wc_order_get($orderId);
    if (!$o) {
        return [false, 'Order not found'];
    }
    if (!in_array($o['status'], ['pending_payment', 'payment_failed', 'placed', 'processing'], true)) {
        return [false, 'This order can no longer be cancelled (status: ' . (WC_STATUSES[$o['status']] ?? $o['status']) . ').'];
    }
    wc_tx(function () use ($o, $reason, $by) {
        wc_run("UPDATE web_orders SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE id = ?", 'si', [mb_substr($reason, 0, 300), (int)$o['id']]);
        wc_order_restore_stock((int)$o['id']);
        wc_order_delete_bill($o);
        wc_coupon_release($o);
        wc_order_history((int)$o['id'], 'cancelled', $reason, $by);
    });
    $note = '';
    $msg  = 'Order cancelled.';
    if ($o['payment_status'] === 'paid') {
        [$ok, $m] = wc_order_refund((int)$o['id'], $reason, $by);
        $note = $ok ? 'Your payment will be refunded to your original payment method within 5-7 working days.' : 'We will refund your payment shortly.';
        $msg .= ' ' . ($ok ? $m : 'Refund needs attention: ' . $m);
        if (!$ok) {
            wc_crm_notify('web_order', 'Refund failed - #' . $o['order_no'], $m, 'web-orders.php?id=' . (int)$o['id'], 'exclamation-triangle', 'red', 'web.orders.manage', 'web_refundfail:' . (int)$o['id'] . ':' . time());
        }
    }
    if (!$silent) {
        wc_order_mail('order_cancelled', $o, ['reason' => $reason, 'refund_note' => $note]);
    }
    return [true, $msg];
}

/** remove abandoned online orders and give their stock back */
function wc_expire_unpaid_orders(): int
{
    $min = max(10, (int)wc_setting('web_unpaid_expiry_min', '45'));
    $n = 0;
    foreach (wc_all("SELECT id FROM web_orders WHERE status IN ('pending_payment','payment_failed') AND created_at < DATE_SUB(NOW(), INTERVAL $min MINUTE) LIMIT 50") as $r) {
        [$ok] = wc_order_cancel((int)$r['id'], 'Payment not completed in time', 'System', true);
        $n += $ok ? 1 : 0;
    }
    return $n;
}

/* ----------------------------------------------------------- status flow */

const WC_NEXT = [
    'placed'           => ['processing', 'shipped', 'cancelled'],
    'processing'       => ['shipped', 'cancelled'],
    'shipped'          => ['out_for_delivery', 'delivered'],
    'out_for_delivery' => ['delivered'],
    'delivered'        => ['refunded'],
    'return_requested' => ['return_approved', 'delivered'],
    'return_approved'  => ['returned'],
    'returned'         => ['refunded'],
    'pending_payment'  => ['cancelled'],
    'payment_failed'   => ['cancelled'],
];

function wc_order_next_statuses(array $o): array
{
    return WC_NEXT[$o['status']] ?? [];
}

/**
 * Admin changes the status.  $opts: note, by, courier, tracking_no, tracking_url
 * @return array{0:bool,1:string}
 */
function wc_order_set_status(int $orderId, string $to, array $opts = []): array
{
    $o = wc_order_get($orderId);
    if (!$o) {
        return [false, 'Order not found'];
    }
    $by   = (string)($opts['by'] ?? 'Admin');
    $note = trim((string)($opts['note'] ?? ''));
    if (!in_array($to, wc_order_next_statuses($o), true)) {
        return [false, 'You cannot move this order from "' . (WC_STATUSES[$o['status']] ?? $o['status']) . '" to "' . (WC_STATUSES[$to] ?? $to) . '".'];
    }
    if ($to === 'cancelled') {
        return wc_order_cancel($orderId, $note !== '' ? $note : 'Cancelled by store', $by);
    }
    if ($to === 'refunded') {
        [$ok, $m] = wc_order_refund($orderId, $note !== '' ? $note : 'Refund', $by);
        if (!$ok) {
            return [false, $m];
        }
        wc_tx(function () use ($o, $orderId, $by, $note) {
            wc_run("UPDATE web_orders SET status = 'refunded' WHERE id = ?", 'i', [$orderId]);
            wc_order_delete_bill($o);
            wc_run("UPDATE web_returns SET status = 'refunded' WHERE order_id = ? AND status IN ('approved','received')", 'i', [$orderId]);
            wc_order_history($orderId, 'refunded', $note, $by);
        });
        return [true, $m];
    }
    if ($to === 'shipped' && trim((string)($opts['courier'] ?? '')) === '' && trim((string)($opts['tracking_no'] ?? '')) === '') {
        return [false, 'Enter the courier name or the tracking number before marking the order as shipped.'];
    }
    wc_tx(function () use ($o, $orderId, $to, $by, $note, $opts) {
        $set = ['status = ?'];
        $ty = 's';
        $val = [$to];
        if ($to === 'shipped') {
            $set[] = 'shipped_at = NOW()';
            foreach (['courier' => 80, 'tracking_no' => 80, 'tracking_url' => 255] as $k => $max) {
                $set[] = "$k = ?";
                $ty .= 's';
                $val[] = mb_substr(trim((string)($opts[$k] ?? '')), 0, $max);
            }
        } elseif ($to === 'delivered') {
            $set[] = 'delivered_at = COALESCE(delivered_at, NOW())';
            if ($o['payment_method'] === 'cod' && $o['payment_status'] !== 'paid') {
                $set[] = "payment_status = 'paid'";
                $set[] = 'paid_at = NOW()';
                if ((int)$o['bill_id'] > 0) {
                    wc_run("UPDATE bills SET payment_status = 'Paid' WHERE id = ?", 'i', [(int)$o['bill_id']]);
                }
            }
        } elseif ($to === 'returned') {
            wc_run("UPDATE web_returns SET status = 'received' WHERE order_id = ? AND status = 'approved'", 'i', [$orderId]);
            if (!empty($opts['restock'])) {
                foreach (wc_order_items($orderId) as $it) {
                    if ($it['variant_id']) {
                        wc_run('UPDATE product_variants SET stock = stock + ? WHERE id = ?', 'ii', [(int)$it['qty'], (int)$it['variant_id']]);
                    }
                }
            }
        } elseif ($to === 'return_approved') {
            wc_run("UPDATE web_returns SET status = 'approved', admin_note = ? WHERE order_id = ? AND status = 'requested'", 'si', [$note, $orderId]);
        }
        $val[] = $orderId;
        wc_run('UPDATE web_orders SET ' . implode(', ', $set) . ' WHERE id = ?', $ty . 'i', $val);
        wc_order_history($orderId, $to, $note, $by);
    });
    if ($o['status'] === 'return_requested' && $to === 'delivered') {      // return declined
        wc_run("UPDATE web_returns SET status = 'rejected', admin_note = ? WHERE order_id = ? AND status = 'requested'", 'si', [$note, $orderId]);
        wc_order_mail('return_rejected', $o, ['admin_note' => $note !== '' ? $note : 'The item does not meet our return conditions.']);
    } else {
        $tpl = ['shipped' => 'order_shipped', 'out_for_delivery' => 'out_for_delivery', 'delivered' => 'order_delivered', 'return_approved' => 'return_approved'][$to] ?? '';
        if ($tpl !== '') {
            wc_order_mail($tpl, $o, ['admin_note' => $note !== '' ? $note : 'Please pack the item(s) with the original tags. Our courier partner will contact you.']);
        }
    }
    return [true, 'Status changed to "' . (WC_STATUSES[$to] ?? $to) . '".'];
}

/* ------------------------------------------------------ customer actions */

function wc_order_can_cancel(array $o): bool
{
    return in_array($o['status'], ['pending_payment', 'payment_failed', 'placed', 'processing'], true);
}

function wc_order_can_return(array $o): bool
{
    if ($o['status'] !== 'delivered' || !$o['delivered_at']) {
        return false;
    }
    $days = (int)wc_setting('web_return_days', '7');
    return $days > 0 && strtotime((string)$o['delivered_at']) >= time() - $days * 86400;
}

/** @return array{0:bool,1:string} */
function wc_order_request_return(int $orderId, string $reason, string $details): array
{
    $o = wc_order_get($orderId);
    if (!$o || !wc_order_can_return($o)) {
        return [false, 'This order is not eligible for return (return window is ' . (int)wc_setting('web_return_days', '7') . ' days after delivery).'];
    }
    wc_tx(function () use ($orderId, $reason, $details) {
        wc_run('INSERT INTO web_returns (order_id, reason, details) VALUES (?,?,?)', 'iss', [$orderId, mb_substr($reason, 0, 120), mb_substr($details, 0, 800)]);
        wc_run("UPDATE web_orders SET status = 'return_requested' WHERE id = ?", 'i', [$orderId]);
        wc_order_history($orderId, 'return_requested', $reason, 'Customer');
    });
    wc_order_mail('return_requested', $o);
    wc_crm_notify('web_order', 'Return requested - #' . $o['order_no'], $reason . ' - ' . $o['ship_name'], 'web-orders.php?id=' . $orderId, 'arrow-return-left', 'amber', 'web.orders.view', 'web_return:' . $orderId . ':' . time());
    return [true, 'Return request sent. We will email you within 1-2 working days.'];
}
