<?php
/* WhatsApp webhook: verify + delivery status log */
const VERIFY_TOKEN = 'aurelia_verify_2026';   // Meta me bhi yahi daalna

$logFile = __DIR__ . '/wa_status.log';

function wlog(string $file, string $line): void
{
    @file_put_contents($file, date('Y-m-d H:i:s') . ' | ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ---- Meta verification (GET) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? '');
    $token     = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
    $challenge = $_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? '');

    if ($mode === 'subscribe' && hash_equals(VERIFY_TOKEN, (string)$token)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

/* ---- Incoming events (POST) ---- */
$raw  = file_get_contents('php://input');
wlog($logFile, 'RAW ' . substr((string)$raw, 0, 3000));   // debugging ke liye

$data = json_decode((string)$raw, true);

foreach (($data['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        foreach (($change['value']['statuses'] ?? []) as $s) {
            $line = 'to=' . ($s['recipient_id'] ?? '')
                . ' | status=' . ($s['status'] ?? '')
                . ' | id=' . ($s['id'] ?? '');
            if (!empty($s['errors'][0])) {
                $er = $s['errors'][0];
                $line .= ' | error=' . ($er['code'] ?? '') . ' ' . ($er['title'] ?? '')
                       . ' ' . ($er['error_data']['details'] ?? '');
            }
            wlog($logFile, $line);
        }
    }
}

http_response_code(200);
echo 'OK';