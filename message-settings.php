<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/*
|--------------------------------------------------------------------------
| message-settings.php - Birthday / Anniversary messages (templates, scheduling,
|                        auto-send, history)
|--------------------------------------------------------------------------
| Tables : message_settings (already exists)
|          reminder_log        (sent marker per customer + type + date)
|          message_history     (every send attempt: sent / failed, who, when)
|          scheduled_messages  (messages scheduled for a particular date & time)
| Reads  : customers (name, mobile, city, dob, anniversary)
| Sends  : WhatsApp through Whatsupbizto API
|
| AJAX GET : customers, upcoming, day_view, stats, history, scheduled
| AJAX POST: send_whatsapp, save_template, save_schedule, mark_sent,
|            schedule_messages, cancel_scheduled, retry_scheduled, run_now
|
| AUTO SEND: cron-send.php includes this file (MSG_LIB_ONLY) and runs
|            runAutoSender() every 5 minutes. See the box on the Schedule tab.
|--------------------------------------------------------------------------
| NOTE: Keep STORE_NAME the same as STORE_NAME in reminders.php.
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Kolkata');

$libOnly = defined('MSG_LIB_ONLY');          // true when included by cron-send.php

if (!$libOnly && session_status() !== PHP_SESSION_ACTIVE) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

require_once __DIR__ . '/config.php';          // must create $conn = mysqli_connect(...)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+05:30'");

$csrf = '';
if (!$libOnly) {
    if (empty($_SESSION['msgset_csrf'])) {
        $_SESSION['msgset_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = $_SESSION['msgset_csrf'];
}

class UserError extends Exception {}

define('STORE_NAME', crm_setting('store_name', 'Our Store'));

/*
|--------------------------------------------------------------------------
| WHATSAPP API SETTINGS (Whatsupbizto)
|--------------------------------------------------------------------------
| BEST: put these in config.php (they override the values below):
|     define('WA_API_TOKEN', 'your-new-token');
*/
if (!defined('WA_API_BASE'))        define('WA_API_BASE', 'https://whatsupbizto.com/api');
if (!defined('WA_VENDOR_UID'))      define('WA_VENDOR_UID', '');
if (!defined('WA_PHONE_NUMBER_ID')) define('WA_PHONE_NUMBER_ID', '');
/* Fallback token (only used if config.php has no WA_API_TOKEN). */
const WA_LOCAL_TOKEN = 'PASTE_YOUR_NEW_TOKEN_HERE';

function waToken(): string
{
    if (defined('WA_API_TOKEN')) {
        $t = trim((string)constant('WA_API_TOKEN'));
        if ($t !== '' && stripos($t, 'PASTE') === false) {
            return $t;
        }
    }
    return trim(WA_LOCAL_TOKEN);
}

/*
| Templates are created in the Whatsupbizto panel (must be Approved).
|     {{1}} = customer first name (automatic)
|     {{2}} = message written on this page (saved in database)
| 'body' is used only for preview. Keep it SAME as the approved template text.
*/
const WA_TEMPLATES = [
    'Birthday' => [
        'name'        => 'birthday_wish',
        'lang'        => 'en',
        'header_vars' => [],
        'vars'        => ['name', 'msg'],
        'body'        => "Happy Birthday, {{1}}! \n\n{{2}}\n\n",
    ],
    'Anniversary' => [
        'name'        => 'anniversary_wish',
        'lang'        => 'en',
        'header_vars' => [],
        'vars'        => ['name', 'msg'],
        'body'        => "Happy Anniversary, {{1}}! \n\n{{2}}\n\nthanks, We look forward to welcoming you!",
    ],
];

const DEFAULTS = [
    'msg_birthday'       => 'Wishing you a wonderful year ahead. Enjoy 15% off on your next purchase.',
    'msg_anniversary'    => 'Wishing you many more years of love and happiness together. Enjoy a special gift on your next visit.',
    'send_time'          => '09:00',
    'before_birthday'    => '0',
    'before_anniversary' => '0',
    'auto_send'          => '0',
    'cron_last_run'      => '',
    'cron_last_result'   => '',
];

const BEFORE_OPTIONS = [
    '0' => 'On the day',
    '1' => '1 day before',
    '2' => '2 days before',
    '3' => '3 days before',
    '7' => '7 days before',
];

const MAX_AUTO_RETRIES = 3;

/* ------------------------------------------------------------------ helpers */

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function cleanValue($value): string
{
    return is_array($value) ? '' : trim((string)($value ?? ''));
}

function jsonOut(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

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

function dbAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $st = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($st, $types, ...$params);
    }
    mysqli_stmt_execute($st);
    $res  = mysqli_stmt_get_result($st);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
    return $rows;
}

/* run INSERT/UPDATE/DELETE, returns affected rows */
function dbExec(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $st = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($st, $types, ...$params);
    }
    mysqli_stmt_execute($st);
    $n = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    return (int)$n;
}

function fmtDate(?string $d): string
{
    if (!$d || $d === '0000-00-00') {
        return '-';
    }
    $t = strtotime($d);
    return $t ? date('j M Y', $t) : '-';
}

function fmtDateTime(?string $d): string
{
    if (!$d || strpos($d, '0000-00-00') === 0) {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('j M Y, g:i A', $t) : '';
}

function daysDiff(string $ymd): int
{
    return (int)(new DateTime('today'))->diff(new DateTime(substr($ymd, 0, 10)))->format('%r%a');
}

function whenText(int $n): string
{
    if ($n === 0) {
        return 'Today';
    }
    if ($n === 1) {
        return 'Tomorrow';
    }
    if ($n === -1) {
        return 'Yesterday';
    }
    return $n > 0 ? 'In ' . $n . ' days' : abs($n) . ' days ago';
}

function timeLabel(string $hhmm): string
{
    $t = strtotime($hhmm);
    return $t ? date('g:i A', $t) : $hhmm;
}

/* ------------------------------------------------------------------ tables */

function ensureTables(mysqli $conn): void
{
    // message_settings already exists; do not recreate or modify it.
    $tables = [
        "CREATE TABLE IF NOT EXISTS `reminder_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` INT UNSIGNED NOT NULL,
            `rtype` VARCHAR(20) NOT NULL,
            `occ_date` DATE NOT NULL,
            `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_reminder` (`customer_id`, `rtype`, `occ_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `message_history` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `scheduled_messages` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `customer_id` INT UNSIGNED NOT NULL,
            `rtype` VARCHAR(20) NOT NULL,
            `occ_date` DATE NOT NULL,
            `send_at` DATETIME NOT NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `last_error` VARCHAR(500) NOT NULL DEFAULT '',
            `processed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_sched_due` (`status`, `send_at`),
            KEY `idx_sched_key` (`customer_id`, `rtype`, `occ_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $sql) {
        try {
            mysqli_query($conn, $sql);
        } catch (Throwable $ex) {
            error_log('CRM table error: ' . $ex->getMessage());
        }
    }
}

/* ---------------------------------------------------------------- settings */

function loadSettings(mysqli $conn): array
{
    $cfg = DEFAULTS;
    foreach (dbAll($conn, 'SELECT skey, svalue FROM message_settings') as $r) {
        if (array_key_exists($r['skey'], DEFAULTS) && $r['svalue'] !== null) {
            $cfg[$r['skey']] = (string)$r['svalue'];
        }
    }
    return $cfg;
}

function saveSettings(mysqli $conn, array $kv): void
{
    $st = mysqli_prepare($conn, 'INSERT INTO message_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    foreach ($kv as $k => $v) {
        $k = (string)$k;
        $v = (string)$v;
        mysqli_stmt_bind_param($st, 'ss', $k, $v);
        mysqli_stmt_execute($st);
    }
    mysqli_stmt_close($st);
}

/* message the customer receives (approved template text with the values filled) */
function buildMsg(array $cfg, string $type, string $name): string
{
    $k     = $type === 'Birthday' ? 'birthday' : 'anniversary';
    $first = trim(explode(' ', trim($name))[0] ?? '');
    return str_replace(
        ['{{1}}', '{{2}}'],
        [$first !== '' ? $first : 'there', waClean((string)$cfg['msg_' . $k])],
        WA_TEMPLATES[$type]['body']
    );
}

/* ------------------------------------------------ WhatsApp (Whatsupbizto) */

/* 10 digit Indian number -> 91XXXXXXXXXX (no + sign) */
function normalizeMobile(string $mobile): string
{
    $number = preg_replace('/\D+/', '', $mobile);
    if (strlen($number) === 10) {
        $number = '91' . $number;
    } elseif (strlen($number) === 11 && $number[0] === '0') {
        $number = '91' . substr($number, 1);
    }
    if (strlen($number) < 11 || strlen($number) > 15) {
        throw new UserError('Invalid mobile number: ' . $mobile);
    }
    return $number;
}

function waClean(string $s): string
{
    $s = preg_replace('/[\r\n\t]+/', ' ', $s);
    $s = preg_replace('/ {2,}/', ' ', $s);
    $s = trim($s);
    return $s !== '' ? $s : '-';
}

/* One HTTP call to Whatsupbizto. Returns ['ok'=>bool,'id'=>string,'error'=>string] */
function waCall(string $url, array $payload, string $token): array
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
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 45,
    ]);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        error_log('CRM WhatsApp CURL error: ' . $curlError);
        return ['ok' => false, 'id' => '', 'error' => 'WhatsApp API connection failed: ' . $curlError];
    }

    $res    = json_decode((string)$response, true);
    $result = is_array($res) ? strtolower((string)($res['result'] ?? '')) : '';
    $ok     = $httpCode >= 200 && $httpCode < 300 && is_array($res) && !in_array($result, ['failed', 'error'], true);

    if (!$ok) {
        $msg = is_array($res) ? ($res['message'] ?? '') : '';
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        if ($msg === '' && is_array($res) && isset($res['errors'])) {
            $msg = json_encode($res['errors'], JSON_UNESCAPED_UNICODE);
        }
        if ($msg === '') {
            $msg = 'WhatsApp API returned HTTP ' . $httpCode;
        }
        error_log('CRM WhatsApp API error: HTTP ' . $httpCode . ' Response: ' . substr((string)$response, 0, 2000));
        return ['ok' => false, 'id' => '', 'error' => (string)$msg];
    }

    $id = (string)($res['data']['log_uid'] ?? ($res['data']['wamid'] ?? ''));
    return ['ok' => true, 'id' => $id, 'error' => ''];
}

/* Sends one approved-template WhatsApp message. Returns ['ok','id','error'] */
function waSend(array $cfg, string $type, string $name, string $number): array
{
    $token = waToken();
    if ($token === '' || stripos($token, 'PASTE') !== false) {
        throw new UserError('WhatsApp API token is not set. Add define(\'WA_API_TOKEN\', \'...\') in config.php.');
    }

    $first = trim(explode(' ', trim($name))[0] ?? '');
    $first = $first !== '' ? $first : 'there';
    $base  = rtrim(WA_API_BASE, '/') . '/' . rawurlencode(WA_VENDOR_UID) . '/contact/';

    $tpl = WA_TEMPLATES[$type];
    $k   = $type === 'Birthday' ? 'birthday' : 'anniversary';
    $map = [
        'name' => waClean($first),
        'msg'  => waClean((string)$cfg['msg_' . $k]),
    ];
    $headerVars = array_values($tpl['header_vars'] ?? []);
    $bodyVars   = array_values($tpl['vars'] ?? []);
    $url        = $base . 'send-template-message';
    $r          = ['ok' => false, 'id' => '', 'error' => ''];

    // Language codes to try (panel language first, then common English codes).
    $langs = array_values(array_unique([(string)$tpl['lang'], 'en_US', 'en', 'en_GB']));
    $li    = 0;

    // Up to 10 tries: retries with another language code, or with the param count Meta expects.
    for ($try = 0; $try < 10; $try++) {
        $payload = [
            'from_phone_number_id' => WA_PHONE_NUMBER_ID,
            'phone_number'         => $number,
            'template_name'        => $tpl['name'],
            'template_language'    => $langs[$li],
        ];
        foreach ($headerVars as $i => $v) {
            $payload['header_field_' . ($i + 1)] = $map[$v] ?? '-';
        }
        foreach ($bodyVars as $i => $v) {
            $payload['field_' . ($i + 1)] = $map[$v] ?? '-';
        }

        $r = waCall($url, $payload, $token);
        if ($r['ok']) {
            return $r;
        }

        if (stripos($r['error'], 'language not found') !== false || stripos($r['error'], 'selected language') !== false) {
            if ($li + 1 < count($langs)) {
                $li++;
                continue;
            }
            $r['error'] .= ' (tried language codes: ' . implode(', ', $langs) . ')';
            break;
        }

        $err     = $r['error'];
        $changed = false;

        if (preg_match_all('/header field (\d+) field is required/i', $err, $hm)) {
            $need = max(array_map('intval', $hm[1]));
            while (count($headerVars) < $need) {
                $headerVars[] = 'name';
                $changed = true;
            }
        }

        if (preg_match_all('/(header|body)\s*:\s*number of localizable_params\s*\((\d+)\).*?expected number of params\s*\((\d+)\)/i', $err, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $want = (int)$x[3];
                if (strtolower($x[1]) === 'header') {
                    if ($want < count($headerVars)) {
                        $headerVars = array_slice($headerVars, 0, $want);
                        $changed    = true;
                    } elseif ($want > count($headerVars)) {
                        while (count($headerVars) < $want) {
                            $headerVars[] = 'name';
                        }
                        $changed = true;
                    }
                } else {
                    if ($want < count($bodyVars)) {
                        if ($want === 0) {
                            $bodyVars = [];
                        } elseif ($want === 1 && count($headerVars) > 0) {
                            $bodyVars = ['msg'];
                        } else {
                            $bodyVars = array_slice($bodyVars, 0, $want);
                        }
                        $changed = true;
                    } elseif ($want > count($bodyVars)) {
                        while (count($bodyVars) < $want) {
                            $bodyVars[] = 'msg';
                        }
                        $changed = true;
                    }
                }
            }
        }

        if (!$changed) {
            break;
        }
    }
    return $r;
}

/* --------------------------------------------- history / delivery (one place) */

function addHistory(mysqli $conn, int $cid, string $type, ?string $occ, string $mobile, string $source, string $status, string $waId, string $error): void
{
    try {
        $error = mb_substr($error, 0, 500);
        dbExec(
            $conn,
            'INSERT INTO message_history (customer_id, rtype, occ_date, mobile, source, status, wa_id, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            'isssssss',
            [$cid, $type, $occ, $mobile, $source, $status, $waId, $error]
        );
    } catch (Throwable $ex) {
        error_log('CRM history error: ' . $ex->getMessage());
    }
}

/*
 * THE single place that sends a birthday / anniversary message to a customer.
 * Used by: manual send, scheduled messages, auto-sender (cron).
 *
 * Duplicate protection: the reminder_log row is "claimed" BEFORE sending, so two
 * clicks / two cron runs can never send the same message twice.
 * $force = true -> allow re-sending an already sent message.
 *
 * Returns ['ok'=>bool, 'error'=>string, 'code'=>string, 'id'=>string, 'name'=>string, 'sent_at'=>string]
 */
function deliverReminder(mysqli $conn, array $cfg, string $type, int $cid, string $occ, string $source, bool $force = false): array
{
    $rows = dbAll($conn, 'SELECT id, name, mobile FROM customers WHERE id = ? LIMIT 1', 'i', [$cid]);
    if (!$rows) {
        return ['ok' => false, 'error' => 'Customer not found.', 'code' => 'nocustomer'];
    }
    $name   = cleanValue($rows[0]['name'] ?? '');
    $mobile = cleanValue($rows[0]['mobile'] ?? '');
    if ($mobile === '') {
        return ['ok' => false, 'error' => 'This customer has no mobile number.', 'code' => 'nomobile', 'name' => $name];
    }
    try {
        $number = normalizeMobile($mobile);
    } catch (UserError $ex) {
        addHistory($conn, $cid, $type, $occ, $mobile, $source, 'failed', '', $ex->getMessage());
        return ['ok' => false, 'error' => $ex->getMessage(), 'code' => 'badmobile', 'name' => $name];
    }

    $claimed = dbExec($conn, 'INSERT IGNORE INTO reminder_log (customer_id, rtype, occ_date) VALUES (?, ?, ?)', 'iss', [$cid, $type, $occ]) === 1;
    if (!$claimed && !$force) {
        return ['ok' => false, 'error' => 'Already sent for this date.', 'code' => 'duplicate', 'name' => $name];
    }

    try {
        $r = waSend($cfg, $type, $name, $number);
    } catch (Throwable $ex) {
        $r = ['ok' => false, 'id' => '', 'error' => $ex->getMessage()];
    }

    if (!$r['ok']) {
        if ($claimed) {   // give the claim back so it can be retried
            dbExec($conn, 'DELETE FROM reminder_log WHERE customer_id = ? AND rtype = ? AND occ_date = ?', 'iss', [$cid, $type, $occ]);
        }
        addHistory($conn, $cid, $type, $occ, $mobile, $source, 'failed', '', (string)$r['error']);
        return ['ok' => false, 'error' => (string)$r['error'], 'code' => 'api', 'name' => $name];
    }

    if (!$claimed) {      // forced re-send: refresh the sent time
        dbExec($conn, 'UPDATE reminder_log SET sent_at = NOW() WHERE customer_id = ? AND rtype = ? AND occ_date = ?', 'iss', [$cid, $type, $occ]);
    }
    addHistory($conn, $cid, $type, $occ, $mobile, $source, 'sent', (string)$r['id'], '');
    return ['ok' => true, 'error' => '', 'code' => '', 'id' => (string)$r['id'], 'name' => $name, 'sent_at' => date('Y-m-d H:i:s')];
}

/* --------------------------------------------- birthday / anniversary engine */

function occurrenceOn(string $date, int $year): ?string
{
    $m = (int)substr($date, 5, 2);
    $d = (int)substr($date, 8, 2);
    if (!checkdate($m, $d, $year)) {
        if ($m === 2 && $d === 29) {
            $d = 28;
        } else {
            return null;
        }
    }
    return sprintf('%04d-%02d-%02d', $year, $m, $d);
}

function fetchDateCustomers(mysqli $conn, string $col, string $q = ''): array
{
    $col = $col === 'anniversary' ? 'anniversary' : 'dob';
    $sql = "SELECT id, name, mobile, city, $col AS d FROM customers WHERE $col IS NOT NULL AND $col > '1000-01-01'";
    $t   = '';
    $p   = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $sql .= ' AND (name LIKE ? OR mobile LIKE ? OR city LIKE ?)';
        $t    = 'sss';
        $p    = [$like, $like, $like];
    }
    return dbAll($conn, $sql, $t, $p);
}

function buildOccurrences(array $customers, string $type, string $from, string $to): array
{
    $y1  = (int)substr($from, 0, 4);
    $y2  = (int)substr($to, 0, 4);
    $out = [];
    foreach ($customers as $c) {
        $orig = (string)$c['d'];
        if (strlen($orig) < 10 || substr($orig, 0, 4) === '0000') {
            continue;
        }
        for ($y = $y1; $y <= $y2; $y++) {
            $occ = occurrenceOn($orig, $y);
            if ($occ === null || $occ < $from || $occ > $to) {
                continue;
            }
            $out[] = [
                'id'     => (int)$c['id'],
                'name'   => (string)$c['name'],
                'mobile' => (string)($c['mobile'] ?? ''),
                'city'   => (string)($c['city'] ?? ''),
                'date'   => $occ,
                'n'      => $y - (int)substr($orig, 0, 4),
            ];
        }
    }
    return $out;
}

/* key "customer|date" => sent_at */
function sentMap(mysqli $conn, string $type, string $from, string $to): array
{
    $m    = [];
    $rows = dbAll(
        $conn,
        'SELECT customer_id, occ_date, sent_at FROM reminder_log WHERE rtype = ? AND occ_date BETWEEN ? AND ?',
        'sss',
        [$type, $from, $to]
    );
    foreach ($rows as $r) {
        $m[$r['customer_id'] . '|' . $r['occ_date']] = (string)$r['sent_at'];
    }
    return $m;
}

/* key => send_at of a pending custom schedule */
function schedMap(mysqli $conn, string $type, string $from, string $to): array
{
    $m    = [];
    $rows = dbAll(
        $conn,
        "SELECT customer_id, occ_date, send_at FROM scheduled_messages WHERE rtype = ? AND occ_date BETWEEN ? AND ? AND status IN ('pending','sending')",
        'sss',
        [$type, $from, $to]
    );
    foreach ($rows as $r) {
        $m[$r['customer_id'] . '|' . $r['occ_date']] = (string)$r['send_at'];
    }
    return $m;
}

/* key => ['n' => failed attempts, 'err' => last error] */
function failMap(mysqli $conn, string $type, string $from, string $to): array
{
    $m    = [];
    $rows = dbAll(
        $conn,
        "SELECT customer_id, occ_date, COUNT(*) AS n,
                SUBSTRING_INDEX(GROUP_CONCAT(error ORDER BY id DESC SEPARATOR '||'), '||', 1) AS err
         FROM message_history
         WHERE status = 'failed' AND source <> 'test' AND rtype = ? AND occ_date BETWEEN ? AND ?
         GROUP BY customer_id, occ_date",
        'sss',
        [$type, $from, $to]
    );
    foreach ($rows as $r) {
        $m[$r['customer_id'] . '|' . $r['occ_date']] = ['n' => (int)$r['n'], 'err' => (string)$r['err']];
    }
    return $m;
}

/* customer_id => last time ANY message was sent to this customer */
function lastSentMap(mysqli $conn): array
{
    $m    = [];
    $rows = dbAll(
        $conn,
        "SELECT customer_id, MAX(t) AS t FROM (
            SELECT customer_id, sent_at AS t FROM reminder_log
            UNION ALL
            SELECT customer_id, created_at AS t FROM message_history WHERE status = 'sent' AND source <> 'test' AND customer_id > 0
         ) x GROUP BY customer_id"
    );
    foreach ($rows as $r) {
        $m[(int)$r['customer_id']] = (string)$r['t'];
    }
    return $m;
}

/* Sent / Queued / Missed / Failed / Due / Scheduled */
function msgStatus(string $eventDate, string $sendDate, bool $sent, bool $queued = false, bool $failed = false): array
{
    $today = date('Y-m-d');
    if ($sent) {
        return ['Sent', 'b-green'];
    }
    if ($queued) {
        return ['Queued', 'b-blue'];
    }
    if ($eventDate < $today) {
        return ['Missed', 'b-red'];
    }
    if ($failed) {
        return ['Failed', 'b-red'];
    }
    if ($sendDate <= $today) {
        return ['Due', 'b-amber'];
    }
    return ['Scheduled', 'b-blue'];
}

/*
 * All birthday OR anniversary items whose EVENT date is between $from and $to.
 * Each item carries status, sent time, last message time, errors, etc.
 */
function collectType(mysqli $conn, array $cfg, string $type, string $from, string $to, string $q = ''): array
{
    $col    = $type === 'Birthday' ? 'dob' : 'anniversary';
    $before = (int)$cfg['before_' . strtolower($type)];
    $occ    = buildOccurrences(fetchDateCustomers($conn, $col, $q), $type, $from, $to);
    if (!$occ) {
        return [];
    }
    $sent  = sentMap($conn, $type, $from, $to);
    $sched = schedMap($conn, $type, $from, $to);
    $fail  = failMap($conn, $type, $from, $to);
    $last  = lastSentMap($conn);

    $items = [];
    foreach ($occ as $o) {
        $k        = $o['id'] . '|' . $o['date'];
        $isSent   = isset($sent[$k]);
        $sendDate = date('Y-m-d', strtotime($o['date'] . ' -' . $before . ' days'));
        $st       = msgStatus($o['date'], $sendDate, $isSent, isset($sched[$k]), isset($fail[$k]));

        $age = '';
        if ($o['n'] >= 1 && $o['n'] <= 120) {
            $age = $type === 'Birthday' ? 'Turns ' . $o['n'] : $o['n'] . ' year' . ($o['n'] === 1 ? '' : 's');
        }
        $lastT = $last[$o['id']] ?? '';

        $items[] = [
            'key'        => $o['id'] . '|' . $type . '|' . $o['date'],
            'id'         => $o['id'],
            'name'       => $o['name'],
            'mobile'     => $o['mobile'],
            'city'       => $o['city'],
            'type'       => $type,
            'occ'        => $o['date'],
            'send'       => $sendDate,
            'date_label' => fmtDate($o['date']),
            'when'       => whenText(daysDiff($o['date'])),
            'send_label' => fmtDate($sendDate),
            'age'        => $age,
            'sent'       => $isSent ? 1 : 0,
            'sent_at'    => $isSent ? fmtDateTime($sent[$k]) : '',
            'sched_at'   => isset($sched[$k]) ? fmtDateTime($sched[$k]) : '',
            'fails'      => $fail[$k]['n'] ?? 0,
            'error'      => $fail[$k]['err'] ?? '',
            'last_sent'  => $lastT !== '' ? fmtDateTime($lastT) : '',
            'last_ago'   => $lastT !== '' ? whenText(daysDiff($lastT)) : '',
            'status'     => $st[0],
            'cls'        => $st[1],
            'msg'        => buildMsg($cfg, $type, $o['name']),
        ];
    }
    return $items;
}

/* ------------------------------------------------------ auto sender (cron) */

function runAutoSender(mysqli $conn, string $via = 'cron'): array
{
    $sum  = ['scheduled_sent' => 0, 'auto_sent' => 0, 'failed' => 0, 'skipped' => 0, 'note' => ''];
    $lock = dbAll($conn, "SELECT GET_LOCK('aurelia_msg_cron', 0) AS l");
    if ((int)($lock[0]['l'] ?? 0) !== 1) {
        $sum['note'] = 'Another run is already in progress.';
        return $sum;
    }

    try {
        $cfg = loadSettings($conn);

        // a run that crashed in the middle: release stuck rows
        mysqli_query($conn, "UPDATE scheduled_messages SET status = 'pending' WHERE status = 'sending' AND processed_at < (NOW() - INTERVAL 15 MINUTE)");

        /* ---- 1) messages scheduled for a particular date & time ---- */
        $due = dbAll(
            $conn,
            "SELECT id, customer_id, rtype, occ_date, attempts FROM scheduled_messages
             WHERE status = 'pending' AND send_at <= ? ORDER BY send_at ASC LIMIT 100",
            's',
            [date('Y-m-d H:i:s')]
        );
        foreach ($due as $r) {
            $id  = (int)$r['id'];
            $got = dbExec($conn, "UPDATE scheduled_messages SET status = 'sending', attempts = attempts + 1, processed_at = NOW() WHERE id = ? AND status = 'pending'", 'i', [$id]);
            if ($got !== 1) {
                continue;
            }
            $res = deliverReminder($conn, $cfg, (string)$r['rtype'], (int)$r['customer_id'], (string)$r['occ_date'], 'scheduled', false);

            if ($res['ok']) {
                dbExec($conn, "UPDATE scheduled_messages SET status = 'sent', last_error = '' WHERE id = ?", 'i', [$id]);
                $sum['scheduled_sent']++;
            } elseif (($res['code'] ?? '') === 'duplicate') {
                dbExec($conn, "UPDATE scheduled_messages SET status = 'sent', last_error = 'Already sent earlier' WHERE id = ?", 'i', [$id]);
            } else {
                $attempts = (int)$r['attempts'] + 1;
                $err      = mb_substr((string)$res['error'], 0, 500);
                $retry    = $attempts < MAX_AUTO_RETRIES && !in_array($res['code'] ?? '', ['nomobile', 'badmobile', 'nocustomer'], true);
                if ($retry) {
                    dbExec($conn, "UPDATE scheduled_messages SET status = 'pending', send_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), last_error = ? WHERE id = ?", 'si', [$err, $id]);
                } else {
                    dbExec($conn, "UPDATE scheduled_messages SET status = 'failed', last_error = ? WHERE id = ?", 'si', [$err, $id]);
                }
                $sum['failed']++;
            }
            usleep(400000);
        }

        /* ---- 2) daily auto-send (birthday / anniversary of the day) ---- */
        if ($cfg['auto_send'] === '1' && date('H:i') >= $cfg['send_time']) {
            $today = date('Y-m-d');
            $count = 0;
            foreach (['Birthday', 'Anniversary'] as $type) {
                $before = (int)$cfg['before_' . strtolower($type)];
                $occ    = date('Y-m-d', strtotime($today . ' +' . $before . ' days'));   // events whose SEND date is today
                foreach (collectType($conn, $cfg, $type, $occ, $occ) as $it) {
                    if ($it['sent'] || $it['status'] === 'Queued' || $it['fails'] >= MAX_AUTO_RETRIES) {
                        continue;
                    }
                    if (trim($it['mobile']) === '') {
                        $sum['skipped']++;
                        continue;
                    }
                    $res = deliverReminder($conn, $cfg, $type, (int)$it['id'], $it['occ'], 'auto', false);
                    if ($res['ok']) {
                        $sum['auto_sent']++;
                    } elseif (($res['code'] ?? '') !== 'duplicate') {
                        $sum['failed']++;
                    }
                    usleep(400000);
                    if (++$count >= 200) {
                        break 2;
                    }
                }
            }
        }
        // remember that the cron really ran (shown on the Schedule tab)
        if ($via === 'cron') {
            saveSettings($conn, [
                'cron_last_run'    => date('Y-m-d H:i:s'),
                'cron_last_result' => 'Scheduled sent: ' . $sum['scheduled_sent'] . ', auto sent: ' . $sum['auto_sent'] . ', failed: ' . $sum['failed'] . ', no mobile: ' . $sum['skipped'],
            ]);
        }
    } finally {
        try {
            dbAll($conn, "SELECT RELEASE_LOCK('aurelia_msg_cron')");
        } catch (Throwable $ex) {
        }
    }
    return $sum;
}

/*
 * Explains, line by line, why the cron / auto-sender will or will not send right now.
 * Used by the "Why is cron not sending?" button and by cron-send.php?key=...&debug=1
 * Returns [['lvl' => ok|warn|bad|info, 'text' => string], ...]
 */
function diagnoseAutoSender(mysqli $conn): array
{
    $L   = [];
    $add = function (string $lvl, string $txt) use (&$L) {
        $L[] = ['lvl' => $lvl, 'text' => $txt];
    };

    $cfg   = loadSettings($conn);
    $autoOn = $cfg['auto_send'] === '1';
    $timeOk = date('H:i') >= $cfg['send_time'];

    $add('info', 'Server time now: ' . date('j M Y, g:i A') . ' (IST)');

    $tok = waToken();
    if ($tok === '' || stripos($tok, 'PASTE') !== false) {
        $add('bad', 'WhatsApp token is NOT set in config.php (WA_API_TOKEN). Nothing can be sent.');
    } else {
        $add('ok', 'WhatsApp token is set.');
    }
    if (!function_exists('curl_init')) {
        $add('bad', 'PHP cURL extension is missing here (' . PHP_SAPI . '). Enable "curl" in hPanel > PHP Configuration > Extensions, or use the URL cron.');
    } else {
        $add('ok', 'PHP cURL is available (' . PHP_SAPI . ').');
    }

    if ($autoOn) {
        $add('ok', 'Auto-send is ON.');
    } else {
        $add('bad', 'Auto-send is OFF. Turn it ON in "Reminder scheduling" and click "Save schedule". (Scheduled date & time messages still go.)');
    }
    if ($timeOk) {
        $add('ok', 'Sending time (' . timeLabel($cfg['send_time']) . ') has been reached for today.');
    } else {
        $add('warn', 'Sending time is ' . timeLabel($cfg['send_time']) . ' - it is not reached yet, so auto-send waits. For a test, set the time a few minutes before now and save.');
    }

    $last = $cfg['cron_last_run'] !== '' ? strtotime($cfg['cron_last_run']) : false;
    if (!$last) {
        $add('bad', 'The cron job has NEVER contacted this system. Check the Hostinger cron job (URL must include ?key=...).');
    } else {
        $min = (int)floor((time() - $last) / 60);
        $add($min <= 15 ? 'ok' : 'warn', 'Cron last ran ' . $min . ' min ago (' . fmtDateTime($cfg['cron_last_run']) . ').');
    }

    $ready = 0;
    foreach (['Birthday', 'Anniversary'] as $type) {
        $before = (int)$cfg['before_' . strtolower($type)];
        $occ    = date('Y-m-d', strtotime('+' . $before . ' days'));
        $rule   = BEFORE_OPTIONS[(string)$before] ?? ($before . ' days before');
        $items  = collectType($conn, $cfg, $type, $occ, $occ);

        $add('info', $type . ' rule: "' . $rule . '" -> today the system looks for ' . ($type === 'Birthday' ? 'birthdays' : 'anniversaries') . ' on ' . fmtDate($occ) . '.');
        if (!$items) {
            $add('warn', 'No customer has a ' . strtolower($type) . ' date on ' . fmtDate($occ) . ', so there is nothing to send for ' . $type . '. (Only these dates are sent automatically; dates saved in the customer form.)');
            continue;
        }
        foreach ($items as $it) {
            $who = $it['name'] . ' (' . ($it['mobile'] !== '' ? $it['mobile'] : 'no mobile') . ')';
            if ($it['sent']) {
                $add('info', $who . ': ALREADY SENT' . ($it['sent_at'] !== '' ? ' on ' . $it['sent_at'] : '') . ' (maybe by the WhatsApp icon / "Mark as sent"). Cron never sends the same message twice.');
            } elseif ($it['status'] === 'Queued') {
                $add('info', $who . ': has its own scheduled date & time (' . $it['sched_at'] . ') - it goes at that time.');
            } elseif ($it['fails'] >= MAX_AUTO_RETRIES) {
                $add('bad', $who . ': failed ' . $it['fails'] . ' times, auto-send gave up. Last error: ' . $it['error']);
            } elseif (trim($it['mobile']) === '') {
                $add('bad', $who . ': no mobile number saved.');
            } elseif (!$autoOn || !$timeOk) {
                $add('warn', $who . ': ready, but waiting (auto-send OFF or time not reached).');
            } else {
                $ready++;
                $add('ok', $who . ': WILL be sent in the next cron run.');
            }
        }
    }

    $due = dbAll($conn, "SELECT COUNT(*) AS c FROM scheduled_messages WHERE status = 'pending' AND send_at <= NOW()");
    $add('info', 'Scheduled (date & time) messages due right now: ' . (int)($due[0]['c'] ?? 0) . '.');

    $err = dbAll($conn, "SELECT source, error, created_at FROM message_history WHERE status = 'failed' AND source <> 'test' ORDER BY id DESC LIMIT 3");
    foreach ($err as $r) {
        $add('bad', 'Recent failure (' . $r['source'] . ', ' . fmtDateTime($r['created_at']) . '): ' . $r['error']);
    }

    if ($ready > 0) {
        $add('ok', 'RESULT: ' . $ready . ' message(s) will go at the next cron run (within 5 minutes).');
    } else {
        $add('warn', 'RESULT: nothing is waiting to be sent automatically right now. Read the lines above for the reason.');
    }
    return $L;
}

ensureTables($conn);

/* cron-send.php only needs the functions above */
if ($libOnly) {
    return;
}

/*
|--------------------------------------------------------------------------
| AJAX API
|--------------------------------------------------------------------------
*/
if (isset($_GET['api'])) {
    try {
        $api = (string)$_GET['api'];

        /* ---- customer search for the preview box ---- */
        if ($api === 'customers') {
            $q   = cleanValue($_GET['q'] ?? '');
            $sql = 'SELECT id, name, mobile FROM customers';
            $t   = '';
            $p   = [];
            if ($q !== '') {
                $like = '%' . addcslashes($q, '\\%_') . '%';
                $sql .= ' WHERE name LIKE ? OR mobile LIKE ?';
                $t    = 'ss';
                $p    = [$like, $like];
            }
            $sql .= ' ORDER BY name ASC LIMIT 15';
            jsonOut(['success' => true, 'items' => dbAll($conn, $sql, $t, $p)]);
        }

        /* ---- why is cron not sending? ---- */
        if ($api === 'diagnose') {
            jsonOut(['success' => true, 'lines' => diagnoseAutoSender($conn)]);
        }

        /* ---- dashboard numbers ---- */
        if ($api === 'stats') {
            $cfg      = loadSettings($conn);
            $today    = date('Y-m-d');
            $toSend   = 0;
            foreach (['Birthday', 'Anniversary'] as $type) {
                $occ = date('Y-m-d', strtotime($today . ' +' . (int)$cfg['before_' . strtolower($type)] . ' days'));
                foreach (collectType($conn, $cfg, $type, $occ, $occ) as $it) {
                    if (!$it['sent']) {
                        $toSend++;
                    }
                }
            }
            $h = dbAll($conn, "SELECT COALESCE(SUM(status = 'sent'), 0) AS s, COALESCE(SUM(status = 'failed'), 0) AS f
                               FROM message_history WHERE source <> 'test' AND DATE(created_at) = CURDATE()");
            $q = dbAll($conn, "SELECT COUNT(*) AS c FROM scheduled_messages WHERE status IN ('pending','sending')");
            jsonOut([
                'success'   => true,
                'to_send'   => $toSend,
                'sent'      => (int)($h[0]['s'] ?? 0),
                'failed'    => (int)($h[0]['f'] ?? 0),
                'queued'    => (int)($q[0]['c'] ?? 0),
                'auto'      => $cfg['auto_send'] === '1' ? 1 : 0,
                'send_time' => timeLabel($cfg['send_time']),
                'cron_last' => fmtDateTime($cfg['cron_last_run']),
                'cron_min'  => $cfg['cron_last_run'] !== '' && strtotime($cfg['cron_last_run']) ? (int)floor((time() - strtotime($cfg['cron_last_run'])) / 60) : null,
                'cron_res'  => $cfg['cron_last_result'],
            ]);
        }

        /* ---- upcoming messages list ---- */
        if ($api === 'upcoming') {
            $cfg     = loadSettings($conn);
            $q       = cleanValue($_GET['q'] ?? '');
            $ftype   = cleanValue($_GET['type'] ?? 'all');
            $range   = cleanValue($_GET['range'] ?? '30');
            $fstatus = cleanValue($_GET['status'] ?? '');
            $per     = (int)($_GET['per'] ?? 30);
            if (!in_array($per, [30, 60, 100, 5000], true)) {
                $per = 30;
            }
            $page = max(1, (int)($_GET['page'] ?? 1));
            $all  = (int)($_GET['all'] ?? 0) === 1;

            $today = date('Y-m-d');
            if ($range === 'past') {
                $from = date('Y-m-d', strtotime('-30 days'));
                $to   = date('Y-m-d', strtotime('-1 day'));
            } else {
                $days = in_array($range, ['7', '30', '90', '365'], true) ? (int)$range : 30;
                $from = $today;
                $to   = date('Y-m-d', strtotime('+' . $days . ' days'));
            }

            $items = [];
            foreach (['Birthday', 'Anniversary'] as $type) {
                if ($ftype !== 'all' && $ftype !== $type) {
                    continue;
                }
                foreach (collectType($conn, $cfg, $type, $from, $to, $q) as $it) {
                    if ($fstatus !== '' && $it['status'] !== $fstatus) {
                        continue;
                    }
                    $items[] = $it;
                }
            }

            $desc = ($range === 'past');
            usort($items, function ($a, $b) use ($desc) {
                $c = $desc ? strcmp($b['occ'], $a['occ']) : strcmp($a['occ'], $b['occ']);
                return $c !== 0 ? $c : strcmp($a['name'], $b['name']);
            });

            $total    = count($items);
            $sendTime = timeLabel($cfg['send_time']);

            if ($all) {
                jsonOut(['success' => true, 'items' => array_slice($items, 0, 2000), 'total' => $total, 'send_time' => $sendTime]);
            }

            $pages = max(1, (int)ceil($total / $per));
            $page  = min($page, $pages);
            jsonOut([
                'success'   => true,
                'items'     => array_slice($items, ($page - 1) * $per, $per),
                'total'     => $total,
                'pages'     => $pages,
                'page'      => $page,
                'per'       => $per,
                'send_time' => $sendTime,
            ]);
        }

        /* ---- who gets a message on the selected date (popup) ---- */
        if ($api === 'day_view') {
            $cfg  = loadSettings($conn);
            $date = validDateOrNull(cleanValue($_GET['date'] ?? ''), 'date') ?? date('Y-m-d');

            $items = [];
            foreach (['Birthday', 'Anniversary'] as $type) {
                // messages whose SEND date is the selected date => event date = date + "days before"
                $occ = date('Y-m-d', strtotime($date . ' +' . (int)$cfg['before_' . strtolower($type)] . ' days'));
                foreach (collectType($conn, $cfg, $type, $occ, $occ) as $it) {
                    $items[] = $it;
                }
            }
            usort($items, fn($a, $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

            $sentN = 0;
            foreach ($items as $it) {
                $sentN += $it['sent'] ? 1 : 0;
            }
            jsonOut([
                'success'    => true,
                'items'      => $items,
                'total'      => count($items),
                'sent'       => $sentN,
                'remaining'  => count($items) - $sentN,
                'date_label' => fmtDate($date),
                'send_time'  => timeLabel($cfg['send_time']),
            ]);
        }

        /* ---- sent / failed history ---- */
        if ($api === 'history') {
            $fs  = cleanValue($_GET['status'] ?? '');
            $sql = 'SELECT h.id, h.customer_id, h.rtype, h.occ_date, h.mobile, h.source, h.status, h.error, h.created_at, c.name
                    FROM message_history h LEFT JOIN customers c ON c.id = h.customer_id';
            $t   = '';
            $p   = [];
            if (in_array($fs, ['sent', 'failed'], true)) {
                $sql .= ' WHERE h.status = ?';
                $t    = 's';
                $p    = [$fs];
            }
            $sql .= ' ORDER BY h.id DESC LIMIT 100';
            $out = [];
            foreach (dbAll($conn, $sql, $t, $p) as $r) {
                $out[] = [
                    'name'   => $r['name'] ?? ($r['source'] === 'test' ? 'Test message' : 'Deleted customer'),
                    'mobile' => $r['mobile'],
                    'type'   => $r['rtype'],
                    'occ'    => fmtDate($r['occ_date']),
                    'source' => $r['source'],
                    'status' => $r['status'],
                    'error'  => $r['error'],
                    'at'     => fmtDateTime($r['created_at']),
                ];
            }
            jsonOut(['success' => true, 'items' => $out]);
        }

        /* ---- scheduled queue ---- */
        if ($api === 'scheduled') {
            $out  = [];
            $rows = dbAll(
                $conn,
                "SELECT s.id, s.rtype, s.occ_date, s.send_at, s.status, s.attempts, s.last_error, c.name, c.mobile
                 FROM scheduled_messages s LEFT JOIN customers c ON c.id = s.customer_id
                 WHERE s.status IN ('pending','sending','failed') ORDER BY s.send_at ASC LIMIT 200"
            );
            foreach ($rows as $r) {
                $out[] = [
                    'id'      => (int)$r['id'],
                    'name'    => $r['name'] ?? 'Deleted customer',
                    'mobile'  => $r['mobile'] ?? '',
                    'type'    => $r['rtype'],
                    'occ'     => fmtDate($r['occ_date']),
                    'send_at' => fmtDateTime($r['send_at']),
                    'status'  => $r['status'],
                    'tries'   => (int)$r['attempts'],
                    'error'   => $r['last_error'],
                ];
            }
            jsonOut(['success' => true, 'items' => $out]);
        }

        /* ---- write actions: POST + CSRF ---- */
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonOut(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        $posted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($posted) || !hash_equals($csrf, $posted)) {
            jsonOut(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], 403);
        }

        /* ---- SEND WHATSAPP NOW (manual click) ---- */
        if ($api === 'send_whatsapp') {
            $mode = cleanValue($_POST['mode'] ?? 'reminder');
            $type = cleanValue($_POST['type'] ?? '');

            if (!in_array($type, ['birthday', 'anniversary', 'Birthday', 'Anniversary'], true)) {
                throw new UserError('Invalid message type.');
            }
            $type = strtolower($type) === 'birthday' ? 'Birthday' : 'Anniversary';
            $cfg  = loadSettings($conn);

            if ($mode === 'test') {
                $mobile = cleanValue($_POST['mobile'] ?? '');
                if ($mobile === '') {
                    throw new UserError('Please enter a mobile number.');
                }
                $number = normalizeMobile($mobile);
                $r      = waSend($cfg, $type, 'Test Customer', $number);
                addHistory($conn, 0, $type, null, $number, 'test', $r['ok'] ? 'sent' : 'failed', (string)$r['id'], (string)$r['error']);
                if (!$r['ok']) {
                    jsonOut(['success' => false, 'message' => 'WhatsApp: ' . $r['error']], 502);
                }
                jsonOut(['success' => true, 'message' => 'Test message sent.', 'message_id' => $r['id']]);
            }

            $customerId = (int)($_POST['customer_id'] ?? 0);
            $occ        = validDateOrNull(cleanValue($_POST['occ'] ?? ''), 'reminder date');
            if ($customerId <= 0 || $occ === null) {
                throw new UserError('Invalid customer or reminder date.');
            }
            $force = (int)($_POST['force'] ?? 0) === 1;
            $res   = deliverReminder($conn, $cfg, $type, $customerId, $occ, 'manual', $force);

            if (!$res['ok']) {
                $code   = (string)($res['code'] ?? '');
                $status = $code === 'duplicate' ? 409 : (in_array($code, ['nomobile', 'badmobile', 'nocustomer'], true) ? 422 : 502);
                jsonOut(['success' => false, 'code' => $code, 'message' => ($code === 'api' ? 'WhatsApp: ' : '') . $res['error']], $status);
            }
            jsonOut([
                'success'    => true,
                'message'    => 'WhatsApp message sent to ' . $res['name'] . '.',
                'message_id' => $res['id'],
                'sent_at'    => fmtDateTime($res['sent_at']),
            ]);
        }

        /* ---- save the message of one template ---- */
        if ($api === 'save_template') {
            $type = cleanValue($_POST['type'] ?? '');
            if (!in_array($type, ['birthday', 'anniversary'], true)) {
                throw new UserError('Invalid template type.');
            }
            $msg = trim((string)preg_replace('/\s+/u', ' ', cleanValue($_POST['message'] ?? '')));
            if ($msg === '') {
                throw new UserError('Message cannot be empty.');
            }
            if (mb_strlen($msg) > 300) {
                throw new UserError('Message is too long (maximum 300 characters).');
            }
            saveSettings($conn, ['msg_' . $type => $msg]);
            jsonOut(['success' => true, 'message' => ucfirst($type) . ' message saved.', 'saved' => $msg]);
        }

        /* ---- save schedule ---- */
        if ($api === 'save_schedule') {
            $time = cleanValue($_POST['send_time'] ?? '');
            $bb   = cleanValue($_POST['before_birthday'] ?? '');
            $ba   = cleanValue($_POST['before_anniversary'] ?? '');
            $auto = (int)($_POST['auto_send'] ?? 0) === 1 ? '1' : '0';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new UserError('Please choose a valid sending time.');
            }
            if (!isset(BEFORE_OPTIONS[$bb]) || !isset(BEFORE_OPTIONS[$ba])) {
                throw new UserError('Please choose a valid "send before" option.');
            }
            saveSettings($conn, ['send_time' => $time, 'before_birthday' => $bb, 'before_anniversary' => $ba, 'auto_send' => $auto]);
            jsonOut(['success' => true, 'message' => 'Schedule saved.' . ($auto === '1' ? ' Auto-send is ON.' : ' Auto-send is OFF.')]);
        }

        /* ---- mark one or many reminders as sent / undo ---- */
        if ($api === 'mark_sent') {
            $done  = (int)($_POST['done'] ?? 1) === 1;
            $items = json_decode((string)($_POST['items'] ?? ''), true);
            if (!is_array($items) || !$items || count($items) > 2000) {
                throw new UserError('Nothing selected.');
            }

            mysqli_begin_transaction($conn);
            try {
                if ($done) {
                    $st = mysqli_prepare($conn, 'INSERT IGNORE INTO reminder_log (customer_id, rtype, occ_date) VALUES (?, ?, ?)');
                } else {
                    $st = mysqli_prepare($conn, 'DELETE FROM reminder_log WHERE customer_id = ? AND rtype = ? AND occ_date = ?');
                }
                $n = 0;
                foreach ($items as $it) {
                    $cid   = (int)($it['id'] ?? 0);
                    $rtype = is_string($it['rtype'] ?? null) ? $it['rtype'] : '';
                    $occ   = validDateOrNull(is_string($it['occ'] ?? null) ? $it['occ'] : '', 'reminder date');
                    if ($cid <= 0 || $occ === null || !in_array($rtype, ['Birthday', 'Anniversary'], true)) {
                        throw new UserError('Invalid reminder in the list.');
                    }
                    mysqli_stmt_bind_param($st, 'iss', $cid, $rtype, $occ);
                    mysqli_stmt_execute($st);
                    $n++;
                }
                mysqli_stmt_close($st);
                mysqli_commit($conn);
            } catch (Throwable $ex) {
                mysqli_rollback($conn);
                throw $ex;
            }

            jsonOut([
                'success' => true,
                'count'   => $n,
                'message' => $done ? $n . ' marked as sent.' : $n . ' marked as not sent.',
            ]);
        }

        /* ---- schedule selected messages for a date & time ---- */
        if ($api === 'schedule_messages') {
            $mode  = cleanValue($_POST['mode'] ?? 'custom') === 'default' ? 'default' : 'custom';
            $items = json_decode((string)($_POST['items'] ?? ''), true);
            if (!is_array($items) || !$items || count($items) > 500) {
                throw new UserError('Nothing selected (maximum 500 at a time).');
            }
            $cfg      = loadSettings($conn);
            $customAt = null;
            if ($mode === 'custom') {
                $raw = cleanValue($_POST['send_at'] ?? '');
                $d   = DateTime::createFromFormat('Y-m-d\TH:i', $raw);
                if (!$d || $d->format('Y-m-d\TH:i') !== $raw) {
                    throw new UserError('Please choose a valid date and time.');
                }
                if ($d->getTimestamp() < time() - 60) {
                    throw new UserError('Please choose a future date and time.');
                }
                $customAt = $d->format('Y-m-d H:i:00');
            }

            $n = 0;
            mysqli_begin_transaction($conn);
            try {
                foreach ($items as $it) {
                    $cid   = (int)($it['id'] ?? 0);
                    $rtype = is_string($it['rtype'] ?? null) ? $it['rtype'] : '';
                    $occ   = validDateOrNull(is_string($it['occ'] ?? null) ? $it['occ'] : '', 'reminder date');
                    if ($cid <= 0 || $occ === null || !in_array($rtype, ['Birthday', 'Anniversary'], true)) {
                        throw new UserError('Invalid reminder in the list.');
                    }
                    if ($mode === 'default') {
                        $before = (int)$cfg['before_' . strtolower($rtype)];
                        $at     = date('Y-m-d', strtotime($occ . ' -' . $before . ' days')) . ' ' . $cfg['send_time'] . ':00';
                        if (strtotime($at) < time()) {
                            $at = date('Y-m-d H:i:00', time() + 120);   // already past -> send in 2 minutes
                        }
                    } else {
                        $at = $customAt;
                    }
                    dbExec($conn, "DELETE FROM scheduled_messages WHERE customer_id = ? AND rtype = ? AND occ_date = ? AND status IN ('pending','failed')", 'iss', [$cid, $rtype, $occ]);
                    dbExec($conn, 'INSERT INTO scheduled_messages (customer_id, rtype, occ_date, send_at) VALUES (?, ?, ?, ?)', 'isss', [$cid, $rtype, $occ, $at]);
                    $n++;
                }
                mysqli_commit($conn);
            } catch (Throwable $ex) {
                mysqli_rollback($conn);
                throw $ex;
            }
            jsonOut(['success' => true, 'count' => $n, 'message' => $n . ' message(s) scheduled. They will go automatically (cron must be running).']);
        }

        if ($api === 'cancel_scheduled') {
            $id = (int)($_POST['id'] ?? 0);
            dbExec($conn, "UPDATE scheduled_messages SET status = 'cancelled' WHERE id = ? AND status IN ('pending','failed')", 'i', [$id]);
            jsonOut(['success' => true, 'message' => 'Scheduled message cancelled.']);
        }

        if ($api === 'retry_scheduled') {
            $id = (int)($_POST['id'] ?? 0);
            dbExec($conn, "UPDATE scheduled_messages SET status = 'pending', attempts = 0, last_error = '', send_at = NOW() WHERE id = ? AND status = 'failed'", 'i', [$id]);
            jsonOut(['success' => true, 'message' => 'Will be retried in the next run.']);
        }

        /* ---- run the auto-sender right now (same as cron) ---- */
        if ($api === 'run_now') {
            @set_time_limit(300);
            $s = runAutoSender($conn, 'manual');
            if ($s['note'] !== '') {
                jsonOut(['success' => false, 'message' => $s['note']], 409);
            }
            jsonOut([
                'success' => true,
                'message' => 'Done. Scheduled sent: ' . $s['scheduled_sent'] . ', auto sent: ' . $s['auto_sent'] . ', failed: ' . $s['failed'] . ', no mobile: ' . $s['skipped'] . '.',
            ]);
        }

        jsonOut(['success' => false, 'message' => 'Unknown action.'], 400);
    } catch (UserError $ex) {
        jsonOut(['success' => false, 'message' => $ex->getMessage()], 422);
    } catch (mysqli_sql_exception $ex) {
        error_log('message-settings.php DB error [' . $ex->getCode() . ']: ' . $ex->getMessage());
        if ((int)$ex->getCode() === 1146) {
            jsonOut(['success' => false, 'message' => 'A required table is missing (message_settings / reminder_log / message_history / scheduled_messages). Please give CREATE permission to the database user.'], 500);
        }
        jsonOut(['success' => false, 'message' => 'Database error. Please try again.'], 500);
    } catch (Throwable $ex) {
        error_log('message-settings.php error: ' . $ex->getMessage());
        jsonOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/
$loadError = '';
try {
    $cfg = loadSettings($conn);
} catch (Throwable $ex) {
    error_log('message-settings.php load: ' . $ex->getMessage());
    $cfg       = DEFAULTS;
    $loadError = 'Settings could not be loaded. Please check that the database user can create tables (message_settings).';
}

$tabs = [
    'birthday'    => ['label' => 'Birthday',    'icon' => 'gift'],
    'anniversary' => ['label' => 'Anniversary', 'icon' => 'heart'],
];

$jsCfg = [
    'csrf'  => $csrf,
    'today' => date('Y-m-d'),
    'store' => STORE_NAME,
    'tpl'   => [
        'birthday'    => WA_TEMPLATES['Birthday']['body'],
        'anniversary' => WA_TEMPLATES['Anniversary']['body'],
    ],
];

$cronPath = __DIR__ . '/cron-send.php';
$tokenOk  = (waToken() !== '' && stripos(waToken(), 'PASTE') === false);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?= e($csrf) ?>" />
    <title>Message Settings | <?= crm_e(crm_brand()['name']) ?> CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap"
        rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/style.css?v=1" rel="stylesheet" />
    
    <style>
        /* =========================================
   BIRTHDAY & ANNIVERSARY MESSAGES PAGE
========================================= */

/* Page heading */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  margin-bottom: 24px;
}

.page-head h1 {
  color: #1e293b;
  font-size: 26px;
  font-weight: 700;
  margin-bottom: 8px;
}

.page-head p {
  font-size: 13px;
  line-height: 1.7;
}

/* General panels */
.content .panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 22px;
  box-shadow: 0 4px 18px rgba(15, 23, 42, 0.035);
  margin-bottom: 20px;
}

.content .panel-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding-bottom: 15px;
  margin-bottom: 20px;
  border-bottom: 1px solid #e5e7eb;
}

.content .panel-title h5 {
  color: #1e293b;
  font-size: 16px;
  font-weight: 700;
  margin: 0;
}

/* Buttons */
.content .btn {
  border-radius: 4px;
  font-size: 13px;
  font-weight: 600;
  transition: all 0.2s ease;
}

.content .btn-primary {
  background: #c9a227;
  border-color: #c9a227;
  color: #fff;
}

.content .btn-primary:hover {
  background: #ad871b;
  border-color: #ad871b;
  color: #fff;
}

.content .btn-success {
  background: #168653;
  border-color: #168653;
}

.content .btn-success:hover {
  background: #116b42;
  border-color: #116b42;
}

.content .btn-outline-primary {
  color: #334155;
  background: #fff;
  border-color: #cbd5e1;
}

.content .btn-outline-primary:hover {
  color: #1d4ed8;
  background: #eff6ff;
  border-color: #93c5fd;
}

.content .btn-light {
  color: #475569;
  background: #fff;
  border-color: #dbe1e8;
}

.content .btn-light:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}

.content .btn-sm {
  padding: 7px 11px;
  font-size: 12px;
}

/* Stat cards */
.stat-box {
  height: 100%;
  min-height: 100px;
  padding: 18px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  box-shadow: 0 3px 12px rgba(15, 23, 42, 0.035);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-box:hover {
  transform: translateY(-2px);
  box-shadow: 0 7px 18px rgba(15, 23, 42, 0.07);
}

.stat-box small {
  color: #64748b;
  font-size: 12px;
  font-weight: 500;
}

.stat-box .v {
  margin-top: 8px;
  color: #1e293b;
  font-size: 27px;
  font-weight: 700;
  line-height: 1.2;
}

/* How-to instructions */
.howto {
  padding: 19px 22px;
  background: #fffbeb;
  border: 1px solid #f3df9b;
  border-left: 4px solid #c9a227;
  border-radius: 4px;
  color: #475569;
  font-size: 13px;
  line-height: 1.8;
}

.howto > b {
  display: block;
  margin-bottom: 8px;
  color: #854d0e;
  font-size: 14px;
}

.howto ol {
  margin: 0;
  padding-left: 22px;
}

.howto li {
  margin-bottom: 5px;
}

.howto li:last-child {
  margin-bottom: 0;
}

/* Tabs */
.nav-pills {
  padding: 6px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}

.nav-pills .nav-link {
  padding: 10px 15px;
  border-radius: 4px;
  color: #475569;
  font-size: 13px;
  font-weight: 600;
  transition: all 0.2s ease;
}

.nav-pills .nav-link:hover {
  color: #1e293b;
  background: #fff;
}

.nav-pills .nav-link.active {
  color: #fff;
  background: #c9a227;
  box-shadow: 0 3px 8px rgba(201, 162, 39, 0.2);
}

/* Form labels */
.content .form-label {
  display: block;
  margin-bottom: 8px;
  color: #334155;
  font-size: 13px;
  font-weight: 600;
}

.content .form-label small {
  font-weight: 400;
}

/* Form controls */
.content .form-control,
.content .form-select,
.modal .form-control,
.modal .form-select {
  min-height: 42px;
  padding: 10px 12px;
  color: #334155;
  background-color: #fff;
  border: 1px solid #dbe1e8;
  border-radius: 4px;
  font-size: 13px;
  box-shadow: none;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.content textarea.form-control {
  min-height: 115px;
  line-height: 1.7;
  resize: vertical;
}

.content .form-control::placeholder,
.modal .form-control::placeholder {
  color: #94a3b8;
}

.content .form-control:focus,
.content .form-select:focus,
.modal .form-control:focus,
.modal .form-select:focus {
  border-color: #c9a227;
  box-shadow: 0 0 0 3px rgba(201, 162, 39, 0.12);
}

.content .input-group-text {
  color: #64748b;
  border-color: #dbe1e8;
  border-radius: 4px 0 0 4px;
}

.content .input-group > .form-control {
  border-radius: 0 4px 4px 0;
}

/* Template message counter */
#cnt_birthday,
#cnt_anniversary {
  font-weight: 600;
  color: #64748b;
}

/* Customer picker */
.cpick {
  position: relative;
}

.cpick-list {
  position: absolute;
  z-index: 1050;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  max-height: 230px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid #dbe1e8;
  border-radius: 4px;
  box-shadow: 0 10px 25px rgba(15, 23, 42, 0.12);
}

.cpick-list .list-group-item {
  padding: 11px 13px;
  color: #334155;
  border-color: #edf0f4;
  font-size: 13px;
}

.cpick-list .list-group-item:hover {
  background: #fffbeb;
}

.cpick-list .list-group-item b {
  font-weight: 600;
}

/* WhatsApp preview */
.wa-prev {
  min-height: 180px;
  padding: 18px;
  display: flex;
  align-items: flex-start;
  justify-content: flex-start;
  background-color: #e9eee8;
  background-image: radial-gradient(rgba(100, 116, 139, 0.1) 1px, transparent 1px);
  background-size: 15px 15px;
  border: 1px solid #d8e0d6;
  border-radius: 4px;
}

.wa-bubble {
  max-width: 95%;
  padding: 12px 15px;
  color: #334155;
  background: #fff;
  border-radius: 4px 4px 4px 4px;
  box-shadow: 0 2px 5px rgba(15, 23, 42, 0.08);
  font-size: 13px;
  line-height: 1.7;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

/* Scheduling steps */
.step {
  display: flex;
  gap: 15px;
  margin-bottom: 25px;
}

.step-no {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 34px;
  width: 34px;
  height: 34px;
  color: #fff;
  background: #c9a227;
  border-radius: 4px;
  font-size: 14px;
  font-weight: 700;
  box-shadow: 0 3px 8px rgba(201, 162, 39, 0.2);
}

.step-body {
  flex: 1;
  min-width: 0;
}

.step-title {
  margin-bottom: 5px;
  color: #1e293b;
  font-size: 14px;
  font-weight: 700;
}

.step-help {
  margin-bottom: 13px;
  color: #64748b;
  font-size: 12px;
  line-height: 1.7;
}

/* Auto-send switch card */
.auto-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 15px;
  padding: 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  transition: all 0.2s ease;
}

.auto-card.on {
  background: #f0fdf4;
  border-color: #86efac;
}

.auto-card .fw-semibold {
  color: #1e293b;
  font-size: 14px;
}

.auto-card small {
  display: block;
  margin-top: 4px;
  font-size: 12px;
}

.form-check-input {
  cursor: pointer;
  border-color: #cbd5e1;
  box-shadow: none !important;
}

.form-check-input:checked {
  background-color: #168653;
  border-color: #168653;
}

/* Quick time buttons */
.time-quick .btn {
  min-width: 58px;
  padding: 8px 11px;
  border-radius: 4px;
  font-size: 12px;
}

/* Schedule summary */
.sum-box {
  padding: 16px 18px;
  color: #475569;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 13px;
  line-height: 1.9;
}

.sum-box > div {
  margin-bottom: 5px;
}

.sum-box > div:last-child {
  margin-bottom: 0;
}

/* Unsaved indicator */
#dirtyBadge {
  display: none;
}

#dirtyBadge.show {
  display: inline-flex;
}

/* Status badges */
.badge-s {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 2px 3px;
  border: 1px solid transparent;
  border-radius: 2px;
  font-size: 11px;
  font-weight: 700;
  line-height: 1.35;
  white-space: nowrap;
}

.b-green {
  color: #166534;
  background: #dcfce7;
  border-color: #bbf7d0;
}

.b-red {
  color: #991b1b;
  background: #fee2e2;
  border-color: #fecaca;
}

.b-amber {
  color: #92400e;
  background: #fef3c7;
  border-color: #fde68a;
}

.b-blue {
  color: #1d4ed8;
  background: #dbeafe;
  border-color: #bfdbfe;
}

/* Live status rows */
.live-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 15px;
  padding: 13px 0;
  border-bottom: 1px solid #edf0f4;
  font-size: 13px;
}

.live-row:last-child {
  border-bottom: 0;
}

.live-row .lbl {
  flex: 0 0 38%;
  color: #64748b;
}

.live-row .val {
  flex: 1;
  color: #334155;
  text-align: right;
  overflow-wrap: anywhere;
}

.live-row .val small {
  line-height: 1.6;
}

/* Run and diagnostic result boxes */
.runbox {
  margin-top: 15px;
  padding: 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  color: #334155;
  font-size: 13px;
  line-height: 1.7;
  overflow-wrap: anywhere;
}

.runbox .ln {
  padding: 8px 0;
  border-bottom: 1px solid #e5e7eb;
}

.runbox .ln:last-child {
  border-bottom: 0;
}

.runbox .ln i {
  margin-right: 5px;
}

#saveNote {
  font-size: 12px;
}

/* Details / cron information */
.content details {
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}

.content details summary {
  color: #64748b;
  font-weight: 500;
}

.content details code {
  overflow-wrap: anywhere;
}

/* Selection action bar */
.selbar {
  display: none;
  align-items: center;
  flex-wrap: wrap;
  gap: 9px;
  margin: 14px 0;
  padding: 13px 15px;
  background: #fffbeb;
  border: 1px solid #f3df9b;
  border-radius: 4px;
}

.selbar.show {
  display: flex;
}

.selbar > b {
  margin-right: auto;
  color: #854d0e;
  font-size: 13px;
}

#selAllHint {
  padding: 8px 12px;
  color: #475569;
  background: #eff6ff;
  border-radius: 4px;
}

/* Tables: full borders */
.content .table-responsive {
  width: 100%;
  overflow-x: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  scrollbar-width: thin;
}

.content table.table {
  width: 100%;
  min-width: 720px;
  margin-bottom: 0;
  border-collapse: separate;
  border-spacing: 0;
  color: #334155;
  font-size: 11px;
  background: #fff;
}

.content table.table thead th {
  padding: 14px 15px;
  color: #475569;
  background: #f8fafc;
  border-top: 0;
  border-bottom: 1px solid #dbe1e8;
  border-right: 1px solid #e2e8f0;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.35px;
  text-transform: uppercase;
  white-space: nowrap;
  vertical-align: middle;
}

.content table.table thead th:last-child {
  border-right: 0;
}

.content table.table tbody td {
  padding: 2px 5px;
  color: #334155;
  background: transparent;
  border-bottom: 1px solid #e5e7eb;
  border-right: 1px solid #edf0f4;
  vertical-align: middle;
  line-height: 1.6;
 
}

.content table.table tbody td:last-child {
  border-right: 0;
}

.content table.table tbody tr:last-child td {
  border-bottom: 0;
}

.content table.table tbody tr:nth-child(even) {
  background: #fafbfc;
}

.content table.table tbody tr:nth-child(odd) {
  background: #fff;
}

.content table.table tbody tr:hover {
  background: #fff9e8;
}

.content table.table tbody .fw-semibold {
  color: #1e293b;
  font-weight: 600 !important;
}

.content table.table tbody small {
  font-size: 11px;
  line-height: 1.6;
}

/* Table checkbox */
.content table .form-check-input {
  width: 16px;
  height: 16px;
  margin-top: 0;
  border: 1px solid #94a3b8 !important;
  border-radius: 4px;
}

.content table .form-check-input:checked {
  background-color: #c9a227;
  border-color: #c9a227 !important;
}

/* Row action icon buttons */
.btn-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  color: #64748b;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}

.btn-icon i {
  font-size: 15px;
}

.btn-icon:hover {
  color: #1d4ed8;
  background: #eff6ff;
  border-color: #bfdbfe;
}

.btn-icon.text-success:hover {
  color: #166534;
  background: #f0fdf4;
  border-color: #86efac;
}

.btn-icon:disabled,
.btn-icon.disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

/* Pagination */
.pagination {
  gap: 4px;
}

.pagination .page-link {
  padding: 7px 11px;
  color: #475569;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 4px !important;
  font-size: 12px;
}

.pagination .page-link:hover {
  color: #1d4ed8;
  background: #eff6ff;
  border-color: #bfdbfe;
}

.pagination .page-item.disabled .page-link {
  color: #94a3b8;
  background: #f8fafc;
}

/* Empty table states */
.content .empty {
  padding: 35px 20px;
  color: #64748b;
  font-size: 13px;
}

.content .empty i {
  display: block;
  margin-bottom: 10px;
  color: #cbd5e1;
}

/* Modals */
.modal-content {
  overflow: hidden;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  box-shadow: 0 20px 60px rgba(15, 23, 42, 0.18);
}

.modal-header {
  padding: 18px 22px;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
}

.modal-header .modal-title {
  color: #1e293b;
  font-size: 16px;
  font-weight: 700;
}

.modal-body {
  padding: 22px;
}

.modal-footer {
  padding: 15px 22px;
  background: #f8fafc;
  border-top: 1px solid #e5e7eb;
}

.modal-footer .btn {
  min-height: 38px;
  padding: 8px 15px;
}

.modal .form-check {
  margin-bottom: 10px;
}

.modal .form-check-label {
  color: #475569;
  font-size: 13px;
  line-height: 1.6;
}

/* Queue modal message */
.q-msg {
  margin-top: 12px;
  padding: 15px;
  color: #334155;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 13px;
  line-height: 1.7;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

/* Progress bar */
.modal .progress {
  overflow: hidden;
  background: #e2e8f0;
  border-radius: 4px;
}

.modal .progress-bar {
  border-radius: 4px;
}

/* Date modal */
#dayModal .modal-dialog {
  max-width: 1100px;
}

#daySummary,
#todaySummary {
  align-items: center;
}

#daySummary .badge-s,
#todaySummary .badge-s {
  margin-bottom: 3px;
}

/* Toast notifications */
#pageToasts {
  z-index: 2000;
}

.crm-toast {
  min-width: 280px;
  max-width: 420px;
  color: #fff;
  background: #166534;
  border: 0;
  border-radius: 4px;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.2);
}

.crm-toast.error {
  background: #b91c1c;
}

.crm-toast .toast-body {
  padding: 13px 15px;
  font-size: 13px;
  font-weight: 500;
}

/* Alerts */
.content .alert {
  padding: 14px 17px;
  border-radius: 4px;
  font-size: 13px;
  line-height: 1.7;
}

.content .alert code {
  padding: 2px 5px;
  border-radius: 4px;
}

/* Gold utility */
.text-gold {
  color: #c9a227 !important;
}

/* Loading spinner */
.spinner-border-sm {
  width: 0.9rem;
  height: 0.9rem;
  border-width: 0.13em;
}

/* Responsive layout */
@media (max-width: 991.98px) {
  .content .panel {
    padding: 18px;
  }

  .page-head h1 {
    font-size: 23px;
  }

  .live-row .lbl {
    flex-basis: 34%;
  }
}

@media (max-width: 767.98px) {
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
  }

  .page-head h1 {
    font-size: 21px;
  }

  .page-head p {
    font-size: 12px;
  }

  .content .panel {
    padding: 15px;
    border-radius: 4px;
  }

  .content .panel-title {
    margin-bottom: 16px;
    padding-bottom: 12px;
  }

  .content .panel-title h5 {
    font-size: 14px;
  }

  .stat-box {
    min-height: 90px;
    padding: 13px;
  }

  .stat-box .v {
    font-size: 23px;
  }

  .howto {
    padding: 15px;
    font-size: 12px;
  }

  .nav-pills {
    gap: 5px !important;
  }

  .nav-pills .nav-link {
    padding: 9px 11px;
    font-size: 12px;
  }

  .step {
    gap: 11px;
    margin-bottom: 22px;
  }

  .step-no {
    flex-basis: 30px;
    width: 30px;
    height: 30px;
    font-size: 12px;
  }

  .live-row {
    gap: 10px;
    font-size: 12px;
  }

  .live-row .lbl {
    flex-basis: 36%;
  }

  .content table.table {
    min-width: 760px;
  }

  .content table.table thead th {
    padding: 12px;
  }

  .content table.table tbody td {
    padding: 12px;
  }

  .selbar {
    gap: 7px;
    padding: 12px;
  }

  .selbar > b {
    width: 100%;
  }

  .modal-header,
  .modal-footer {
    padding: 15px 17px;
  }

  .modal-body {
    padding: 17px;
  }
}

@media (max-width: 480px) {
  .page-head h1 {
    font-size: 19px;
  }

  .content .panel {
    padding: 12px;
  }

  .stat-box {
    padding: 11px;
  }

  .stat-box small {
    font-size: 11px;
  }

  .stat-box .v {
    font-size: 21px;
  }

  .nav-pills .nav-link {
    padding: 8px 9px;
    font-size: 11px;
  }

  .nav-pills .nav-link i {
    margin-right: 3px !important;
  }

  .auto-card {
    padding: 12px;
  }

  .time-quick {
    width: 100%;
  }

  .time-quick .btn {
    flex: 1;
  }

  .live-row {
    flex-direction: column;
    gap: 5px;
  }

  .live-row .lbl,
  .live-row .val {
    flex: auto;
    width: 100%;
    text-align: left;
  }

  .selbar .btn {
    flex: 1 1 auto;
  }

  .crm-toast {
    min-width: 0;
    width: calc(100vw - 32px);
  }

  #dayModal .modal-dialog {
    margin: 8px;
  }
}
    </style>
    
    
    
    <style>
        .wa-prev {
            background: #efeae2;
            border-radius: 4px;
            padding: 16px 14px;
            min-height: 130px
        }

        .wa-bubble {
            background: #d9fdd3;
            color: #111b21;
            font-size: 14px;
            line-height: 1.45;
            padding: 8px 12px;
            border-radius: 4px 4px 2px 4px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, .12);
            white-space: pre-wrap;
            word-break: break-word;
            margin-left: auto;
            max-width: 92%
        }

        .cpick {
            position: relative
        }

        .cpick-list {
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            z-index: 30;
            max-height: 240px;
            overflow-y: auto;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .15)
        }

        .howto {
            background: #fffaf0;
            border: 1px solid #f0e2bd;
            border-radius: 4px;
            padding: 12px 16px;
            font-size: 14px
        }

        .howto ol {
            margin: 6px 0 0;
            padding-left: 20px
        }

        .selbar {
            display: none;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            background: #e7f0ff;
            border: 1px solid #c9dcff;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 10px
        }

        .selbar.show {
            display: flex
        }

        .q-msg {
            background: #efeae2;
            border-radius: 4px;
            padding: 12px;
            white-space: pre-wrap;
            word-break: break-word
        }

        .stat-box {
            background: #fff;
            border: 1px solid #e6e9ef;
            border-radius: 4px;
            padding: 10px 14px
        }

        .stat-box small {
            color: #6b7280
        }

        .stat-box .v {
            font-size: 22px;
            font-weight: 600;
            line-height: 1.2
        }

        .cronbox {
            background: #f6f8fb;
            border: 1px dashed #c5cedb;
            border-radius: 4px;
            padding: 10px 12px;
            font-size: 13px
        }

        .cronbox code {
            word-break: break-all
        }
    </style>
    <?php crm_head(); ?>
</head>

<body>
    <div class="overlay"></div>
    <?php crm_sidebar(); ?>
    <div class="main">
        <?php crm_topbar(); ?>
        <main class="content">
            <div class="page-head">
                <div>
                    <h1 id="pageTitle">Birthday &amp; anniversary messages</h1>
                    <p class="text-muted mb-0">Write the message, choose when to send it, then send on WhatsApp manually, schedule it, or let auto-send do it.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="reminders.php" class="btn btn-outline-primary" style="font-size:12px;"><i class="bi bi-bell me-1"></i>Open reminders</a>
                </div>
            </div>

            <?php if ($loadError !== ''): ?>
                <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($loadError) ?></div>
            <?php endif; ?>
            <?php if (!$tokenOk): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-key-fill me-2"></i>WhatsApp API token is not set. Add <code>define('WA_API_TOKEN', 'your-token');</code> in <b>config.php</b>, otherwise no message can be sent.</div>
            <?php endif; ?>

            <!-- quick numbers -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="stat-box"><small>To send today</small>
                        <div class="v" id="stToday">-</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box"><small>Sent today</small>
                        <div class="v text-success" id="stSent">-</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box"><small>Failed today</small>
                        <div class="v text-danger" id="stFailed">-</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box"><small>Queued (scheduled)</small>
                        <div class="v" id="stQueued">-</div>
                        <small id="stAuto" class="d-block"></small>
                    </div>
                </div>
            </div>

            <div class="howto mb-3">
                <b><i class="bi bi-lightbulb me-1 text-gold"></i>How does it work?</b>
                <ol>
                    <li>In the <b>Birthday / Anniversary message</b> tab, write the message (customer's name is added automatically) and click <b>Save message</b>.</li>
                    <li>In <b>Reminder scheduling</b>, choose how many days before and at what time to send. Turn on <b>Auto-send</b> to let the system send by itself at that time.</li>
                    <li>In the list, select customers and click <b>Send on WhatsApp</b> (now) or <b>Schedule</b> (on a chosen date &amp; time). Use <b>Check by date</b> to see who is pending on any date.</li>
                </ol>
            </div>

            <ul class="nav nav-pills mb-3 flex-wrap gap-1">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#birthday"><i class="bi bi-gift me-1"></i>Birthday message</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#anniversary"><i class="bi bi-heart me-1"></i>Anniversary message</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#schedule"><i class="bi bi-calendar-check me-1"></i>Reminder scheduling</button></li>
                <li class="nav-item"><button class="nav-link" id="tabHistoryBtn" data-bs-toggle="pill" data-bs-target="#history"><i class="bi bi-clock-history me-1"></i>History &amp; scheduled</button></li>
            </ul>

            <div class="tab-content">

                <?php foreach ($tabs as $key => $tb): ?>
                    <!-- ================== <?= e($tb['label']) ?> template ================== -->
                    <div class="tab-pane fade<?= $key === 'birthday' ? ' show active' : '' ?>" id="<?= e($key) ?>">
                        <div class="panel">
                            <div class="panel-title">
                                <h5><i class="bi bi-<?= e($tb['icon']) ?> me-1"></i><?= e($tb['label']) ?> template</h5>
                            </div>
                            <div class="row g-3">
                                <div class="col-lg-7">
                                    <label class="form-label" for="msg_<?= e($key) ?>">Message for customer <small class="text-muted">(goes into {{2}} of the WhatsApp template)</small></label>
                                    <textarea id="msg_<?= e($key) ?>" class="form-control mb-1" rows="4" maxlength="300" placeholder="e.g. Wishing you a wonderful year ahead. Enjoy 15% off on your next purchase."><?= e($cfg['msg_' . $key]) ?></textarea>
                                    <div class="d-flex justify-content-between small text-muted mb-3">
                                        <span>Customer's name is added automatically. Write in a single line.</span>
                                        <span><span id="cnt_<?= e($key) ?>">0</span>/300</span>
                                    </div>
                                    <button type="button" class="btn btn-primary" data-save-tpl="<?= e($key) ?>"><i class="bi bi-check-lg me-1"></i>Save message</button>
                                </div>

                                <div class="col-lg-5">
                                    <label class="form-label">Which customer should the preview use?</label>
                                    <div class="cpick mb-3">
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                            <input type="text" class="form-control cpick-q" placeholder="Type customer name or mobile" autocomplete="off">
                                        </div>
                                        <div class="list-group cpick-list d-none"></div>
                                    </div>
                                    <div class="wa-prev">
                                        <div class="wa-bubble" id="pv_<?= e($key) ?>"></div>
                                    </div>
                                    <label class="form-label mt-3" for="test_<?= e($key) ?>">Send a test message to your own number</label>
                                    <div class="input-group">
                                        <input type="tel" class="form-control" id="test_<?= e($key) ?>" placeholder="10 digit mobile number" maxlength="15">
                                        <button type="button" class="btn btn-outline-success" data-test="<?= e($key) ?>"><i class="bi bi-whatsapp me-1"></i>Send test</button>
                                    </div>
                                    <small class="text-muted">The test message is sent directly through the WhatsApp API (save the message first).</small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- ================== Scheduling + upcoming list ================== -->
                <div class="tab-pane fade" id="schedule">
                    <div class="panel mb-3 d-none">
                        <div class="panel-title">
                            <h5>Scheduling rules</h5>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="sTime">Sending time</label>
                                <input type="time" class="form-control" id="sTime" value="<?= e($cfg['send_time']) ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="sBb">When to send birthday message?</label>
                                <select class="form-select" id="sBb">
                                    <?php foreach (BEFORE_OPTIONS as $v => $l): ?>
                                        <option value="<?= e($v) ?>" <?= (string)$cfg['before_birthday'] === (string)$v ? 'selected' : '' ?>><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="sBa">When to send anniversary message?</label>
                                <select class="form-select" id="sBa">
                                    <?php foreach (BEFORE_OPTIONS as $v => $l): ?>
                                        <option value="<?= e($v) ?>" <?= (string)$cfg['before_anniversary'] === (string)$v ? 'selected' : '' ?>><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="sTz">Time zone</label>
                                <input type="text" class="form-control" id="sTz" value="Asia/Kolkata (IST)" readonly>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sAuto" <?= $cfg['auto_send'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="sAuto">Auto-send on the chosen day &amp; time</label>
                                </div>
                                <small class="text-muted">ON: every birthday / anniversary message goes automatically at the sending time above. OFF: messages go only when you click.</small>
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                                <button type="button" class="btn btn-primary" id="saveSchedule"><i class="bi bi-check-lg me-1"></i>Save schedule</button>
                                <button type="button" class="btn btn-outline-success" id="btnRunNow"><i class="bi bi-play-circle me-1"></i>Run auto-sender now</button>
                                <small class="text-muted">"Run now" sends everything that is due right now (same as the cron job).</small>
                            </div>
                            <div class="col-12 d-none">
                                <div class="cronbox">
                                    <b><i class="bi bi-gear me-1"></i>One-time server setup for auto-send</b><br>
                                    Add this Cron Job in cPanel / server (every 5 minutes):<br>
                                    <code>*/5 * * * * php <?= e($cronPath) ?> &gt;/dev/null 2&gt;&amp;1</code><br>
                                    <span class="text-muted">Without this cron job, auto-send and scheduled date/time messages will NOT go (manual sending still works).</span>
                                    <div class="mt-2" id="cronStatus"><span class="text-muted">Checking cron status...</span></div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="btnDiag"><i class="bi bi-search me-1"></i>Why is cron not sending?</button>
                                    <div class="mt-2 d-none" id="diagBox"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-title d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="mb-0">Upcoming messages</h5>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnDay"><i class="bi bi-calendar-event me-1"></i>Check by date</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCsv"><i class="bi bi-download me-1"></i>Export CSV</button>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-3">
                                <label class="form-label small mb-1" for="fQ">Search</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                    <input class="form-control" id="fQ" placeholder="Name, mobile or city">
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small mb-1" for="fType">Type</label>
                                <select class="form-select" id="fType">
                                    <option value="all">All</option>
                                    <option value="Birthday">Birthday</option>
                                    <option value="Anniversary">Anniversary</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small mb-1" for="fRange">Dates</label>
                                <select class="form-select" id="fRange">
                                    <option value="7">Next 7 days</option>
                                    <option value="30" selected>Next 30 days</option>
                                    <option value="90">Next 90 days</option>
                                    <option value="365">Next 1 year</option>
                                    <option value="past">Last 30 days</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small mb-1" for="fStatus">Status</label>
                                <select class="form-select" id="fStatus">
                                    <option value="">All</option>
                                    <option value="Due">Due (ready to send)</option>
                                    <option value="Scheduled">Scheduled</option>
                                    <option value="Queued">Queued (date &amp; time set)</option>
                                    <option value="Failed">Failed</option>
                                    <option value="Missed">Missed</option>
                                    <option value="Sent">Sent</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-1">
                                <label class="form-label small mb-1" for="fPer">Show</label>
                                <select class="form-select" id="fPer"><option value="30">30</option><option value="60">60</option><option value="100">100</option><option value="5000">All</option></select>
                            </div>
                            <div class="col-12 col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-light border w-100" id="fClear"><i class="bi bi-x-lg me-1"></i>Clear filters</button>
                            </div>
                        </div>

                        <div class="selbar" id="selBar">
                            <b><span id="selCount">0</span> selected</b>
                            <button type="button" class="btn btn-success btn-sm" id="btnQueue"><i class="bi bi-whatsapp me-1"></i>Send on WhatsApp</button>
                            <button type="button" class="btn btn-primary btn-sm" id="btnSchedule"><i class="bi bi-alarm me-1"></i>Schedule date &amp; time</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnMarkSent"><i class="bi bi-check2-circle me-1"></i>Mark as sent</button>
                            <button type="button" class="btn btn-link btn-sm text-muted" id="btnClearSel">Clear selection</button>
                        </div>
                        <div class="small mb-2 d-none" id="selAllHint">
                            <span id="selAllText"></span>
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="btnSelAll"></button>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:36px"><input type="checkbox" class="form-check-input" id="chkPage" aria-label="Select all on this page" style="border:1px solid #5d8ab6 !important;"></th>
                                        <th>Customer</th>
                                        <th>Type</th>
                                        <th>Event date</th>
                                        <th>Send on</th>
                                        <th>Last message sent</th>
                                        <!--<th>Message</th>-->
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="msgBody"></tbody>
                            </table>
                        </div>
                        <div class="empty text-center d-none" id="msgEmpty">
                            <i class="bi bi-inbox fs-1"></i>
                            <p class="mb-0">No customers found for this filter. Birthday / anniversary dates must be saved in the customer form.</p>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                            <small class="text-muted" id="msgInfo"></small>
                            <nav aria-label="Pagination">
                                <ul class="pagination pagination-sm mb-0" id="msgPager"></ul>
                            </nav>
                        </div>
                    </div>
                </div>

                <!-- ================== History & scheduled ================== -->
                <div class="tab-pane fade" id="history">
                    <div class="panel mb-3">
                        <div class="panel-title d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-alarm me-1"></i>Scheduled queue</h5>
                            <button type="button" class="btn btn-light border btn-sm" id="btnReloadSched"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" data-crm-table>
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Type</th>
                                        <th>Event date</th>
                                        <th>Will be sent at</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="schedBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-title d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-1"></i>Message history <small class="text-muted">(last 100)</small></h5>
                            <select class="form-select form-select-sm" id="hStatus" style="width:auto">
                                <option value="">All</option>
                                <option value="sent">Sent</option>
                                <option value="failed">Failed</option>
                            </select>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" data-crm-table>
                                <thead>
                                    <tr>
                                        <th>Date &amp; time</th>
                                        <th>Customer</th>
                                        <th>Type</th>
                                        <th>Event date</th>
                                        <th>How</th>
                                        <th>Result</th>
                                    </tr>
                                </thead>
                                <tbody id="histBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- WhatsApp queue modal -->
    <div class="modal fade" id="queueModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-whatsapp text-success me-2"></i>Send on WhatsApp</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="qBody"></div>
                <div class="modal-footer" id="qFoot"></div>
            </div>
        </div>
    </div>

    <!-- Check by date modal -->
    <div class="modal fade" id="dayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-event me-2"></i>Who gets a message on a date?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                        <div>
                            <label class="form-label small mb-1" for="dayDate">Select date</label>
                            <input type="date" class="form-control" id="dayDate">
                        </div>
                        <button type="button" class="btn btn-light border" data-day-add="0">Today</button>
                        <button type="button" class="btn btn-light border" data-day-add="1">Tomorrow</button>
                        <button type="button" class="btn btn-light border" data-day-add="7">+7 days</button>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mb-2" id="daySummary"></div>
                    <small class="text-muted d-block mb-2">Shows every message whose <b>"Send on"</b> date is the selected date (based on your schedule rules), who already got it, and who is still remaining.</small>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" data-crm-table>
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Type</th>
                                    <th>Event date</th>
                                    <th>Last message sent</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="dayBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="btnDaySend"><i class="bi bi-whatsapp me-1"></i>Send to remaining now</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Schedule modal -->
    <div class="modal fade" id="schedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-alarm me-2"></i>Schedule messages</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3" id="schedInfo"></p>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="radio" name="schedMode" id="schedModeCustom" value="custom" checked>
                        <label class="form-check-label" for="schedModeCustom">Send all at one date &amp; time (IST)</label>
                    </div>
                    <input type="datetime-local" class="form-control mb-3" id="schedAt">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="schedMode" id="schedModeDef" value="default">
                        <label class="form-check-label" for="schedModeDef">On each message's own <b>"Send on"</b> date at the saved sending time</label>
                    </div>
                    <small class="text-muted d-block mt-3">Scheduled messages are sent by the cron job (every 5 minutes). You can cancel them in the "History &amp; scheduled" tab.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnSchedSave"><i class="bi bi-check-lg me-1"></i>Schedule</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container position-fixed top-0 end-0 p-3" id="pageToasts" style="z-index:2000"></div>
    <div id="printArea"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
        (function() {
            'use strict';
            const CFG = <?= json_encode($jsCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const $ = (s, r = document) => r.querySelector(s);
            const $$ = (s, r = document) => [...r.querySelectorAll(s)];
            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const pad = n => String(n).padStart(2, '0');
            const localDT = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            const localD = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());

            /* ---- toast ---- */
            function toast(msg, type) {
                type = type || 'success';
                const el = document.createElement('div');
                el.className = 'toast crm-toast ' + type;
                el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button class="btn-close btn-close-white m-auto me-2" data-bs-dismiss="toast" aria-label="Close"></button></div>';
                el.querySelector('.toast-body').textContent = msg;
                $('#pageToasts').append(el);
                new bootstrap.Toast(el, {
                    delay: 4500
                }).show();
                el.addEventListener('hidden.bs.toast', () => el.remove());
            }

            /* ---- JSON call to this same file ---- */
            async function call(query, body) {
                const opt = {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                };
                if (body) {
                    body.set('csrf_token', CFG.csrf);
                    opt.method = 'POST';
                    opt.body = body;
                }
                try {
                    const res = await fetch('message-settings.php?' + new URLSearchParams(query), opt);
                    const j = await res.json().catch(() => null);
                    return j || {
                        success: false,
                        message: 'Server returned an invalid response (HTTP ' + res.status + ').'
                    };
                } catch (err) {
                    return {
                        success: false,
                        message: 'Network error. Please check your connection.'
                    };
                }
            }

            const waNumber = m => {
                const d = String(m || '').replace(/\D+/g, '');
                if (d.length === 10) return '91' + d;
                if (d.length === 11 && d[0] === '0') return '91' + d.slice(1);
                return d.length >= 12 ? d : '';
            };

            /* ============================ TEMPLATES ============================ */
            let pvName = 'Customer';
            const firstName = n => (String(n || '').trim().split(/\s+/)[0]) || 'there';
            const oneLine = s => String(s || '').replace(/\s+/g, ' ').trim();

            function fillMsg(key, name, msg) {
                return String(CFG.tpl[key] || '')
                    .replaceAll('{{1}}', firstName(name))
                    .replaceAll('{{2}}', oneLine(msg));
            }

            function refreshPreview(key) {
                const t = $('#msg_' + key).value;
                $('#pv_' + key).textContent = fillMsg(key, pvName, t);
                $('#cnt_' + key).textContent = t.length;
            }

            ['birthday', 'anniversary'].forEach(key => {
                $('#msg_' + key).addEventListener('input', () => refreshPreview(key));
                refreshPreview(key);
            });

            document.addEventListener('click', async e => {
                const b = e.target.closest('[data-save-tpl]');
                if (!b) return;
                const key = b.dataset.saveTpl;
                if (!$('#msg_' + key).value.trim()) {
                    toast('Message cannot be empty.', 'error');
                    return;
                }
                const fd = new FormData();
                fd.set('type', key);
                fd.set('message', $('#msg_' + key).value);
                b.disabled = true;
                const j = await call({
                    api: 'save_template'
                }, fd);
                b.disabled = false;
                if (j.success) {
                    toast(j.message);
                    loadList(S.page);
                } else toast(j.message || 'Could not save.', 'error');
            });

            /* ---- customer picker (preview) ---- */
            function setPreviewCustomer(name) {
                pvName = name;
                $$('.cpick-q').forEach(i => i.value = name);
                refreshPreview('birthday');
                refreshPreview('anniversary');
            }
            $$('.cpick').forEach(box => {
                const inp = $('.cpick-q', box),
                    list = $('.cpick-list', box);
                let timer;
                const show = async () => {
                    const j = await call({
                        api: 'customers',
                        q: inp.value.trim()
                    });
                    if (!j.success) {
                        list.classList.add('d-none');
                        return;
                    }
                    list.innerHTML = j.items.length ?
                        j.items.map(c => '<button type="button" class="list-group-item list-group-item-action py-2" data-n="' + esc(c.name) + '"><b>' + esc(c.name) + '</b> <small class="text-muted">' + esc(c.mobile || '') + '</small></button>').join('') :
                        '<div class="list-group-item text-muted small">No customer found</div>';
                    list.classList.remove('d-none');
                };
                inp.addEventListener('input', () => {
                    clearTimeout(timer);
                    timer = setTimeout(show, 250);
                });
                inp.addEventListener('focus', () => {
                    inp.select();
                    show();
                });
                list.addEventListener('click', e => {
                    const b = e.target.closest('[data-n]');
                    if (!b) return;
                    setPreviewCustomer(b.dataset.n);
                    list.classList.add('d-none');
                });
            });
            document.addEventListener('click', e => {
                if (!e.target.closest('.cpick')) $$('.cpick-list').forEach(l => l.classList.add('d-none'));
            });
            call({
                api: 'customers',
                q: ''
            }).then(j => {
                if (j.success && j.items.length) setPreviewCustomer(j.items[0].name);
            });

            /* ---- Test send through WhatsApp API ---- */
            document.addEventListener('click', async e => {
                const b = e.target.closest('[data-test]');
                if (!b) return;

                const key = b.dataset.test;
                const m = $('#test_' + key).value.trim();

                if (!waNumber(m)) {
                    toast('Enter a valid mobile number for the test.', 'error');
                    $('#test_' + key).focus();
                    return;
                }

                const fd = new FormData();
                fd.set('mode', 'test');
                fd.set('type', key);
                fd.set('mobile', m);

                b.disabled = true;
                const oldHtml = b.innerHTML;
                b.innerHTML = 'Sending...';
                const j = await call({
                    api: 'send_whatsapp'
                }, fd);
                b.disabled = false;
                b.innerHTML = oldHtml;

                if (j.success) toast(j.message || 'Test message sent.');
                else toast(j.message || 'WhatsApp API send failed.', 'error');
            });

            /* ============================ STATS ============================ */
            async function loadStats() {
                const j = await call({
                    api: 'stats'
                });
                if (!j.success) return;
                $('#stToday').textContent = j.to_send;
                $('#stSent').textContent = j.sent;
                $('#stFailed').textContent = j.failed;
                $('#stQueued').textContent = j.queued;
                $('#stAuto').innerHTML = j.auto ?
                    '<span class="text-success"><i class="bi bi-lightning-charge-fill"></i> Auto-send ON &middot; ' + esc(j.send_time) + '</span>' :
                    '<span class="text-muted">Auto-send OFF</span>';

                const cs = $('#cronStatus');
                if (cs) {
                    if (j.cron_min === null || j.cron_min === undefined) {
                        cs.innerHTML = '<span class="badge-s b-red">Cron NOT running</span> <small class="text-muted">It has never contacted this page. Check the cron job (URL with ?key=...).</small>';
                    } else if (j.cron_min > 15) {
                        cs.innerHTML = '<span class="badge-s b-amber">Cron stopped?</span> <small class="text-muted">Last run: ' + esc(j.cron_last) + ' (' + j.cron_min + ' min ago). It should run every 5 minutes.</small>';
                    } else {
                        cs.innerHTML = '<span class="badge-s b-green">Cron is working</span> <small class="text-muted">Last run: ' + esc(j.cron_last) + ' (' + j.cron_min + ' min ago) &middot; ' + esc(j.cron_res) + '</small>';
                    }
                }
            }

            /* ============================ CRON DIAGNOSIS ============================ */
            $('#btnDiag').addEventListener('click', async e => {
                const b = e.currentTarget,
                    box = $('#diagBox');
                b.disabled = true;
                box.classList.remove('d-none');
                box.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking...';
                const j = await call({
                    api: 'diagnose'
                });
                b.disabled = false;
                if (!j.success) {
                    box.innerHTML = '<span class="text-danger">' + esc(j.message || 'Could not check.') + '</span>';
                    return;
                }
                const ico = {
                    ok: '<i class="bi bi-check-circle-fill text-success"></i>',
                    warn: '<i class="bi bi-exclamation-circle-fill text-warning"></i>',
                    bad: '<i class="bi bi-x-circle-fill text-danger"></i>',
                    info: '<i class="bi bi-info-circle text-secondary"></i>'
                };
                box.innerHTML = '<div class="bg-white border rounded p-2">' + j.lines.map(l => '<div class="py-1">' + ico[l.lvl] + ' ' + esc(l.text) + '</div>').join('') + '</div>';
            });

            /* ============================ SCHEDULE RULES ============================ */
            $('#saveSchedule').addEventListener('click', async e => {
                const b = e.currentTarget;
                if (!$('#sTime').value) {
                    toast('Please choose a sending time.', 'error');
                    return;
                }
                const fd = new FormData();
                fd.set('send_time', $('#sTime').value);
                fd.set('before_birthday', $('#sBb').value);
                fd.set('before_anniversary', $('#sBa').value);
                fd.set('auto_send', $('#sAuto').checked ? 1 : 0);
                b.disabled = true;
                const j = await call({
                    api: 'save_schedule'
                }, fd);
                b.disabled = false;
                if (j.success) {
                    toast(j.message);
                    loadList(1);
                } else toast(j.message || 'Could not save.', 'error');
            });

            $('#btnRunNow').addEventListener('click', async e => {
                const b = e.currentTarget;
                if (!confirm('Send everything that is due right now (scheduled + auto-send)?')) return;
                b.disabled = true;
                const old = b.innerHTML;
                b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running...';
                const j = await call({
                    api: 'run_now'
                }, new FormData());
                b.disabled = false;
                b.innerHTML = old;
                toast(j.message || 'Finished.', j.success ? 'success' : 'error');
                loadList(S.page);
            });

            /* ============================ UPCOMING LIST ============================ */
            const S = {
                page: 1,
                pages: 1,
                total: 0,
                per: 30,
                items: [],
                sendTime: ''
            };
            const sel = new Map(); // key -> item (stays selected across pages)

            function rowHtml(it) {
                const wa = waNumber(it.mobile);
                let sub = '';
                if (it.sent && it.sent_at) sub = '<small class="text-muted d-block">' + esc(it.sent_at) + '</small>';
                else if (it.status === 'Queued') sub = '<small class="text-muted d-block">at ' + esc(it.sched_at) + '</small>';
                else if (it.status === 'Failed') sub = '<small class="text-danger d-block text-truncate" style="max-width:150px" title="' + esc(it.error) + '">' + esc(it.error) + '</small>';

                const last = it.last_sent ?
                    '<div>' + esc(it.last_sent) + '</div><small class="text-muted">' + esc(it.last_ago) + '</small>' :
                    '<span class="text-muted">Never</span>';

                return '<tr data-key="' + esc(it.key) + '">' +
                    '<td style="text-align: center;"><input type="checkbox" class="form-check-input rchk" aria-label="Select ' + esc(it.name) + '" style="border:1px solid #5d8ab6 !important;"' + (sel.has(it.key) ? ' checked' : '') + '></td>' +
                    '<td><div class="fw-semibold">' + esc(it.name) + '</div><small class="text-muted">' + esc(it.mobile || 'No mobile') + (it.city ? ' &middot; ' + esc(it.city) : '') + '</small></td>' +
                    '<td><i class="bi bi-' + (it.type === 'Birthday' ? 'gift' : 'heart') + ' text-gold me-1"></i>' + esc(it.type) + '</td>' +
                    '<td>' + esc(it.date_label) + '<br><small class="text-muted">' + esc(it.when) + (it.age ? ' &middot; ' + esc(it.age) : '') + '</small></td>' +
                    '<td>' + esc(it.send_label) + '<br><small class="text-muted">' + esc(S.sendTime) + '</small></td>' +
                    '<td>' + last + '</td>' +
                     
                    '<td><span class="badge-s ' + esc(it.cls) + '">' + esc(it.status) + '</span>' + sub + '</td>' +
                    '<td class="text-end text-nowrap">' +
                    (wa ?
                        '<button type="button" class="btn btn-sm btn-icon text-success" data-wa title="Send now through WhatsApp"><i class="bi bi-whatsapp"></i></button>' :
                        '<span class="btn btn-sm btn-icon disabled text-muted" title="No mobile number"><i class="bi bi-whatsapp"></i></span>') +
                    '<button type="button" class="btn btn-sm btn-icon' + (it.sent ? ' text-success' : '') + '" data-sent title="Mark as sent / undo"><i class="bi bi-check2-circle"></i></button>' +
                    '</td></tr>';
            }

            function renderList() {
                $('#msgBody').innerHTML = S.items.map(rowHtml).join('');
                $('#msgEmpty').classList.toggle('d-none', S.items.length > 0);
                const from = S.total ? (S.page - 1) * S.per + 1 : 0;
                const to = S.total ? from + S.items.length - 1 : 0;
                $('#msgInfo').textContent = S.total ? 'Showing ' + from + '-' + to + ' of ' + S.total : '0 records';

                let p = '';
                if (S.pages > 1) {
                    p += '<li class="page-item' + (S.page <= 1 ? ' disabled' : '') + '"><button class="page-link" data-page="' + (S.page - 1) + '">Previous</button></li>';
                    p += '<li class="page-item disabled"><span class="page-link">Page ' + S.page + ' / ' + S.pages + '</span></li>';
                    p += '<li class="page-item' + (S.page >= S.pages ? ' disabled' : '') + '"><button class="page-link" data-page="' + (S.page + 1) + '">Next</button></li>';
                }
                $('#msgPager').innerHTML = p;
                updateBar();
            }

            function updateBar() {
                const n = sel.size;
                $('#selCount').textContent = n;
                $('#selBar').classList.toggle('show', n > 0);

                const pageAll = S.items.length > 0 && S.items.every(it => sel.has(it.key));
                const chk = $('#chkPage');
                chk.checked = pageAll;
                chk.indeterminate = !pageAll && S.items.some(it => sel.has(it.key));

                const hint = $('#selAllHint');
                if (pageAll && S.total > S.items.length && sel.size < S.total) {
                    $('#selAllText').textContent = 'All ' + S.items.length + ' customers on this page are selected. ';
                    $('#btnSelAll').textContent = 'Select all ' + S.total + ' customers';
                    hint.classList.remove('d-none');
                } else {
                    hint.classList.add('d-none');
                }
            }

            const listQuery = extra => Object.assign({
                api: 'upcoming',
                q: $('#fQ').value.trim(),
                type: $('#fType').value,
                range: $('#fRange').value,
                status: $('#fStatus').value
            }, extra || {});

            async function loadList(page) {
                $('#msgBody').innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</td></tr>';
                $('#msgEmpty').classList.add('d-none');
                const j = await call(listQuery({
                    per: $('#fPer').value,
                    page: page || 1
                }));
                if (!j.success) {
                    $('#msgBody').innerHTML = '';
                    toast(j.message || 'Could not load the list.', 'error');
                    return;
                }
                S.items = j.items;
                S.page = j.page;
                S.pages = j.pages;
                S.total = j.total;
                S.per = j.per;
                S.sendTime = j.send_time;
                renderList();
                loadStats();
            }

            /* filters */
            let ft;
            $('#fQ').addEventListener('input', () => {
                clearTimeout(ft);
                ft = setTimeout(() => loadList(1), 350);
            });
            ['#fType', '#fRange', '#fStatus', '#fPer'].forEach(s => $(s).addEventListener('change', () => loadList(1)));
            $('#fClear').addEventListener('click', () => {
                $('#fQ').value = '';
                $('#fType').value = 'all';
                $('#fRange').value = '30';
                $('#fStatus').value = '';
                loadList(1);
            });
            $('#msgPager').addEventListener('click', e => {
                const b = e.target.closest('[data-page]');
                if (b) loadList(parseInt(b.dataset.page, 10));
            });

            /* selection */
            $('#msgBody').addEventListener('change', e => {
                if (!e.target.classList.contains('rchk')) return;
                const key = e.target.closest('tr').dataset.key;
                const it = S.items.find(x => x.key === key);
                if (!it) return;
                if (e.target.checked) sel.set(key, it);
                else sel.delete(key);
                updateBar();
            });
            $('#chkPage').addEventListener('change', e => {
                S.items.forEach(it => e.target.checked ? sel.set(it.key, it) : sel.delete(it.key));
                renderList();
            });
            $('#btnClearSel').addEventListener('click', () => {
                sel.clear();
                renderList();
            });
            $('#btnSelAll').addEventListener('click', async e => {
                const b = e.currentTarget;
                b.disabled = true;
                const j = await call(listQuery({
                    all: 1
                }));
                b.disabled = false;
                if (!j.success) {
                    toast(j.message || 'Could not select all.', 'error');
                    return;
                }
                j.items.forEach(it => sel.set(it.key, it));
                renderList();
                toast(j.items.length + ' customers selected.');
            });

            /* export CSV of the current filter */
            $('#btnCsv').addEventListener('click', async e => {
                const b = e.currentTarget;
                b.disabled = true;
                const j = await call(listQuery({
                    all: 1
                }));
                b.disabled = false;
                if (!j.success || !j.items.length) {
                    toast(j.success ? 'Nothing to export.' : (j.message || 'Could not export.'), 'error');
                    return;
                }
                const c = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
                const rows = [
                    ['Name', 'Mobile', 'City', 'Type', 'Event date', 'Send on', 'Status', 'Sent at', 'Last message sent'].map(c).join(',')
                ];
                j.items.forEach(it => rows.push([it.name, it.mobile, it.city, it.type, it.occ, it.send, it.status, it.sent_at, it.last_sent].map(c).join(',')));
                const blob = new Blob(['\ufeff' + rows.join('\r\n')], {
                    type: 'text/csv;charset=utf-8'
                });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'messages-' + CFG.today + '.csv';
                document.body.append(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 2000);
            });

            /* mark sent (one or many) */
            async function markItems(items, done) {
                const fd = new FormData();
                fd.set('done', done ? 1 : 0);
                fd.set('items', JSON.stringify(items.map(i => ({
                    id: i.id,
                    rtype: i.type,
                    occ: i.occ
                }))));
                return call({
                    api: 'mark_sent'
                }, fd);
            }

            /* row buttons: send now (API) + manual sent / undo */
            $('#msgBody').addEventListener('click', async e => {
                const tr = e.target.closest('tr');
                if (!tr) return;
                const it = S.items.find(x => x.key === tr.dataset.key);
                if (!it) return;

                if (e.target.closest('[data-wa]')) {
                    const btn = e.target.closest('[data-wa]');
                    const fd = new FormData();
                    fd.set('mode', 'reminder');
                    fd.set('customer_id', it.id);
                    fd.set('type', it.type);
                    fd.set('occ', it.occ);

                    if (it.sent) {
                        if (!confirm(it.name + ' already received this message' + (it.sent_at ? ' on ' + it.sent_at : '') + '.\nSend it again?')) return;
                        fd.set('force', 1);
                    } else if (!confirm('Send ' + it.type + ' message to ' + it.name + ' now?')) {
                        return;
                    }

                    btn.disabled = true;
                    const j = await call({
                        api: 'send_whatsapp'
                    }, fd);
                    btn.disabled = false;

                    if (!j.success) {
                        toast(j.message || 'WhatsApp API send failed.', 'error');
                        if (j.code === 'duplicate') loadList(S.page);
                        return;
                    }
                    sel.delete(it.key);
                    toast(j.message || 'WhatsApp message sent.');
                    loadList(S.page);
                } else if (e.target.closest('[data-sent]')) {
                    const j = await markItems([it], !it.sent);
                    if (j.success) {
                        toast(it.sent ? 'Marked as not sent.' : 'Marked as sent.');
                        loadList(S.page);
                    } else toast(j.message || 'Could not update.', 'error');
                }
            });

            $('#btnMarkSent').addEventListener('click', async () => {
                const items = [...sel.values()];
                if (!items.length) return;
                if (!confirm('Mark ' + items.length + ' reminders as "Sent"? (No WhatsApp message will be sent.)')) return;
                const j = await markItems(items, true);
                if (j.success) {
                    toast(j.message);
                    sel.clear();
                    loadList(S.page);
                } else toast(j.message || 'Could not update.', 'error');
            });

            /* ============================ WHATSAPP QUEUE ============================ */
            const qModal = new bootstrap.Modal('#queueModal');
            let Q = {
                list: [],
                i: 0,
                sent: 0,
                failed: 0,
                skipped: 0,
                already: 0,
                noMobile: 0,
                running: false,
                stop: false,
                errors: []
            };

            function renderQ() {
                const body = $('#qBody'),
                    foot = $('#qFoot');
                if (Q.i >= Q.list.length) {
                    body.innerHTML = '<div class="text-center py-3"><i class="bi bi-check-circle-fill text-success" style="font-size:44px"></i>' +
                        '<h5 class="mt-2">All done!</h5><p class="text-muted mb-0">' + Q.sent + ' message(s) sent' +
                        (Q.failed ? ', <span class="text-danger">' + Q.failed + ' failed</span>' : '') +
                        (Q.skipped ? ', ' + Q.skipped + ' skipped' : '') +
                        (Q.already ? ', ' + Q.already + ' already sent' : '') +
                        (Q.noMobile ? ', ' + Q.noMobile + ' without mobile number' : '') + '.</p>' +
                        (Q.errors.length ? '<div class="text-start small text-danger mt-3">' + Q.errors.slice(0, 5).map(x => '&bull; ' + esc(x)).join('<br>') + (Q.errors.length > 5 ? '<br>...' : '') + '</div>' : '') +
                        '</div>';
                    foot.innerHTML = '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>';
                    return;
                }
                const it = Q.list[Q.i];
                const pct = Math.round(Q.i / Q.list.length * 100);
                body.innerHTML =
                    '<div class="d-flex justify-content-between small text-muted mb-1"><span>Customer ' + (Q.i + 1) + ' of ' + Q.list.length + '</span><span>' + pct + '%</span></div>' +
                    '<div class="progress mb-3" style="height:6px"><div class="progress-bar bg-success" style="width:' + pct + '%"></div></div>' +
                    '<div class="fw-semibold">' + esc(it.name) + ' <small class="text-muted">' + esc(it.mobile) + '</small></div>' +
                    '<small class="text-muted d-block mb-1">' + esc(it.type) + ' &middot; ' + esc(it.date_label) + '</small>' +
                    '<small class="text-muted d-block mb-2">Last message: ' + (it.last_sent ? esc(it.last_sent) : 'Never') + '</small>' +
                    '<div class="q-msg">' + esc(it.msg) + '</div>';
                foot.innerHTML = Q.running ?
                    '<button type="button" class="btn btn-outline-danger" id="qStop"><i class="bi bi-stop-circle me-1"></i>Stop</button>' :
                    '<button type="button" class="btn btn-light" id="qSkip">Skip</button>' +
                    '<button type="button" class="btn btn-outline-success" id="qAll"><i class="bi bi-send-check me-1"></i>Send all (' + (Q.list.length - Q.i) + ')</button>' +
                    '<button type="button" class="btn btn-success" id="qSend"><i class="bi bi-whatsapp me-1"></i>Send &amp; next</button>';
            }

            function startQueue(all) {
                const withMobile = all.filter(it => waNumber(it.mobile));
                const list = withMobile.filter(it => !it.sent);
                if (!list.length) {
                    toast(withMobile.length ? 'All selected messages are already sent.' : 'The selected customers have no mobile number.', 'error');
                    return;
                }
                Q = {
                    list: list,
                    i: 0,
                    sent: 0,
                    failed: 0,
                    skipped: 0,
                    already: withMobile.length - list.length,
                    noMobile: all.length - withMobile.length,
                    running: false,
                    stop: false,
                    errors: []
                };
                renderQ();
                qModal.show();
            }

            $('#btnQueue').addEventListener('click', () => startQueue([...sel.values()]));

            async function sendCurrent() {
                const it = Q.list[Q.i];
                const fd = new FormData();
                fd.set('mode', 'reminder');
                fd.set('customer_id', it.id);
                fd.set('type', it.type);
                fd.set('occ', it.occ);
                const j = await call({
                    api: 'send_whatsapp'
                }, fd);
                if (j.success) {
                    sel.delete(it.key);
                    Q.sent++;
                } else if (j.code === 'duplicate') {
                    Q.already++;
                } else {
                    Q.failed++;
                    Q.errors.push(it.name + ': ' + (j.message || 'API send failed.'));
                }
                Q.i++;
            }

            $('#queueModal').addEventListener('click', async e => {
                if (e.target.closest('#qSend')) {
                    const btn = e.target.closest('#qSend');
                    btn.disabled = true;
                    btn.innerHTML = 'Sending...';
                    await sendCurrent();
                    renderQ();
                } else if (e.target.closest('#qSkip')) {
                    Q.i++;
                    Q.skipped++;
                    renderQ();
                } else if (e.target.closest('#qAll')) {
                    if (!confirm('Send ' + (Q.list.length - Q.i) + ' WhatsApp messages one after another?')) return;
                    Q.running = true;
                    Q.stop = false;
                    renderQ();
                    while (Q.i < Q.list.length && !Q.stop) {
                        await sendCurrent();
                        renderQ();
                        await sleep(500);
                    }
                    Q.running = false;
                    renderQ();
                } else if (e.target.closest('#qStop')) {
                    Q.stop = true;
                    e.target.closest('#qStop').disabled = true;
                }
            });

            $('#queueModal').addEventListener('hidden.bs.modal', () => {
                Q.stop = true;
                loadList(S.page);
                if ($('#dayModal').classList.contains('show')) loadDay();
            });

            /* ============================ SCHEDULE (date & time) ============================ */
            const schedModal = new bootstrap.Modal('#schedModal');
            let schedItems = [];

            $('#btnSchedule').addEventListener('click', () => {
                schedItems = [...sel.values()].filter(it => !it.sent);
                if (!schedItems.length) {
                    toast('Select customers whose message is not sent yet.', 'error');
                    return;
                }
                const d = new Date(Date.now() + 3600000);
                d.setMinutes(0, 0, 0);
                $('#schedAt').min = localDT(new Date());
                $('#schedAt').value = localDT(d);
                $('#schedModeCustom').checked = true;
                $('#schedInfo').innerHTML = '<b>' + schedItems.length + '</b> message(s) will be scheduled.';
                schedModal.show();
            });

            $('#btnSchedSave').addEventListener('click', async e => {
                const b = e.currentTarget;
                const mode = $('input[name="schedMode"]:checked').value;
                if (mode === 'custom' && !$('#schedAt').value) {
                    toast('Please choose a date and time.', 'error');
                    return;
                }
                const fd = new FormData();
                fd.set('mode', mode);
                fd.set('send_at', $('#schedAt').value);
                fd.set('items', JSON.stringify(schedItems.map(i => ({
                    id: i.id,
                    rtype: i.type,
                    occ: i.occ
                }))));
                b.disabled = true;
                const j = await call({
                    api: 'schedule_messages'
                }, fd);
                b.disabled = false;
                if (j.success) {
                    toast(j.message);
                    schedModal.hide();
                    schedItems.forEach(i => sel.delete(i.key));
                    loadList(S.page);
                } else toast(j.message || 'Could not schedule.', 'error');
            });

            /* ============================ CHECK BY DATE ============================ */
            const dayModal = new bootstrap.Modal('#dayModal');
            let D = {
                items: []
            };

            async function loadDay() {
                const d = $('#dayDate').value;
                if (!d) return;
                $('#dayBody').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</td></tr>';
                const j = await call({
                    api: 'day_view',
                    date: d
                });
                if (!j.success) {
                    $('#dayBody').innerHTML = '';
                    toast(j.message || 'Could not load.', 'error');
                    return;
                }
                D.items = j.items;
                $('#daySummary').innerHTML =
                    '<span class="badge-s b-blue">' + esc(j.date_label) + ' &middot; ' + esc(j.send_time) + '</span>' +
                    '<span class="badge-s b-amber">Total: ' + j.total + '</span>' +
                    '<span class="badge-s b-green">Already sent: ' + j.sent + '</span>' +
                    '<span class="badge-s b-red">Remaining: ' + j.remaining + '</span>';
                $('#btnDaySend').disabled = j.remaining === 0;
                $('#dayBody').innerHTML = j.items.length ? j.items.map(it =>
                    '<tr><td><div class="fw-semibold">' + esc(it.name) + '</div><small class="text-muted">' + esc(it.mobile || 'No mobile') + '</small></td>' +
                    '<td><i class="bi bi-' + (it.type === 'Birthday' ? 'gift' : 'heart') + ' text-gold me-1"></i>' + esc(it.type) + '</td>' +
                    '<td>' + esc(it.date_label) + (it.age ? '<br><small class="text-muted">' + esc(it.age) + '</small>' : '') + '</td>' +
                    '<td>' + (it.last_sent ? esc(it.last_sent) + '<br><small class="text-muted">' + esc(it.last_ago) + '</small>' : '<span class="text-muted">Never</span>') + '</td>' +
                    '<td><span class="badge-s ' + esc(it.cls) + '">' + esc(it.status) + '</span>' +
                    (it.sent && it.sent_at ? '<small class="text-muted d-block">' + esc(it.sent_at) + '</small>' : '') + '</td></tr>'
                ).join('') : '<tr><td colspan="5" class="text-center text-muted py-4">No birthday / anniversary message is due on this date.</td></tr>';
            }

            $('#btnDay').addEventListener('click', () => {
                if (!$('#dayDate').value) $('#dayDate').value = CFG.today;
                dayModal.show();
                loadDay();
            });
            $('#dayDate').addEventListener('change', loadDay);
            $$('[data-day-add]').forEach(b => b.addEventListener('click', () => {
                const d = new Date();
                d.setDate(d.getDate() + parseInt(b.dataset.dayAdd, 10));
                $('#dayDate').value = localD(d);
                loadDay();
            }));
            $('#btnDaySend').addEventListener('click', () => {
                const list = D.items.filter(it => !it.sent);
                if (!list.length) return;
                $('#dayModal').addEventListener('hidden.bs.modal', () => startQueue(list), {
                    once: true
                });
                dayModal.hide();
            });

            /* ============================ HISTORY TAB ============================ */
            const srcLabel = {
                manual: 'Manual',
                auto: 'Auto-send',
                scheduled: 'Scheduled',
                test: 'Test'
            };

            async function loadHistory() {
                const j = await call({
                    api: 'history',
                    status: $('#hStatus').value
                });
                if (!j.success) {
                    toast(j.message || 'Could not load history.', 'error');
                    return;
                }
                $('#histBody').innerHTML = j.items.length ? j.items.map(h =>
                    '<tr><td>' + esc(h.at) + '</td>' +
                    '<td><div class="fw-semibold">' + esc(h.name) + '</div><small class="text-muted">' + esc(h.mobile) + '</small></td>' +
                    '<td>' + esc(h.type) + '</td><td>' + esc(h.occ) + '</td>' +
                    '<td>' + esc(srcLabel[h.source] || h.source) + '</td>' +
                    '<td>' + (h.status === 'sent' ? '<span class="badge-s b-green">Sent</span>' : '<span class="badge-s b-red">Failed</span><small class="text-danger d-block">' + esc(h.error) + '</small>') + '</td></tr>'
                ).join('') : '<tr><td colspan="6" class="text-center text-muted py-4">No messages yet.</td></tr>';
            }

            async function loadSched() {
                const j = await call({
                    api: 'scheduled'
                });
                if (!j.success) {
                    toast(j.message || 'Could not load scheduled messages.', 'error');
                    return;
                }
                $('#schedBody').innerHTML = j.items.length ? j.items.map(s =>
                    '<tr><td><div class="fw-semibold">' + esc(s.name) + '</div><small class="text-muted">' + esc(s.mobile) + '</small></td>' +
                    '<td>' + esc(s.type) + '</td><td>' + esc(s.occ) + '</td><td>' + esc(s.send_at) + '</td>' +
                    '<td>' + (s.status === 'failed' ? '<span class="badge-s b-red">Failed</span><small class="text-danger d-block">' + esc(s.error) + '</small>' :
                        '<span class="badge-s b-blue">' + (s.status === 'sending' ? 'Sending' : 'Waiting') + '</span>' + (s.tries ? '<small class="text-muted d-block">Tries: ' + s.tries + '</small>' : '')) + '</td>' +
                    '<td class="text-end text-nowrap">' +
                    (s.status === 'failed' ? '<button class="btn btn-sm btn-outline-primary me-1" data-retry="' + s.id + '">Retry</button>' : '') +
                    (s.status !== 'sending' ? '<button class="btn btn-sm btn-outline-danger" data-cancel="' + s.id + '">Cancel</button>' : '') + '</td></tr>'
                ).join('') : '<tr><td colspan="6" class="text-center text-muted py-4">No scheduled messages waiting.</td></tr>';
            }

            $('#tabHistoryBtn').addEventListener('shown.bs.tab', () => {
                loadSched();
                loadHistory();
            });
            $('#hStatus').addEventListener('change', loadHistory);
            $('#btnReloadSched').addEventListener('click', () => {
                loadSched();
                loadHistory();
            });
            $('#schedBody').addEventListener('click', async e => {
                const c = e.target.closest('[data-cancel]'),
                    r = e.target.closest('[data-retry]');
                if (!c && !r) return;
                if (c && !confirm('Cancel this scheduled message?')) return;
                const fd = new FormData();
                fd.set('id', (c || r).dataset.cancel || (c || r).dataset.retry);
                const j = await call({
                    api: c ? 'cancel_scheduled' : 'retry_scheduled'
                }, fd);
                toast(j.message || 'Done.', j.success ? 'success' : 'error');
                loadSched();
                loadStats();
            });

            /* first load */
            loadList(1);
        })();
    </script>
<?php crm_foot(); ?>
</body>

</html>