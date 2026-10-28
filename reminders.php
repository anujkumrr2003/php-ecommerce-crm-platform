<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/*
|--------------------------------------------------------------------------
| reminders.php  -  Birthday / Anniversary reminders + Upcoming events
|                   + History & scheduled
|--------------------------------------------------------------------------
| IMPORTANT: this file REQUIRES message-settings.php in the same folder.
|   It loads all WhatsApp API / sending functions from there (MSG_LIB_ONLY),
|   exactly like cron-send.php does. So WhatsApp messages go through the
|   Whatsupbizto API (no more wa.me manual links).
|
| Data    : customers (dob, anniversary), customer_remarks (chat),
|           reminder_log / message_history / scheduled_messages (from message-settings.php)
|           reminder_events (upcoming events) -> auto-created
|
| Page    : reminders.php?view=birthday|anniversary|events|history
|           &q=&from=&to=&status=&per=30|60|100|all&page=
| AJAX GET : summary, remarks, history, scheduled
| AJAX POST: send_whatsapp, schedule_messages, cancel_scheduled, retry_scheduled,
|            mark_sent, event_save, event_delete, remark_add, remark_done, remark_delete
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Kolkata');            // Delhi / Noida (IST)

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

/* all common helpers + WhatsApp API functions live in message-settings.php */
define('MSG_LIB_ONLY', true);
require_once __DIR__ . '/message-settings.php';   // also loads config.php ($conn)

if (empty($_SESSION['reminders_csrf'])) {
    $_SESSION['reminders_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['reminders_csrf'];

const REMARK_TYPES = ['Call', 'WhatsApp', 'Visit', 'Other'];
const QUICK_REPLIES = [
    'Phone not picked',
    'Asked to call back later',
    'Interested, will visit store',
    'Sent catalogue on WhatsApp',
    'Order confirmed',
    'Payment pending',
    'Not interested',
];
const PER_OPTIONS = ['30', '60', '100', 'all'];
const MAX_RANGE_YEARS = 3;
const LIST_STATUSES = [
    'Due'       => 'Due (ready to send)',
    'Scheduled' => 'Scheduled',
    'Queued'    => 'Queued (date & time set)',
    'Failed'    => 'Failed',
    'Missed'    => 'Missed',
    'Sent'      => 'Sent',
];

/* ------------------------------------------------------------------ helpers
   (e, cleanValue, jsonOut, validDateOrNull, dbAll, dbExec, fmtDate, fmtDateTime,
    daysDiff, whenText come from message-settings.php) */

function dbOne(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = dbAll($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

/* for GET filters: invalid date => ignored */
function safeDate(string $v): string
{
    if ($v === '') {
        return '';
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

function initials(string $name): string
{
    $out = '';
    foreach (preg_split('/\s+/u', trim($name)) as $w) {
        if ($w !== '') {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        if (mb_strlen($out) >= 2) {
            break;
        }
    }
    return $out !== '' ? $out : '?';
}

function dayLabel(string $ymd): string
{
    $today = date('Y-m-d');
    if ($ymd === $today) {
        return 'Today';
    }
    if ($ymd === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    return date('j F Y', strtotime($ymd));
}

function agoText(?string $dt): string
{
    if (!$dt) {
        return '-';
    }
    $n = (int)(new DateTime('today'))->diff(new DateTime(substr($dt, 0, 10)))->days;
    if ($n === 0) {
        return 'Today';
    }
    if ($n === 1) {
        return 'Yesterday';
    }
    return $n < 60 ? $n . ' days ago' : fmtDate($dt);
}

function remarkIcon(string $type): string
{
    switch ($type) {
        case 'Call':
            return 'telephone';
        case 'WhatsApp':
            return 'whatsapp';
        case 'Visit':
            return 'shop';
        default:
            return 'chat-text';
    }
}

function eventStatus(string $date): array
{
    $today = date('Y-m-d');
    if ($date === $today) {
        return ['Today', 'b-amber'];
    }
    return $date > $today ? ['Upcoming', 'b-blue'] : ['Past', 'b-gray'];
}

function statusBadge(array $st): string
{
    return '<span class="badge-s ' . $st[1] . '">' . e($st[0]) . '</span>';
}

/* status cell (badge + small info) of a birthday / anniversary item */
function statusCell(array $it): string
{
    $h = '<span class="badge-s ' . e($it['cls']) . '">' . e($it['status']) . '</span>';
    if ($it['sent'] && $it['sent_at'] !== '') {
        $h .= '<small class="text-muted d-block">' . e($it['sent_at']) . '</small>';
    } elseif ($it['status'] === 'Queued' && $it['sched_at'] !== '') {
        $h .= '<small class="text-muted d-block">at ' . e($it['sched_at']) . '</small>';
    } elseif ($it['status'] === 'Failed' && $it['error'] !== '') {
        $h .= '<small class="text-danger d-block text-truncate" style="max-width:150px" title="' . e($it['error']) . '">' . e($it['error']) . '</small>';
    }
    return $h;
}

/* "last message sent" cell */
function lastCell(array $it): string
{
    if ($it['last_sent'] === '') {
        return '<span class="text-muted">Never</span>';
    }
    return '<div>' . e($it['last_sent']) . '</div><small class="text-muted">' . e($it['last_ago']) . '</small>';
}

/* one item (customer + type + event date) with fresh status */
function itemFor(mysqli $conn, array $cfg, string $type, int $cid, string $occ): ?array
{
    foreach (collectType($conn, $cfg, $type, $occ, $occ) as $it) {
        if ((int)$it['id'] === $cid) {
            return $it;
        }
    }
    return null;
}

function rowPayload(?array $it): array
{
    if (!$it) {
        return [];
    }
    return [
        'sent'        => (int)$it['sent'],
        'status_html' => statusCell($it),
        'last_html'   => lastCell($it),
        'last_text'   => (string)$it['last_sent'],
    ];
}

/* keep current filters in links */
function qs(array $over = []): string
{
    $keep = [];
    foreach (['view', 'q', 'from', 'to', 'status', 'per', 'page'] as $k) {
        $v = cleanValue($_GET[$k] ?? '');
        if ($v !== '') {
            $keep[$k] = $v;
        }
    }
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') {
            unset($keep[$k]);
        } else {
            $keep[$k] = $v;
        }
    }
    return $keep ? '?' . http_build_query($keep) : '';
}

/* ----------------------------------------------------- tables (auto create) */

function ensureRemTables(mysqli $conn): void
{
    if (!empty($_SESSION['reminders_tbl_ok2'])) {
        return;
    }
    try {
        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS customer_remarks (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id   INT UNSIGNED    NOT NULL,
                remark        TEXT            NOT NULL,
                remark_type   VARCHAR(20)     NOT NULL DEFAULT 'Call',
                remark_at     DATETIME        NOT NULL,
                followup_date DATE            NULL DEFAULT NULL,
                followup_done TINYINT(1)      NOT NULL DEFAULT 0,
                created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_customer_time (customer_id, remark_at),
                KEY idx_followup (followup_done, followup_date)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS reminder_events (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                title      VARCHAR(150) NOT NULL,
                event_date DATE         NOT NULL,
                details    VARCHAR(500) NULL DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_event_date (event_date)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $_SESSION['reminders_tbl_ok2'] = 1;
    } catch (Throwable $ex) {
        error_log('reminders.php ensureRemTables: ' . $ex->getMessage());   // maybe no CREATE permission
    }
}

/* ------------------------------------------------------- remarks (chat) side */

function followupCell(?string $next, ?string $last): string
{
    $h = '';
    if ($next) {
        $today = date('Y-m-d');
        if ($next < $today) {
            $n  = (int)(new DateTime($today))->diff(new DateTime($next))->days;
            $h .= '<span class="badge-s b-red"><i class="bi bi-alarm"></i> Overdue</span><br><small class="text-muted">' . e(fmtDate($next)) . ' (' . $n . ' day' . ($n === 1 ? '' : 's') . ')</small>';
        } elseif ($next === $today) {
            $h .= '<span class="badge-s b-amber"><i class="bi bi-alarm"></i> Today</span>';
        } else {
            $h .= '<span class="badge-s b-blue"><i class="bi bi-alarm"></i> ' . e(fmtDate($next)) . '</span>';
        }
    } else {
        $h .= '<span class="text-muted">-</span>';
    }
    if ($last) {
        $h .= '<br><small class="text-muted">Last talk: ' . e(agoText($last)) . '</small>';
    }
    return $h;
}

function renderChat(array $remarks): string
{
    if (!$remarks) {
        return '<div class="chat-empty"><i class="bi bi-chat-dots"></i>'
            . '<p class="mb-0">No remarks yet.<br>After talking to the customer, write a remark below and save it.</p></div>';
    }

    $today = date('Y-m-d');
    $h     = '';
    $last  = '';
    foreach ($remarks as $r) {
        $ts  = strtotime($r['remark_at']);
        $day = date('Y-m-d', $ts);
        if ($day !== $last) {
            $h   .= '<div class="chat-day"><span>' . e(dayLabel($day)) . '</span></div>';
            $last = $day;
        }

        $type = (string)$r['remark_type'];
        $h .= '<div class="chat-row"><div class="chat-bubble">';
        $h .= '<div class="chat-type"><i class="bi bi-' . remarkIcon($type) . '"></i> ' . e($type) . '</div>';
        $h .= '<div class="chat-text">' . nl2br(e($r['remark'])) . '</div>';

        if (!empty($r['followup_date'])) {
            $fd   = $r['followup_date'];
            $done = (int)$r['followup_done'] === 1;
            if ($done) {
                $cls = 'fu-done';
                $lbl = 'Done';
            } elseif ($fd < $today) {
                $cls = 'fu-over';
                $lbl = 'Overdue';
            } elseif ($fd === $today) {
                $cls = 'fu-today';
                $lbl = 'Today';
            } else {
                $cls = 'fu-soon';
                $lbl = 'Upcoming';
            }
            $h .= '<div class="chat-fu ' . $cls . '"><i class="bi bi-alarm"></i> Follow-up: ' . e(fmtDate($fd)) . ' <b>' . $lbl . '</b>'
                . '<button type="button" class="chat-link" data-ract="done" data-rid="' . (int)$r['id'] . '" data-done="' . ($done ? 0 : 1) . '">'
                . ($done ? 'Undo' : 'Mark done') . '</button></div>';
        }

        $h .= '<div class="chat-foot"><span>' . e(date('h:i A', $ts)) . '</span>'
            . '<button type="button" class="chat-del" data-ract="del" data-rid="' . (int)$r['id'] . '" title="Delete remark" aria-label="Delete remark"> </button></div>';
        $h .= '</div></div>';
    }
    return $h;
}

function remarksPayload(mysqli $conn, int $cid): array
{
    $list = dbAll(
        $conn,
        'SELECT id, remark, remark_type, remark_at, followup_date, followup_done
           FROM customer_remarks WHERE customer_id = ? ORDER BY remark_at ASC, id ASC',
        'i',
        [$cid]
    );

    $next   = null;
    $lastAt = null;
    foreach ($list as $r) {
        $lastAt = $r['remark_at'];
        if (!empty($r['followup_date']) && (int)$r['followup_done'] === 0 && ($next === null || $r['followup_date'] < $next)) {
            $next = $r['followup_date'];
        }
    }

    $due = dbOne(
        $conn,
        'SELECT COUNT(DISTINCT customer_id) AS n FROM customer_remarks
          WHERE followup_done = 0 AND followup_date IS NOT NULL AND followup_date <= ?',
        's',
        [date('Y-m-d')]
    );

    return [
        'success' => true,
        'html'    => renderChat($list),
        'count'   => count($list),
        'fu_html' => followupCell($next, $lastAt),
        'due'     => (int)($due['n'] ?? 0),
        'now'     => date('Y-m-d H:i'),          // server time in IST
    ];
}

ensureRemTables($conn);

/* message settings (templates, schedule rules) */
$cfg       = DEFAULTS;
$loadError = '';
try {
    $cfg = loadSettings($conn);
} catch (Throwable $ex) {
    error_log('reminders.php settings: ' . $ex->getMessage());
    $loadError = 'Message settings could not be loaded. Please open Message Settings once and check the database tables.';
}

/*
|--------------------------------------------------------------------------
| AJAX API
|--------------------------------------------------------------------------
*/
if (isset($_GET['api'])) {
    try {
        $api = (string)$_GET['api'];

        /* ---- dashboard + notification data (read only) ---- */
        if ($api === 'summary') {
            $today = date('Y-m-d');
            $end   = date('Y-m-d', strtotime('+60 days'));
            $items = [];
            $todayBirthdays = [];

            foreach (['Birthday' => 'dob', 'Anniversary' => 'anniversary'] as $type => $col) {
                $occ  = buildOccurrences(fetchDateCustomers($conn, $col), $type, $today, $end);
                $sent = sentMap($conn, $type, $today, $end);
                foreach ($occ as $o) {
                    if ($type === 'Birthday' && $o['date'] === $today) {
                        $todayBirthdays[] = $o['name'];
                    }
                    if (isset($sent[$o['id'] . '|' . $o['date']])) {
                        continue;
                    }
                    $items[] = ['name' => $o['name'], 'type' => $type, 'date' => $o['date']];
                }
            }
            foreach (dbAll($conn, 'SELECT title, event_date FROM reminder_events WHERE event_date BETWEEN ? AND ?', 'ss', [$today, $end]) as $ev) {
                $items[] = ['name' => $ev['title'], 'type' => 'Event', 'date' => $ev['event_date']];
            }
            usort($items, function ($a, $b) {
                return strcmp($a['date'], $b['date']);
            });

            $top = [];
            foreach (array_slice($items, 0, 5) as $it) {
                $it['off'] = daysDiff($it['date']);
                $top[]     = $it;
            }
            jsonOut(['success' => true, 'scheduled' => count($items), 'items' => $top, 'today_birthdays' => $todayBirthdays]);
        }

        /* ---- remarks of one customer (read only) ---- */
        if ($api === 'remarks') {
            $id = (int)($_GET['id'] ?? 0);
            $c  = $id > 0 ? dbOne($conn, 'SELECT id, name, mobile FROM customers WHERE id = ?', 'i', [$id]) : null;
            if (!$c) {
                jsonOut(['success' => false, 'message' => 'Customer not found.'], 404);
            }
            jsonOut(array_merge(remarksPayload($conn, $id), [
                'name'     => $c['name'],
                'mobile'   => $c['mobile'],
                'initials' => initials((string)$c['name']),
            ]));
        }

        /* ---- sent / failed history ---- */
        if ($api === 'history') {
            $fs = cleanValue($_GET['status'] ?? '');
            $ft = cleanValue($_GET['type'] ?? '');
            $w  = [];
            $t  = '';
            $p  = [];
            if (in_array($fs, ['sent', 'failed'], true)) {
                $w[] = 'h.status = ?';
                $t  .= 's';
                $p[] = $fs;
            }
            if (in_array($ft, ['Birthday', 'Anniversary'], true)) {
                $w[] = 'h.rtype = ?';
                $t  .= 's';
                $p[] = $ft;
            }
            $sql = 'SELECT h.id, h.customer_id, h.rtype, h.occ_date, h.mobile, h.source, h.status, h.error, h.created_at, c.name
                      FROM message_history h LEFT JOIN customers c ON c.id = h.customer_id'
                . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                . ' ORDER BY h.id DESC LIMIT 100';
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

        /* ---- SEND WHATSAPP NOW through the API ---- */
        if ($api === 'send_whatsapp') {
            $type = cleanValue($_POST['type'] ?? '');
            if (!in_array($type, ['Birthday', 'Anniversary'], true)) {
                throw new UserError('Invalid message type.');
            }
            $cid = (int)($_POST['customer_id'] ?? 0);
            $occ = validDateOrNull(cleanValue($_POST['occ'] ?? ''), 'reminder date');
            if ($cid <= 0 || $occ === null) {
                throw new UserError('Invalid customer or reminder date.');
            }
            $force = (int)($_POST['force'] ?? 0) === 1;
            $res   = deliverReminder($conn, $cfg, $type, $cid, $occ, 'manual', $force);

            if (!$res['ok']) {
                $code   = (string)($res['code'] ?? '');
                $status = $code === 'duplicate' ? 409 : (in_array($code, ['nomobile', 'badmobile', 'nocustomer'], true) ? 422 : 502);
                jsonOut(['success' => false, 'code' => $code, 'message' => ($code === 'api' ? 'WhatsApp: ' : '') . $res['error']], $status);
            }
            jsonOut([
                'success'    => true,
                'message'    => 'WhatsApp message sent to ' . $res['name'] . '.',
                'message_id' => $res['id'],
                'row'        => rowPayload(itemFor($conn, $cfg, $type, $cid, $occ)),
            ]);
        }

        /* ---- schedule selected messages for a date & time ---- */
        if ($api === 'schedule_messages') {
            $mode  = cleanValue($_POST['mode'] ?? 'custom') === 'default' ? 'default' : 'custom';
            $items = json_decode((string)($_POST['items'] ?? ''), true);
            if (!is_array($items) || !$items || count($items) > 500) {
                throw new UserError('Nothing selected (maximum 500 at a time).');
            }
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

        /* ---- mark a reminder as sent / undo (no WhatsApp message is sent) ---- */
        if ($api === 'mark_sent') {
            $cid   = (int)($_POST['id'] ?? 0);
            $rtype = cleanValue($_POST['rtype'] ?? '');
            $occ   = validDateOrNull(cleanValue($_POST['occ'] ?? ''), 'reminder date');
            $done  = (int)($_POST['done'] ?? 1) === 1;

            if ($cid <= 0 || $occ === null || !in_array($rtype, ['Birthday', 'Anniversary'], true)) {
                throw new UserError('Invalid reminder.');
            }
            if (!dbOne($conn, 'SELECT id FROM customers WHERE id = ?', 'i', [$cid])) {
                throw new UserError('Customer not found.');
            }

            if ($done) {
                $st = mysqli_prepare($conn, 'INSERT IGNORE INTO reminder_log (customer_id, rtype, occ_date) VALUES (?, ?, ?)');
            } else {
                $st = mysqli_prepare($conn, 'DELETE FROM reminder_log WHERE customer_id = ? AND rtype = ? AND occ_date = ?');
            }
            mysqli_stmt_bind_param($st, 'iss', $cid, $rtype, $occ);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            jsonOut([
                'success' => true,
                'sent'    => $done ? 1 : 0,
                'message' => $done ? 'Marked as sent.' : 'Marked as not sent.',
                'row'     => rowPayload(itemFor($conn, $cfg, $rtype, $cid, $occ)),
            ]);
        }

        /* ---- add an event / edit an event ---- */
        if ($api === 'event_save') {
            $id      = (int)($_POST['id'] ?? 0);
            $title   = cleanValue($_POST['title'] ?? '');
            $dateRaw = cleanValue($_POST['event_date'] ?? '');
            $details = cleanValue($_POST['details'] ?? '');

            if ($title === '' || $dateRaw === '') {
                throw new UserError('Please enter the event name and date.');
            }
            if (mb_strlen($title) > 150) {
                throw new UserError('Event name is too long (max 150 characters).');
            }
            if (mb_strlen($details) > 500) {
                throw new UserError('Details are too long (max 500 characters).');
            }
            $date      = validDateOrNull($dateRaw, 'event date');
            $detailsDb = $details !== '' ? $details : null;

            if ($id > 0) {
                if (!dbOne($conn, 'SELECT id FROM reminder_events WHERE id = ?', 'i', [$id])) {
                    throw new UserError('Event not found (maybe already deleted).');
                }
                $st = mysqli_prepare($conn, 'UPDATE reminder_events SET title = ?, event_date = ?, details = ? WHERE id = ?');
                mysqli_stmt_bind_param($st, 'sssi', $title, $date, $detailsDb, $id);
            } else {
                $st = mysqli_prepare($conn, 'INSERT INTO reminder_events (title, event_date, details) VALUES (?, ?, ?)');
                mysqli_stmt_bind_param($st, 'sss', $title, $date, $detailsDb);
            }
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            jsonOut(['success' => true, 'message' => $id > 0 ? 'Event updated.' : 'Event added.']);
        }

        /* ---- delete an event ---- */
        if ($api === 'event_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new UserError('Invalid event.');
            }
            $st = mysqli_prepare($conn, 'DELETE FROM reminder_events WHERE id = ?');
            mysqli_stmt_bind_param($st, 'i', $id);
            mysqli_stmt_execute($st);
            $gone = mysqli_stmt_affected_rows($st);
            mysqli_stmt_close($st);
            if ($gone < 1) {
                throw new UserError('Event not found (maybe already deleted).');
            }
            jsonOut(['success' => true, 'message' => 'Event deleted.']);
        }

        /* ---- add a remark (chat message) ---- */
        if ($api === 'remark_add') {
            $cid   = (int)($_POST['id'] ?? 0);
            $text  = cleanValue($_POST['remark'] ?? '');
            $type  = cleanValue($_POST['rtype'] ?? 'Call');
            $atRaw = cleanValue($_POST['remark_at'] ?? '');
            $fuRaw = cleanValue($_POST['followup'] ?? '');

            if ($cid <= 0 || !dbOne($conn, 'SELECT id FROM customers WHERE id = ?', 'i', [$cid])) {
                throw new UserError('Customer not found.');
            }
            if ($text === '') {
                throw new UserError('Please write a remark before saving.');
            }
            if (mb_strlen($text) > 2000) {
                throw new UserError('Remark is too long (maximum 2000 characters).');
            }
            if (!in_array($type, REMARK_TYPES, true)) {
                throw new UserError('Please choose a valid type (Call / WhatsApp / Visit / Other).');
            }

            $now = new DateTime('now');                                   // IST
            if ($atRaw === '') {
                $at = clone $now;
            } else {
                $at = DateTime::createFromFormat('!Y-m-d\TH:i', $atRaw) ?: DateTime::createFromFormat('!Y-m-d H:i', $atRaw);
                if (!$at) {
                    throw new UserError('Invalid date or time.');
                }
            }
            if ($at->getTimestamp() > $now->getTimestamp() + 300) {
                throw new UserError('Remark date and time cannot be in the future.');
            }
            $atDb = $at->format('Y-m-d H:i:00');

            $fu = validDateOrNull($fuRaw, 'follow-up date');
            if ($fu !== null && $fu < date('Y-m-d')) {
                throw new UserError('Follow-up date cannot be in the past.');
            }

            $st = mysqli_prepare(
                $conn,
                'INSERT INTO customer_remarks (customer_id, remark, remark_type, remark_at, followup_date) VALUES (?, ?, ?, ?, ?)'
            );
            mysqli_stmt_bind_param($st, 'issss', $cid, $text, $type, $atDb, $fu);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            jsonOut(array_merge(remarksPayload($conn, $cid), ['message' => 'Remark saved.']));
        }

        /* ---- mark follow-up done / undo ---- */
        if ($api === 'remark_done') {
            $rid  = (int)($_POST['id'] ?? 0);
            $done = (int)($_POST['done'] ?? 1) === 1 ? 1 : 0;
            $row  = $rid > 0 ? dbOne($conn, 'SELECT customer_id FROM customer_remarks WHERE id = ?', 'i', [$rid]) : null;
            if (!$row) {
                throw new UserError('Remark not found (maybe already deleted).');
            }
            $st = mysqli_prepare($conn, 'UPDATE customer_remarks SET followup_done = ? WHERE id = ? AND followup_date IS NOT NULL');
            mysqli_stmt_bind_param($st, 'ii', $done, $rid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            jsonOut(array_merge(remarksPayload($conn, (int)$row['customer_id']), ['message' => $done ? 'Follow-up marked as done.' : 'Follow-up re-opened.']));
        }

        /* ---- delete one remark ---- */
        if ($api === 'remark_delete') {
            $rid = (int)($_POST['id'] ?? 0);
            $row = $rid > 0 ? dbOne($conn, 'SELECT customer_id FROM customer_remarks WHERE id = ?', 'i', [$rid]) : null;
            if (!$row) {
                throw new UserError('Remark not found (maybe already deleted).');
            }
            $st = mysqli_prepare($conn, 'DELETE FROM customer_remarks WHERE id = ?');
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            jsonOut(array_merge(remarksPayload($conn, (int)$row['customer_id']), ['message' => 'Remark deleted.']));
        }

        jsonOut(['success' => false, 'message' => 'Unknown action.'], 400);
    } catch (UserError $ex) {
        jsonOut(['success' => false, 'message' => $ex->getMessage()], 422);
    } catch (mysqli_sql_exception $ex) {
        error_log('reminders.php DB error [' . $ex->getCode() . ']: ' . $ex->getMessage());
        if ((int)$ex->getCode() === 1146) {
            jsonOut(['success' => false, 'message' => 'A required table is missing (customer_remarks / reminder_log / reminder_events / message_history / scheduled_messages). Please give CREATE permission to the database user or create the tables in phpMyAdmin.'], 500);
        }
        jsonOut(['success' => false, 'message' => 'Database error. Please try again.'], 500);
    } catch (Throwable $ex) {
        error_log('reminders.php error: ' . $ex->getMessage());
        jsonOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/
$view = cleanValue($_GET['view'] ?? 'birthday');
if (!in_array($view, ['birthday', 'anniversary', 'events', 'history'], true)) {
    $view = 'birthday';
}

$fq      = cleanValue($_GET['q'] ?? '');
$ffrom   = safeDate(cleanValue($_GET['from'] ?? ''));
$fto     = safeDate(cleanValue($_GET['to'] ?? ''));
$fstatus = cleanValue($_GET['status'] ?? '');
$fper    = cleanValue($_GET['per'] ?? '30');
if (!in_array($fper, PER_OPTIONS, true)) {
    $fper = '30';
}
$fper = 'all';          // paging is done by crm-table.js (30/60/100/All)

$titles = [
    'birthday'    => 'Birthday reminders',
    'anniversary' => 'Anniversary reminders',
    'events'      => 'Upcoming events',
    'history'     => 'History & scheduled',
];
$type = $view === 'anniversary' ? 'Anniversary' : 'Birthday';

$rangeNote = '';
$pageRows  = [];
$remStats  = [];
$total     = 0;
$pages     = 1;
$page      = 1;
$perN      = (int)($fper === 'all' ? 30 : $fper);

if ($view === 'events') {
    $statusOptions = ['Upcoming', 'Today', 'Past'];
} elseif ($view === 'history') {
    $statusOptions = [];
} else {
    $statusOptions = array_keys(LIST_STATUSES);
}
if (!in_array($fstatus, $statusOptions, true)) {
    $fstatus = '';
}
$hasFilt = ($fq !== '' || $ffrom !== '' || $fto !== '' || $fstatus !== '');

try {
    if ($view === 'events') {
        /* ------------------------------------------------ events (SQL side) */
        $w = [];
        $t = '';
        $p = [];
        if ($fq !== '') {
            $like = '%' . addcslashes($fq, '\\%_') . '%';
            $w[]  = '(title LIKE ? OR details LIKE ?)';
            $t   .= 'ss';
            array_push($p, $like, $like);
        }
        if ($ffrom !== '') {
            $w[] = 'event_date >= ?';
            $t  .= 's';
            $p[] = $ffrom;
        }
        if ($fto !== '') {
            $w[] = 'event_date <= ?';
            $t  .= 's';
            $p[] = $fto;
        }
        if ($fstatus === 'Upcoming') {
            $w[] = 'event_date > ?';
            $t  .= 's';
            $p[] = date('Y-m-d');
        } elseif ($fstatus === 'Today') {
            $w[] = 'event_date = ?';
            $t  .= 's';
            $p[] = date('Y-m-d');
        } elseif ($fstatus === 'Past') {
            $w[] = 'event_date < ?';
            $t  .= 's';
            $p[] = date('Y-m-d');
        }
        $whereSql = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $cnt   = dbOne($conn, "SELECT COUNT(*) AS n FROM reminder_events $whereSql", $t, $p);
        $total = (int)($cnt['n'] ?? 0);
        $perN  = $fper === 'all' ? max(1, $total) : (int)$fper;
        $pages = max(1, (int)ceil($total / $perN));
        $page  = min($pages, max(1, (int)($_GET['page'] ?? 1)));
        $off   = ($page - 1) * $perN;

        $today = date('Y-m-d');
        $pageRows = dbAll(
            $conn,
            "SELECT id, title, event_date, details FROM reminder_events $whereSql
              ORDER BY (event_date < ?) ASC,
                       CASE WHEN event_date >= ? THEN event_date END ASC,
                       CASE WHEN event_date <  ? THEN event_date END DESC,
                       id ASC
              LIMIT ? OFFSET ?",
            $t . 'sssii',
            array_merge($p, [$today, $today, $today, $perN, $off])
        );
    } elseif ($view !== 'history') {
        /* ----------------------------------- birthdays / anniversaries (PHP) */
        $from = $ffrom !== '' ? $ffrom : date('Y-m-d');
        $to   = $fto !== '' ? $fto : date('Y-m-d', strtotime($from . ' +1 year -1 day'));
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $maxTo = date('Y-m-d', strtotime($from . ' +' . MAX_RANGE_YEARS . ' years'));
        if ($to > $maxTo) {
            $to        = $maxTo;
            $rangeNote = 'Date range is limited to ' . MAX_RANGE_YEARS . ' years. Showing ' . fmtDate($from) . ' to ' . fmtDate($to) . '.';
        }

        $list = [];
        foreach (collectType($conn, $cfg, $type, $from, $to, $fq) as $it) {
            if ($fstatus !== '' && $it['status'] !== $fstatus) {
                continue;
            }
            $list[] = $it;
        }
        usort($list, function ($a, $b) {
            return [$a['occ'], $a['name']] <=> [$b['occ'], $b['name']];
        });

        $total = count($list);
        $perN  = $fper === 'all' ? max(1, $total) : (int)$fper;
        $pages = max(1, (int)ceil($total / $perN));
        $page  = min($pages, max(1, (int)($_GET['page'] ?? 1)));
        $pageRows = array_slice($list, ($page - 1) * $perN, $perN);

        /* remark count + follow-up info for the customers on this page */
        if ($pageRows) {
            $ids = implode(',', array_unique(array_map('intval', array_column($pageRows, 'id'))));
            $res = dbAll(
                $conn,
                "SELECT customer_id, COUNT(*) AS cnt, MAX(remark_at) AS last_at,
                        MIN(CASE WHEN followup_done = 0 AND followup_date IS NOT NULL THEN followup_date END) AS next_fu
                   FROM customer_remarks WHERE customer_id IN ($ids) GROUP BY customer_id"
            );
            foreach ($res as $r) {
                $remStats[(int)$r['customer_id']] = $r;
            }
        }
    }
} catch (Throwable $ex) {
    error_log('reminders.php list: ' . $ex->getMessage());
    $loadError = 'Reminders could not be loaded. Please check the database tables.';
    $perN = (int)($fper === 'all' ? 30 : $fper);
}

$from1 = $total ? (($page - 1) * $perN + 1) : 0;
$to1   = $total ? min($total, $page * $perN) : 0;
$base  = ['view' => $view];

/* cron / auto-send status (History & scheduled tab) */
$cronTs  = ($cfg['cron_last_run'] ?? '') !== '' ? strtotime((string)$cfg['cron_last_run']) : false;
$cronMin = $cronTs ? (int)floor((time() - $cronTs) / 60) : null;
$tokenOk = (waToken() !== '' && stripos(waToken(), 'PASTE') === false);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?= e($csrf) ?>" />
    <title>Reminders | <?= crm_e(crm_brand()['name']) ?> CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap"
        rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/style.css?v=1" rel="stylesheet" />
    
    <style>
        /* ===================== REMINDERS TABLE STYLE ===================== */

/* Table wrapper */
.panel .table-responsive {
    border: 1px solid #dce3eb;
    border-radius: 4px;
    overflow-x: auto;
    background: #fff;
}

/* Main table */
.panel .table-responsive > .table {
    width: 100%;
    margin-bottom: 0;
    border-collapse: separate;
    border-spacing: 0;
    color: #344054;
    font-size: 11px;
    vertical-align: middle;
}

/* Table header */
.panel .table-responsive > .table thead th {
    background: #f5f7fa;
    color: #344054;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.35px;
    padding: 13px 14px;
    border-bottom: 1px solid #dce3eb;
    border-right: 1px solid #e5eaf0;
    white-space: nowrap;
}

/* Remove last header border */
.panel .table-responsive > .table thead th:last-child {
    border-right: none;
}

/* Table cells */
.panel .table-responsive > .table tbody td {
    padding: 2px 5px;
    border-bottom: 1px solid #e5eaf0;
    border-right: 1px solid #edf0f4;
    background: #fff;
}

/* Remove last cell border */
.panel .table-responsive > .table tbody td:last-child {
    border-right: none;
}

/* Remove last row bottom border */
.panel .table-responsive > .table tbody tr:last-child td {
    border-bottom: none;
}

/* Alternate row background */
.panel .table-responsive > .table tbody tr:nth-child(even) td {
    background: #fafbfd;
}

/* Hover effect */
.panel .table-responsive > .table tbody tr:hover td {
    background: #f0f6ff;
    transition: background-color 0.2s ease;
}

/* Customer and event names */
.panel .table-responsive .fw-semibold {
    color: #263449;
    font-weight: 600 !important;
}

/* Customer avatar */
.panel .table-responsive .av {
    flex-shrink: 0;
    border: 1px solid #dce5f0;
}

/* Checkboxes */
.panel .table-responsive .form-check-input {
    border: 1px solid #aab7c7 !important;
    cursor: pointer;
    box-shadow: none;
}

.panel .table-responsive .form-check-input:checked {
    background-color: #2563eb;
    border-color: #2563eb !important;
}

/* Action buttons */
.panel .table-responsive .btn-icon {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #dce3eb;
    border-radius: 4px;
    background: #fff;
    color: #475467;
    padding: 0;
    margin-left: 3px;
    transition: all 0.2s ease;
}

.panel .table-responsive .btn-icon:hover {
    background: #edf4ff;
    border-color: #9dbcf0;
    color: #1d4ed8;
}

.panel .table-responsive .btn-icon.text-success {
    color: #198754 !important;
}

.panel .table-responsive .btn-icon.text-danger {
    color: #dc3545 !important;
}

.panel .table-responsive .btn-icon.text-success:hover {
    background: #e7f7ed;
    border-color: #a7dfba;
}

.panel .table-responsive .btn-icon.text-danger:hover {
    background: #fff0f0;
    border-color: #f1b0b7;
}

/* Status badges */
.panel .table-responsive .badge-s {
    display: inline-block;
    padding: 1px 5px;
    border-radius: 2px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

/* Follow-up and message text */
.panel .table-responsive small {
    font-size: 11px;
    line-height: 1.5;
}

/* Empty table message */
.panel .table-responsive .text-center.text-muted {
    padding: 24px 12px !important;
}

/* Scheduled and history table widths */
#schedBody,
#histBody {
    font-size: 12px;
}

/* Table responsive layout */
@media (max-width: 768px) {
    .panel .table-responsive {
        border-radius: 4px;
    }

    .panel .table-responsive > .table {
        min-width: 760px;
    }

    .panel .table-responsive > .table thead th {
        padding: 11px 10px;
        font-size: 11px;
    }

    .panel .table-responsive > .table tbody td {
        padding: 10px;
    }
}

@media (max-width: 575px) {
    .panel .table-responsive > .table {
        min-width: 700px;
    }

    .panel .table-responsive > .table tbody td {
        font-size: 12px;
    }
}
    </style>
    <style>
        .rbadge {
            position: absolute;
            top: -3px;
            right: -3px;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            border-radius: 4px;
            background: #198754;
            color: #fff;
            font-size: 10px;
            line-height: 16px;
            font-weight: 600;
            text-align: center;
            pointer-events: none
        }

        .range-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center
        }

        .range-chips .btn {
            border-radius: 4px;
            font-size: 12px;
            padding: 2px 10px
        }

        .q-msg {
            background: #efeae2;
            border-radius: 4px;
            padding: 12px;
            white-space: pre-wrap;
            word-break: break-word
        }

        .cronbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            font-size: 14px
        }

        /* ---------- WhatsApp style chat ---------- */
        .chat-body {
            background: #efeae2;
            height: 46vh;
            min-height: 240px;
            overflow-y: auto;
            padding: 14px 14px 8px;
            scroll-behavior: smooth
        }

        .chat-day {
            text-align: center;
            margin: 10px 0 8px
        }

        .chat-day span {
            display: inline-block;
            background: #fff;
            color: #54656f;
            font-size: 12px;
            padding: 3px 12px;
            border-radius: 4px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, .08)
        }

        .chat-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 7px
        }

        .chat-bubble {
            position: relative;
            max-width: 84%;
            min-width: 120px;
            background: #d9fdd3;
            color: #111b21;
            font-size: 14px;
            line-height: 1.4;
            padding: 6px 10px 3px;
            border-radius: 4px 4px 2px 4px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, .12);
            word-break: break-word
        }

        .chat-type {
            font-size: 11px;
            font-weight: 600;
            color: #1f7a5a;
            margin-bottom: 1px
        }

        .chat-text {
            white-space: normal
        }

        .chat-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            font-size: 11px;
            color: #667781;
            margin-top: 2px
        }

        .chat-del {
            border: 0;
            background: transparent;
            color: #8696a0;
            padding: 0 2px;
            font-size: 12px;
            line-height: 1;
            cursor: pointer
        }

        .chat-del:hover {
            color: #dc3545
        }

        .chat-del.sure {
            color: #fff;
            background: #dc3545;
            border-radius: 4px;
            padding: 1px 6px;
            font-size: 11px
        }

        .chat-fu {
            margin-top: 5px;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px
        }

        .chat-fu .chat-link {
            border: 0;
            background: transparent;
            color: #0b6bcb;
            font-weight: 600;
            margin-left: 6px;
            padding: 0;
            cursor: pointer;
            font-size: 12px
        }

        .fu-over {
            background: #fde8e8;
            color: #b42318
        }

        .fu-today {
            background: #fff4d6;
            color: #8a5a00
        }

        .fu-soon {
            background: #e7f0ff;
            color: #1d4ed8
        }

        .fu-done {
            background: #e3f6e8;
            color: #157347
        }

        .chat-empty {
            height: 100%;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #667781
        }

        .chat-empty i {
            font-size: 38px;
            margin-bottom: 8px
        }

        .chat-compose {
            padding: 12px 14px;
            border-top: 1px solid #dee2e6;
            background: #f0f2f5
        }

        .quick-chips {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 4px;
            margin-bottom: 8px
        }

        .quick-chips .btn {
            white-space: nowrap;
            border-radius: 4px;
            font-size: 12px;
            padding: 2px 10px
        }

        .chat-head .av {
            flex: 0 0 auto
        }

        @media (max-width:575px) {
            .chat-bubble {
                max-width: 92%
            }

            .chat-body {
                height: 42vh
            }
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
                    <h1 id="pageTitle"><?= e($titles[$view]) ?></h1>
                    <p class="text-muted mb-0">
                        <?php if ($view === 'events'): ?>
                            All your store events in one place. Add, edit or delete events.
                        <?php elseif ($view === 'history'): ?>
                            Scheduled (date &amp; time) messages waiting to go, and the history of every WhatsApp message sent or failed.
                        <?php else: ?>
                            Customers whose <?= $view === 'birthday' ? 'birthday' : 'anniversary' ?> is coming up. Send the message on WhatsApp (through API), schedule it, or add a follow-up remark.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($view === 'events'): ?>
                        <button type="button" class="btn btn-gold" id="addEventBtn"><i class="bi bi-calendar-plus me-1"></i>Add event</button>
                    <?php elseif ($view === 'history'): ?>
                        <button type="button" class="btn btn-light border" id="btnReloadAll" style="font-size:12px;"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                        <a href="message-settings.php" class="btn btn-outline-primary" style="font-size:12px;"><i class="bi bi-chat-text me-1"></i>Edit templates</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-success" id="bulkBtn" style="font-size:12px;" disabled><i class="bi bi-whatsapp me-1"></i>Send selected (<span id="bulkCount">0</span>)</button>
                        <button type="button" class="btn btn-primary" id="schedBtn" style="font-size:12px;" disabled><i class="bi bi-alarm me-1"></i>Schedule selected</button>
                        <a href="message-settings.php" class="btn btn-outline-primary" style="font-size:12px;"><i class="bi bi-chat-text me-1"></i>Edit templates</a>
                    <?php endif; ?>
                </div>
            </div>

            <ul class="nav nav-pills mb-3 gap-1 flex-wrap">
                <li class="nav-item"><a class="nav-link<?= $view === 'birthday' ? ' active' : '' ?>" href="reminders.php?view=birthday"><i class="bi bi-gift me-1"></i>Birthdays</a></li>
                <li class="nav-item"><a class="nav-link<?= $view === 'anniversary' ? ' active' : '' ?>" href="reminders.php?view=anniversary"><i class="bi bi-heart me-1"></i>Anniversaries</a></li>
                <li class="nav-item"><a class="nav-link<?= $view === 'events' ? ' active' : '' ?>" href="reminders.php?view=events"><i class="bi bi-calendar-event me-1"></i>Upcoming events</a></li>
                <li class="nav-item"><a class="nav-link<?= $view === 'history' ? ' active' : '' ?>" href="reminders.php?view=history"><i class="bi bi-clock-history me-1"></i>History &amp; scheduled</a></li>
            </ul>

            <?php if ($loadError !== ''): ?>
                <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($loadError) ?></div>
            <?php endif; ?>
            <?php if (!$tokenOk && $view !== 'events'): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-key-fill me-2"></i>WhatsApp API token is not set. Add <code>define('WA_API_TOKEN', 'your-token');</code> in <b>config.php</b>, otherwise no message can be sent.</div>
            <?php endif; ?>
            <?php if ($rangeNote !== ''): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-info-circle me-2"></i><?= e($rangeNote) ?></div>
            <?php endif; ?>

            <?php if ($view === 'history'): ?>
                <!-- ============================ HISTORY & SCHEDULED ============================ -->
                <div class="panel mb-3">
                    <div class="cronbar">
                        <b><i class="bi bi-lightning-charge me-1 text-gold"></i>Auto-send:</b>
                        <?php if (($cfg['auto_send'] ?? '0') === '1'): ?>
                            <span class="badge-s b-green">ON &middot; <?= e(timeLabel((string)$cfg['send_time'])) ?></span>
                        <?php else: ?>
                            <span class="badge-s b-gray">OFF</span>
                        <?php endif; ?>
                        <b class="ms-2">Cron:</b>
                        <?php if ($cronMin === null): ?>
                            <span class="badge-s b-red">NOT running</span>
                            <small class="text-muted">It has never contacted the system. Scheduled messages will not go until the cron job runs.</small>
                        <?php elseif ($cronMin > 15): ?>
                            <span class="badge-s b-amber">Stopped?</span>
                            <small class="text-muted">Last run <?= e(fmtDateTime($cfg['cron_last_run'])) ?> (<?= (int)$cronMin ?> min ago). It should run every 5 minutes.</small>
                        <?php else: ?>
                            <span class="badge-s b-green">Working</span>
                            <small class="text-muted">Last run <?= e(fmtDateTime($cfg['cron_last_run'])) ?> (<?= (int)$cronMin ?> min ago)</small>
                        <?php endif; ?>
                        <a href="message-settings.php" class="ms-auto small">Auto-send settings &rarr;</a>
                    </div>
                </div>

                <div class="panel mb-3">
                    <div class="panel-title d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-alarm me-1"></i>Scheduled queue</h5>
                        <button type="button" class="btn btn-light border btn-sm" id="btnReloadSched"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" data-crm-table>
                            <thead>
                                <tr style="font-size: 12px !important;">
                                    <th>Customer</th>
                                    <th>Type</th>
                                    <th>Event date</th>
                                    <th>Will be sent at</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="schedBody">
                                <tr style="font-size: 12px !important;">
                                    <td colspan="6" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-title d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-1"></i>Message history <small class="text-muted">(last 100)</small></h5>
                        <div class="d-flex gap-2">
                            <select class="form-select form-select-sm" id="hType" style="width:auto" aria-label="Type">
                                <option value="">All types</option>
                                <option value="Birthday">Birthday</option>
                                <option value="Anniversary">Anniversary</option>
                            </select>
                            <select class="form-select form-select-sm" id="hStatus" style="width:auto" aria-label="Result">
                                <option value="">All results</option>
                                <option value="sent">Sent</option>
                                <option value="failed">Failed</option>
                            </select>
                        </div>
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
                            <tbody id="histBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <div class="panel">
                    <form method="get" action="reminders.php" class="row g-2 toolbar" id="filterForm">
                        <input type="hidden" name="view" value="<?= e($view) ?>">
                        
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="fFrom">From date</label>
                            <input type="date" class="form-control" id="fFrom" name="from" value="<?= e($ffrom) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="fTo">To date</label>
                            <input type="date" class="form-control" id="fTo" name="to" value="<?= e($fto) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="fStatus">Status</label>
                            <select class="form-select" id="fStatus" name="status" onchange="this.form.submit()">
                                <option value="">All</option>
                                <?php foreach ($statusOptions as $so): ?>
                                    <option value="<?= e($so) ?>" <?= $fstatus === $so ? 'selected' : '' ?>><?= e($view === 'events' ? $so : (LIST_STATUSES[$so] ?? $so)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Apply</button>
                            <?php if ($hasFilt): ?><a href="reminders.php?view=<?= e($view) ?>" class="btn btn-light border" title="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                        </div>

                        <div class="col-12">
                            <div class="range-chips">
                                <small class="text-muted me-1">Quick dates:</small>
                                <button type="button" class="btn btn-outline-secondary" data-range="today">Today</button>
                                <button type="button" class="btn btn-outline-secondary" data-range="tomorrow">Tomorrow</button>
                                <button type="button" class="btn btn-outline-secondary" data-range="7">Next 7 days</button>
                                <button type="button" class="btn btn-outline-secondary" data-range="30">Next 30 days</button>
                                <button type="button" class="btn btn-outline-secondary" data-range="month">This month</button>
                                <button type="button" class="btn btn-link btn-sm text-muted py-0" data-range="clear">Clear dates</button>
                                
                            </div>
                            <?php if ($view !== 'events' && $ffrom === '' && $fto === ''): ?>
                                <small class="text-muted d-block mt-1">Dates not selected: showing every customer's next <?= $view === 'birthday' ? 'birthday' : 'anniversary' ?> from today.</small>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ($view === 'events'): ?>
                        <!-- ============================ EVENTS TABLE ============================ -->
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" data-crm-table>
                                <thead>
                                    <tr>
                                        <th>Event</th>
                                        <th>Date</th>
                                        <th>Details</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pageRows as $ev):
                                        $eid = (int)$ev['id'];
                                        $est = eventStatus($ev['event_date']);
                                    ?>
                                        <tr data-event="<?= $eid ?>" data-title="<?= e($ev['title']) ?>" data-date="<?= e($ev['event_date']) ?>" data-details="<?= e($ev['details']) ?>">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="av ic"><i class="bi bi-calendar-event"></i></span>
                                                    <span class="fw-semibold"><?= e($ev['title']) ?></span>
                                                </div>
                                            </td>
                                            <td><?= e(fmtDate($ev['event_date'])) ?><br><small class="text-muted"><?= e(whenText(daysDiff($ev['event_date']))) ?></small></td>
                                            <td class="text-break" style="max-width:380px"><?= $ev['details'] !== null && $ev['details'] !== '' ? nl2br(e($ev['details'])) : '<span class="text-muted">-</span>' ?></td>
                                            <td><?= statusBadge($est) ?></td>
                                            <td class="text-end text-nowrap">
                                                <button class="btn btn-sm btn-icon" data-action="event-edit" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></button>
                                                <button class="btn btn-sm btn-icon text-danger" data-action="event-delete" title="Delete" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!$pageRows): ?>
                            <div class="empty text-center">
                                <i class="bi bi-calendar-x fs-1"></i>
                                <p class="mb-0"><?= $hasFilt ? 'No event matches your search or filters. Clear them to see all events.' : 'No events yet. Click "Add event" to create the first one.' ?></p>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- ===================== BIRTHDAY / ANNIVERSARY TABLE ===================== -->
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" data-crm-table>
                                <thead>
                                    <tr>
                                        <th style="width:36px"><input type="checkbox" class="form-check-input" id="chkAll" aria-label="Select all" style="border:1px solid #5d8ab6 !important;"></th>
                                        <th>Customer</th>
                                        <th>City</th>
                                        <th><?= $view === 'birthday' ? 'Birthday' : 'Anniversary' ?></th>
                                        <th>Last message sent</th>
                                        <!--<th>Message</th>-->
                                        <th>Follow-up</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pageRows as $r):
                                        $cid    = (int)$r['id'];
                                        $rs     = $remStats[$cid] ?? null;
                                        $rc     = (int)($rs['cnt'] ?? 0);
                                        $hasMob = trim((string)$r['mobile']) !== '';
                                    ?>
                                        <tr data-cid="<?= $cid ?>" data-key="<?= e($cid . '|' . $r['occ']) ?>" data-date="<?= e($r['occ']) ?>" data-datelabel="<?= e($r['date_label']) ?>"
                                            data-name="<?= e($r['name']) ?>" data-mobile="<?= e($r['mobile']) ?>" data-msg="<?= e($r['msg']) ?>"
                                            data-rtype="<?= e($type) ?>" data-sent="<?= $r['sent'] ? 1 : 0 ?>" data-last="<?= e($r['last_sent']) ?>">
                                            <td><input type="checkbox" class="form-check-input rowchk" aria-label="Select <?= e($r['name']) ?>" style="border:1px solid #5d8ab6 !important;"></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="av"><?= e(initials($r['name'])) ?></span>
                                                    <div>
                                                        <div class="fw-semibold"><?= e($r['name']) ?></div>
                                                        <small class="text-muted"><?= $hasMob ? e($r['mobile']) : 'No mobile' ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= e($r['city']) ?></td>
                                            <td>
                                                <i class="bi bi-<?= $view === 'birthday' ? 'gift' : 'heart' ?> text-gold me-1"></i><?= e($r['date_label']) ?>
                                                <br><small class="text-muted"><?= e($r['when']) ?><?= $r['age'] !== '' ? ' &middot; ' . e($r['age']) : '' ?></small>
                                                <br><small class="text-muted">Send on: <?= e($r['send_label']) ?></small>
                                            </td>
                                            <td data-last-cell><?= lastCell($r) ?></td>
                                            <!--<td><span class="d-inline-block text-truncate" style="max-width:220px" title="<?= e($r['msg']) ?>"><?= e($r['msg']) ?></span></td>-->
                                            <td data-fu><?= followupCell($rs['next_fu'] ?? null, $rs['last_at'] ?? null) ?></td>
                                            <td data-status><?= statusCell($r) ?></td>
                                            <td class="text-end text-nowrap">
                                                <button class="btn btn-sm btn-icon position-relative" data-action="remarks" data-id="<?= $cid ?>" data-name="<?= e($r['name']) ?>" data-mobile="<?= e($r['mobile']) ?>" title="Remarks &amp; follow-up" aria-label="Remarks">
                                                    <i class="bi bi-chat-dots"></i><span class="rbadge<?= $rc ? '' : ' d-none' ?>" data-rcount><?= $rc ?></span>
                                                </button>
                                                <?php if ($hasMob): ?>
                                                    <button class="btn btn-sm btn-icon text-success" data-action="send" title="Send now through WhatsApp" aria-label="Send WhatsApp message"><i class="bi bi-whatsapp"></i></button>
                                                <?php else: ?>
                                                    <span class="btn btn-sm btn-icon disabled text-muted" title="No mobile number"><i class="bi bi-whatsapp"></i></span>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-icon<?= $r['sent'] ? ' text-success' : '' ?>" data-action="sent" title="Mark as sent / undo (no message is sent)" aria-label="Mark as sent or undo"><i class="bi bi-check2-circle"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!$pageRows): ?>
                            <div class="empty text-center">
                                <i class="bi bi-inbox fs-1"></i>
                                <p class="mb-0"><?= $hasFilt
                                                    ? 'Nothing matches your dates or filters. Clear them to see all records.'
                                                    : 'No customer has a ' . ($view === 'birthday' ? 'birthday' : 'anniversary') . ' saved yet.' ?></p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($pages > 1):
                        $startP = max(1, $page - 2);
                        $endP   = min($pages, $page + 2);
                    ?>
                        <nav class="d-flex justify-content-center mt-3" aria-label="Pagination">
                            <ul class="pagination mb-0">
                                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="reminders.php<?= e(qs($base + ['page' => $page - 1])) ?>">Previous</a></li>
                                <?php if ($startP > 1): ?>
                                    <li class="page-item"><a class="page-link" href="reminders.php<?= e(qs($base + ['page' => 1])) ?>">1</a></li>
                                    <?php if ($startP > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                <?php endif; ?>
                                <?php for ($i = $startP; $i <= $endP; $i++): ?>
                                    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="reminders.php<?= e(qs($base + ['page' => $i])) ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                                <?php if ($endP < $pages): ?>
                                    <?php if ($endP < $pages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="reminders.php<?= e(qs($base + ['page' => $pages])) ?>"><?= $pages ?></a></li>
                                <?php endif; ?>
                                <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="reminders.php<?= e(qs($base + ['page' => $page + 1])) ?>">Next</a></li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($view === 'events'): ?>
        <!-- Event add / edit modal -->
        <div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="eventTitleBar">Add event</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="eventForm" novalidate>
                        <div class="modal-body">
                            <input type="hidden" name="id" id="evId" value="0">
                            <div class="mb-3">
                                <label class="form-label" for="evTitle">Event name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="evTitle" name="title" maxlength="150" required placeholder="e.g. Wedding Season Sale">
                                <div class="invalid-feedback">Please enter the event name.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="evDate">Event date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="evDate" name="event_date" required>
                                <div class="invalid-feedback">Please choose the event date.</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="evDetails">Details</label>
                                <textarea class="form-control" id="evDetails" name="details" rows="3" maxlength="500" placeholder="Short note about the event (optional)"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-gold" id="evSave">Save event</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Event delete modal -->
        <div class="modal fade" id="eventDelModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <div class="ic-warn"><i class="bi bi-exclamation-triangle"></i></div>
                        <h5 class="mt-3">Delete event?</h5>
                        <p class="text-muted mb-4" id="eventDelText"></p>
                        <button type="button" class="btn btn-light me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="eventDelOk">Yes, delete</button>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($view !== 'history'): ?>
        <!-- WhatsApp API send queue modal -->
        <div class="modal fade" id="queueModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-whatsapp text-success me-2"></i>Send <?= $view === 'birthday' ? 'birthday' : 'anniversary' ?> message</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="qBody"></div>
                    <div class="modal-footer" id="qFoot"></div>
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

        <!-- Remarks (WhatsApp style chat) modal -->
        <div class="modal fade" id="remarkModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" style="overflow:hidden">
                    <div class="modal-header chat-head py-2 align-items-center">
                        <span class="av" id="rmAv">?</span>
                        <div class="ms-2 me-auto min-w-0">
                            <div class="fw-semibold" id="rmName">Customer</div>
                            <small class="text-muted" id="rmMobile"></small>
                        </div>
                        <a id="rmCall" href="#" class="btn btn-sm btn-outline-primary me-1" title="Call"><i class="bi bi-telephone"></i></a>
                        <a id="rmWa" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success me-2" title="Open WhatsApp chat"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="chat-body" id="rmChat"></div>

                    <form id="remarkForm" class="chat-compose" novalidate>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <small class="text-muted me-1">Talked via:</small>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Type">
                                <?php foreach (REMARK_TYPES as $i => $rt): ?>
                                    <input type="radio" class="btn-check" name="rtype" id="rt<?= $i ?>" value="<?= e($rt) ?>" autocomplete="off" <?= $i === 0 ? ' checked' : '' ?>>
                                    <label class="btn btn-outline-secondary" for="rt<?= $i ?>" style="font-size:12px;"><i class="bi bi-<?= remarkIcon($rt) ?> me-1"></i><?= e($rt) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="quick-chips" aria-label="Quick replies">
                            <?php foreach (QUICK_REPLIES as $qr): ?>
                                <button type="button" class="btn btn-outline-secondary" data-quick="<?= e($qr) ?>"><?= e($qr) ?></button>
                            <?php endforeach; ?>
                        </div>

                        <textarea id="rmText" class="form-control mb-2" rows="2" maxlength="2000" placeholder="Write what you talked about... (Ctrl + Enter to save)"></textarea>

                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-sm-5">
                                <label class="form-label small mb-1" for="rmAt"><i class="bi bi-clock me-1"></i>Conversation date &amp; time (IST)</label>
                                <input type="datetime-local" id="rmAt" class="form-control form-control-sm" style="padding: 5px 7px !important;">
                            </div>
                            <div class="col-12 col-sm-4">
                                <label class="form-label small mb-1" for="rmFu"><i class="bi bi-alarm me-1"></i>Remind me to follow up on</label>
                                <input type="date" id="rmFu" class="form-control form-control-sm" style="padding: 5px 7px !important;">
                            </div>
                            <div class="col-12 col-sm-3 d-grid">
                                <button type="submit" class="btn btn-gold btn-sm" id="rmSend"><i class="bi bi-send me-1"></i>Save remark</button>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <button type="button" class="btn btn-sm btn-light border py-0" data-fuadd="1">Tomorrow</button>
                            <button type="button" class="btn btn-sm btn-light border py-0" data-fuadd="3">+3 days</button>
                            <button type="button" class="btn btn-sm btn-light border py-0" data-fuadd="7">+1 week</button>
                            <button type="button" class="btn btn-sm btn-light border py-0" data-fuadd="30">+1 month</button>
                            <button type="button" class="btn btn-sm btn-link py-0 text-muted" data-fuadd="clear">No reminder</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="toast-container position-fixed top-0 end-0 p-3" id="pageToasts" style="z-index:2000"></div>
    <div id="printArea"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
        (function() {
            'use strict';
            const CSRF = <?= json_encode($csrf) ?>;
            const TODAY = <?= json_encode(date('Y-m-d')) ?>;
            const VIEW = <?= json_encode($view) ?>;
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

            /* ---- small toast ---- */
            function toast(msg, type) {
                type = type || 'success';
                const el = document.createElement('div');
                el.className = 'toast crm-toast ' + type;
                el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button class="btn-close btn-close-white m-auto me-2" data-bs-dismiss="toast" aria-label="Close"></button></div>';
                el.querySelector('.toast-body').textContent = msg;
                $('#pageToasts').append(el);
                new bootstrap.Toast(el, {
                    delay: 3500
                }).show();
                el.addEventListener('hidden.bs.toast', () => el.remove());
            }

            const flash = sessionStorage.getItem('rem_flash');
            if (flash) {
                sessionStorage.removeItem('rem_flash');
                toast(flash);
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
                    body.set('csrf_token', CSRF);
                    opt.method = 'POST';
                    opt.body = body;
                }
                try {
                    const res = await fetch('reminders.php?' + new URLSearchParams(query), opt);
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

            const spinner = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</div>';

            /* ---- quick date buttons ---- */
            const pad = n => String(n).padStart(2, '0');
            const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            const localDT = d => ymd(d) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            const baseDate = () => {
                const p = TODAY.split('-');
                return new Date(+p[0], +p[1] - 1, +p[2]);
            };
            const plusDays = n => {
                const d = baseDate();
                d.setDate(d.getDate() + n);
                return d;
            };
            const filterForm = $('#filterForm');
            if (filterForm) filterForm.addEventListener('click', e => {
                const b = e.target.closest('[data-range]');
                if (!b) return;
                const k = b.dataset.range;
                let f = '',
                    t = '';
                if (k === 'today') {
                    f = t = ymd(baseDate());
                } else if (k === 'tomorrow') {
                    f = t = ymd(plusDays(1));
                } else if (k === '7') {
                    f = ymd(baseDate());
                    t = ymd(plusDays(6));
                } else if (k === '30') {
                    f = ymd(baseDate());
                    t = ymd(plusDays(29));
                } else if (k === 'month') {
                    const d = baseDate();
                    f = ymd(new Date(d.getFullYear(), d.getMonth(), 1));
                    t = ymd(new Date(d.getFullYear(), d.getMonth() + 1, 0));
                }
                $('#fFrom').value = f;
                $('#fTo').value = t;
                filterForm.submit();
            });

            /* ============================== EVENTS ============================== */
            if (VIEW === 'events') {
                const evModal = new bootstrap.Modal('#eventModal');
                const evDelModal = new bootstrap.Modal('#eventDelModal');
                let delId = 0;

                function openEvent(tr) {
                    const f = $('#eventForm');
                    f.classList.remove('was-validated');
                    $('#evId').value = tr ? tr.dataset.event : 0;
                    $('#evTitle').value = tr ? tr.dataset.title : '';
                    $('#evDate').value = tr ? tr.dataset.date : TODAY;
                    $('#evDetails').value = tr ? tr.dataset.details : '';
                    $('#eventTitleBar').textContent = tr ? 'Edit event' : 'Add event';
                    evModal.show();
                    setTimeout(() => $('#evTitle').focus(), 300);
                }

                $('#addEventBtn').addEventListener('click', () => openEvent(null));

                $('#eventForm').addEventListener('submit', async e => {
                    e.preventDefault();
                    const f = e.target;
                    f.classList.add('was-validated');
                    if (!f.checkValidity()) {
                        toast('Please fill the highlighted fields.', 'error');
                        const x = f.querySelector(':invalid');
                        if (x) x.focus();
                        return;
                    }
                    const btn = $('#evSave');
                    btn.disabled = true;
                    const j = await call({
                        api: 'event_save'
                    }, new FormData(f));
                    btn.disabled = false;
                    if (j.success) {
                        sessionStorage.setItem('rem_flash', j.message || 'Event saved.');
                        location.reload();
                    } else {
                        toast(j.message || 'Could not save the event.', 'error');
                    }
                });

                document.addEventListener('click', e => {
                    const b = e.target.closest('[data-action]');
                    if (!b) return;
                    const tr = b.closest('tr[data-event]');
                    if (!tr) return;
                    if (b.dataset.action === 'event-edit') {
                        openEvent(tr);
                    } else if (b.dataset.action === 'event-delete') {
                        delId = tr.dataset.event;
                        $('#eventDelText').textContent = 'Delete "' + tr.dataset.title + '"? This cannot be undone.';
                        evDelModal.show();
                    }
                });

                $('#eventDelOk').addEventListener('click', async () => {
                    const btn = $('#eventDelOk');
                    btn.disabled = true;
                    const fd = new FormData();
                    fd.set('id', delId);
                    const j = await call({
                        api: 'event_delete'
                    }, fd);
                    btn.disabled = false;
                    if (j.success) {
                        sessionStorage.setItem('rem_flash', j.message || 'Event deleted.');
                        location.reload();
                    } else {
                        evDelModal.hide();
                        toast(j.message || 'Could not delete the event.', 'error');
                    }
                });
                return;
            }

            /* ========================= HISTORY & SCHEDULED ========================= */
            if (VIEW === 'history') {
                const srcLabel = {
                    manual: 'Manual',
                    auto: 'Auto-send',
                    scheduled: 'Scheduled',
                    test: 'Test'
                };

                async function loadHistory() {
                    const j = await call({
                        api: 'history',
                        status: $('#hStatus').value,
                        type: $('#hType').value
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

                const reloadAll = () => {
                    loadSched();
                    loadHistory();
                };
                $('#hStatus').addEventListener('change', loadHistory);
                $('#hType').addEventListener('change', loadHistory);
                $('#btnReloadSched').addEventListener('click', reloadAll);
                $('#btnReloadAll').addEventListener('click', reloadAll);

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
                });

                reloadAll();
                return;
            }

            /* ===================== BIRTHDAY / ANNIVERSARY ===================== */
            const qModal = new bootstrap.Modal('#queueModal');
            const schedModal = new bootstrap.Modal('#schedModal');
            const rmModal = new bootstrap.Modal('#remarkModal');

            const waNumber = m => {
                const d = String(m || '').replace(/\D+/g, '');
                if (d.length === 10) return '91' + d;
                if (d.length === 11 && d[0] === '0') return '91' + d.slice(1);
                return d.length >= 12 ? d : '';
            };

            /* ---- row selection (bulk send / schedule) ---- */
            const chkAll = $('#chkAll');

            function selectedRows() {
                return $$('.rowchk:checked').map(c => c.closest('tr'));
            }

            function refreshBulk() {
                const n = selectedRows().length;
                $('#bulkCount').textContent = n;
                $('#bulkBtn').disabled = n === 0;
                $('#schedBtn').disabled = n === 0;
                const all = $$('.rowchk');
                if (chkAll) {
                    chkAll.checked = all.length > 0 && n === all.length;
                    chkAll.indeterminate = n > 0 && n < all.length;
                }
            }
            if (chkAll) chkAll.addEventListener('change', () => {
                $$('.rowchk').forEach(c => c.checked = chkAll.checked);
                refreshBulk();
            });
            document.addEventListener('change', e => {
                if (e.target.classList.contains('rowchk')) refreshBulk();
            });

            /* ---- row <-> item ---- */
            const trByKey = key => $('tr[data-key="' + key + '"]');
            const itemOf = tr => ({
                id: parseInt(tr.dataset.cid, 10),
                key: tr.dataset.key,
                name: tr.dataset.name,
                mobile: tr.dataset.mobile,
                type: tr.dataset.rtype,
                occ: tr.dataset.date,
                date_label: tr.dataset.datelabel,
                msg: tr.dataset.msg,
                sent: tr.dataset.sent === '1',
                last_sent: tr.dataset.last || ''
            });

            /* update status / last message cells of a row from the server answer */
            function applyRow(tr, r) {
                if (!tr || !r || r.sent === undefined) return;
                tr.dataset.sent = String(r.sent);
                tr.dataset.last = r.last_text || '';
                const s = tr.querySelector('[data-status]');
                if (s) s.innerHTML = r.status_html;
                const l = tr.querySelector('[data-last-cell]');
                if (l) l.innerHTML = r.last_html;
                const b = tr.querySelector('[data-action="sent"]');
                if (b) b.classList.toggle('text-success', Number(r.sent) === 1);
            }

            /* send one message through the WhatsApp API */
            async function sendOne(it, force) {
                const fd = new FormData();
                fd.set('customer_id', it.id);
                fd.set('type', it.type);
                fd.set('occ', it.occ);
                if (force) fd.set('force', 1);
                const j = await call({
                    api: 'send_whatsapp'
                }, fd);
                if (j.success) applyRow(trByKey(it.key), j.row);
                return j;
            }

            async function markSent(tr, done) {
                const fd = new FormData();
                fd.set('id', tr.dataset.cid);
                fd.set('rtype', tr.dataset.rtype);
                fd.set('occ', tr.dataset.date);
                fd.set('done', done ? 1 : 0);
                const j = await call({
                    api: 'mark_sent'
                }, fd);
                if (j.success) applyRow(tr, j.row);
                return j;
            }

            /* ---- row buttons ---- */
            document.addEventListener('click', async e => {
                const b = e.target.closest('[data-action]');
                if (!b) return;
                const act = b.dataset.action;
                const tr = b.closest('tr');

                if (act === 'send' && tr) {
                    const it = itemOf(tr);
                    if (!waNumber(it.mobile)) {
                        toast('This customer has no valid mobile number.', 'error');
                        return;
                    }
                    let force = false;
                    if (it.sent) {
                        if (!confirm(it.name + ' already received this message.\nSend it again?')) return;
                        force = true;
                    } else if (!confirm('Send ' + it.type + ' message to ' + it.name + ' now?')) {
                        return;
                    }
                    b.disabled = true;
                    const j = await sendOne(it, force);
                    b.disabled = false;
                    if (j.success) toast(j.message || 'WhatsApp message sent.');
                    else {
                        toast(j.message || 'WhatsApp API send failed.', 'error');
                        if (j.code === 'duplicate') setTimeout(() => location.reload(), 1200);
                    }
                } else if (act === 'sent' && tr) {
                    const j = await markSent(tr, tr.dataset.sent !== '1');
                    if (j.success) toast(j.message);
                    else toast(j.message || 'Could not update.', 'error');
                } else if (act === 'remarks') {
                    openRemarks(parseInt(b.dataset.id, 10), b.dataset.name, b.dataset.mobile);
                }
            });

            /* ============================ SEND QUEUE (bulk) ============================ */
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

            $('#bulkBtn').addEventListener('click', () => {
                const rows = selectedRows();
                if (rows.length) startQueue(rows.map(itemOf));
            });

            async function sendCurrent() {
                const it = Q.list[Q.i];
                const j = await sendOne(it, false);
                if (j.success) {
                    const c = trByKey(it.key);
                    if (c) {
                        const k = c.querySelector('.rowchk');
                        if (k) k.checked = false;
                    }
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
                refreshBulk();
            });

            /* ============================ SCHEDULE (date & time) ============================ */
            let schedItems = [];

            $('#schedBtn').addEventListener('click', () => {
                schedItems = selectedRows().map(itemOf).filter(it => !it.sent);
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
                    sessionStorage.setItem('rem_flash', j.message || 'Scheduled.');
                    location.reload();
                } else toast(j.message || 'Could not schedule.', 'error');
            });

            /* ======================= REMARKS (WhatsApp style chat) ======================= */
            let rmId = 0,
                rmNow = '';

            const chatBox = () => $('#rmChat');
            const scrollChat = () => {
                const c = chatBox();
                c.scrollTop = c.scrollHeight;
            };

            function setNow(now) {
                rmNow = now || rmNow;
                $('#rmAt').value = rmNow.replace(' ', 'T').slice(0, 16);
                $('#rmFu').min = rmNow.slice(0, 10);
            }

            function addDays(n) {
                const p = rmNow.slice(0, 10).split('-');
                const d = new Date(+p[0], +p[1] - 1, +p[2] + n);
                return ymd(d);
            }

            /* update chat + every table row of this customer */
            function applyRemarks(j) {
                chatBox().innerHTML = j.html;
                scrollChat();
                $$('tr[data-cid="' + rmId + '"]').forEach(tr => {
                    const bd = tr.querySelector('[data-rcount]');
                    if (bd) {
                        bd.textContent = j.count;
                        bd.classList.toggle('d-none', !j.count);
                    }
                    const fu = tr.querySelector('[data-fu]');
                    if (fu) fu.innerHTML = j.fu_html;
                });
            }

            async function openRemarks(id, name, mobile) {
                rmId = id;
                $('#rmName').textContent = name || 'Customer';
                $('#rmMobile').textContent = mobile ? '+91 ' + mobile : '';
                $('#rmAv').textContent = ((name || '?').trim().split(/\s+/).slice(0, 2).map(w => w.charAt(0)).join('') || '?').toUpperCase();
                $('#rmWa').href = 'https://wa.me/91' + encodeURIComponent(mobile || '');
                $('#rmCall').href = 'tel:+91' + encodeURIComponent(mobile || '');
                $('#rmText').value = '';
                $('#rmFu').value = '';
                $('#rt0').checked = true;
                chatBox().innerHTML = spinner;
                rmModal.show();
                const j = await call({
                    api: 'remarks',
                    id: id
                });
                if (!j.success) {
                    rmModal.hide();
                    toast(j.message || 'Could not load remarks.', 'error');
                    return;
                }
                $('#rmName').textContent = j.name;
                $('#rmMobile').textContent = '+91 ' + j.mobile;
                $('#rmAv').textContent = j.initials;
                setNow(j.now);
                applyRemarks(j);
                setTimeout(() => $('#rmText').focus(), 350);
            }

            $('#remarkForm').addEventListener('submit', async e => {
                e.preventDefault();
                const text = $('#rmText').value.trim();
                if (!text) {
                    toast('Please write a remark before saving.', 'error');
                    $('#rmText').focus();
                    return;
                }
                const fd = new FormData();
                fd.set('id', rmId);
                fd.set('remark', text);
                fd.set('rtype', (document.querySelector('input[name="rtype"]:checked') || {}).value || 'Call');
                fd.set('remark_at', $('#rmAt').value);
                fd.set('followup', $('#rmFu').value);
                const btn = $('#rmSend'),
                    old = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
                const j = await call({
                    api: 'remark_add'
                }, fd);
                btn.disabled = false;
                btn.innerHTML = old;
                if (j.success) {
                    applyRemarks(j);
                    $('#rmText').value = '';
                    $('#rmFu').value = '';
                    setNow(j.now);
                    toast(j.message || 'Remark saved.');
                    $('#rmText').focus();
                } else {
                    toast(j.message || 'Could not save the remark.', 'error');
                }
            });

            $('#rmText').addEventListener('keydown', e => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    $('#remarkForm').requestSubmit();
                }
            });

            $('#remarkForm').addEventListener('click', e => {
                const q = e.target.closest('[data-quick]');
                if (q) {
                    const t = $('#rmText');
                    t.value = t.value.trim() ? t.value.replace(/\s+$/, '') + '. ' + q.dataset.quick : q.dataset.quick;
                    t.focus();
                    return;
                }
                const f = e.target.closest('[data-fuadd]');
                if (f) {
                    $('#rmFu').value = f.dataset.fuadd === 'clear' ? '' : addDays(parseInt(f.dataset.fuadd, 10));
                }
            });

            chatBox().addEventListener('click', async e => {
                const b = e.target.closest('[data-ract]');
                if (!b) return;
                const rid = b.dataset.rid;

                if (b.dataset.ract === 'del') {
                    if (!b.classList.contains('sure')) { // first click = ask, second click = delete
                        b.classList.add('sure');
                        b.innerHTML = 'Delete?';
                        setTimeout(() => {
                            if (b.isConnected) {
                                b.classList.remove('sure');
                                b.innerHTML = '<i class="bi bi-trash3"></i>';
                            }
                        }, 3000);
                        return;
                    }
                    const fd = new FormData();
                    fd.set('id', rid);
                    const j = await call({
                        api: 'remark_delete'
                    }, fd);
                    if (j.success) {
                        applyRemarks(j);
                        toast(j.message);
                    } else toast(j.message || 'Could not delete.', 'error');
                } else if (b.dataset.ract === 'done') {
                    const fd = new FormData();
                    fd.set('id', rid);
                    fd.set('done', b.dataset.done);
                    const j = await call({
                        api: 'remark_done'
                    }, fd);
                    if (j.success) {
                        applyRemarks(j);
                        toast(j.message);
                    } else toast(j.message || 'Could not update.', 'error');
                }
            });
        })();
    </script>
<?php crm_foot(); ?>
</body>

</html>