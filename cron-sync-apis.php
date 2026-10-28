<?php
/*
|--------------------------------------------------------------------------
| cron-sync-apis.php  -  Hostinger cron job
|--------------------------------------------------------------------------
| 1) api_integrations table se saari CONNECTED APIs (is_connected = 1) leta hai
|    (Housing, 99acres, REI ... jo bhi api-settings.php me add hai)
| 2) Har API se leads fetch karta hai
| 3) Naye leads ko customers table me insert karta hai
|    source = API ka naam (jaise "Housing"), duplicate mobile skip hota hai
| 4) Jin customers ka wa_status 'pending' / 'failed' hai unko WhatsApp bhejta hai
|    aur result customers.wa_status + message_history me likhta hai
| 5) Sab kuch logs/api-sync.log me likhta hai
|
| Test (SSH / terminal):      php cron-sync-apis.php --debug
| Browser (sirf agar CRON_SECRET define kiya):  cron-sync-apis.php?key=SECRET&debug=1
|--------------------------------------------------------------------------
*/

/* ---------- Access control ---------- */
$isCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/customer-core.php';

if (!$isCli) {
    /* config.php me pehle se defined CRON_KEY use hota hai */
    $secret = defined('CRON_KEY') ? (string)constant('CRON_KEY') : '';
    $key    = (string)($_GET['key'] ?? '');
    if ($secret === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$debug = $isCli ? in_array('--debug', $argv ?? [], true) : isset($_GET['debug']);

@set_time_limit(280);
ignore_user_abort(true);

/* ---------- Logging ---------- */
const LOG_DIR = __DIR__ . '/logs';

function logLine(string $msg): void
{
    global $isCli;
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
    $ht = LOG_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    $file = LOG_DIR . '/api-sync.log';
    if (is_file($file) && filesize($file) > 2 * 1024 * 1024) {
        @rename($file, $file . '.' . date('Ymd-His') . '.old');
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    if ($isCli) {
        echo $line;
    } else {
        echo $line;
    }
}

function maskMobile(string $m): string
{
    return strlen($m) >= 4 ? str_repeat('x', strlen($m) - 4) . substr($m, -4) : $m;
}

/* ---------- Lock: do cron ek saath na chalein ---------- */
$lockFh = @fopen(__DIR__ . '/logs/.sync.lock', 'c');
if (!$lockFh) {
    @mkdir(LOG_DIR, 0755, true);
    $lockFh = @fopen(LOG_DIR . '/.sync.lock', 'c');
}
if ($lockFh && !flock($lockFh, LOCK_EX | LOCK_NB)) {
    logLine('Another sync is still running. Exiting.');
    exit;
}

/*
|--------------------------------------------------------------------------
| API profiles
|--------------------------------------------------------------------------
| Har portal ki API alag hoti hai. Yahan har API ka naam (lowercase) ke
| hisaab se auth ka tareeka set hota hai:
|   auth   : bearer | header | basic | query
|   method : GET | POST
|   headers: extra headers
|   query  : extra query params (jaise from_date)
| Housing ki official documentation dekh kar sirf ye array badalna hai.
*/

/* ---------- API profiles ---------- */
function apiProfile(string $name): array
{
    $n = strtolower(trim($name));

    // Name "Housing", "Housing API" ya "Housing.com" ho sakta hai.
    if (strpos($n, 'housing') !== false) {
        return [
            'auth'    => 'housing_hmac',
            'method'  => 'GET',
            'headers' => [],
            'query'   => [],
        ];
    }

    if (strpos($n, '99acres') !== false) {
        return [
            'auth'    => 'bearer',
            'method'  => 'GET',
            'headers' => [],
            'query'   => [],
        ];
    }

    if (strpos($n, 'rei') !== false) {
        return [
            'auth'    => 'bearer',
            'method'  => 'GET',
            'headers' => [],
            'query'   => [],
        ];
    }

    return [
        'auth'    => 'bearer',
        'method'  => 'GET',
        'headers' => [],
        'query'   => [],
    ];
}


/* ---------- Fetch leads from one API ---------- */
function apiFetchLeads(array $api): array
{
    $p = apiProfile((string)($api['name'] ?? ''));

    $url = trim((string)($api['endpoint_url'] ?? ''));
    $key = trim((string)($api['api_key'] ?? ''));
    $sec = trim((string)($api['api_secret'] ?? ''));

    if ($url === '') {
        return [
            'ok' => false,
            'leads' => [],
            'error' => 'API endpoint URL is empty.',
            'raw' => '',
        ];
    }

    $headers = ['Accept: application/json'];

    foreach ($p['headers'] as $hk => $hv) {
        $headers[] = $hk . ': ' . $hv;
    }

    $query = $p['query'];

    switch ($p['auth']) {
        case 'housing_hmac':

            // Current CRM mapping:
            // api_key    = Housing Encryption Key
            // api_secret = Housing Profile ID
            $accountId = $sec;

            if ($key === '' || $accountId === '') {
                return [
                    'ok' => false,
                    'leads' => [],
                    'error' => 'Housing API key or Profile ID is missing. Check api_integrations.',
                    'raw' => '',
                ];
            }

            // Working CRM ke request ke mutabik timestamps.
            $startTime   = strtotime(date('Y-m-d 00:00:00'));
            $endTime     = time();
            $currentTime = time();

            // Working CRM mein parameter "hash" hai, "1hash" nahi.
            $hash = hash_hmac(
                'sha256',
                (string)$currentTime,
                $key
            );

            $query['start_date']   = (string)$startTime;
            $query['end_date']     = (string)$endTime;
            $query['current_time'] = (string)$currentTime;
            $query['hash']         = $hash;
            $query['id']           = $accountId;

            break;

        case 'header':
            if ($key !== '') {
                $headers[] = 'X-API-KEY: ' . $key;
            }

            if ($sec !== '') {
                $headers[] = 'X-API-SECRET: ' . $sec;
            }
            break;

        case 'basic':
            $headers[] = 'Authorization: Basic ' .
                base64_encode($key . ':' . $sec);
            break;

        case 'query':
            $query['api_key'] = $key;

            if ($sec !== '') {
                $query['api_secret'] = $sec;
            }
            break;

        case 'bearer':
        default:
            $token = $key !== '' ? $key : $sec;

            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            break;
    }

    // GET request ke query parameters URL mein add karo.
    if ($query && strtoupper($p['method']) === 'GET') {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        $url .= $separator . http_build_query($query);
    }

    $ch = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
    ];

    if (strtoupper($p['method']) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode(
            $query,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $opts[CURLOPT_HTTPHEADER] = array_merge(
            $headers,
            ['Content-Type: application/json']
        );
    }

    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false || $err !== '') {
        return [
            'ok' => false,
            'leads' => [],
            'error' => 'Connection failed: ' . $err,
            'raw' => '',
        ];
    }

    if ($http < 200 || $http >= 300) {
        return [
            'ok' => false,
            'leads' => [],
            'error' => 'HTTP ' . $http . ' ' . substr((string)$body, 0, 500),
            'raw' => (string)$body,
        ];
    }

    $json = json_decode((string)$body, true);

    if (!is_array($json)) {
        return [
            'ok' => false,
            'leads' => [],
            'error' => 'Response is not valid JSON.',
            'raw' => (string)$body,
        ];
    }

    // Housing API response ke "data" se leads nikaalo.
    return [
        'ok' => true,
        'leads' => extractLeadList($json),
        'error' => '',
        'raw' => (string)$body,
    ];
}


/* Response me se leads ki list nikalo (root list, ya data/leads/results/... ke andar) */
function extractLeadList(array $json): array
{
    $isList = static function (array $a): bool {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    };
    if ($isList($json)) {
        return $json;
    }
    $keys = ['data', 'leads', 'results', 'result', 'items', 'enquiries', 'inquiries', 'records', 'response'];
    foreach ($keys as $k) {
        if (isset($json[$k]) && is_array($json[$k])) {
            if ($isList($json[$k])) {
                return $json[$k];
            }
            foreach ($keys as $k2) {
                if (isset($json[$k][$k2]) && is_array($json[$k][$k2]) && $isList($json[$k][$k2])) {
                    return $json[$k][$k2];
                }
            }
        }
    }
    return [];
}

/* Nested array ko flat banao, keys lowercase + sirf a-z0-9 */
function flattenLead(array $a, array &$out = []): array
{
    foreach ($a as $k => $v) {
        if (is_array($v)) {
            flattenLead($v, $out);
        } else {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$k));
            if ($key !== '' && !isset($out[$key]) && $v !== null && $v !== '') {
                $out[$key] = (string)$v;
            }
        }
    }
    return $out;
}

function pick(array $flat, array $candidates): string
{
    foreach ($candidates as $c) {
        $k = strtolower(preg_replace('/[^a-z0-9]/i', '', $c));
        if (isset($flat[$k]) && trim($flat[$k]) !== '') {
            return trim($flat[$k]);
        }
    }
    return '';
}

/* ---------- Insert one lead. Returns inserted|duplicate|invalid ---------- */
function insertApiCustomer(mysqli $conn, array $api, array $lead, string &$why): string
{
    $flat = flattenLead($lead);

    $name   = pick($flat, ['name', 'customer_name', 'lead_name', 'full_name', 'user_name', 'buyer_name', 'contact_name', 'client_name', 'username']);
    $mobile = normalizeMobile(pick($flat, [
    'mobile',
    'phone',
    'lead_phone',
    'mobile_number',
    'phone_number',
    'contact_number',
    'contact',
    'customer_mobile',
    'lead_mobile',
    'customer_phone',
    'whatsapp'
]));
    $email  = pick($flat, ['email', 'email_id', 'customer_email', 'lead_email']);
    // $city = pick($flat, ['city', 'location_city', 'locality']);
    // City ko alag field se nikalo
$city = pick($flat, [
    'city',
    'city_name',
    'location_city',
    'property_city'
]);

// Locality ko address mein save karne ke liye nikalo
$locality = pick($flat, [
    'locality',
    'locality_name',
    'sub_locality',
    'sublocality',
    'area',
    'area_name',
    'micro_market',
    'neighborhood',
    'address'
]);

$state = pick($flat, ['state', 'state_name']);
     
    $pin    = preg_replace('/\D+/', '', pick($flat, ['pincode', 'pin', 'zip', 'postal_code']));
    $msg    = pick($flat, ['message', 'requirement', 'remarks', 'comments', 'enquiry', 'query', 'description']);
    $prop   = pick($flat, ['property_name', 'project_name', 'project', 'property_title', 'listing_title', 'property']);
    $ref    = pick($flat, ['lead_id', 'enquiry_id', 'inquiry_id', 'reference_id', 'id']);

    if ($mobile === '') {
        $why = 'invalid/missing mobile';
        return 'invalid';
    }
    if ($name === '') {
        $name = $api['name'] . ' Lead';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
        $email = '';
    }
    if (!preg_match('/^[0-9]{6}$/', $pin)) {
        $pin = '000000';
    }

    $noteParts = [];
    if ($ref !== '')  $noteParts[] = $api['name'] . ' lead ref: ' . $ref;
    if ($prop !== '') $noteParts[] = 'Property: ' . $prop;
    if ($msg !== '')  $noteParts[] = 'Message: ' . $msg;
    $notes = mb_substr(implode(' | ', $noteParts), 0, 2000);

    /* duplicate mobile? */
    // $chk = mysqli_prepare($conn, 'SELECT id FROM customers WHERE mobile = ? LIMIT 1');
    // mysqli_stmt_bind_param($chk, 's', $mobile);
    // mysqli_stmt_execute($chk);
    // mysqli_stmt_store_result($chk);
    // $exists = mysqli_stmt_num_rows($chk) > 0;
    // mysqli_stmt_close($chk);
    // if ($exists) {
    //     return 'duplicate';
    // }
    
    /* City aur address ki values prepare karo */
$city = mb_substr($city, 0, 100);
$address = mb_substr($locality, 0, 255);

/* Duplicate mobile? Missing City/Locality ko bhi update karo */
$chk = mysqli_prepare(
    $conn,
    'SELECT id FROM customers WHERE mobile = ? LIMIT 1'
);
mysqli_stmt_bind_param($chk, 's', $mobile);
mysqli_stmt_execute($chk);
mysqli_stmt_bind_result($chk, $existingId);

$exists = mysqli_stmt_fetch($chk);
mysqli_stmt_close($chk);

if ($exists) {
    $upd = mysqli_prepare(
        $conn,
        "UPDATE customers
         SET city = CASE
                 WHEN city IS NULL OR city = '' OR city = '-'
                 THEN ? ELSE city
             END,
             address = CASE
                 WHEN address IS NULL OR address = '' OR address = '-'
                 THEN ? ELSE address
             END
         WHERE id = ?"
    );

    $existingId = (int)$existingId;
    mysqli_stmt_bind_param(
        $upd,
        'ssi',
        $city,
        $address,
        $existingId
    );
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    return 'duplicate';
}

    $name    = mb_substr($name, 0, 150);
    $city    = mb_substr($city !== '' ? $city : '-', 0, 100);
    $state   = mb_substr($state !== '' ? $state : '-', 0, 100);
    $emailDb = $email !== '' ? $email : null;
    $notesDb = $notes !== '' ? $notes : null;
    $source  = mb_substr((string)$api['name'], 0, 60);
    $apiId   = (int)$api['id'];

    try {
       $st = mysqli_prepare(
    $conn,
    "INSERT INTO customers
        (name, mobile, email, gender, city, address, state, pin,
         clothing_preferences, notes, category, registration_date,
         source, api_id, whatsapp_consent, sms_consent, wa_status)
     VALUES (?, ?, ?, 'Other', ?, ?, ?, ?, '[]', ?, 'Regular',
             CURDATE(), ?, ?, 1, 0, 'pending')"
);

mysqli_stmt_bind_param(
    $st,
    'sssssssssi',
    $name,
    $mobile,
    $emailDb,
    $city,
    $address,
    $state,
    $pin,
    $notesDb,
    $source,
    $apiId
);

mysqli_stmt_execute($st);
mysqli_stmt_close($st);

/* every NEW portal lead also becomes an inquiry + notification (bell rings in the CRM) */
try {
    require_once __DIR__ . '/includes/inquiry.php';
    crm_inquiry_create(['name' => $name, 'mobile' => $mobile, 'email' => $email, 'message' => $notes, 'source' => $source, 'type' => 'Contact']);
} catch (Throwable $inqEx) {
    error_log('cron-sync-apis inquiry: ' . $inqEx->getMessage());
}

return 'inserted';
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            return 'duplicate';
        }
        $why = $e->getMessage();
        return 'invalid';
    }
}

/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/
logLine('===== Sync started =====');

try {
    /* ---- STEP 1: APIs se leads laao ---- */
    $res = mysqli_query(
        $conn,
        "SELECT id, name, endpoint_url, api_key, api_secret
           FROM api_integrations
          WHERE is_connected = 1 AND endpoint_url <> ''
          ORDER BY id ASC"
    );
    $apis = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $apis[] = $r;
    }
    logLine('Connected APIs found: ' . count($apis));

    foreach ($apis as $api) {
        $label = $api['name'] . ' (#' . $api['id'] . ')';
        $f = apiFetchLeads($api);

        if (!$f['ok']) {
            logLine("[$label] FETCH FAILED: " . $f['error']);
            if ($debug && $f['raw'] !== '') {
                logLine("[$label] RAW: " . substr($f['raw'], 0, 800));
            }
            $msg = mb_substr('Failed: ' . $f['error'], 0, 500);
            $u = mysqli_prepare($conn, 'UPDATE api_integrations SET last_synced_at = NOW(), last_sync_msg = ? WHERE id = ?');
            $aid = (int)$api['id'];
            mysqli_stmt_bind_param($u, 'si', $msg, $aid);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
            continue;
        }

        $leads = $f['leads'];
        logLine("[$label] Leads received: " . count($leads));
        if ($debug && $leads) {
            logLine("[$label] SAMPLE LEAD: " . substr(json_encode($leads[0], JSON_UNESCAPED_UNICODE), 0, 800));
        }

        $ins = $dup = $bad = 0;
        foreach ($leads as $lead) {
            if (!is_array($lead)) {
                $bad++;
                continue;
            }
            $why = '';
            $r = insertApiCustomer($conn, $api, $lead, $why);
            if ($r === 'inserted')      $ins++;
            elseif ($r === 'duplicate') $dup++;
            else {
                $bad++;
                logLine("[$label] skipped lead: $why");
            }
        }

        $summary = "inserted=$ins, duplicate=$dup, skipped=$bad";
        logLine("[$label] $summary");

        $u = mysqli_prepare($conn, 'UPDATE api_integrations SET last_synced_at = NOW(), last_sync_msg = ? WHERE id = ?');
        $aid = (int)$api['id'];
        mysqli_stmt_bind_param($u, 'si', $summary, $aid);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
    }

    /* ---- STEP 2: WhatsApp (naye + pehle fail hue customers) ---- */
    $maxTry = (int)WA_MAX_ATTEMPTS;
    $res = mysqli_query(
        $conn,
        "SELECT id, name, mobile, source
           FROM customers
          WHERE wa_status IN ('pending', 'failed')
            AND wa_attempts < $maxTry
            AND created_at >= (NOW() - INTERVAL 3 DAY)
          ORDER BY id ASC
          LIMIT 200"
    );
    $queue = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $queue[] = $r;
    }
    logLine('WhatsApp queue: ' . count($queue));

    $sent = $failed = 0;
    foreach ($queue as $c) {
        $wa = sendWelcomeWhatsapp($conn, (int)$c['id'], (string)$c['name'], (string)$c['mobile'], 'api');
        if (!empty($wa['ok'])) {
            $sent++;
            logLine('WA sent  -> #' . $c['id'] . ' ' . maskMobile((string)$c['mobile']) . ' [' . $c['source'] . ']');
        } else {
            $failed++;
            logLine('WA FAIL  -> #' . $c['id'] . ' ' . maskMobile((string)$c['mobile']) . ' : ' . $wa['error']);
        }
        usleep(300000); // 0.3 sec gap
    }
    logLine("WhatsApp done: sent=$sent, failed=$failed");
} catch (Throwable $e) {
    logLine('FATAL: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

logLine('===== Sync finished =====');

if ($lockFh) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}