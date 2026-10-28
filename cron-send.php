<?php



/**
 * cron-send.php
 * Scheduled WhatsApp messages + automatic reminders
 */

define('MSG_LIB_ONLY', true);

date_default_timezone_set('Asia/Kolkata');

@set_time_limit(300);
ignore_user_abort(true);

$logFile = __DIR__ . '/cron-send.log';

function cronLog(string $message): void
{
    global $logFile;

    $line = '[' . date('Y-m-d H:i:s') . ' IST] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/* Web URL security */
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';

    $key = (string)($_GET['key'] ?? '');

    if (
        !defined('CRON_KEY') ||
        (string)CRON_KEY === '' ||
        !hash_equals((string)CRON_KEY, $key)
    ) {
        http_response_code(403);
        exit('Forbidden: invalid cron key');
    }

    header('Content-Type: text/plain; charset=utf-8');
}

/* Load database connection and sender functions */
try {
    require_once __DIR__ . '/message-settings.php';
    
    date_default_timezone_set('Asia/Kolkata');

if (isset($conn) && $conn instanceof mysqli) {
    mysqli_query($conn, "SET time_zone = '+05:30'");
}

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            'Database connection $conn is missing.'
        );
    }

    mysqli_query($conn, "SET time_zone = '+05:30'");

    cronLog('CRON STARTED; PHP mode: ' . PHP_SAPI);

    $dbTime = mysqli_query(
        $conn,
        "SELECT NOW() AS db_time, @@session.time_zone AS db_timezone"
    );

    if ($dbTime) {
        $timeRow = mysqli_fetch_assoc($dbTime);

        cronLog(
            'Database time: ' . $timeRow['db_time'] .
            '; timezone: ' . $timeRow['db_timezone']
        );
    }

    $before = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM scheduled_messages
         WHERE status = 'pending'
           AND send_at <= NOW()"
    );

    if (!$before) {
        throw new RuntimeException(
            'Unable to check pending messages: ' . mysqli_error($conn)
        );
    }

    $due = mysqli_fetch_assoc($before);
    cronLog('Due scheduled messages before sender: ' . $due['total']);

    $sum = runAutoSender($conn, 'cron');

    $result =
        'Scheduled sent: ' . ($sum['scheduled_sent'] ?? 0) .
        ' | Auto sent: ' . ($sum['auto_sent'] ?? 0) .
        ' | Failed: ' . ($sum['failed'] ?? 0) .
        ' | Skipped: ' . ($sum['skipped'] ?? 0) .
        ' | Note: ' . ($sum['note'] ?? '');

    cronLog('SENDER RESULT: ' . $result);

    echo date('Y-m-d H:i:s') . ' IST | ' . $result . PHP_EOL;

    $debug = PHP_SAPI === 'cli'
        ? (($argv[1] ?? '') === 'debug')
        : (($_GET['debug'] ?? '') === '1');

    if ($debug) {
        echo PHP_EOL . "--- DIAGNOSIS ---" . PHP_EOL;

        foreach (diagnoseAutoSender($conn) as $line) {
            $output = '[' . strtoupper($line['lvl']) . '] ' . $line['text'];
            echo $output . PHP_EOL;
            cronLog('DIAGNOSIS: ' . $output);
        }
    }

    cronLog('CRON FINISHED');

} catch (Throwable $e) {
    cronLog(
        'ERROR: ' . get_class($e) . ': ' . $e->getMessage()
    );

    error_log('cron-send.php: ' . $e->getMessage());

    http_response_code(500);
    echo 'CRON ERROR: ' . $e->getMessage() . PHP_EOL;
}




// // --------------------------------------------------------------------------
// // cron-send.php - runs the auto-sender (scheduled date/time + daily auto-send)
// // --------------------------------------------------------------------------
// // Put this file in the SAME folder as message-settings.php.
// //
// // Cron job (every 5 minutes), in hPanel > Advanced > Cron Jobs:
// //     Minute: */5   Hour/Day/Month/Weekday: *
// //     Command: php /full/path/to/cron-send.php
// //
// // If your host only allows URL cron, config.php must have a CRON_KEY, and you call:
// //     https://your-site.com/cron-send.php?key=YOUR_CRON_KEY
// // --------------------------------------------------------------------------

// define('MSG_LIB_ONLY', true);

// if (PHP_SAPI !== 'cli') {
//     // web call: needs the secret key from config.php
//     require_once __DIR__ . '/config.php';
//     $key = (string)($_GET['key'] ?? '');
//     if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals((string)CRON_KEY, $key)) {
//         http_response_code(403);
//         exit('Forbidden');
//     }
//     header('Content-Type: text/plain; charset=utf-8');
// }

// @set_time_limit(300);

// require_once __DIR__ . '/message-settings.php';   // loads functions only (no page output)

// try {
//     $sum = runAutoSender($conn);
//     echo date('Y-m-d H:i:s') . ' | scheduled sent: ' . $sum['scheduled_sent']
//         . ' | auto sent: ' . $sum['auto_sent']
//         . ' | failed: ' . $sum['failed']
//         . ' | no mobile: ' . $sum['skipped']
//         . ($sum['note'] !== '' ? ' | ' . $sum['note'] : '') . PHP_EOL;
//     // &debug=1 (URL) or "php cron-send.php debug" (CLI): explain what the sender sees
//     $debug = (PHP_SAPI === 'cli') ? (($argv[1] ?? '') === 'debug') : (($_GET['debug'] ?? '') === '1');
//     if ($debug) {
//         echo PHP_EOL . '--- DIAGNOSIS ---' . PHP_EOL;
//         foreach (diagnoseAutoSender($conn) as $l) {
//             echo '[' . strtoupper($l['lvl']) . '] ' . $l['text'] . PHP_EOL;
//         }
//     }
// } catch (Throwable $ex) {
//     error_log('cron-send.php error: ' . $ex->getMessage());
//     echo date('Y-m-d H:i:s') . ' | ERROR: ' . $ex->getMessage() . PHP_EOL;
// }

?>







<?php
// --------------------------------------------------------------------------
// cron-send.php - runs the auto-sender (scheduled date/time + daily auto-send)
// --------------------------------------------------------------------------
// Put this file in the SAME folder as message-settings.php.
//
// Cron job (every 5 minutes), in hPanel > Advanced > Cron Jobs:
//     Minute: */5   Hour/Day/Month/Weekday: *
//     Command: php /full/path/to/cron-send.php
//
// If your host only allows URL cron, config.php must have a CRON_KEY, and you call:
//     https://your-site.com/cron-send.php?key=YOUR_CRON_KEY
// --------------------------------------------------------------------------

// define('MSG_LIB_ONLY', true);

// if (PHP_SAPI !== 'cli') {
//     // web call: needs the secret key from config.php
//     require_once __DIR__ . '/config.php';
//     $key = (string)($_GET['key'] ?? '');
//     if (!defined('CRON_KEY') || CRON_KEY === '' || !hash_equals((string)CRON_KEY, $key)) {
//         http_response_code(403);
//         exit('Forbidden');
//     }
//     header('Content-Type: text/plain; charset=utf-8');
// }

// @set_time_limit(300);

// require_once __DIR__ . '/message-settings.php';   // loads functions only (no page output)

// try {
//     $sum = runAutoSender($conn);
//     echo date('Y-m-d H:i:s') . ' | scheduled sent: ' . $sum['scheduled_sent']
//         . ' | auto sent: ' . $sum['auto_sent']
//         . ' | failed: ' . $sum['failed']
//         . ' | no mobile: ' . $sum['skipped']
//         . ($sum['note'] !== '' ? ' | ' . $sum['note'] : '') . PHP_EOL;
// } catch (Throwable $ex) {
//     error_log('cron-send.php error: ' . $ex->getMessage());
//     echo date('Y-m-d H:i:s') . ' | ERROR: ' . $ex->getMessage() . PHP_EOL;
// }