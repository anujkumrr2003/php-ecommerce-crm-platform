<?php
/* payment-failed.php - Razorpay window reported a failed attempt */
require_once __DIR__ . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false], 405);
}
csrf_require(true);
$u = user();
$pay = $u ? wc_one('SELECT p.order_id FROM web_payments p JOIN web_orders o ON o.id = p.order_id WHERE p.gateway_order_id = ? AND o.web_user_id = ?', 'si', [(string)($_POST['razorpay_order_id'] ?? ''), (int)$u['id']]) : null;
if ($pay) {
    wc_order_payment_failed((int)$pay['order_id'], (string)$_POST['razorpay_order_id'], mb_substr((string)($_POST['error'] ?? 'Payment failed'), 0, 250));
}
json_out(['success' => true]);
