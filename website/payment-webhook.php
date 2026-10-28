<?php
/*
| payment-webhook.php - Razorpay calls this by itself (even when the customer closes the browser).
| Add this URL in Razorpay Dashboard -> Webhooks:   https://YOUR-SITE/payment/webhook
| Events: payment.captured, order.paid, payment.failed, refund.processed.  Secret = "Webhook secret" in CRM -> Website settings -> Payments.
*/
define('SHOP_ALLOW_MAINTENANCE', true);
define('SHOP_NO_HOUSEKEEPING', true);
require_once __DIR__ . '/inc/bootstrap.php';

$raw = (string)file_get_contents('php://input');
if (!wc_rzp_verify_webhook($raw, (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''))) {
    http_response_code(400);
    exit('invalid signature');
}
$ev = json_decode($raw, true) ?: [];
$type = (string)($ev['event'] ?? '');
$pay  = $ev['payload']['payment']['entity'] ?? [];
$ordE = $ev['payload']['order']['entity'] ?? [];
$rzpOrder = (string)($pay['order_id'] ?? ($ordE['id'] ?? ''));
$row = $rzpOrder !== '' ? wc_one('SELECT p.*, o.total FROM web_payments p JOIN web_orders o ON o.id = p.order_id WHERE p.gateway_order_id = ?', 's', [$rzpOrder]) : null;
try {
    if ($row) {
        if (in_array($type, ['payment.captured', 'order.paid'], true) && (int)round((float)($pay['amount'] ?? $ordE['amount_paid'] ?? 0)) === (int)round((float)$row['total'] * 100)) {
            wc_order_mark_paid((int)$row['order_id'], $rzpOrder, (string)($pay['id'] ?? ''), (string)($pay['method'] ?? 'upi'));
        } elseif ($type === 'payment.failed') {
            wc_order_payment_failed((int)$row['order_id'], $rzpOrder, mb_substr((string)($pay['error_description'] ?? 'Payment failed'), 0, 250));
        }
    }
    if ($type === 'refund.processed') {
        $rid = (string)($ev['payload']['refund']['entity']['id'] ?? '');
        if ($rid !== '') {
            wc_run("UPDATE web_refunds SET status = 'processed' WHERE gateway_refund_id = ?", 's', [$rid]);
        }
    }
} catch (Throwable $e) {
    error_log('webhook: ' . $e->getMessage());
    http_response_code(500);
    exit('error');
}
echo 'ok';
