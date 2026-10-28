<?php
/*
|--------------------------------------------------------------------------
| welcome-whatsapp.php - New customer welcome message (WhatsApp)
|--------------------------------------------------------------------------
| Template (Whatsupbizto): new_customer
|     {{1}} = customer name
|     {{2}} = store name
| Customer save hone ke baad sendWelcomeWhatsapp() call karo.
| Ye kabhi exception nahi fenkta, isliye customer save kabhi nahi rukta.
| Token config.php se aata hai: define('WA_API_TOKEN', '...');
*/

if (!defined('WA_API_BASE'))        define('WA_API_BASE', 'https://whatsupbizto.com/api');
if (!defined('WA_VENDOR_UID'))      define('WA_VENDOR_UID', '');
if (!defined('WA_PHONE_NUMBER_ID')) define('WA_PHONE_NUMBER_ID', '');
if (!defined('WELCOME_STORE_NAME')) define('WELCOME_STORE_NAME', (function () { global $conn; $r = isset($conn) && $conn ? @mysqli_fetch_row(@mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'store_name'")) : null; return ($r && $r[0] !== '') ? $r[0] : 'Our Store'; })());
if (!defined('WELCOME_TEMPLATE'))   define('WELCOME_TEMPLATE', 'new_customer');

function nc_clean(string $s): string
{
    $s = preg_replace('/[\r\n\t]+/', ' ', $s);
    $s = preg_replace('/ {2,}/', ' ', $s);
    $s = trim($s);
    return $s !== '' ? $s : '-';
}

/* 10 digit number -> 91XXXXXXXXXX, galat ho to '' */
function nc_mobile(string $mobile): string
{
    $n = preg_replace('/\D+/', '', $mobile);
    if (strlen($n) === 10) {
        $n = '91' . $n;
    } elseif (strlen($n) === 11 && $n[0] === '0') {
        $n = '91' . substr($n, 1);
    }
    return (strlen($n) >= 11 && strlen($n) <= 15) ? $n : '';
}

function nc_history(mysqli $conn, int $cid, string $mobile, string $status, string $waId, string $error): void
{
    try {
        $error = mb_substr($error, 0, 500);
        $st = mysqli_prepare(
            $conn,
            "INSERT INTO message_history (customer_id, rtype, occ_date, mobile, source, status, wa_id, error)
             VALUES (?, 'NewCustomer', CURDATE(), ?, 'welcome', ?, ?, ?)"
        );
        mysqli_stmt_bind_param($st, 'issss', $cid, $mobile, $status, $waId, $error);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    } catch (Throwable $ex) {
        error_log('Welcome history error: ' . $ex->getMessage());
    }
}

/*
 * Returns ['ok' => bool, 'error' => string]
 */
function sendWelcomeWhatsapp(mysqli $conn, int $customerId, string $name, string $mobile): array
{
    try {
        $token = defined('WA_API_TOKEN') ? trim((string)constant('WA_API_TOKEN')) : '';
        if ($token === '' || stripos($token, 'PASTE') !== false) {
            throw new RuntimeException('WA_API_TOKEN config.php me set nahi hai.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension enabled nahi hai.');
        }
        $number = nc_mobile($mobile);
        if ($number === '') {
            throw new RuntimeException('Invalid mobile number: ' . $mobile);
        }

        $url = rtrim(WA_API_BASE, '/') . '/' . rawurlencode(WA_VENDOR_UID) . '/contact/send-template-message';

        // Language codes: pehle 'en', fir baaki English codes (jaise message-settings.php me)
        $langs = ['en', 'en_US', 'en_GB'];
        $last  = 'Unknown error';

        foreach ($langs as $lang) {
            $payload = [
                'from_phone_number_id' => WA_PHONE_NUMBER_ID,
                'phone_number'         => $number,
                'template_name'        => WELCOME_TEMPLATE,
                'template_language'    => $lang,
                'field_1'              => nc_clean($name),               // {{1}} customer name
                'field_2'              => nc_clean(WELCOME_STORE_NAME),  // {{2}} store name
            ];

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
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $response = curl_exec($ch);
            $curlErr  = curl_error($ch);
            $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $curlErr !== '') {
                $last = 'Connection failed: ' . $curlErr;
                break;
            }

            $res    = json_decode((string)$response, true);
            $result = is_array($res) ? strtolower((string)($res['result'] ?? '')) : '';
            $ok     = $http >= 200 && $http < 300 && is_array($res) && !in_array($result, ['failed', 'error'], true);

            if ($ok) {
                $id = (string)($res['data']['log_uid'] ?? ($res['data']['wamid'] ?? ''));
                nc_history($conn, $customerId, $number, 'sent', $id, '');
                return ['ok' => true, 'error' => ''];
            }

            $msg = is_array($res) ? ($res['message'] ?? '') : '';
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            $last = $msg !== '' ? (string)$msg : 'WhatsApp API HTTP ' . $http;
            error_log('Welcome WhatsApp error: HTTP ' . $http . ' ' . substr((string)$response, 0, 1000));

            // sirf language ka error ho to agla language code try karo
            if (stripos($last, 'language') === false) {
                break;
            }
        }

        nc_history($conn, $customerId, $number, 'failed', '', $last);
        return ['ok' => false, 'error' => $last];
    } catch (Throwable $ex) {
        error_log('Welcome WhatsApp exception: ' . $ex->getMessage());
        nc_history($conn, $customerId, $mobile, 'failed', '', $ex->getMessage());
        return ['ok' => false, 'error' => $ex->getMessage()];
    }
}