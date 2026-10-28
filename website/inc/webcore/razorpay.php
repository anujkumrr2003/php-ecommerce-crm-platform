<?php
/*
| webcore/razorpay.php - Razorpay REST API.
| Key ID / Key Secret / Webhook secret come from CRM -> Website -> Payment settings (table `settings`).
| Nothing here is hard-coded: every client uses his own keys.
*/
if (!defined('WC_RZP_API')) {
    define('WC_RZP_API', 'https://api.razorpay.com/v1');   // tests can point this to a mock server
}

function wc_rzp_enabled(): bool
{
    return wc_setting('web_rzp_enabled', '0') === '1' && wc_setting('web_rzp_key_id') !== '' && wc_setting('web_rzp_key_secret') !== '';
}

function wc_rzp_key_id(): string
{
    return wc_setting('web_rzp_key_id');
}

/** @return array{0:bool,1:array|string} [ok, data|error message] */
function wc_rzp_call(string $method, string $path, array $data = []): array
{
    $ch = curl_init(WC_RZP_API . $path);
    $opt = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERPWD => wc_setting('web_rzp_key_id') . ':' . wc_setting('web_rzp_key_secret'),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($data && $method !== 'GET') {
        $opt[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return [false, 'Could not reach Razorpay: ' . $err];
    }
    $j = json_decode((string)$body, true);
    if ($code >= 400 || !is_array($j)) {
        return [false, is_array($j) && isset($j['error']['description']) ? (string)$j['error']['description'] : 'Razorpay error (HTTP ' . $code . ')'];
    }
    return [true, $j];
}

/** create a Razorpay order for one of our orders. Amount in rupees -> paise */
function wc_rzp_create_order(array $order): array
{
    return wc_rzp_call('POST', '/orders', [
        'amount' => (int)round((float)$order['total'] * 100), 'currency' => 'INR', 'receipt' => $order['order_no'],
        'notes' => ['order_no' => $order['order_no']],
    ]);
}

/** Checkout signature = HMAC_SHA256(razorpay_order_id|razorpay_payment_id, key_secret) */
function wc_rzp_verify_signature(string $rzpOrderId, string $paymentId, string $signature): bool
{
    $secret = wc_setting('web_rzp_key_secret');
    if ($secret === '' || $rzpOrderId === '' || $paymentId === '' || $signature === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $rzpOrderId . '|' . $paymentId, $secret), $signature);
}

function wc_rzp_verify_webhook(string $rawBody, string $signature): bool
{
    $secret = wc_setting('web_rzp_webhook_secret');
    return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
}

function wc_rzp_fetch_payment(string $paymentId): array
{
    return wc_rzp_call('GET', '/payments/' . rawurlencode($paymentId));
}

function wc_rzp_capture(string $paymentId, float $amount): array
{
    return wc_rzp_call('POST', '/payments/' . rawurlencode($paymentId) . '/capture', ['amount' => (int)round($amount * 100), 'currency' => 'INR']);
}

function wc_rzp_refund(string $paymentId, float $amount, array $notes = []): array
{
    return wc_rzp_call('POST', '/payments/' . rawurlencode($paymentId) . '/refund', ['amount' => (int)round($amount * 100), 'speed' => 'normal', 'notes' => $notes]);
}
