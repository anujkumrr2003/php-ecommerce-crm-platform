<?php
/* payment-verify.php - the browser tells us "payment done"; we CHECK the signature with Razorpay's secret before believing it */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/cart.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Invalid request.'], 405);
}
csrf_require(true);
$u = user();
if (!$u) {
    json_out(['success' => false, 'message' => 'Please log in again.'], 401);
}
$rzpOrder = (string)($_POST['razorpay_order_id'] ?? '');
$rzpPay   = (string)($_POST['razorpay_payment_id'] ?? '');
$sig      = (string)($_POST['razorpay_signature'] ?? '');
$pay = wc_one('SELECT p.*, o.web_user_id, o.order_no, o.total FROM web_payments p JOIN web_orders o ON o.id = p.order_id WHERE p.gateway_order_id = ?', 's', [$rzpOrder]);
if (!$pay || (int)$pay['web_user_id'] !== (int)$u['id']) {
    json_out(['success' => false, 'message' => 'Payment record not found.'], 404);
}
if (!wc_rzp_verify_signature($rzpOrder, $rzpPay, $sig)) {
    wc_order_payment_failed((int)$pay['order_id'], $rzpOrder, 'Signature mismatch - payment not verified');
    wc_log_activity((int)$u['id'], 'payment_bad_signature', $pay['order_no']);
    json_out(['success' => false, 'message' => 'We could not verify this payment. If money was deducted it will be refunded automatically.'], 400);
}
/* signature is genuine. Ask Razorpay for the payment (method + status) and capture it if it is only authorised */
$method = '';
[$ok, $rp] = wc_rzp_fetch_payment($rzpPay);
if ($ok) {
    $method = (string)($rp['method'] ?? '');
    if (($rp['status'] ?? '') === 'authorized') {
        [$cok] = wc_rzp_capture($rzpPay, (float)$pay['total']);
        if (!$cok) {
            json_out(['success' => false, 'message' => 'Payment is authorised but could not be completed. Please contact us.'], 502);
        }
    } elseif (!in_array($rp['status'] ?? '', ['captured'], true)) {
        wc_order_payment_failed((int)$pay['order_id'], $rzpOrder, 'Payment status: ' . ($rp['status'] ?? 'unknown'));
        json_out(['success' => false, 'message' => 'Payment was not successful.'], 400);
    }
    if ((int)round((float)($rp['amount'] ?? 0)) !== (int)round((float)$pay['total'] * 100)) {
        wc_order_payment_failed((int)$pay['order_id'], $rzpOrder, 'Amount mismatch');
        json_out(['success' => false, 'message' => 'Payment amount did not match the order.'], 400);
    }
}
wc_order_mark_paid((int)$pay['order_id'], $rzpOrder, $rzpPay, $method !== '' ? $method : 'upi');
wc_log_activity((int)$u['id'], 'payment_success', $pay['order_no']);
json_out(['success' => true, 'redirect' => url('order-success/' . $pay['order_no'])]);
