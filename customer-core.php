<?php
/*
|--------------------------------------------------------------------------
| customer-core.php  -  shared code
|--------------------------------------------------------------------------
| Used by: customer-form.php (admin / Self Added)
|          cron-sync-apis.php (Housing, 99acres, REI ... API leads)
|
| Provides: $conn (via config.php), WhatsApp sending, message_history,
|           and customers.wa_status tracking.
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/config.php';          // must create $conn = mysqli_connect(...)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+05:30'");

/* ---------- WhatsApp (Whatsupbizto) settings ---------- */
if (!defined('WA_API_BASE'))        define('WA_API_BASE', 'https://whatsupbizto.com/api');
if (!defined('WA_VENDOR_UID'))      define('WA_VENDOR_UID', '');
if (!defined('WA_PHONE_NUMBER_ID')) define('WA_PHONE_NUMBER_ID', '');

if (!defined('NC_TEMPLATE'))    define('NC_TEMPLATE', 'new_customer');       // must be Approved
if (!defined('NC_STORE_NAME'))  define('NC_STORE_NAME', (function () { global $conn; $r = isset($conn) && $conn ? @mysqli_fetch_row(@mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'store_name'")) : null; return ($r && $r[0] !== '') ? $r[0] : 'Our Store'; })()); // {{2}}
if (!defined('NC_LOCAL_TOKEN')) define('NC_LOCAL_TOKEN', 'PASTE_YOUR_NEW_TOKEN_HERE');

/* Max kitni baar ek customer ko WhatsApp retry karna hai */
if (!defined('WA_MAX_ATTEMPTS')) define('WA_MAX_ATTEMPTS', 3);

if (!class_exists('UserError')) {
    /* An error that is safe to show to the user (validation messages) */
    class UserError extends Exception {}
}

function cleanValue($value): string
{
    return is_array($value) ? '' : trim((string)($value ?? ''));
}

/* Valid Y-m-d date or null (empty). Throws UserError for a bad date. */
function validDateOrNull(string $value, string $label): ?string
{
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        throw new UserError('Invalid date for ' . $label . '.');
    }
    return $value;
}

/* Any input -> 10 digit Indian mobile, or '' if invalid */
function normalizeMobile(string $raw): string
{
    $m = preg_replace('/\D+/', '', $raw);
    if (strlen($m) > 10 && preg_match('/^(91|0)/', $m)) {
        $m = substr($m, -10);
    }
    return preg_match('/^[6-9][0-9]{9}$/', $m) ? $m : '';
}

/*
|--------------------------------------------------------------------------
| WhatsApp helpers
|--------------------------------------------------------------------------
*/
function ncToken(): string
{
    if (defined('WA_API_TOKEN')) {
        $t = trim((string)constant('WA_API_TOKEN'));
        if ($t !== '' && stripos($t, 'PASTE') === false) {
            return $t;
        }
    }
    $t = trim(NC_LOCAL_TOKEN);
    return stripos($t, 'PASTE') === false ? $t : '';
}

function ncClean(string $s): string
{
    $s = preg_replace('/[\r\n\t]+/', ' ', $s);
    $s = preg_replace('/ {2,}/', ' ', $s);
    $s = trim($s);
    return $s !== '' ? $s : '-';
}

/* 10 digit number -> 91XXXXXXXXXX ('' if invalid) */
function ncMobile(string $mobile): string
{
    $n = preg_replace('/\D+/', '', $mobile);
    if (strlen($n) === 10) {
        $n = '91' . $n;
    } elseif (strlen($n) === 11 && $n[0] === '0') {
        $n = '91' . substr($n, 1);
    }
    return (strlen($n) >= 11 && strlen($n) <= 15) ? $n : '';
}

/* Save one result row in message_history */
function ncHistory(mysqli $conn, int $cid, string $mobile, string $status, string $waId, string $error, string $source = 'welcome'): void
{
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `message_history` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `rtype` VARCHAR(20) NOT NULL,
            `occ_date` DATE NULL,
            `mobile` VARCHAR(20) NOT NULL DEFAULT '',
            `source` VARCHAR(12) NOT NULL DEFAULT 'manual',
            `status` VARCHAR(10) NOT NULL DEFAULT 'sent',
            `wa_id` VARCHAR(120) NOT NULL DEFAULT '',
            `error` VARCHAR(500) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hist_cust` (`customer_id`, `created_at`),
            KEY `idx_hist_created` (`created_at`),
            KEY `idx_hist_occ` (`rtype`, `occ_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $error  = mb_substr($error, 0, 500);
        $source = mb_substr($source, 0, 12);
        $st = mysqli_prepare(
            $conn,
            "INSERT INTO message_history (customer_id, rtype, occ_date, mobile, source, status, wa_id, error)
             VALUES (?, 'NewCustomer', CURDATE(), ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($st, 'isssss', $cid, $mobile, $source, $status, $waId, $error);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    } catch (Throwable $ex) {
        error_log('Welcome history error: ' . $ex->getMessage());
    }
}

/* One HTTP call. Returns ['ok'=>bool,'id'=>string,'error'=>string] */
function ncCall(string $url, array $payload, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlErr !== '') {
        return ['ok' => false, 'id' => '', 'error' => 'Connection failed: ' . $curlErr];
    }

    $res    = json_decode((string)$response, true);
    $result = is_array($res) ? strtolower((string)($res['result'] ?? '')) : '';
    $ok     = $http >= 200 && $http < 300 && is_array($res) && !in_array($result, ['failed', 'error'], true);

    if (!$ok) {
        $msg = is_array($res) ? ($res['message'] ?? '') : '';
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        if ($msg === '' && is_array($res) && isset($res['errors'])) {
            $msg = json_encode($res['errors'], JSON_UNESCAPED_UNICODE);
        }
        if ($msg === '') {
            $msg = 'WhatsApp API returned HTTP ' . $http;
        }
        error_log('Welcome WhatsApp API error: HTTP ' . $http . ' ' . substr((string)$response, 0, 1500));
        return ['ok' => false, 'id' => '', 'error' => (string)$msg];
    }

    $id = (string)($res['data']['log_uid'] ?? ($res['data']['wamid'] ?? ''));
    return ['ok' => true, 'id' => $id, 'error' => ''];
}

/*
 * Sends the welcome template to ONE customer, then records the result in:
 *   1) message_history table
 *   2) customers.wa_status / wa_attempts / wa_sent_at / wa_message_id / wa_error
 * Never throws. Returns ['ok' => bool, 'error' => string]
 */
function sendWelcomeWhatsapp(mysqli $conn, int $customerId, string $name, string $mobile, string $histSource = 'welcome'): array
{
    $number = '';
    $result = ['ok' => false, 'id' => '', 'error' => 'Unknown error'];

    try {
        $token = ncToken();
        if ($token === '') {
            throw new RuntimeException('WhatsApp token is not configured (WA_API_TOKEN in config.php).');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is not enabled.');
        }
        $number = ncMobile($mobile);
        if ($number === '') {
            throw new RuntimeException('Invalid mobile number.');
        }

        $url = rtrim(WA_API_BASE, '/') . '/'
             . rawurlencode(WA_VENDOR_UID)
             . '/contact/send-template-message';

        /* header_field_1 = name, body {{1}} = name, {{2}} = store */
        $payload = [
            'from_phone_number_id' => WA_PHONE_NUMBER_ID,
            'phone_number'         => $number,
            'template_name'        => NC_TEMPLATE,
            'template_language'    => 'en',
            'header_field_1'       => ncClean($name),
            'field_1'              => ncClean($name),
            'field_2'              => ncClean(NC_STORE_NAME),
        ];

        $result = ncCall($url, $payload, $token);
    } catch (Throwable $ex) {
        error_log('Welcome WhatsApp exception: ' . $ex->getMessage());
        $result = ['ok' => false, 'id' => '', 'error' => $ex->getMessage()];
    }

    $ok    = !empty($result['ok']);
    $waId  = (string)($result['id'] ?? '');
    $error = $ok ? '' : mb_substr((string)($result['error'] ?? 'WhatsApp sending failed.'), 0, 500);

    ncHistory($conn, $customerId, $number !== '' ? $number : $mobile, $ok ? 'sent' : 'failed', $waId, $error, $histSource);

    /* customers table me bhi status likho */
    try {
        $status = $ok ? 'sent' : 'failed';
        $st = mysqli_prepare(
            $conn,
            "UPDATE customers
                SET wa_status = ?,
                    wa_attempts = wa_attempts + 1,
                    wa_sent_at = IF(? = 'sent', NOW(), wa_sent_at),
                    wa_message_id = ?,
                    wa_error = ?
              WHERE id = ?"
        );
        mysqli_stmt_bind_param($st, 'ssssi', $status, $status, $waId, $error, $customerId);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    } catch (Throwable $ex) {
        error_log('wa_status update error: ' . $ex->getMessage());
    }

    return ['ok' => $ok, 'error' => $error];
}