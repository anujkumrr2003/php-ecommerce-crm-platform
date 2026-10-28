<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/*
|--------------------------------------------------------------------------
| customers.php  -  All customers (real data from MySQL)
|--------------------------------------------------------------------------
| Tables : customers, customer_custom_fields, customer_custom_values,
|          customer_remarks, bills, bill_items, message_history, api_integrations
|
| NEW in this version
|   * Source column: "Self Added" (admin) / API name (Housing, 99acres, REI ...)
|   * WhatsApp column: Sent / Failed / Pending / Not sent  (+ Send now / Send again)
|   * WhatsApp filter + dynamic Source filter (built from the database)
|   * CSV export has WhatsApp status columns
|
| Customer types (filter pills)
|   t=customer (default) : customers whose invoice is already made
|   t=enquiry            : customers with NO invoice yet
|   t=all                : everybody
| A customer is linked to bills by MOBILE (last 10 digits).
|
| Page   : customers.php?t=&q=&cat=&city=&src=&fu=&wa=&page=
| Export : customers.php?export=1&(same filters)          -> CSV download
| AJAX GET : view, edit, remarks, cust_bills, bill_get
| AJAX POST: update, delete, remark_add, remark_done, remark_delete,
|            bill_delete, wa_resend
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Kolkata');            // Delhi / Noida (IST)

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

/* customer-core.php loads config.php ($conn), mysqli strict mode, IST timezone,
   UserError, cleanValue(), validDateOrNull() and the WhatsApp sender. */
require_once __DIR__ . '/customer-core.php';

if (empty($_SESSION['customers_csrf'])) {
    $_SESSION['customers_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['customers_csrf'];

const SELF_ADDED       = 'Self Added';
const CUSTOMER_CATS    = ['Regular', 'Premium', 'VIP', 'Wholesale'];
const CUSTOMER_SOURCES = ['Walk-in', 'Website', 'Instagram', 'Referral', 'Facebook', 'Housing', '99acres', 'REI'];
const CUSTOMER_STATES  = ['Delhi', 'Haryana', 'Uttar Pradesh', 'Punjab', 'Rajasthan', 'Maharashtra'];
const CUSTOMER_GENDERS = ['Female', 'Male', 'Other'];
/* preference options come from Settings (crm_customer_prefs) */
const WA_STATUSES      = ['sent' => 'Sent', 'failed' => 'Failed', 'pending' => 'Pending', 'skipped' => 'Not sent'];
const REMARK_TYPES     = ['Call', 'WhatsApp', 'Visit', 'Other'];
const QUICK_REPLIES    = [
    'Phone not picked',
    'Asked to call back later',
    'Interested, will visit store',
    'Sent catalogue on WhatsApp',
    'Order confirmed',
    'Payment pending',
    'Not interested',
];
const PER_PAGE         = 20000;   // paging is done by assets/js/crm-table.js (30/60/100/All)
const RESTORE_STOCK    = true;      // true = deleting an invoice gives the stock back to the products

/* SQL fragment: "this customers row has a bill" (bills.customer_mobile <-> customers.mobile) */
const BILL_MATCH = 'RIGHT(bb.customer_mobile, 10) = customers.mobile';

/* ---------------------------------------------------- shop details (invoice) */
/* Same source as billing.php (settings-helper.php). Falls back to simple defaults. */
$SET  = null;
$SHOP = [
    'sym' => "\u{20B9}", 'word' => 'Rupees', 'minor' => 'Paise', 'name' => 'My Store',
    'addr' => '', 'gstin' => '', 'phone' => '', 'email' => '', 'logo' => '', 'terms' => '',
    'footer' => 'Thank you for shopping with us!', 'pos' => '',
];
try {
    if (is_file(__DIR__ . '/settings-helper.php') && defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
        require_once __DIR__ . '/settings-helper.php';
        $pdoSet = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $SET  = get_settings($pdoSet);
        $SHOP = array_merge($SHOP, (array)shop_for_js($SET));
    }
} catch (Throwable $ex) {
    error_log('customers.php settings: ' . $ex->getMessage());
    $SET = null;
}

/* ------------------------------------------------------------------ helpers */

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function jsonOut(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* prepared SELECT -> all rows */
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

function dbOne(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = dbAll($conn, $sql, $types, $params);
    return $rows[0] ?? null;
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

function badgeClass(string $cat): string
{
    switch ($cat) {
        case 'VIP':
            return 'b-gold';
        case 'Premium':
            return 'b-blue';
        case 'Wholesale':
            return 'b-green';
        default:
            return 'b-gray';
    }
}

function fmtDate(?string $d): string
{
    if (!$d || $d === '0000-00-00') {
        return '-';
    }
    $t = strtotime($d);
    return $t ? date('j M Y', $t) : '-';
}

/* days until the next birthday / anniversary (null if no date) */
function daysUntil(?string $date): ?int
{
    if (!$date || $date === '0000-00-00' || strlen($date) < 10) {
        return null;
    }
    $today = new DateTime('today');
    $m = (int)substr($date, 5, 2);
    $d = (int)substr($date, 8, 2);
    $y = (int)$today->format('Y');
    for ($i = 0; $i < 2; $i++) {
        $c = DateTime::createFromFormat('!Y-n-j', ($y + $i) . '-' . $m . '-' . $d);
        if ($c && $c >= $today) {
            return (int)$today->diff($c)->days;
        }
    }
    return null;
}

function whenText(int $n): string
{
    return $n === 0 ? 'Today' : ($n === 1 ? 'Tomorrow' : 'In ' . $n . ' days');
}

/* ------------------------------------------------ source + WhatsApp (NEW) */

/* every source value that may be saved (Self Added + old list + sources already in DB + API names) */
function allowedSources(mysqli $conn): array
{
    $list = array_merge([SELF_ADDED], CUSTOMER_SOURCES);
    try {
        foreach (dbAll($conn, "SELECT DISTINCT source FROM customers WHERE source <> ''") as $r) {
            $list[] = (string)$r['source'];
        }
    } catch (Throwable $ex) {
    }
    try {
        foreach (dbAll($conn, "SELECT name FROM api_integrations WHERE name <> ''") as $r) {
            $list[] = (string)$r['name'];
        }
    } catch (Throwable $ex) {
    }
    return array_values(array_unique($list));
}

/* table cell: Self Added / API badge */
function sourceCell(string $src, $apiId): string
{
    if ($src === SELF_ADDED) {
        return '<span class="badge-s b-blue"><i class="bi bi-person-check"></i> Self Added</span>';
    }
    if (!empty($apiId)) {
        return '<span class="badge-s b-green"><i class="bi bi-plug"></i> ' . e($src) . '</span>';
    }
    return $src !== '' ? e($src) : '<span class="text-muted">-</span>';
}

/* table cell: WhatsApp welcome message status + Send button */
function waCell(array $r): string
{
    $s   = (string)($r['wa_status'] ?? 'skipped');
    $id  = (int)$r['id'];
    $err = (string)($r['wa_error'] ?? '');

    switch ($s) {
        case 'sent':
            $h = '<span class="badge-s b-green"><i class="bi bi-check2-all"></i> Sent</span>';
            if (!empty($r['wa_sent_at'])) {
                $h .= '<br><small class="text-muted">' . e(date('j M, h:i A', strtotime((string)$r['wa_sent_at']))) . '</small>';
            }
            $btn = 'Send again';
            break;
        case 'failed':
            $h = '<span class="badge-s b-red" title="' . e($err) . '"><i class="bi bi-x-circle"></i> Failed</span>';
            if ($err !== '') {
                $h .= '<br><small class="text-muted" title="' . e($err) . '">' . e(mb_strimwidth($err, 0, 38, '...')) . '</small>';
            }
            $btn = 'Send now';
            break;
        case 'pending':
            $h   = '<span class="badge-s b-amber"><i class="bi bi-hourglass-split"></i> Pending</span>';
            $btn = 'Send now';
            break;
        default:
            $h   = '<span class="badge-s b-gray">Not sent</span>';
            $btn = 'Send now';
    }

    return $h . '<br><button type="button" class="btn btn-link p-0 small text-decoration-none" style="font-size:10px !important; data-action="waresend" data-id="' . $id
        . '" data-again="' . ($s === 'sent' ? '1' : '0') . '"><i class="bi bi-whatsapp me-1"></i>' . $btn . '</button>';
}

/* ------------------------------------------------------ bills (invoices) side */

/* does the bills table exist? (page still works if billing is not installed) */
function billsTableExists(mysqli $conn): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $r  = mysqli_query($conn, "SHOW TABLES LIKE 'bills'");
            $ok = $r && mysqli_num_rows($r) > 0;
        } catch (Throwable $ex) {
            $ok = false;
        }
    }
    return $ok;
}

/* customer / enquiry / all  (default: customer, but a just-saved customer opens in Enquiry) */
function effectiveType(array $src): string
{
    $t = cleanValue($src['t'] ?? '');
    if (in_array($t, ['customer', 'enquiry', 'all'], true)) {
        return $t;
    }
    return isset($src['saved']) ? 'enquiry' : 'customer';
}

function billDate(?string $d): string
{
    global $SET;
    if ($SET && function_exists('app_date')) {
        try {
            return (string)app_date($d, $SET);
        } catch (Throwable $ex) {
        }
    }
    return fmtDate($d);
}

/* one saved bill in the shape the invoice template needs */
function loadBill(mysqli $conn, int $id): ?array
{
    $b = dbOne($conn, 'SELECT * FROM bills WHERE id = ?', 'i', [$id]);
    if (!$b) {
        return null;
    }
    $items = [];
    foreach (dbAll($conn, 'SELECT * FROM bill_items WHERE bill_id = ? ORDER BY id', 'i', [$id]) as $it) {
        $items[] = [
            'name'    => $it['item_name'],
            'sku'     => $it['sku'],
            'variant' => implode(' / ', array_filter([$it['size'], $it['color']], 'strlen')),
            'qty'     => (int)$it['qty'],
            'rate'    => (float)$it['rate'],
            'disc'    => (float)$it['disc_percent'],
            'gst'     => (float)$it['gst_percent'],
            'amt'     => (float)$it['line_total'],
        ];
    }
    return [
        'id'      => (int)$b['id'],
        'no'      => $b['invoice_no'],
        'date'    => billDate($b['invoice_date']),
        'cust'    => $b['customer_name'],
        'contact' => implode(', ', array_filter([$b['customer_mobile'], $b['customer_city']], 'strlen')),
        'method'  => $b['payment_method'],
        'status'  => $b['payment_status'],
        'sub'     => (float)$b['subtotal'],
        'disc'    => (float)$b['discount'],
        'gst'     => (float)$b['gst'],
        'total'   => (float)$b['total'],
        'items'   => $items,
    ];
}

/* search + filters -> [WHERE sql, types, params] */
function buildWhere(array $src): array
{
    global $conn;
    $w = [];
    $t = '';
    $p = [];

    $q = cleanValue($src['q'] ?? '');
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $w[]  = '(name LIKE ? OR mobile LIKE ? OR email LIKE ? OR city LIKE ?)';
        $t   .= 'ssss';
        array_push($p, $like, $like, $like, $like);
    }
    foreach (['cat' => 'category', 'city' => 'city', 'src' => 'source'] as $key => $col) {
        $v = cleanValue($src[$key] ?? '');
        if ($v !== '') {
            $w[] = $col . ' = ?';
            $t  .= 's';
            $p[] = $v;
        }
    }

    /* WhatsApp status filter (sent / failed / pending / skipped) */
    $wa = cleanValue($src['wa'] ?? '');
    if ($wa !== '' && isset(WA_STATUSES[$wa])) {
        $w[] = 'wa_status = ?';
        $t  .= 's';
        $p[] = $wa;
    }

    /* customer type: invoice made / enquiry (no invoice) / all */
    $type = effectiveType($src);
    if ($type !== 'all') {
        if (billsTableExists($conn)) {
            $ex  = 'EXISTS (SELECT 1 FROM bills bb WHERE ' . BILL_MATCH . ')';
            $w[] = $type === 'customer' ? $ex : 'NOT ' . $ex;
        } elseif ($type === 'customer') {
            $w[] = '1 = 0';                    // no bills table: nobody has an invoice
        }
    }

    /* follow-up filter: due (today / overdue) or any pending reminder */
    $fu = cleanValue($src['fu'] ?? '');
    if ($fu === 'due' || $fu === 'pending') {
        $cond = 'EXISTS (SELECT 1 FROM customer_remarks fr WHERE fr.customer_id = customers.id
                         AND fr.followup_done = 0 AND fr.followup_date IS NOT NULL';
        if ($fu === 'due') {
            $cond .= ' AND fr.followup_date <= ?';
            $t    .= 's';
            $p[]   = date('Y-m-d');
        }
        $w[] = $cond . ')';
    }

    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $t, $p];
}

/* keep current filters in links */
function qs(array $over = []): string
{
    $keep = [];
    foreach (['q', 'cat', 'city', 'src', 'fu', 'wa', 't', 'page'] as $k) {
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
    if (!isset($keep['t'])) {
        $keep['t'] = effectiveType($_GET);
    }
    return '?' . http_build_query($keep);
}

/* ------------------------------------------------------- remarks (chat) side */

/* create the table automatically if it does not exist yet (safe to run many times) */
function ensureRemarksTable(mysqli $conn): void
{
    if (!empty($_SESSION['remarks_tbl_ok'])) {
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
        $_SESSION['remarks_tbl_ok'] = 1;
    } catch (Throwable $ex) {
        error_log('customers.php ensureRemarksTable: ' . $ex->getMessage());   // maybe no CREATE permission: run customer_remarks.sql manually
    }
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

/* "Today", "Yesterday", "5 days ago" */
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

/* table cell: next pending follow-up + last contact */
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

/* WhatsApp style conversation */
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

/* everything the page needs after any remark action */
function remarksPayload(mysqli $conn, int $cid): array
{
    $list = dbAll(
        $conn,
        'SELECT id, remark, remark_type, remark_at, followup_date, followup_done
           FROM customer_remarks WHERE customer_id = ? ORDER BY remark_at ASC, id ASC',
        'i',
        [$cid]
    );

    $next = null;
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
        'now'     => date('Y-m-d H:i'),          // server time in IST (not the visitor's device time)
    ];
}

/* ------------------------------------------------- custom fields (edit side) */

function activeFieldsWithValues(mysqli $conn, int $customerId): array
{
    return dbAll(
        $conn,
        "SELECT f.id, f.label, f.field_type, f.placeholder, f.options_json, f.is_required,
                (SELECT v.field_value FROM customer_custom_values v
                  WHERE v.customer_id = ? AND v.field_id = f.id
                  ORDER BY v.id DESC LIMIT 1) AS field_value
           FROM customer_custom_fields f
          WHERE f.is_active = 1
          ORDER BY f.sort_order ASC, f.id ASC",
        'i',
        [$customerId]
    );
}

function renderCustomInput(array $f): string
{
    $fid  = (int)$f['id'];
    $req  = (int)$f['is_required'] === 1;
    $name = 'custom[' . $fid . ']';
    $id   = 'e_cf_' . $fid;
    $val  = (string)($f['field_value'] ?? '');
    $ph   = e($f['placeholder'] ?? '');
    $r    = $req ? ' required' : '';
    $type = $f['field_type'];

    $h  = '<div class="' . ($type === 'textarea' ? 'col-12' : 'col-md-6') . '">';
    $h .= '<label class="form-label" for="' . $id . '">' . e($f['label']) . ($req ? ' <span class="text-danger">*</span>' : '') . '</label>';

    if ($type === 'dropdown') {
        $opts = json_decode($f['options_json'] ?? '[]', true);
        $opts = is_array($opts) ? $opts : [];
        $h .= '<select id="' . $id . '" name="' . $name . '" class="form-select"' . $r . '><option value="">Select...</option>';
        foreach ($opts as $o) {
            $h .= '<option value="' . e($o) . '"' . ($val === (string)$o ? ' selected' : '') . '>' . e($o) . '</option>';
        }
        $h .= '</select>';
    } elseif ($type === 'textarea') {
        $h .= '<textarea id="' . $id . '" name="' . $name . '" class="form-control" rows="2" maxlength="1000" placeholder="' . $ph . '"' . $r . '>' . e($val) . '</textarea>';
    } else {
        $t     = in_array($type, ['number', 'date'], true) ? $type : 'text';
        $extra = $t === 'number' ? ' step="any"' : ($t === 'text' ? ' maxlength="1000"' : '');
        $h .= '<input type="' . $t . '" id="' . $id . '" name="' . $name . '" class="form-control"' . $extra . ' placeholder="' . $ph . '" value="' . e($val) . '"' . $r . '>';
    }
    $h .= '<div class="invalid-feedback">This field is required.</div></div>';
    return $h;
}

function renderEditForm(array $c, array $fields, string $csrf, array $sources): string
{
    $prefs = json_decode($c['clothing_preferences'] ?? '[]', true);
    $prefs = is_array($prefs) ? $prefs : [];

    $opt = function (array $list, string $current): string {
        $h = '<option value="">Select...</option>';
        if ($current !== '' && !in_array($current, $list, true)) {
            $list[] = $current;                 // keep an old value that is not in the list anymore
        }
        foreach ($list as $x) {
            $h .= '<option value="' . e($x) . '"' . ($x === $current ? ' selected' : '') . '>' . e($x) . '</option>';
        }
        return $h;
    };

    $h  = '<form id="editForm" method="post" action="customers.php?api=update" novalidate>';
    $h .= '<input type="hidden" name="csrf_token" value="' . e($csrf) . '">';
    $h .= '<input type="hidden" name="id" value="' . (int)$c['id'] . '">';
    $h .= '<div class="row g-3">';

    $h .= '<div class="col-md-6"><label class="form-label" for="e_name">Full name <span class="text-danger">*</span></label>'
        . '<input type="text" id="e_name" name="name" class="form-control" maxlength="150" required value="' . e($c['name']) . '">'
        . '<div class="invalid-feedback">Please enter the customer\'s full name.</div></div>';

    $h .= '<div class="col-md-6"><label class="form-label" for="e_mobile">Mobile number <span class="text-danger">*</span></label>'
        . '<input type="tel" id="e_mobile" name="mobile" class="form-control" required pattern="[6-9][0-9]{9}" maxlength="10" inputmode="numeric" value="' . e($c['mobile']) . '">'
        . '<div class="invalid-feedback">Enter a 10-digit mobile number starting with 6, 7, 8 or 9.</div></div>';

    $h .= '<div class="col-md-6"><label class="form-label" for="e_email">Email</label>'
        . '<input type="email" id="e_email" name="email" class="form-control" maxlength="190" value="' . e($c['email']) . '">'
        . '<div class="invalid-feedback">Enter a valid email.</div></div>';

    $h .= '<div class="col-md-6"><label class="form-label" for="e_gender">Gender <span class="text-danger">*</span></label>'
        . '<select id="e_gender" name="gender" class="form-select" required>' . $opt(CUSTOMER_GENDERS, (string)$c['gender']) . '</select>'
        . '<div class="invalid-feedback">Please choose an option.</div></div>';

    $h .= '<div class="col-md-6"><label class="form-label" for="e_dob">Date of birth</label>'
        . '<input type="date" id="e_dob" name="dob" class="form-control" value="' . e($c['dob']) . '"></div>';

    $h .= '<div class="col-md-6"><label class="form-label" for="e_anniv">Anniversary</label>'
        . '<input type="date" id="e_anniv" name="anniv" class="form-control" value="' . e($c['anniversary']) . '"></div>';

    $h .= '<div class="col-12"><label class="form-label" for="e_addr">Address</label>'
        . '<textarea id="e_addr" name="addr" class="form-control" rows="2">' . e($c['address']) . '</textarea></div>';

    $h .= '<div class="col-md-4"><label class="form-label" for="e_city">City <span class="text-danger">*</span></label>'
        . '<input type="text" id="e_city" name="city" class="form-control" maxlength="100" required value="' . e($c['city']) . '">'
        . '<div class="invalid-feedback">Enter a valid city.</div></div>';

    $h .= '<div class="col-md-4"><label class="form-label" for="e_state">State <span class="text-danger">*</span></label>'
        . '<select id="e_state" name="state" class="form-select" required>' . $opt(CUSTOMER_STATES, (string)$c['state']) . '</select>'
        . '<div class="invalid-feedback">Please choose an option.</div></div>';

    $h .= '<div class="col-md-4"><label class="form-label" for="e_pin">PIN code <span class="text-danger">*</span></label>'
        . '<input type="text" id="e_pin" name="pin" class="form-control" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" value="' . e($c['pin']) . '">'
        . '<div class="invalid-feedback">Enter a 6-digit PIN code.</div></div>';

    $h .= '<div class="col-md-4"><label class="form-label" for="e_cat">Category <span class="text-danger">*</span></label>'
        . '<select id="e_cat" name="cat" class="form-select" required>' . $opt(CUSTOMER_CATS, (string)$c['category']) . '</select>'
        . '<div class="invalid-feedback">Please choose an option.</div></div>';

    $h .= '<div class="col-md-4"><label class="form-label" for="e_src">Source <span class="text-danger">*</span></label>'
        . '<select id="e_src" name="src" class="form-select" required>' . $opt($sources, (string)$c['source']) . '</select>'
        . '<div class="invalid-feedback">Please choose an option.</div></div>';

    $h .= '<div class="col-md-4"><label class="form-label" for="e_regdate">Registration date <span class="text-danger">*</span></label>'
        . '<input type="date" id="e_regdate" name="regdate" class="form-control" required value="' . e($c['registration_date']) . '">'
        . '<div class="invalid-feedback">Enter a valid registration date.</div></div>';

    $h .= '<div class="col-12"><label class="form-label d-block">Product preferences</label>';
    foreach (crm_customer_prefs() as $i => $p) {
        $h .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="e_p' . $i . '" name="preferences[]" value="' . e($p) . '"'
            . (in_array($p, $prefs, true) ? ' checked' : '') . '><label class="form-check-label" for="e_p' . $i . '">' . e($p) . '</label></div>';
    }
    $h .= '</div>';

    $h .= '<div class="col-12"><label class="form-label" for="e_notes">Notes</label>'
        . '<textarea id="e_notes" name="notes" class="form-control" rows="3">' . e($c['notes']) . '</textarea></div>';

    if ($fields) {
        $h .= '<div class="col-12"><hr class="my-1"><h6 class="mb-0"><i class="bi bi-ui-checks me-1"></i>Additional fields</h6></div>';
        foreach ($fields as $f) {
            $h .= renderCustomInput($f);
        }
    }

    $h .= '</div></form>';
    return $h;
}

/* ------------------------------------------------------------ view (read-only) */

function kv(string $label, string $html, string $col = 'col-sm-6'): string
{
    return '<div class="' . $col . '"><small class="text-muted d-block">' . e($label) . '</small><div>' . $html . '</div></div>';
}

function renderView(array $c, array $custom): string
{
    $dash  = '<span class="text-muted">-</span>';
    $plain = function ($v) use ($dash): string {
        $v = trim((string)($v ?? ''));
        return $v === '' ? $dash : e($v);
    };

    $prefs = json_decode($c['clothing_preferences'] ?? '[]', true);
    $prefHtml = $dash;
    if (is_array($prefs) && $prefs) {
        $prefHtml = '';
        foreach ($prefs as $p) {
            $prefHtml .= '<span class="badge-s b-gray me-1 mb-1 d-inline-block">' . e($p) . '</span>';
        }
    }

    $yn = function ($v): string {
        return (int)$v === 1 ? '<span class="badge-s b-green">Yes</span>' : '<span class="badge-s b-gray">No</span>';
    };

    /* WhatsApp welcome message status */
    $ws    = (string)($c['wa_status'] ?? 'skipped');
    $wTone = ['sent' => 'b-green', 'failed' => 'b-red', 'pending' => 'b-amber'][$ws] ?? 'b-gray';
    $wHtml = '<span class="badge-s ' . $wTone . '">' . e(WA_STATUSES[$ws] ?? 'Not sent') . '</span>';
    if ($ws === 'sent' && !empty($c['wa_sent_at'])) {
        $wHtml .= ' <small class="text-muted">' . e(date('j M Y, h:i A', strtotime((string)$c['wa_sent_at']))) . '</small>';
    }
    if ($ws === 'failed' && trim((string)($c['wa_error'] ?? '')) !== '') {
        $wHtml .= '<div><small class="text-danger">' . e($c['wa_error']) . '</small></div>';
    }
    if (!empty($c['wa_attempts'])) {
        $wHtml .= ' <small class="text-muted">(attempts: ' . (int)$c['wa_attempts'] . ')</small>';
    }

    $h  = '<div class="row g-3">';
    $h .= kv('Full name', '<b>' . e($c['name']) . '</b>');
    $h .= kv('Mobile', $plain($c['mobile']));
    $h .= kv('Email', $plain($c['email']));
    $h .= kv('Gender', $plain($c['gender']));
    $h .= kv('Date of birth', e(fmtDate($c['dob'])));
    $h .= kv('Anniversary', e(fmtDate($c['anniversary'])));
    $h .= kv('Address', $plain($c['address']), 'col-12');
    $h .= kv('City', $plain($c['city']), 'col-sm-4');
    $h .= kv('State', $plain($c['state']), 'col-sm-4');
    $h .= kv('PIN code', $plain($c['pin']), 'col-sm-4');
    $h .= kv('Category', '<span class="badge-s ' . badgeClass((string)$c['category']) . '">' . e($c['category']) . '</span>', 'col-sm-4');
    $h .= kv('Source', sourceCell((string)$c['source'], $c['api_id'] ?? null), 'col-sm-4');
    $h .= kv('Registered on', e(fmtDate($c['registration_date'])), 'col-sm-4');
    $h .= kv('Product preferences', $prefHtml, 'col-12');
    $h .= kv('Notes', $plain($c['notes']), 'col-12');
    $h .= kv('WhatsApp consent', $yn($c['whatsapp_consent'] ?? 0), 'col-sm-6');
    $h .= kv('SMS consent', $yn($c['sms_consent'] ?? 0), 'col-sm-6');
    $h .= kv('WhatsApp welcome message', $wHtml, 'col-12');

    $h .= '<div class="col-12"><hr class="my-1"><h6 class="mb-0"><i class="bi bi-ui-checks me-1"></i>Additional fields</h6></div>';
    if ($custom) {
        foreach ($custom as $f) {
            $label = e($f['label']) . ((int)$f['is_active'] === 0 ? ' <span class="text-muted">(inactive field)</span>' : '');
            $val   = (string)$f['field_value'];
            $h .= '<div class="' . ($f['field_type'] === 'textarea' ? 'col-12' : 'col-sm-6') . '"><small class="text-muted d-block">' . $label . '</small><div>'
                . ($val === '' ? $dash : nl2br(e($f['field_type'] === 'date' ? fmtDate($val) : $val))) . '</div></div>';
        }
    } else {
        $h .= '<div class="col-12 text-muted">No additional field values saved for this customer.</div>';
    }

    $h .= '</div>';
    return $h;
}

/* ---------------------------------------------- save custom values on update */

function saveCustomValues(mysqli $conn, int $customerId, array $posted): void
{
    $fields = dbAll(
        $conn,
        "SELECT id, label, field_type, options_json, is_required
           FROM customer_custom_fields
          WHERE is_active = 1
          ORDER BY sort_order ASC, id ASC"
    );

    $del = mysqli_prepare($conn, 'DELETE FROM customer_custom_values WHERE customer_id = ? AND field_id = ?');
    $ins = mysqli_prepare($conn, 'INSERT INTO customer_custom_values (customer_id, field_id, field_value) VALUES (?, ?, ?)');

    foreach ($fields as $field) {
        $fieldId = (int)$field['id'];
        $label   = $field['label'];
        $raw     = $posted[$fieldId] ?? '';

        if (is_array($raw)) {
            throw new UserError('Invalid value for "' . $label . '".');
        }
        $value = trim((string)$raw);

        if ($value === '' && (int)$field['is_required'] === 1) {
            throw new UserError('"' . $label . '" is required.');
        }

        if ($value !== '') {
            if (mb_strlen($value) > 1000) {
                throw new UserError('"' . $label . '" is too long (max 1000 characters).');
            }
            switch ($field['field_type']) {
                case 'dropdown':
                    $options = json_decode($field['options_json'] ?? '[]', true);
                    if (!is_array($options) || !in_array($value, $options, true)) {
                        throw new UserError('Please choose a valid option for "' . $label . '".');
                    }
                    break;
                case 'number':
                    if (!is_numeric($value)) {
                        throw new UserError('"' . $label . '" must be a number.');
                    }
                    break;
                case 'date':
                    $value = validDateOrNull($value, $label);
                    break;
            }
        }

        /* replace old value (works even if there is no UNIQUE key on customer_id + field_id) */
        mysqli_stmt_bind_param($del, 'ii', $customerId, $fieldId);
        mysqli_stmt_execute($del);

        if ($value !== '') {
            mysqli_stmt_bind_param($ins, 'iis', $customerId, $fieldId, $value);
            mysqli_stmt_execute($ins);
        }
    }
    mysqli_stmt_close($del);
    mysqli_stmt_close($ins);
}

/* make sure the remarks table exists before any query uses it */
ensureRemarksTable($conn);
$billsOk = billsTableExists($conn);

/*
|--------------------------------------------------------------------------
| AJAX API
|--------------------------------------------------------------------------
*/
if (isset($_GET['api'])) {
    try {
        $api = (string)$_GET['api'];

        /* ---- all invoices of one customer (popup) ---- */
        if ($api === 'cust_bills') {
            $id = (int)($_GET['id'] ?? 0);
            $c  = $id > 0 ? dbOne($conn, 'SELECT id, name, mobile FROM customers WHERE id = ?', 'i', [$id]) : null;
            if (!$c) {
                jsonOut(['success' => false, 'message' => 'Customer not found.'], 404);
            }
            $out = [];
            $tot = 0.0;
            $pen = 0.0;
            if ($billsOk) {
                $rows = dbAll(
                    $conn,
                    "SELECT b.id, b.invoice_no, b.invoice_date, b.payment_method, b.payment_status, b.total,
                            (SELECT COALESCE(SUM(i.qty), 0) FROM bill_items i WHERE i.bill_id = b.id) AS item_qty
                       FROM bills b
                      WHERE RIGHT(b.customer_mobile, 10) = ?
                      ORDER BY b.invoice_date DESC, b.id DESC",
                    's',
                    [(string)$c['mobile']]
                );
                foreach ($rows as $r) {
                    $amt  = (float)$r['total'];
                    $tot += $amt;
                    if ($r['payment_status'] !== 'Paid') {
                        $pen += $amt;
                    }
                    $out[] = [
                        'id'     => (int)$r['id'],
                        'no'     => $r['invoice_no'],
                        'date'   => billDate($r['invoice_date']),
                        'items'  => (int)$r['item_qty'],
                        'amount' => $amt,
                        'method' => $r['payment_method'],
                        'status' => $r['payment_status'],
                    ];
                }
            }
            jsonOut([
                'success' => true,
                'name'    => $c['name'],
                'mobile'  => $c['mobile'],
                'bills'   => $out,
                'total'   => $tot,
                'pending' => $pen,
            ]);
        }

        /* ---- one invoice (view / print / WhatsApp) ---- */
        if ($api === 'bill_get') {
            $b = $billsOk ? loadBill($conn, (int)($_GET['id'] ?? 0)) : null;
            if (!$b) {
                jsonOut(['success' => false, 'message' => 'Bill not found.'], 404);
            }
            jsonOut(['success' => true, 'invoice' => $b]);
        }

        /* ---- read-only ---- */
        if ($api === 'view' || $api === 'edit' || $api === 'remarks') {
            $id = (int)($_GET['id'] ?? 0);
            $c  = $id > 0 ? dbOne($conn, 'SELECT * FROM customers WHERE id = ?', 'i', [$id]) : null;
            if (!$c) {
                jsonOut(['success' => false, 'message' => 'Customer not found.'], 404);
            }

            if ($api === 'remarks') {
                jsonOut(array_merge(remarksPayload($conn, $id), [
                    'name'     => $c['name'],
                    'mobile'   => $c['mobile'],
                    'initials' => initials((string)$c['name']),
                ]));
            }

            if ($api === 'view') {
                $custom = dbAll(
                    $conn,
                    "SELECT f.label, f.field_type, f.is_active, v.field_value
                       FROM customer_custom_values v
                       INNER JOIN customer_custom_fields f ON f.id = v.field_id
                      WHERE v.customer_id = ?
                      ORDER BY f.sort_order ASC, f.id ASC",
                    'i',
                    [$id]
                );
                jsonOut(['success' => true, 'name' => $c['name'], 'mobile' => $c['mobile'], 'html' => renderView($c, $custom)]);
            }

            $fields = activeFieldsWithValues($conn, $id);
            jsonOut(['success' => true, 'name' => $c['name'], 'html' => renderEditForm($c, $fields, $csrf, allowedSources($conn))]);
        }

        /* ---- write actions: POST + CSRF ---- */
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonOut(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        $posted = $_POST['csrf_token'] ?? '';
        if (!is_string($posted) || !hash_equals($csrf, $posted)) {
            jsonOut(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], 403);
        }

        /* ---- (NEW) send / re-send the WhatsApp welcome message ---- */
        if ($api === 'wa_resend') {
            $cid = (int)($_POST['id'] ?? 0);
            $c   = $cid > 0 ? dbOne($conn, 'SELECT id, name, mobile FROM customers WHERE id = ?', 'i', [$cid]) : null;
            if (!$c) {
                throw new UserError('Customer not found.');
            }

            $res = sendWelcomeWhatsapp($conn, $cid, (string)$c['name'], (string)$c['mobile'], 'manual');

            $fresh = dbOne($conn, 'SELECT id, wa_status, wa_sent_at, wa_error FROM customers WHERE id = ?', 'i', [$cid]);
            jsonOut([
                'success' => !empty($res['ok']),
                'message' => !empty($res['ok']) ? 'WhatsApp message sent.' : ('WhatsApp failed: ' . (string)($res['error'] ?? 'unknown error')),
                'wa_html' => $fresh ? waCell($fresh) : '',
            ]);
        }

        /* ---- delete one invoice (stock goes back to the products) ---- */
        if ($api === 'bill_delete') {
            if (!$billsOk) {
                throw new UserError('Billing tables are missing.');
            }
            $bid = (int)($_POST['id'] ?? 0);
            mysqli_begin_transaction($conn);
            try {
                if (!dbOne($conn, 'SELECT id FROM bills WHERE id = ? FOR UPDATE', 'i', [$bid])) {
                    throw new UserError('Bill not found or already deleted.');
                }
                if (RESTORE_STOCK) {
                    $items = dbAll($conn, 'SELECT variant_id, qty FROM bill_items WHERE bill_id = ? AND variant_id IS NOT NULL', 'i', [$bid]);
                    $up    = mysqli_prepare($conn, 'UPDATE product_variants SET stock = stock + ? WHERE id = ?');
                    foreach ($items as $it) {
                        $q = (int)$it['qty'];
                        $v = (int)$it['variant_id'];
                        mysqli_stmt_bind_param($up, 'ii', $q, $v);
                        mysqli_stmt_execute($up);
                    }
                    mysqli_stmt_close($up);
                }
                $st = mysqli_prepare($conn, 'DELETE FROM bill_items WHERE bill_id = ?');
                mysqli_stmt_bind_param($st, 'i', $bid);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);

                $st = mysqli_prepare($conn, 'DELETE FROM bills WHERE id = ?');
                mysqli_stmt_bind_param($st, 'i', $bid);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);

                mysqli_commit($conn);
            } catch (Throwable $inner) {
                mysqli_rollback($conn);
                throw $inner;
            }
            jsonOut(['success' => true, 'message' => 'Bill deleted.']);
        }

        /* ---- add a remark (chat message) ---- */
        if ($api === 'remark_add') {
            $cid    = (int)($_POST['id'] ?? 0);
            $text   = cleanValue($_POST['remark'] ?? '');
            $type   = cleanValue($_POST['rtype'] ?? 'Call');
            $atRaw  = cleanValue($_POST['remark_at'] ?? '');
            $fuRaw  = cleanValue($_POST['followup'] ?? '');

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

        /* ---- delete customer ---- */
        if ($api === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new UserError('Invalid customer.');
            }
            mysqli_begin_transaction($conn);
            try {
                $st = mysqli_prepare($conn, 'DELETE FROM customer_custom_values WHERE customer_id = ?');
                mysqli_stmt_bind_param($st, 'i', $id);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);

                $st = mysqli_prepare($conn, 'DELETE FROM customer_remarks WHERE customer_id = ?');
                mysqli_stmt_bind_param($st, 'i', $id);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);

                $st = mysqli_prepare($conn, 'DELETE FROM customers WHERE id = ?');
                mysqli_stmt_bind_param($st, 'i', $id);
                mysqli_stmt_execute($st);
                $gone = mysqli_stmt_affected_rows($st);
                mysqli_stmt_close($st);

                if ($gone < 1) {
                    throw new UserError('Customer not found (maybe already deleted).');
                }
                mysqli_commit($conn);
            } catch (Throwable $inner) {
                mysqli_rollback($conn);
                throw $inner;
            }
            jsonOut(['success' => true, 'message' => 'Customer deleted.']);
        }

        /* ---- update ---- */
        if ($api === 'update') {
            $id = (int)($_POST['id'] ?? 0);

            $name     = cleanValue($_POST['name'] ?? '');
            $mobile   = preg_replace('/\D+/', '', cleanValue($_POST['mobile'] ?? ''));
            $email    = cleanValue($_POST['email'] ?? '');
            $gender   = cleanValue($_POST['gender'] ?? '');
            $dobRaw   = cleanValue($_POST['dob'] ?? '');
            $annivRaw = cleanValue($_POST['anniv'] ?? '');
            $address  = cleanValue($_POST['addr'] ?? '');
            $city     = cleanValue($_POST['city'] ?? '');
            $state    = cleanValue($_POST['state'] ?? '');
            $pin      = preg_replace('/\D+/', '', cleanValue($_POST['pin'] ?? ''));
            $notes    = cleanValue($_POST['notes'] ?? '');
            $category = cleanValue($_POST['cat'] ?? '');
            $regRaw   = cleanValue($_POST['regdate'] ?? '');
            $source   = cleanValue($_POST['src'] ?? '');

            if (strlen($mobile) > 10 && preg_match('/^(91|0)/', $mobile)) {
                $mobile = substr($mobile, -10);
            }

            if ($id <= 0) {
                throw new UserError('Invalid customer.');
            }
            if ($name === '' || $mobile === '' || $gender === '' || $city === '' || $state === '' || $pin === '' || $category === '' || $source === '') {
                throw new UserError('Please fill in all required fields.');
            }
            if (mb_strlen($name) > 150) {
                throw new UserError('Name is too long (max 150 characters).');
            }
            if (mb_strlen($city) > 100) {
                throw new UserError('City is too long (max 100 characters).');
            }
            if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
                throw new UserError('Enter a 10-digit mobile number starting with 6, 7, 8 or 9.');
            }
            if (!preg_match('/^[0-9]{6}$/', $pin)) {
                throw new UserError('Enter a valid 6-digit PIN code.');
            }
            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
                throw new UserError('Enter a valid email address.');
            }
            if (!in_array($gender, CUSTOMER_GENDERS, true)) {
                throw new UserError('Please choose a valid gender.');
            }
            if (!in_array($category, CUSTOMER_CATS, true)) {
                throw new UserError('Please choose a valid customer category.');
            }
            if (!in_array($source, allowedSources($conn), true)) {
                throw new UserError('Please choose a valid source.');
            }

            $dob  = validDateOrNull($dobRaw, 'date of birth');
            $anv  = validDateOrNull($annivRaw, 'anniversary');
            $reg  = validDateOrNull($regRaw !== '' ? $regRaw : date('Y-m-d'), 'registration date');

            $prefs = $_POST['preferences'] ?? [];
            if (!is_array($prefs)) {
                $prefs = [];
            }
            $prefs     = array_values(array_intersect(array_map('strval', $prefs), crm_customer_prefs()));
            $prefsJson = json_encode($prefs, JSON_UNESCAPED_UNICODE);

            $emailDb   = $email   !== '' ? $email   : null;
            $addressDb = $address !== '' ? $address : null;
            $notesDb   = $notes   !== '' ? $notes   : null;

            $customPosted = $_POST['custom'] ?? [];
            if (!is_array($customPosted)) {
                $customPosted = [];
            }

            mysqli_begin_transaction($conn);
            try {
                if (!dbOne($conn, 'SELECT id FROM customers WHERE id = ?', 'i', [$id])) {
                    throw new UserError('Customer not found.');
                }
                if (dbOne($conn, 'SELECT id FROM customers WHERE mobile = ? AND id <> ? LIMIT 1', 'si', [$mobile, $id])) {
                    throw new UserError('This mobile number is already registered for another customer.');
                }

                $st = mysqli_prepare(
                    $conn,
                    "UPDATE customers SET
                        name = ?, mobile = ?, email = ?, gender = ?, dob = ?, anniversary = ?,
                        address = ?, city = ?, state = ?, pin = ?,
                        clothing_preferences = ?, notes = ?, category = ?, registration_date = ?, source = ?
                      WHERE id = ?"
                );
                mysqli_stmt_bind_param(
                    $st,
                    'sssssssssssssssi',
                    $name,
                    $mobile,
                    $emailDb,
                    $gender,
                    $dob,
                    $anv,
                    $addressDb,
                    $city,
                    $state,
                    $pin,
                    $prefsJson,
                    $notesDb,
                    $category,
                    $reg,
                    $source,
                    $id
                );
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);

                saveCustomValues($conn, $id, $customPosted);

                mysqli_commit($conn);
            } catch (Throwable $inner) {
                mysqli_rollback($conn);
                throw $inner;
            }

            $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            if (!$isAjax) {
                header('Location: customers.php');
                exit;
            }

            jsonOut(['success' => true, 'message' => 'Customer updated.']);
        }

        jsonOut(['success' => false, 'message' => 'Unknown action.'], 400);
    } catch (UserError $ex) {
        jsonOut(['success' => false, 'message' => $ex->getMessage()], 422);
    } catch (mysqli_sql_exception $ex) {
        if ((int)$ex->getCode() === 1062) {
            jsonOut(['success' => false, 'message' => 'This mobile number is already registered.'], 422);
        }
        if ((int)$ex->getCode() === 1146) {
            jsonOut(['success' => false, 'message' => 'A required table is missing (customer_remarks / bills / bill_items). Please run the .sql files once in phpMyAdmin.'], 500);
        }
        if ((int)$ex->getCode() === 1054) {
            jsonOut(['success' => false, 'message' => 'A database column is missing (wa_status / api_id). Please run 01-migration.sql once in phpMyAdmin.'], 500);
        }
        error_log('customers.php DB error [' . $ex->getCode() . ']: ' . $ex->getMessage());
        jsonOut(['success' => false, 'message' => 'Database error. Please try again.'], 500);
    } catch (Throwable $ex) {
        error_log('customers.php error: ' . $ex->getMessage());
        jsonOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}

/*
|--------------------------------------------------------------------------
| CSV export (uses the same search + filters)
|--------------------------------------------------------------------------
*/
if (isset($_GET['export'])) {
    try {
        [$whereSql, $wt, $wp] = buildWhere($_GET);
        $rows   = dbAll($conn, "SELECT * FROM customers $whereSql ORDER BY id DESC", $wt, $wp);
        $fields = dbAll($conn, "SELECT id, label FROM customer_custom_fields WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");

        $vals = [];
        if ($rows && $fields) {
            $ids = implode(',', array_map('intval', array_column($rows, 'id')));
            $res = mysqli_query($conn, "SELECT customer_id, field_id, field_value FROM customer_custom_values WHERE customer_id IN ($ids) ORDER BY id ASC");
            while ($v = mysqli_fetch_assoc($res)) {
                $vals[(int)$v['customer_id']][(int)$v['field_id']] = $v['field_value'];
            }
        }

        /* stop spreadsheet formula injection */
        $safe = function ($v): string {
            $v = (string)($v ?? '');
            return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
        };

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customers-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads it correctly

        $head = [
            'ID',
            'Name',
            'Mobile',
            'Email',
            'Gender',
            'Date of birth',
            'Anniversary',
            'Address',
            'City',
            'State',
            'PIN',
            'Category',
            'Source',
            'Added via',
            'Registration date',
            'Product preferences',
            'Notes',
            'WhatsApp status',
            'WhatsApp sent at',
            'WhatsApp error'
        ];
        foreach ($fields as $f) {
            $head[] = $f['label'];
        }
        fputcsv($out, array_map($safe, $head));

        foreach ($rows as $r) {
            $prefs = json_decode($r['clothing_preferences'] ?? '[]', true);
            $line  = [
                $r['id'],
                $r['name'],
                $r['mobile'],
                $r['email'],
                $r['gender'],
                $r['dob'],
                $r['anniversary'],
                $r['address'],
                $r['city'],
                $r['state'],
                $r['pin'],
                $r['category'],
                $r['source'],
                !empty($r['api_id']) ? 'API' : 'Self Added',
                $r['registration_date'],
                is_array($prefs) ? implode(', ', $prefs) : '',
                $r['notes'],
                WA_STATUSES[(string)($r['wa_status'] ?? 'skipped')] ?? 'Not sent',
                $r['wa_sent_at'] ?? '',
                $r['wa_error'] ?? '',
            ];
            foreach ($fields as $f) {
                $line[] = $vals[(int)$r['id']][(int)$f['id']] ?? '';
            }
            fputcsv($out, array_map($safe, $line));
        }
        fclose($out);
    } catch (Throwable $ex) {
        error_log('customers.php export: ' . $ex->getMessage());
        http_response_code(500);
        echo 'Export failed.';
    }
    exit;
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/
$loadError = '';
$rows      = [];
$cities    = [];
$sources   = [];
$stats     = ['total' => 0, 'vip' => 0, 'month' => 0, 'due' => 0, 'wafail' => 0];
$cnt3      = ['all' => 0, 'billed' => 0, 'enquiry' => 0];
$total     = 0;
$pages     = 1;
$page      = 1;
$ft        = effectiveType($_GET);

try {
    [$whereSql, $wt, $wp] = buildWhere($_GET);

    $cnt   = dbOne($conn, "SELECT COUNT(*) AS n FROM customers $whereSql", $wt, $wp);
    $total = (int)($cnt['n'] ?? 0);

    $pages = max(1, (int)ceil($total / PER_PAGE));
    $page  = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $off   = ($page - 1) * PER_PAGE;

    $billSel = $billsOk
        ? "(SELECT COUNT(*) FROM bills bb WHERE " . BILL_MATCH . ") AS bill_count,
           (SELECT COALESCE(SUM(bb.total), 0) FROM bills bb WHERE " . BILL_MATCH . ") AS bill_total,"
        : "0 AS bill_count, 0 AS bill_total,";

    $rows = dbAll(
        $conn,
        "SELECT id, name, mobile, email, city, state, category, source, api_id,
                wa_status, wa_sent_at, wa_error, registration_date, dob, anniversary,
                $billSel
                (SELECT COUNT(*) FROM customer_remarks r WHERE r.customer_id = customers.id) AS remark_count,
                (SELECT MAX(r.remark_at) FROM customer_remarks r WHERE r.customer_id = customers.id) AS last_contact,
                (SELECT MIN(r.followup_date) FROM customer_remarks r
                  WHERE r.customer_id = customers.id AND r.followup_done = 0 AND r.followup_date IS NOT NULL) AS next_followup
           FROM customers $whereSql
          ORDER BY id DESC
          LIMIT ? OFFSET ?",
        $wt . 'ii',
        array_merge($wp, [PER_PAGE, $off])
    );

    $st = dbOne(
        $conn,
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(category = 'VIP'), 0) AS vip,
                COALESCE(SUM(registration_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS month_new,
                COALESCE(SUM(wa_status = 'failed'), 0) AS wa_failed
           FROM customers"
    );
    if ($st) {
        $stats['total']  = (int)$st['total'];
        $stats['vip']    = (int)$st['vip'];
        $stats['month']  = (int)$st['month_new'];
        $stats['wafail'] = (int)$st['wa_failed'];
    }

    /* counts for the 3 pills (invoice made / enquiry / all) */
    $cnt3['all'] = $stats['total'];
    if ($billsOk) {
        $b3 = dbOne($conn, "SELECT COALESCE(SUM(EXISTS (SELECT 1 FROM bills bb WHERE " . BILL_MATCH . ")), 0) AS billed FROM customers");
        $cnt3['billed'] = (int)($b3['billed'] ?? 0);
    }
    $cnt3['enquiry'] = max(0, $cnt3['all'] - $cnt3['billed']);

    $dueRow = dbOne(
        $conn,
        'SELECT COUNT(DISTINCT customer_id) AS n FROM customer_remarks
          WHERE followup_done = 0 AND followup_date IS NOT NULL AND followup_date <= ?',
        's',
        [date('Y-m-d')]
    );
    $stats['due'] = (int)($dueRow['n'] ?? 0);

    foreach (dbAll($conn, "SELECT DISTINCT city FROM customers WHERE city <> '' ORDER BY city ASC") as $c) {
        $cities[] = $c['city'];
    }
    $sources = allowedSources($conn);
} catch (Throwable $ex) {
    error_log('customers.php list: ' . $ex->getMessage());
    $code = (int)$ex->getCode();
    if ($code === 1146) {
        $loadError = 'A required table is missing (customer_remarks / bills). Please run the .sql files in phpMyAdmin once.';
    } elseif ($code === 1054) {
        $loadError = 'A database column is missing (wa_status / api_id). Please run 01-migration.sql in phpMyAdmin once.';
    } else {
        $loadError = 'Customers could not be loaded. Please check the database tables.';
    }
}

$fq      = cleanValue($_GET['q'] ?? '');
$fcat    = cleanValue($_GET['cat'] ?? '');
$fcity   = cleanValue($_GET['city'] ?? '');
$fsrc    = cleanValue($_GET['src'] ?? '');
$ffu     = cleanValue($_GET['fu'] ?? '');
$fwa     = cleanValue($_GET['wa'] ?? '');
$hasFilt = ($fq !== '' || $fcat !== '' || $fcity !== '' || $fsrc !== '' || $ffu !== '' || $fwa !== '');
$saved   = isset($_GET['saved']);
$hiId    = (int)($_GET['customer_id'] ?? 0);
$from    = $total ? (($page - 1) * PER_PAGE + 1) : 0;
$to      = $total ? min($total, $page * PER_PAGE) : 0;
$sym     = (string)$SHOP['sym'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?= e($csrf) ?>" />
    <title>Customers | <?= crm_e(crm_brand()['name']) ?> CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap"
        rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/style.css?v=2" rel="stylesheet" />
    <style>
        /* ---------- customers table: borders + % widths ---------- */
        .cust-table-wrap {
            border: 1px solid #d5dbe6;
            border-radius: 3px;
            background: #fff
        }

        .cust-table {
            width: 100%;
            min-width: 1150px;
            /* below this width the table scrolls sideways instead of squeezing */
            table-layout: fixed;
            border-collapse: collapse;
            margin: 0
        }

        .cust-table th,
        .cust-table td {
            border: 1px solid #e0e5ee;
            padding: 2px 4px;
            vertical-align: middle;
            font-size: 11px;
            overflow-wrap: anywhere
        }

        .cust-table thead th {
            background: #f1f4f9;
            color: #475166;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .03em;
            text-transform: uppercase;
            white-space: nowrap;
            border-bottom: 2px solid #c9d1de
        }

        .cust-table tbody tr:nth-child(even) {
            background: #fafbfd
        }

        .cust-table tbody tr:hover {
            background: #eef4ff
        }

        .cust-table tbody tr.table-active {
            background: #fff6dd
        }

        .cust-table td.act {
            white-space: nowrap;
            text-align: center
        }

        .cust-table th.act {
            text-align: center
        }
        .badge-s {
            padding: 2px 4px !important;
    border-radius: 2px !important;
    font-size: 9px;
        }
        .btn {
         font-size:12px !important;
            
        }
        .av {
            width: 25px !important;
    height: 25px !important;
    font-size: 10px!important;
        }

        .cust-table .av {
            flex: 0 0 auto
        }

        /* ---------- remark icon badge in the table ---------- */
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

        .cust-link {
            border: 0;
            background: transparent;
            padding: 0;
            text-align: left;
            font-weight: 600;
            color: inherit
        }

        .cust-link:hover {
            color: #0b6bcb;
            text-decoration: underline
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
        .btn-icon {
                width: 20px!important;
    height: 30px!important;
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
    
    <style>
       

/* 1) Sideways scroll band */
.cust-table-wrap {
    overflow-x: hidden !important;
}

/* 2) Table hamesha container ki width me fit ho (min-width hata diya) */
.cust-table {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    table-layout: fixed !important;
}

/* 3) Column widths (total = 100%). <col> ke inline style ko !important se override kiya */
.cust-table col:nth-child(1)  { width: 18% !important; }  /* Customer   */
.cust-table col:nth-child(2)  { width: 7%  !important; }  /* City       */
.cust-table col:nth-child(3)  { width: 8%  !important; }  /* Category   */
.cust-table col:nth-child(4)  { width: 9% !important; }  /* Source     */
.cust-table col:nth-child(5)  { width: 8%  !important; }  /* Registered */
.cust-table col:nth-child(6)  { width: 6%  !important; }  /* Inv.       */
.cust-table col:nth-child(7)  { width: 8%  !important; }  /* Upcoming   */
.cust-table col:nth-child(8)  { width: 9%  !important; }  /* Follow-up  */
.cust-table col:nth-child(9)  { width: 10% !important; }  /* WhatsApp   */
.cust-table col:nth-child(10) { width: 15% !important; }  /* Actions    */

/* 4) Text apni cell ke andar hi wrap ho, bahar na nikle */
.cust-table th,
.cust-table td {
    min-width: 0;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.cust-table thead th {
    white-space: normal !important;   /* header bhi wrap ho sake */
    font-size: 11px !important;
    letter-spacing: 0 !important;
    line-height: 1.2;
}

.cust-table .badge-s {
    white-space: normal;
    display: inline-block;
    max-width: 100%;
}

.cust-table small {
    display: inline-block;
    max-width: 100%;
}

/* 5) Actions ke 5 icons: jagah kam ho to agli line me aa jayen, bahar na jayen */
.cust-table td.act {
    white-space: normal !important;
    text-align: center;
}

.cust-table td.act .btn-icon {
    margin: 1px;
    flex: 0 0 auto;
}

/* 6) Customer cell: avatar + naam/mobile cell ke andar rahe */
.cust-table td .d-flex {
    min-width: 0;
}

.cust-table td .d-flex > div {
    min-width: 0;
}

.cust-table .cust-link {
    text-align: left;
    white-space: normal;
    overflow-wrap: anywhere;
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
                    <h1 id="pageTitle">All customers</h1>
                    <p class="text-muted mb-0">Search, filter and manage your customer base. Click a customer to see all his / her invoices.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="customers.php<?= e(qs(['page' => null])) ?>&export=1" class="btn btn-outline-primary">
                        <i class="bi bi-download me-1"></i>Export CSV</a>
                    <?php if (crm_can('customers.add')): ?><a href="add-customer.php" class="btn btn-gold"><i class="bi bi-person-plus me-1"></i>Add customer</a><?php endif; ?>
                </div>
            </div>

            <?php if ($saved):
                $waRes = $_SESSION['customer_form_wa'] ?? '';
                $waErr = (string)($_SESSION['customer_form_wa_error'] ?? '');
                unset($_SESSION['customer_form_wa'], $_SESSION['customer_form_wa_error']);
            ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>Customer saved successfully. New customers are shown in <b>Enquiry</b> until their first invoice is made.
                    <?php if ($waRes === 'sent'): ?>
                        <br><i class="bi bi-whatsapp me-2"></i>WhatsApp welcome message sent.
                    <?php elseif ($waRes === 'failed'): ?>
                        <br><i class="bi bi-exclamation-triangle me-2"></i>WhatsApp message could not be sent<?= $waErr !== '' ? ': ' . e($waErr) : '' ?>. Use <b>Send now</b> in the WhatsApp column to try again.
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($loadError !== ''): ?>
                <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($loadError) ?></div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-6 col-md">
                    <div class="stat">
                        <div><small>Total customers</small><b><?= (int)$stats['total'] ?></b></div>
                    </div>
                </div>
                <div class="col-6 col-md">
                    <div class="stat">
                        <div><small>VIP customers</small><b><?= (int)$stats['vip'] ?></b></div>
                    </div>
                </div>
                <div class="col-6 col-md">
                    <div class="stat">
                        <div><small>Registered this month</small><b><?= (int)$stats['month'] ?></b></div>
                    </div>
                </div>
                <div class="col-6 col-md">
                    <a href="customers.php?fu=due&t=all" class="text-decoration-none text-reset" title="Show customers whose follow-up is due">
                        <div class="stat">
                            <div><small>Follow-ups due</small><b id="dueCount"><?= (int)$stats['due'] ?></b></div>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md">
                    <a href="customers.php?wa=failed&t=all" class="text-decoration-none text-reset" title="Show customers whose WhatsApp message failed">
                        <div class="stat">
                            <div><small>WhatsApp failed</small><b><?= (int)$stats['wafail'] ?></b></div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- customer type pills -->
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php foreach (
                    [
                        ['customer', 'bi-receipt', 'Customers (invoice made)', $cnt3['billed']],
                        ['enquiry', 'bi-question-circle', 'Enquiry (no invoice yet)', $cnt3['enquiry']],
                        ['all', 'bi-people', 'All', $cnt3['all']],
                    ] as [$k, $ic, $lbl, $n]
                ): ?>
                    <a class="btn btn-sm <?= $ft === $k ? 'btn-primary' : 'btn-outline-primary' ?>"
                        href="customers.php<?= e(qs(['t' => $k, 'page' => null])) ?>">
                        <i class="bi <?= $ic ?> me-1"></i><?= e($lbl) ?> <span class="badge bg-light text-dark ms-1"><?= (int)$n ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="panel">
                <form method="get" action="customers.php" class="row g-2 toolbar" id="filterForm">
                    <input type="hidden" name="t" value="<?= e($ft) ?>">
                    
                    
                    
                    
                    <div class="col-6 col-md-2">
                        <select class="form-select" name="fu" aria-label="Follow-up" onchange="this.form.submit()">
                            <option value="">Follow-up</option>
                            <option value="due" <?= $ffu === 'due' ? ' selected' : '' ?>>Due / overdue</option>
                            <option value="pending" <?= $ffu === 'pending' ? ' selected' : '' ?>>Any pending</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select class="form-select" name="wa" aria-label="WhatsApp" onchange="this.form.submit()">
                            <option value="">WhatsApp</option>
                            <?php foreach (WA_STATUSES as $k => $lbl): ?>
                                <option value="<?= e($k) ?>" <?= $fwa === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 text-md-end align-self-center">
                        <?php if ($hasFilt): ?><a href="customers.php?t=<?= e($ft) ?>" class="small me-2">Clear filters</a><?php endif; ?>
                        
                    </div>
                </form>

                <div class="table-responsive cust-table-wrap">
                    <table class="cust-table" data-crm-table data-search-init="<?= e($fq) ?>">
                        <colgroup>
                            <col style="width:12%"> <!-- Customer -->
                            <col style="width:5%"> <!-- City -->
                            <col style="width:7%"> <!-- Category -->
                            <col style="width:10%"> <!-- Source -->
                            <col style="width:8%"> <!-- Registered -->
                            <col style="width:3%"> <!-- Invoices -->
                            <col style="width:8%"> <!-- Upcoming -->
                            <col style="width:7%"> <!-- Follow-up -->
                            <col style="width:8%"> <!-- WhatsApp -->
                            <col style="width:10%"> <!-- Actions -->
                        </colgroup>
                        <thead>
                            <tr>
                              
                                <th>Customer</th>
                                <th data-filter>City</th>
                                <th data-filter>Category</th>
                                <th data-filter>Source</th>
                                <th>Reg.</th>
                                <th>Inv.</th>
                                <th>Upcoming</th>
                                <th>Follow-up</th>
                                <th>WhatsApp</th>
                                <th class="act">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            
                            foreach ($rows as $r):
                                $id  = (int)$r['id'];
                                $bd  = daysUntil($r['dob']);
                                $an  = daysUntil($r['anniversary']);
                                $up  = [];
                                if ($bd !== null && $bd <= 30) {
                                    $up[] = '<i class="bi bi-gift text-gold" title="Birthday"></i> ' . e(whenText($bd));
                                }
                                if ($an !== null && $an <= 30) {
                                    $up[] = '<i class="bi bi-heart text-gold" title="Anniversary"></i> ' . e(whenText($an));
                                }
                                $rc = (int)$r['remark_count'];
                                $bc = (int)$r['bill_count'];
                            ?>
                                <tr data-row="<?= $id ?>" <?= $hiId === $id ? ' class="table-active"' : '' ?>>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="av"><?= e(initials($r['name'])) ?></span>
                                            <div>
                                                <button type="button" class="cust-link" data-action="bills" data-id="<?= $id ?>" data-name="<?= e($r['name']) ?>" data-mobile="<?= e($r['mobile']) ?>" title="Show all invoices"><?= e($r['name']) ?></button>
                                                <div><small class="text-muted"><?= e($r['mobile']) ?></small></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= e($r['city']) ?></td>
                                    <td><span class="badge-s <?= badgeClass((string)$r['category']) ?>"><?= e($r['category']) ?></span></td>
                                    <td><?= sourceCell((string)$r['source'], $r['api_id']) ?></td>
                                    <td><?= e(fmtDate($r['registration_date'])) ?></td>
                                    <td>
                                        <?php if ($bc > 0): ?>
                                            <button type="button" class="btn btn-link p-0 text-decoration-none" data-action="bills" data-id="<?= $id ?>" data-name="<?= e($r['name']) ?>" data-mobile="<?= e($r['mobile']) ?>">
                                                <b><?= $bc ?></b> bill<?= $bc === 1 ? '' : 's' ?>
                                            </button><br>
                                            <small class="text-muted"><?= e($sym . number_format((float)$r['bill_total'])) ?></small>
                                        <?php else: ?>
                                            <span class="badge-s b-amber">Enquiry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $up ? implode('<br>', $up) : '<span class="text-muted">-</span>' ?></td>
                                    <td data-fu><?= followupCell($r['next_followup'], $r['last_contact']) ?></td>
                                    <td data-wa><?= waCell($r) ?></td>
                                    <td class="act">
                                        <button class="btn btn-sm btn-icon" data-action="view" data-id="<?= $id ?>" title="View" aria-label="View"><i class="bi bi-eye"></i></button>
                                        <button class="btn btn-sm btn-icon" data-action="bills" data-id="<?= $id ?>" data-name="<?= e($r['name']) ?>" data-mobile="<?= e($r['mobile']) ?>" title="Invoices" aria-label="Invoices"><i class="bi bi-receipt"></i></button>
                                        <button class="btn btn-sm btn-icon position-relative" data-action="remarks" data-id="<?= $id ?>" data-name="<?= e($r['name']) ?>" data-mobile="<?= e($r['mobile']) ?>" title="Remarks &amp; follow-up" aria-label="Remarks">
                                            <i class="bi bi-chat-dots"></i><span class="rbadge<?= $rc ? '' : ' d-none' ?>" data-rcount><?= $rc ?></span>
                                        </button>
                                        <?php if (crm_can('customers.edit')): ?><button class="btn btn-sm btn-icon" data-action="edit" data-id="<?= $id ?>" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></button><?php endif; ?>
                                        <?php if (crm_can('customers.delete')): ?><button class="btn btn-sm btn-icon text-danger" data-action="delete" data-id="<?= $id ?>" data-name="<?= e($r['name']) ?>" title="Delete" aria-label="Delete"><i class="bi bi-trash"></i></button><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!$rows): ?>
                    <div class="empty text-center">
                        <i class="bi bi-inbox fs-1"></i>
                        <p class="mb-0">
                            <?php if ($hasFilt): ?>
                                Nothing matches your search or filters. Clear them to see all records.
                            <?php elseif ($ft === 'enquiry'): ?>
                                No enquiry customers. Every customer already has an invoice.
                            <?php elseif ($ft === 'customer'): ?>
                                No customer has an invoice yet. Check the <b>Enquiry</b> tab for new customers.
                            <?php else: ?>
                                No customers yet. Click "Add customer" to register the first one.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($pages > 1):
                    $startP = max(1, $page - 2);
                    $endP   = min($pages, $page + 2);
                ?>
                    <nav class="d-flex justify-content-center mt-3" aria-label="Pagination">
                        <ul class="pagination mb-0">
                            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="customers.php<?= e(qs(['page' => $page - 1])) ?>">Previous</a></li>
                            <?php if ($startP > 1): ?>
                                <li class="page-item"><a class="page-link" href="customers.php<?= e(qs(['page' => 1])) ?>">1</a></li>
                                <?php if ($startP > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($i = $startP; $i <= $endP; $i++): ?>
                                <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="customers.php<?= e(qs(['page' => $i])) ?>"><?= $i ?></a></li>
                            <?php endfor; ?>
                            <?php if ($endP < $pages): ?>
                                <?php if ($endP < $pages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                <li class="page-item"><a class="page-link" href="customers.php<?= e(qs(['page' => $pages])) ?>"><?= $pages ?></a></li>
                            <?php endif; ?>
                            <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="customers.php<?= e(qs(['page' => $page + 1])) ?>">Next</a></li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- View modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light me-auto" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-secondary" id="viewBillsBtn"><i class="bi bi-receipt me-1"></i>Invoices</button>
                    <button type="button" class="btn btn-outline-success" id="viewRemarkBtn"><i class="bi bi-chat-dots me-1"></i>Remarks</button>
                    <button type="button" class="btn btn-primary" id="viewEditBtn"><i class="bi bi-pencil me-1"></i>Edit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editTitle">Edit customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="editBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold" id="editSave" form="editForm" disabled>Save changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer invoices modal -->
    <div class="modal fade" id="invListModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
            <div class="modal-content">
                <div class="modal-header py-2 align-items-center">
                    <span class="av" id="ilAv">?</span>
                    <div class="ms-2 me-auto">
                        <div class="fw-semibold" id="ilName">Customer</div>
                        <small class="text-muted" id="ilMobile"></small>
                    </div>
                    <a href="create-bill.php" class="btn btn-sm btn-gold me-2"><i class="bi bi-plus-lg me-1"></i>Create bill</a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="ilBody"></div>
            </div>
        </div>
    </div>

    <!-- Single invoice modal -->
    <div class="modal fade" id="invModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="invTitle">Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="invBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light me-auto" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" data-ia="wa"><i class="bi bi-whatsapp me-1"></i>Send on WhatsApp</button>
                    <button type="button" class="btn btn-primary" data-ia="print"><i class="bi bi-printer me-1"></i>Print invoice</button>
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
                    <a id="rmWa" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success me-2" title="Open WhatsApp"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="chat-body" id="rmChat"></div>

                <form id="remarkForm" class="chat-compose" novalidate>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <small class="text-muted me-1">Talked via:</small>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Type">
                            <?php foreach (REMARK_TYPES as $i => $t): ?>
                                <input type="radio" class="btn-check" name="rtype" id="rt<?= $i ?>" value="<?= e($t) ?>" autocomplete="off" <?= $i === 0 ? ' checked' : '' ?>>
                                <label class="btn btn-outline-secondary" for="rt<?= $i ?>" style="font-size:12px;"><i class="bi bi-<?= remarkIcon($t) ?> me-1"></i><?= e($t) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="quick-chips" aria-label="Quick replies">
                        <?php foreach (QUICK_REPLIES as $q): ?>
                            <button type="button" class="btn btn-outline-secondary" data-quick="<?= e($q) ?>"><?= e($q) ?></button>
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

    <!-- Delete customer modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <div class="ic-warn"><i class="bi bi-exclamation-triangle"></i></div>
                    <h5 class="mt-3">Delete customer?</h5>
                    <p class="text-muted mb-4" id="deleteText"></p>
                    <button type="button" class="btn btn-light me-2" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="deleteOk">Yes, delete</button>
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
            const CSRF = <?= json_encode($csrf) ?>;
            const SHOP = <?= json_encode($SHOP, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            const $ = (s, r = document) => r.querySelector(s);

            const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
            const editModal = new bootstrap.Modal(document.getElementById('editModal'));
            const delModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            const rmModal = new bootstrap.Modal(document.getElementById('remarkModal'));
            const listEl = document.getElementById('invListModal');
            const invEl = document.getElementById('invModal');
            const listModal = new bootstrap.Modal(listEl);
            const invModal = new bootstrap.Modal(invEl);

            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));

            /* ---- small toast ---- */
            function toast(msg, type) {
                type = type || 'success';
                const el = document.createElement('div');
                el.className = 'toast crm-toast ' + type;
                el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button class="btn-close btn-close-white m-auto me-2" data-bs-dismiss="toast" aria-label="Close"></button></div>';
                el.querySelector('.toast-body').textContent = msg; // textContent: safe
                $('#pageToasts').append(el);
                new bootstrap.Toast(el, {
                    delay: 3500
                }).show();
                el.addEventListener('hidden.bs.toast', () => el.remove());
            }

            /* message that survives a page reload */
            const flash = sessionStorage.getItem('cust_flash');
            if (flash) {
                sessionStorage.removeItem('cust_flash');
                toast(flash);
            }

            /* ---- JSON call to this same file ---- */
            async function call(query, body) {
                const opt = {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                };
                if (body) {
                    body.set('csrf_token', CSRF);
                    opt.method = 'POST';
                    opt.body = body;
                }
                try {
                    const res = await fetch('customers.php?' + new URLSearchParams(query), opt);
                    const j = await res.json().catch(() => null);
                    return j || {
                        success: false,
                        message: 'Server returned an invalid response (HTTP ' + res.status + ').'
                    };
                } catch (e) {
                    return {
                        success: false,
                        message: 'Network error. Please check your connection.'
                    };
                }
            }

            const spinner = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading...</div>';

            /* ======================= WHATSAPP welcome message (send / send again) ======================= */
            async function waResend(btn, id) {
                if (btn.dataset.again === '1' && !confirm('The WhatsApp welcome message was already sent. Send it again?')) return;
                const old = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
                const fd = new FormData();
                fd.set('id', id);
                const j = await call({
                    api: 'wa_resend'
                }, fd);
                const tr = document.querySelector('tr[data-row="' + id + '"]');
                const cell = tr ? tr.querySelector('[data-wa]') : null;
                if (j.wa_html && cell) {
                    cell.innerHTML = j.wa_html; // new badge + button from the server
                } else {
                    btn.disabled = false;
                    btn.innerHTML = old;
                }
                toast(j.message || (j.success ? 'Done.' : 'Could not send the message.'), j.success ? 'success' : 'error');
            }

            /* ---- view ---- */
            let currentId = 0,
                currentName = '',
                currentMobile = '';
            async function openView(id) {
                currentId = id;
                $('#viewBody').innerHTML = spinner;
                $('#viewModal .modal-title').textContent = 'Customer';
                viewModal.show();
                const j = await call({
                    api: 'view',
                    id: id
                });
                if (!j.success) {
                    viewModal.hide();
                    toast(j.message || 'Could not load customer.', 'error');
                    return;
                }
                currentName = j.name || '';
                currentMobile = j.mobile || '';
                $('#viewModal .modal-title').textContent = j.name;
                $('#viewBody').innerHTML = j.html;
            }

            /* ---- edit ---- */
            async function openEdit(id) {
                currentId = id;
                $('#editBody').innerHTML = spinner;
                $('#editSave').disabled = true;
                editModal.show();
                const j = await call({
                    api: 'edit',
                    id: id
                });
                if (!j.success) {
                    editModal.hide();
                    toast(j.message || 'Could not load customer.', 'error');
                    return;
                }
                $('#editTitle').textContent = 'Edit: ' + j.name;
                $('#editBody').innerHTML = j.html;
                $('#editSave').disabled = false;
            }

            $('#viewEditBtn').addEventListener('click', () => {
                const id = currentId;
                $('#viewModal').addEventListener('hidden.bs.modal', () => openEdit(id), {
                    once: true
                });
                viewModal.hide();
            });

            $('#viewRemarkBtn').addEventListener('click', () => {
                const id = currentId,
                    nm = currentName,
                    mb = currentMobile;
                $('#viewModal').addEventListener('hidden.bs.modal', () => openRemarks(id, nm, mb), {
                    once: true
                });
                viewModal.hide();
            });

            $('#viewBillsBtn').addEventListener('click', () => {
                const id = currentId,
                    nm = currentName,
                    mb = currentMobile;
                $('#viewModal').addEventListener('hidden.bs.modal', () => openBills(id, nm, mb), {
                    once: true
                });
                viewModal.hide();
            });

            document.addEventListener('submit', async e => {
                if (e.target.id !== 'editForm') return;
                e.preventDefault();
                const f = e.target;
                f.classList.add('was-validated');
                if (!f.checkValidity()) {
                    toast('Please fill the highlighted fields.', 'error');
                    const x = f.querySelector(':invalid');
                    if (x) x.focus();
                    return;
                }
                const btn = $('#editSave');
                btn.disabled = true;
                btn.textContent = 'Saving...';
                const j = await call({
                    api: 'update'
                }, new FormData(f));
                btn.disabled = false;
                btn.textContent = 'Save changes';
                if (j.success) {
                    sessionStorage.setItem('cust_flash', j.message || 'Customer updated.');
                    location.reload();
                } else {
                    toast(j.message || 'Could not save the customer.', 'error');
                }
            });

            /* ======================= CUSTOMER INVOICES ======================= */
            const money = n => SHOP.sym + (+n || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            const inr = n => SHOP.sym + Math.round(+n || 0).toLocaleString('en-IN');
            const TONE = {
                Paid: 'green',
                Pending: 'amber',
                Partial: 'amber'
            };
            const badge = s => '<span class="badge-s b-' + (TONE[s] || 'gray') + '">' + esc(s) + '</span>';

            /* invoice template (same layout as billing.php) */
            const num = n => {
                n = Math.round(n);
                if (!n) return 'Zero';
                const a = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'],
                    b = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
                const two = x => x < 20 ? a[x] : b[Math.floor(x / 10)] + (x % 10 ? ' ' + a[x % 10] : '');
                const three = x => (x >= 100 ? a[Math.floor(x / 100)] + ' Hundred' + (x % 100 ? ' ' : '') : '') + (x % 100 ? two(x % 100) : '');
                const parts = [];
                [
                    [1e7, 'Crore'],
                    [1e5, 'Lakh'],
                    [1e3, 'Thousand']
                ].forEach(([d, l]) => {
                    const q = Math.floor(n / d);
                    if (q) {
                        parts.push(three(q) + ' ' + l);
                        n %= d;
                    }
                });
                if (n) parts.push(three(n));
                return parts.join(' ');
            };
            const words = n => {
                const r = Math.round(n * 100),
                    major = Math.floor(r / 100),
                    minor = r % 100;
                return num(major) + ' ' + SHOP.word + (minor ? ' and ' + num(minor) + ' ' + SHOP.minor : '') + ' Only';
            };
            const invoice = o => {
                const total = (typeof o.total === 'number' && o.total > 0) ? o.total : o.sub - o.disc + o.gst,
                    ro = total - (o.sub - o.disc + o.gst),
                    st = (o.status || 'Paid').toLowerCase();
                const rows = o.items.map((it, i) => `<tr><td>${i + 1}</td><td><b>${esc(it.name)}</b>${it.sku ? `<small>SKU: ${esc(it.sku)}</small>` : ''}${it.variant ? `<small>${esc(it.variant)}</small>` : ''}</td><td class="r">${it.qty}</td><td class="r">${money(it.rate)}</td><td class="r">${it.disc}%</td><td class="r">${it.gst}%</td><td class="r"><b>${money(it.amt)}</b></td></tr>`).join('');
                const logo = SHOP.logo ? `<span class="inv-logo" style="background:#fff;padding:3px;overflow:hidden"><img src="${esc(SHOP.logo)}" alt="" style="width:100%;height:100%;object-fit:contain"></span>` : `<span class="inv-logo">${esc(SHOP.name.charAt(0))}</span>`;
                const terms = SHOP.terms ? esc(SHOP.terms).replace(/\r?\n/g, '<br>') : '';
                return `<div class="inv-wrap"><div class="inv">
<div class="inv-top"><div class="inv-brand">${logo}<div><h3>${esc(SHOP.name)}</h3><small>${esc(SHOP.addr)}<br>${SHOP.gstin ? 'GSTIN ' + esc(SHOP.gstin) + ' &middot; ' : ''}${esc(SHOP.phone)}</small></div></div>
<div class="inv-title"><div class="t">TAX INVOICE</div><div>${esc(o.no)}</div><div>${esc(o.date)}</div></div></div>
<div class="inv-stamp ${st}">${esc((o.status || 'Paid').toUpperCase())}</div>
<div class="inv-body">
<div class="inv-meta"><div class="inv-box"><div class="k">Billed to</div><div class="v">${esc(o.cust)}</div><small>${esc(o.contact)}</small></div>
<div class="inv-box"><div class="k">Invoice details</div><div class="v">${esc(o.no)}</div><small>Date: ${esc(o.date)}</small></div>
<div class="inv-box"><div class="k">Payment</div><div class="v">${esc(o.method)} <span class="pill ${st}">${esc(o.status || 'Paid')}</span></div>${SHOP.pos ? `<small>Place of supply: ${esc(SHOP.pos)}</small>` : ''}</div></div>
<div class="inv-scroll"><table class="inv-table"><thead><tr><th>#</th><th>Item description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Disc</th><th class="r">GST</th><th class="r">Amount</th></tr></thead><tbody>${rows}</tbody></table></div>
<div class="inv-foot"><div class="inv-note"><div class="k">Amount in words</div><div class="inv-words">${words(total)}</div>
${terms ? `<div class="k">Terms &amp; conditions</div><small class="text-muted d-block">${terms}</small>` : ''}</div>
<div class="inv-tot"><div><span>Subtotal</span><span>${money(o.sub)}</span></div><div class="disc"><span>Discount</span><span>- ${money(o.disc)}</span></div><div><span>CGST</span><span>${money(o.gst / 2)}</span></div><div><span>SGST</span><span>${money(o.gst / 2)}</span></div>${Math.abs(ro) >= 0.005 ? `<div><span>Round off</span><span>${ro > 0 ? '+ ' : '- '}${money(Math.abs(ro))}</span></div>` : ''}<div class="g"><span>Grand total</span><span>${money(total)}</span></div></div></div>
<div class="inv-bottom"><small class="text-muted">This is a computer generated invoice.<br>${esc(SHOP.email)}</small><div class="inv-sign"><div class="sg">${esc(SHOP.name.split(' ')[0])}</div><div class="ln">Authorised signatory</div></div></div></div>
${SHOP.footer ? `<div class="inv-thanks">${esc(SHOP.footer)}</div>` : ''}</div></div>`;
            };

            /* WhatsApp message text of an invoice */
            function waText(o) {
                const L = [];
                L.push('*' + SHOP.name + '*');
                L.push('Invoice: *' + o.no + '*');
                L.push('Date: ' + o.date);
                L.push('Customer: ' + o.cust);
                L.push('');
                o.items.forEach((it, i) => L.push((i + 1) + '. ' + it.name + (it.variant ? ' (' + it.variant + ')' : '') + ' x ' + it.qty + ' = ' + money(it.amt)));
                L.push('');
                L.push('*Total: ' + money(o.total) + '*');
                L.push('Payment: ' + o.method + ' (' + (o.status || 'Paid') + ')');
                L.push('');
                L.push(SHOP.footer || 'Thank you for shopping with us!');
                return L.join('\n');
            }

            const waNum = m => {
                const d = String(m || '').replace(/\D+/g, '');
                if (d.length === 10) return '91' + d;
                if (d.length === 11 && d[0] === '0') return '91' + d.slice(1);
                return d.length >= 12 ? d : '';
            };

            let cust = {
                    id: 0,
                    name: '',
                    mobile: ''
                },
                dirty = false,
                returnToList = false,
                curInv = null;

            async function openBills(id, name, mobile) {
                cust = {
                    id: id,
                    name: name || '',
                    mobile: mobile || ''
                };
                dirty = false;
                $('#ilName').textContent = cust.name || 'Customer';
                $('#ilMobile').textContent = cust.mobile ? '+91 ' + cust.mobile : '';
                $('#ilAv').textContent = ((cust.name || '?').trim().split(/\s+/).slice(0, 2).map(w => w.charAt(0)).join('') || '?').toUpperCase();
                $('#ilBody').innerHTML = spinner;
                listModal.show();
                await loadBills();
            }

            async function loadBills() {
                const j = await call({
                    api: 'cust_bills',
                    id: cust.id
                });
                if (!j.success) {
                    $('#ilBody').innerHTML = '<div class="text-center text-danger py-4">' + esc(j.message || 'Could not load invoices.') + '</div>';
                    return;
                }
                cust.mobile = j.mobile || cust.mobile;
                if (!j.bills.length) {
                    $('#ilBody').innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1"></i>' +
                        '<p class="mb-2">No invoice yet for this customer.<br><span class="badge-s b-amber">Enquiry</span></p>' +
                        '<a class="btn btn-gold btn-sm" href="create-bill.php"><i class="bi bi-plus-lg me-1"></i>Create first bill</a></div>';
                    return;
                }
                const rows = j.bills.map(b => '<tr data-bid="' + b.id + '">' +
                    '<td><b>' + esc(b.no) + '</b></td>' +
                    '<td>' + esc(b.date) + '</td>' +
                    '<td>' + b.items + '</td>' +
                    '<td><b>' + inr(b.amount) + '</b></td>' +
                    '<td>' + esc(b.method) + '</td>' +
                    '<td>' + badge(b.status) + '</td>' +
                    '<td class="text-end text-nowrap">' +
                    '<button class="btn btn-sm btn-icon" data-bact="view" title="View invoice" aria-label="View invoice"><i class="bi bi-receipt"></i></button>' +
                    '<button class="btn btn-sm btn-icon" data-bact="print" title="Print invoice" aria-label="Print invoice"><i class="bi bi-printer"></i></button>' +
                    '<button class="btn btn-sm btn-icon text-success" data-bact="wa" title="Send invoice on WhatsApp" aria-label="Send on WhatsApp"><i class="bi bi-whatsapp"></i></button>' +
                    '<button class="btn btn-sm btn-icon text-danger" data-bact="del" title="Delete invoice" aria-label="Delete invoice"><i class="bi bi-trash"></i></button>' +
                    '</td></tr>').join('');
                $('#ilBody').innerHTML =
                    '<div class="d-flex flex-wrap gap-2 mb-3">' +
                    '<span class="badge-s b-blue">Bills: ' + j.bills.length + '</span>' +
                    '<span class="badge-s b-green">Total billed: ' + inr(j.total) + '</span>' +
                    '<span class="badge-s ' + (j.pending > 0 ? 'b-amber' : 'b-gray') + '">Pending: ' + inr(j.pending) + '</span></div>' +
                    '<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr>' +
                    '<th>Invoice</th><th>Date</th><th>Items</th><th>Amount</th><th>Method</th><th>Status</th><th class="text-end">Actions</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table></div>';
            }

            async function getInvoice(id) {
                const j = await call({
                    api: 'bill_get',
                    id: id
                });
                if (!j.success) {
                    toast(j.message || 'Could not load the invoice.', 'error');
                    return null;
                }
                return j.invoice;
            }

            /* open WhatsApp with the invoice text (window opened first so the browser does not block it) */
            async function sendInvoiceWa(id, preloaded) {
                const n = waNum(cust.mobile);
                if (!n) {
                    toast('This customer has no valid mobile number.', 'error');
                    return;
                }
                const w = window.open('', '_blank');
                const o = preloaded || await getInvoice(id);
                if (!o) {
                    if (w) w.close();
                    return;
                }
                const url = 'https://wa.me/' + n + '?text=' + encodeURIComponent(waText(o));
                if (w) w.location.href = url;
                else window.location.href = url;
            }

            function printInvoice(o) {
                $('#printArea').innerHTML = invoice(o);
                window.print();
            }

            async function showInvoice(id) {
                const o = await getInvoice(id);
                if (!o) return;
                curInv = o;
                $('#invTitle').textContent = 'Invoice ' + o.no;
                $('#invBody').innerHTML = invoice(o);
                if (listEl.classList.contains('show')) {
                    returnToList = true;
                    listEl.addEventListener('hidden.bs.modal', () => invModal.show(), {
                        once: true
                    });
                    listModal.hide();
                } else {
                    invModal.show();
                }
            }

            /* closing the single invoice goes back to the customer's list */
            invEl.addEventListener('hidden.bs.modal', () => {
                if (returnToList) {
                    returnToList = false;
                    listModal.show();
                    loadBills();
                }
            });
            /* if invoices were deleted, refresh the page numbers when the list closes */
            listEl.addEventListener('hidden.bs.modal', () => {
                if (dirty && !returnToList) location.reload();
            });

            $('#ilBody').addEventListener('click', async e => {
                const b = e.target.closest('[data-bact]');
                if (!b) return;
                const tr = b.closest('tr[data-bid]');
                const id = tr ? parseInt(tr.dataset.bid, 10) : 0;
                const no = tr ? tr.querySelector('b').textContent : '';
                const act = b.dataset.bact;

                if (act === 'view') {
                    showInvoice(id);
                } else if (act === 'print') {
                    const o = await getInvoice(id);
                    if (o) printInvoice(o);
                } else if (act === 'wa') {
                    sendInvoiceWa(id);
                } else if (act === 'del') {
                    if (!confirm('Delete invoice ' + no + '?\nThe sold pieces go back to stock. This cannot be undone.')) return;
                    b.disabled = true;
                    const fd = new FormData();
                    fd.set('id', id);
                    const j = await call({
                        api: 'bill_delete'
                    }, fd);
                    if (j.success) {
                        dirty = true;
                        toast('Invoice ' + no + ' deleted.');
                        loadBills();
                    } else {
                        b.disabled = false;
                        toast(j.message || 'Could not delete the invoice.', 'error');
                    }
                }
            });

            invEl.addEventListener('click', e => {
                const b = e.target.closest('[data-ia]');
                if (!b || !curInv) return;
                if (b.dataset.ia === 'print') printInvoice(curInv);
                else if (b.dataset.ia === 'wa') sendInvoiceWa(curInv.id, curInv);
            });

            /* ======================= REMARKS (WhatsApp style chat) ======================= */
            let rmId = 0,
                rmNow = ''; // rmNow = server time in IST ("YYYY-MM-DD HH:MM")

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
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            }

            /* update chat + the table row + the "Follow-ups due" card */
            function applyRemarks(j) {
                chatBox().innerHTML = j.html;
                scrollChat();
                const tr = document.querySelector('tr[data-row="' + rmId + '"]');
                if (tr) {
                    const b = tr.querySelector('[data-rcount]');
                    if (b) {
                        b.textContent = j.count;
                        b.classList.toggle('d-none', !j.count);
                    }
                    const fu = tr.querySelector('[data-fu]');
                    if (fu) fu.innerHTML = j.fu_html;
                }
                if (typeof j.due !== 'undefined') {
                    const d = $('#dueCount');
                    if (d) d.textContent = j.due;
                }
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

            /* save a remark */
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

            /* Ctrl + Enter = save */
            $('#rmText').addEventListener('keydown', e => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    $('#remarkForm').requestSubmit();
                }
            });

            /* quick reply chips + follow-up shortcuts */
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

            /* mark follow-up done / delete a remark (inside the chat) */
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

            /* ---- delete customer ---- */
            let delId = 0;
            $('#deleteOk').addEventListener('click', async () => {
                const btn = $('#deleteOk');
                btn.disabled = true;
                const fd = new FormData();
                fd.set('id', delId);
                const j = await call({
                    api: 'delete'
                }, fd);
                btn.disabled = false;
                if (j.success) {
                    delModal.hide();
                    sessionStorage.setItem('cust_flash', j.message || 'Customer deleted.');
                    location.reload();
                } else {
                    delModal.hide();
                    toast(j.message || 'Could not delete the customer.', 'error');
                }
            });

            /* ---- row buttons ---- */
            document.addEventListener('click', e => {
                const b = e.target.closest('[data-action]');
                if (!b) return;
                const id = parseInt(b.dataset.id, 10);
                if (b.dataset.action === 'view') openView(id);
                else if (b.dataset.action === 'edit') openEdit(id);
                else if (b.dataset.action === 'bills') openBills(id, b.dataset.name, b.dataset.mobile);
                else if (b.dataset.action === 'remarks') openRemarks(id, b.dataset.name, b.dataset.mobile);
                else if (b.dataset.action === 'waresend') waResend(b, id);
                else if (b.dataset.action === 'delete') {
                    delId = id;
                    $('#deleteText').textContent = 'Delete ' + (b.dataset.name || 'this customer') + '? Their additional field values and all remarks will also be deleted. This cannot be undone.';
                    delModal.show();
                }
            });
        })();
    </script>
<?php crm_foot(); ?>
</body>

</html>