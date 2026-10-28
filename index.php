<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/*
|--------------------------------------------------------------------------
| index.php - Admin Dashboard (dynamic data + date filter)
|--------------------------------------------------------------------------
| Reads   : customers (id, name, mobile, city, dob, anniversary, created_at optional)
|           reminder_log, reminder_events  (created by reminders.php)
| Browser : billing.php?api=summary      -> Today's billing, Monthly sales, Pending payments
|           products.php?api=low_count   -> Low / out of stock products
| Filter  : index.php?from=YYYY-MM-DD&to=YYYY-MM-DD   (default = this month)
|--------------------------------------------------------------------------
| Sections that are still demo (sales trend, categories, inquiries, tasks,
| activity) are removed. They will be added back when their data is ready.
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Kolkata');

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

require_once __DIR__ . '/config.php';          // must create $conn = mysqli_connect(...)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+05:30'");

const MAX_RANGE_YEARS = 3;
const LIST_LIMIT      = 8;

/* ------------------------------------------------------------------ helpers */

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function cleanValue($value): string
{
    return is_array($value) ? '' : trim((string)($value ?? ''));
}

function safeDate(string $v): string
{
    if ($v === '') {
        return '';
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
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

function dbOne(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = dbAll($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

/* a table that may not exist yet (created later by reminders.php): treat as empty */
function dbAllSafe(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    try {
        return dbAll($conn, $sql, $types, $params);
    } catch (mysqli_sql_exception $ex) {
        if ((int)$ex->getCode() === 1146) {
            return [];
        }
        throw $ex;
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

/* ---- small HTML builders (same markup the old dashboard used) ---- */

function statCard(string $href, string $icon, string $value, string $label, string $k = ''): string
{
    return '<div class="col"><a class="stat" href="' . e($href) . '"><span class="ico"><i class="bi bi-' . e($icon) . '"></i></span>'
        . '<div><b' . ($k !== '' ? ' data-k="' . e($k) . '"' : '') . '>' . e($value) . '</b><small>' . e($label) . '</small></div></a></div>';
}

function listRow(string $avatarHtml, string $title, string $sub, string $rightHtml): string
{
    return '<li class="mini">' . $avatarHtml
        . '<div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate">' . e($title) . '</div><small class="text-muted">' . e($sub) . '</small></div>'
        . '<div class="text-end">' . $rightHtml . '</div></li>';
}

function rupees(float $n): string
{
    return '₹' . number_format($n, 0, '.', ',');
}

function avatarInitials(string $name): string
{
    return '<span class="av">' . e(initials($name)) . '</span>';
}

function avatarIcon(string $icon): string
{
    return '<span class="av ic"><i class="bi bi-' . e($icon) . '"></i></span>';
}

function whenHtml(string $date): string
{
    return '<small class="fw-semibold">' . e(whenText(daysDiff($date))) . '</small>';
}

function sentBadge(): string
{
    return '<span class="badge-s b-green">Sent</span>';
}

function emptyRow(string $text): string
{
    return '<li class="mini text-muted small">' . e($text) . '</li>';
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

function fetchDateCustomers(mysqli $conn, string $col): array
{
    $col = $col === 'anniversary' ? 'anniversary' : 'dob';
    return dbAll($conn, "SELECT id, name, mobile, city, $col AS d FROM customers WHERE $col IS NOT NULL AND $col > '1000-01-01'");
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
                'type'   => $type,
                'n'      => $y - (int)substr($orig, 0, 4),
            ];
        }
    }
    return $out;
}

function sentMap(mysqli $conn, string $type, string $from, string $to): array
{
    $m    = [];
    $rows = dbAllSafe(
        $conn,
        'SELECT customer_id, occ_date FROM reminder_log WHERE rtype = ? AND occ_date BETWEEN ? AND ?',
        'sss',
        [$type, $from, $to]
    );
    foreach ($rows as $r) {
        $m[$r['customer_id'] . '|' . $r['occ_date']] = true;
    }
    return $m;
}

function sortByDate(array &$list): void
{
    usort($list, function ($a, $b) {
        return [$a['date'], $a['name']] <=> [$b['date'], $b['name']];
    });
}

/*
|--------------------------------------------------------------------------
| Date filter (default = this month)
|--------------------------------------------------------------------------
*/
$today = date('Y-m-d');
$ffrom = safeDate(cleanValue($_GET['from'] ?? ''));
$fto   = safeDate(cleanValue($_GET['to'] ?? ''));

if ($ffrom === '' && $fto === '') {
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
} else {
    $from = $ffrom !== '' ? $ffrom : $fto;
    $to   = $fto !== '' ? $fto : $ffrom;
}
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$rangeNote = '';
$maxTo     = date('Y-m-d', strtotime($from . ' +' . MAX_RANGE_YEARS . ' years'));
if ($to > $maxTo) {
    $to        = $maxTo;
    $rangeNote = 'Date range is limited to ' . MAX_RANGE_YEARS . ' years. Showing ' . fmtDate($from) . ' to ' . fmtDate($to) . '.';
}
$isDefault = ($from === date('Y-m-01') && $to === date('Y-m-t'));

/*
|--------------------------------------------------------------------------
| Dashboard data
|--------------------------------------------------------------------------
*/
$loadError    = '';
$totalCust    = 0;
$hasCreated   = false;
$newCust      = 0;
$recent       = [];
$bd           = [];
$an           = [];
$toSend       = [];
$events       = [];
$eventsTotal  = 0;
$todayBdays   = [];
$bdSent       = 0;
$anSent       = 0;

try {
    $totalCust  = (int)(dbOne($conn, 'SELECT COUNT(*) AS n FROM customers')['n'] ?? 0);
    $colRes     = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'created_at'");
    $hasCreated = $colRes && mysqli_num_rows($colRes) > 0;

    if ($hasCreated) {
        $newCust = (int)(dbOne(
            $conn,
            'SELECT COUNT(*) AS n FROM customers WHERE DATE(created_at) BETWEEN ? AND ?',
            'ss',
            [$from, $to]
        )['n'] ?? 0);
        $recent = dbAll($conn, 'SELECT id, name, mobile, city, created_at FROM customers ORDER BY id DESC LIMIT 5');
    } else {
        $recent = dbAll($conn, 'SELECT id, name, mobile, city FROM customers ORDER BY id DESC LIMIT 5');
    }

    /* birthdays + anniversaries inside the selected range */
    $dobCust = fetchDateCustomers($conn, 'dob');
    $annCust = fetchDateCustomers($conn, 'anniversary');

    $sentB = sentMap($conn, 'Birthday', $from, $to);
    foreach (buildOccurrences($dobCust, 'Birthday', $from, $to) as $o) {
        $o['sent'] = isset($sentB[$o['id'] . '|' . $o['date']]);
        $bd[]      = $o;
    }
    $sentA = sentMap($conn, 'Anniversary', $from, $to);
    foreach (buildOccurrences($annCust, 'Anniversary', $from, $to) as $o) {
        $o['sent'] = isset($sentA[$o['id'] . '|' . $o['date']]);
        $an[]      = $o;
    }
    sortByDate($bd);
    sortByDate($an);

    foreach ($bd as $o) {
        if ($o['sent']) {
            $bdSent++;
        } else {
            $toSend[] = $o;
        }
    }
    foreach ($an as $o) {
        if ($o['sent']) {
            $anSent++;
        } else {
            $toSend[] = $o;
        }
    }
    sortByDate($toSend);

    /* today's birthdays (always today, not affected by the filter) */
    foreach (buildOccurrences($dobCust, 'Birthday', $today, $today) as $o) {
        $todayBdays[] = $o['name'];
    }

    /* store events inside the range */
    $eventsTotal = (int)(dbAllSafe(
        $conn,
        'SELECT COUNT(*) AS n FROM reminder_events WHERE event_date BETWEEN ? AND ?',
        'ss',
        [$from, $to]
    )[0]['n'] ?? 0);
    $events = dbAllSafe(
        $conn,
        'SELECT id, title, event_date, details FROM reminder_events WHERE event_date BETWEEN ? AND ? ORDER BY event_date ASC, id ASC LIMIT ' . LIST_LIMIT,
        'ss',
        [$from, $to]
    );
} catch (Throwable $ex) {
    error_log('index.php dashboard: ' . $ex->getMessage());
    $loadError = 'Some dashboard data could not be loaded. Please check the database tables.';
}


/*
|--------------------------------------------------------------------------
| Billing / stock / inquiry numbers and GRAPH data (real database, selected date range)
| Everything is checked against the permissions of the logged-in employee.
|--------------------------------------------------------------------------
*/
$canBill = crm_can('billing.view');
$canProd = crm_can('products.view');
$canCust = crm_can('customers.view');
$canInq  = crm_can('inquiries.view');
$canTask = crm_can('tasks.view');
$cards   = ['today' => 0.0, 'month' => 0.0, 'pending' => 0.0, 'low' => 0, 'inq_new' => 0, 'tasks' => 0];
$DASH    = [];
try {
    if ($canBill) {
        $r = dbOne($conn, "SELECT COALESCE(SUM(CASE WHEN invoice_date = ? THEN total END),0) AS t,
                                  COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN total END),0) AS m,
                                  COALESCE(SUM(CASE WHEN payment_status <> 'Paid' THEN total END),0) AS p FROM bills",
                   'sss', [$today, date('Y-m-01'), $today]);
        $cards['today'] = (float)$r['t'];
        $cards['month'] = (float)$r['m'];
        $cards['pending'] = (float)$r['p'];

        /* sales graph: daily for up to 62 days, monthly for a longer range */
        $span  = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
        $daily = $span <= 62;
        $fmt   = $daily ? '%Y-%m-%d' : '%Y-%m';
        $sales = [];
        foreach (dbAll($conn, "SELECT DATE_FORMAT(invoice_date, '$fmt') AS k,
                                      COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN total END),0) AS paid,
                                      COALESCE(SUM(CASE WHEN payment_status <> 'Paid' THEN total END),0) AS pend
                                 FROM bills WHERE invoice_date BETWEEN ? AND ? GROUP BY k", 'ss', [$from, $to]) as $x) {
            $sales[$x['k']] = [(float)$x['paid'], (float)$x['pend']];
        }
        $labels = $paid = $pend = [];
        $cur = new DateTime($from);
        $end = new DateTime($to);
        if (!$daily) {
            $cur->modify('first day of this month');
        }
        while ($cur <= $end) {
            $k = $cur->format($daily ? 'Y-m-d' : 'Y-m');
            $labels[] = $cur->format($daily ? 'j M' : 'M Y');
            $paid[]   = $sales[$k][0] ?? 0;
            $pend[]   = $sales[$k][1] ?? 0;
            $cur->modify($daily ? '+1 day' : '+1 month');
        }
        $DASH['sales'] = ['labels' => $labels, 'paid' => $paid, 'pending' => $pend];

        $m = dbAll($conn, 'SELECT payment_method AS k, COALESCE(SUM(total),0) AS v FROM bills WHERE invoice_date BETWEEN ? AND ? GROUP BY payment_method ORDER BY v DESC', 'ss', [$from, $to]);
        $DASH['methods'] = ['labels' => array_column($m, 'k'), 'values' => array_map('floatval', array_column($m, 'v'))];

        $t = dbAll($conn, 'SELECT bi.item_name AS k, COALESCE(SUM(bi.line_total),0) AS v FROM bill_items bi JOIN bills b ON b.id = bi.bill_id
                             WHERE b.invoice_date BETWEEN ? AND ? GROUP BY bi.item_name ORDER BY v DESC LIMIT 6', 'ss', [$from, $to]);
        $DASH['top'] = ['labels' => array_column($t, 'k'), 'values' => array_map('floatval', array_column($t, 'v'))];
    }
    if ($canProd) {
        $cards['low'] = (int)(dbOne($conn, "SELECT COUNT(*) AS n FROM (SELECT p.id, p.min_stock FROM products p LEFT JOIN product_variants v ON v.product_id = p.id
                                             WHERE p.status = 'Active' GROUP BY p.id, p.min_stock HAVING COALESCE(SUM(v.stock),0) <= p.min_stock) t")['n'] ?? 0);
    }
    if ($canCust) {
        $c = dbAll($conn, 'SELECT category AS k, COUNT(*) AS v FROM customers GROUP BY category ORDER BY v DESC');
        $DASH['cat'] = ['labels' => array_column($c, 'k'), 'values' => array_map('intval', array_column($c, 'v'))];
        $c = dbAll($conn, "SELECT source AS k, COUNT(*) AS v FROM customers GROUP BY source ORDER BY v DESC LIMIT 6");
        $DASH['src'] = ['labels' => array_column($c, 'k'), 'values' => array_map('intval', array_column($c, 'v'))];
        $g = [];
        foreach (dbAll($conn, "SELECT DATE_FORMAT(registration_date, '%Y-%m') AS k, COUNT(*) AS v FROM customers WHERE registration_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY k") as $x) {
            $g[$x['k']] = (int)$x['v'];
        }
        $gl = $gv = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = new DateTime('first day of this month');
            $d->modify("-$i month");
            $gl[] = $d->format('M Y');
            $gv[] = $g[$d->format('Y-m')] ?? 0;
        }
        $DASH['growth'] = ['labels' => $gl, 'values' => $gv];
    }
    if ($canInq) {
        $cards['inq_new'] = (int)(dbOne($conn, "SELECT COUNT(*) AS n FROM inquiries WHERE status = 'New'")['n'] ?? 0);
        $c = dbAll($conn, 'SELECT status AS k, COUNT(*) AS v FROM inquiries WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status', 'ss', [$from, $to]);
        $DASH['inq'] = ['labels' => array_column($c, 'k'), 'values' => array_map('intval', array_column($c, 'v'))];
    }
    if ($canTask) {
        $cards['tasks'] = (int)(dbOne($conn, "SELECT COUNT(*) AS n FROM tasks WHERE status = 'Open' AND due_date <= CURDATE()")['n'] ?? 0);
    }
} catch (Throwable $ex) {
    error_log('index.php charts: ' . $ex->getMessage());
    $loadError = 'Some dashboard data could not be loaded. Please check the database tables.';
}

$q       = ['from' => $from, 'to' => $to];
$lnkBd   = 'reminders.php?' . http_build_query(['view' => 'birthday'] + $q);
$lnkAn   = 'reminders.php?' . http_build_query(['view' => 'anniversary'] + $q);
$lnkEv   = 'reminders.php?' . http_build_query(['view' => 'events'] + $q);
$rangeTx = fmtDate($from) . ($from === $to ? '' : ' to ' . fmtDate($to));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Dashboard | <?= crm_e(crm_brand()['name']) ?> CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap"
        rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/style.css?v=3" rel="stylesheet" />
    <style>
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

        .panel-title .view-all {
            font-size: 12px;
            text-decoration: none
        }

        .bday-banner {
            background: #fff4d6;
            border: 1px solid #f3deaa;
            color: #6b4a00;
            border-radius: 4px;
            padding: 10px 14px;
            font-size: 14px
        }
        .form-control,.form-select{
            font-size:12px;
            padding:6px 6px;
            border-color:#d5dbe6;
            border-radius:4px;
            
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
                    <h1 id="pageTitle">Dashboard</h1>
                    <p class="text-muted mb-0">A snapshot of customers, reminders and billing. Showing <b><?= e($rangeTx) ?></b>.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="create-bill.php" class="btn btn-primary"><i class="bi bi-receipt me-1"></i>Create bill</a>
                </div>
            </div>

            <?php if ($loadError !== ''): ?>
                <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($loadError) ?></div>
            <?php endif; ?>
            <?php if ($rangeNote !== ''): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-info-circle me-2"></i><?= e($rangeNote) ?></div>
            <?php endif; ?>

            <?php if ($todayBdays): ?>
                <div class="bday-banner mb-3">
                    <i class="bi bi-gift me-2"></i><b>Today's birthday:</b>
                    <?= e(implode(', ', array_slice($todayBdays, 0, 5))) ?><?= count($todayBdays) > 5 ? ' +' . (count($todayBdays) - 5) . ' more' : '' ?>
                    <a href="reminders.php?view=birthday&from=<?= e($today) ?>&to=<?= e($today) ?>" class="ms-2">Send messages</a>
                </div>
            <?php endif; ?>

            <!-- ============================ DATE FILTER ============================ -->
            <div class="panel mb-3">
                <form method="get" action="index.php" class="row g-2 align-items-end" id="dashFilter">
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label small mb-1" for="dFrom">From date</label>
                        <input type="date" class="form-control" id="dFrom" name="from" value="<?= e($from) ?>">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label small mb-1" for="dTo">To date</label>
                        <input type="date" class="form-control" id="dTo" name="to" value="<?= e($to) ?>">
                    </div>
                    <div class="col-12 col-md-4 col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Apply</button>
                        <?php if (!$isDefault): ?><a href="index.php" class="btn btn-light border" title="Back to this month"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                    </div>
                    <div class="col-12 col-lg-5 d-none">
                        <div class="range-chips">
                            <small class="text-muted me-1">Quick dates:</small>
                            <button type="button" class="btn btn-outline-secondary" data-range="today">Today</button>
                            <button type="button" class="btn btn-outline-secondary" data-range="7">Next 7 days</button>
                            <button type="button" class="btn btn-outline-secondary" data-range="30">Next 30 days</button>
                            <button type="button" class="btn btn-outline-secondary" data-range="month">This month</button>
                            <button type="button" class="btn btn-outline-secondary" data-range="last30">Last 30 days</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ============================== CARDS ============================== -->
            <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-2">
                <?php if ($canCust): ?><?= statCard('customers.php', 'people', (string)$totalCust, 'Total customers') ?><?php endif; ?>
                <?php if ($hasCreated && $canCust): ?>
                    <?= statCard('customers.php', 'person-plus', (string)$newCust, 'New customers (in range)') ?>
                <?php endif; ?>
                <?= statCard($lnkBd, 'gift', (string)count($bd), 'Birthdays (in range)') ?>
                <?= statCard($lnkAn, 'heart', (string)count($an), 'Anniversaries (in range)') ?>
                <?= statCard($lnkBd, 'bell', (string)count($toSend), 'Reminders to send') ?>
                <?= statCard($lnkEv, 'calendar-event', (string)$eventsTotal, 'Events (in range)') ?>
                <?php if ($canBill): ?>
                    <?= statCard('billing.php?view=today', 'receipt', rupees($cards['today']), "Today's billing") ?>
                    <?= statCard('billing.php?view=monthly', 'graph-up-arrow', rupees($cards['month']), 'Monthly sales') ?>
                    <?= statCard('billing.php?view=pending', 'hourglass-split', rupees($cards['pending']), 'Pending payments') ?>
                <?php endif; ?>
                <?php if ($canProd): ?><?= statCard('products.php?view=low', 'box-seam', (string)$cards['low'], 'Low / out of stock') ?><?php endif; ?>
                <?php if ($canInq): ?><?= statCard('inquiries.php?view=new', 'chat-dots', (string)$cards['inq_new'], 'New inquiries') ?><?php endif; ?>
                <?php if ($canTask): ?><?= statCard('tasks.php', 'check2-square', (string)$cards['tasks'], 'Tasks due') ?><?php endif; ?>
            </div>
            <p class="text-muted small mb-4">Today / month / pending / stock cards are live totals. Graphs, birthdays, anniversaries, reminders and events follow the selected dates.</p>


            <!-- ============================== GRAPHS ============================== -->
            <div class="row g-3 mb-3">
                <?php if ($canBill): ?>
                <div class="col-xl-8"><div class="panel"><div class="panel-title"><h5>Sales (<?= e($rangeTx) ?>)</h5><small class="text-muted">Paid vs pending, from bills</small></div>
                    <div class="chart-box"><canvas id="chSales"></canvas></div></div></div>
                <div class="col-md-6 col-xl-4"><div class="panel"><div class="panel-title"><h5>Payment methods</h5></div>
                    <div class="chart-box"><canvas id="chMethods"></canvas></div></div></div>
                <div class="col-md-6 col-xl-4"><div class="panel"><div class="panel-title"><h5>Top selling items</h5></div>
                    <div class="chart-box"><canvas id="chTop"></canvas></div></div></div>
                <?php endif; ?>
                <?php if ($canCust): ?>
                <div class="col-md-6 col-xl-4"><div class="panel"><div class="panel-title"><h5>New customers (6 months)</h5></div>
                    <div class="chart-box"><canvas id="chGrowth"></canvas></div></div></div>
                <div class="col-md-6 col-xl-4"><div class="panel"><div class="panel-title"><h5>Customers by category</h5></div>
                    <div class="chart-box"><canvas id="chCat"></canvas></div></div></div>
                <div class="col-md-6 col-xl-4"><div class="panel"><div class="panel-title"><h5>Customers by source</h5></div>
                    <div class="chart-box"><canvas id="chSrc"></canvas></div></div></div>
                <?php endif; ?>
                <?php if ($canInq): ?>
                <div class="col-md-6 col-xl-4"><div class="panel"><div class="panel-title"><h5>Inquiries by status</h5></div>
                    <div class="chart-box"><canvas id="chInq"></canvas></div></div></div>
                <?php endif; ?>
            </div>

            <!-- ============================== LISTS ============================== -->
            <div class="row g-3">
                <div class="col-md-6 col-xl-4">
                    <div class="panel">
                        <div class="panel-title"><h5>Recent customers</h5><a class="view-all" href="customers.php">View all</a></div>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($recent as $c):
                                $right = isset($c['created_at']) && $c['created_at']
                                    ? whenHtml(substr((string)$c['created_at'], 0, 10))
                                    : '<small class="text-muted">' . e($c['mobile']) . '</small>';
                            ?>
                                <?= listRow(avatarInitials((string)$c['name']), (string)$c['name'], trim((string)$c['city']) !== '' ? (string)$c['city'] : (string)$c['mobile'], $right) ?>
                            <?php endforeach; ?>
                            <?php if (!$recent) echo emptyRow('No customers yet'); ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="panel">
                        <div class="panel-title"><h5>Birthdays</h5><a class="view-all" href="<?= e($lnkBd) ?>">View all (<?= count($bd) ?>)</a></div>
                        <ul class="list-unstyled mb-0">
                            <?php foreach (array_slice($bd, 0, LIST_LIMIT) as $o):
                                $sub = fmtDate($o['date']) . ($o['n'] >= 1 && $o['n'] <= 120 ? ' · Turns ' . $o['n'] : '');
                            ?>
                                <?= listRow(avatarInitials($o['name']), $o['name'], $sub, $o['sent'] ? sentBadge() : whenHtml($o['date'])) ?>
                            <?php endforeach; ?>
                            <?php if (!$bd) echo emptyRow('No birthdays in this range'); ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="panel">
                        <div class="panel-title"><h5>Anniversaries</h5><a class="view-all" href="<?= e($lnkAn) ?>">View all (<?= count($an) ?>)</a></div>
                        <ul class="list-unstyled mb-0">
                            <?php foreach (array_slice($an, 0, LIST_LIMIT) as $o):
                                $sub = fmtDate($o['date']) . ($o['n'] >= 1 && $o['n'] <= 120 ? ' · ' . $o['n'] . ' year' . ($o['n'] === 1 ? '' : 's') : '');
                            ?>
                                <?= listRow(avatarInitials($o['name']), $o['name'], $sub, $o['sent'] ? sentBadge() : whenHtml($o['date'])) ?>
                            <?php endforeach; ?>
                            <?php if (!$an) echo emptyRow('No anniversaries in this range'); ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="panel">
                        <div class="panel-title"><h5>Reminders to send</h5><a class="view-all" href="message-settings.php#schedule">Send messages</a></div>
                        <ul class="list-unstyled mb-0">
                            <?php foreach (array_slice($toSend, 0, LIST_LIMIT) as $o): ?>
                                <?= listRow(
                                    avatarIcon($o['type'] === 'Birthday' ? 'gift' : 'heart'),
                                    $o['name'],
                                    $o['type'] . ' · ' . fmtDate($o['date']),
                                    whenHtml($o['date'])
                                ) ?>
                            <?php endforeach; ?>
                            <?php if (!$toSend) echo emptyRow('Nothing left to send in this range'); ?>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-xl-4">
                    <div class="panel">
                        <div class="panel-title"><h5>Events</h5><a class="view-all" href="<?= e($lnkEv) ?>">View all (<?= $eventsTotal ?>)</a></div>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($events as $ev):
                                $sub = fmtDate($ev['event_date']) . (trim((string)$ev['details']) !== '' ? ' · ' . mb_strimwidth((string)$ev['details'], 0, 40, '...') : '');
                            ?>
                                <?= listRow(avatarIcon('calendar-event'), (string)$ev['title'], $sub, whenHtml($ev['event_date'])) ?>
                            <?php endforeach; ?>
                            <?php if (!$events) echo emptyRow('No events in this range'); ?>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <div id="printArea"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        /* graphs: the numbers come straight from the database (see PHP above) */
        (function() {
            'use strict';
            if (!window.Chart) return;
            const D = <?= json_encode($DASH, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            const NAVY = '#0B1F3A', GOLD = '#C9A24B', BLUE = '#5B7DB1', GREY = '#B8C2D3', GREEN = '#2e9e5b', RED = '#dc3545', PAL = [NAVY, GOLD, BLUE, GREEN, GREY, RED];
            const inr = n => '\u20B9' + Math.round(n).toLocaleString('en-IN');
            Chart.defaults.font.size = 11;
            Chart.defaults.font.family = "'Inter','Segoe UI',sans-serif";
            const empty = (id, d) => {
                const el = document.getElementById(id);
                if (!el || !d) return null;
                if (!d.values && !d.paid) return el;
                const tot = (d.values || d.paid.concat(d.pending)).reduce((a, b) => a + b, 0);
                if (tot > 0) return el;
                el.parentNode.innerHTML = '<div class="chart-empty"><i class="bi bi-bar-chart"></i>No data for this period</div>';
                return null;
            };
            const make = (id, d, cfg) => { const el = empty(id, d); if (el) new Chart(el, cfg); };
            const base = { responsive: true, maintainAspectRatio: false };
            const money = { callbacks: { label: c => ' ' + (c.dataset.label ? c.dataset.label + ': ' : c.label + ': ') + inr(c.parsed.y ?? c.parsed.x ?? c.parsed) } };

            if (D.sales) make('chSales', D.sales, { type: 'bar', data: { labels: D.sales.labels, datasets: [
                { label: 'Paid', data: D.sales.paid, backgroundColor: NAVY, borderRadius: 3 },
                { label: 'Pending', data: D.sales.pending, backgroundColor: GOLD, borderRadius: 3 }] },
                options: { ...base, scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, ticks: { callback: v => inr(v) } } }, plugins: { tooltip: money, legend: { position: 'bottom' } } } });
            if (D.methods) make('chMethods', D.methods, { type: 'doughnut', data: { labels: D.methods.labels, datasets: [{ data: D.methods.values, backgroundColor: PAL, borderWidth: 0 }] },
                options: { ...base, cutout: '62%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + inr(c.parsed) } } } } });
            if (D.top) make('chTop', D.top, { type: 'bar', data: { labels: D.top.labels, datasets: [{ data: D.top.values, backgroundColor: NAVY, borderRadius: 3 }] },
                options: { ...base, indexAxis: 'y', plugins: { legend: { display: false }, tooltip: money }, scales: { x: { ticks: { callback: v => inr(v) } }, y: { grid: { display: false } } } } });
            if (D.growth) make('chGrowth', D.growth, { type: 'line', data: { labels: D.growth.labels, datasets: [{ label: 'New customers', data: D.growth.values, borderColor: NAVY, backgroundColor: 'rgba(201,162,75,.2)', fill: true, tension: .3, pointBackgroundColor: GOLD }] },
                options: { ...base, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } });
            if (D.cat) make('chCat', D.cat, { type: 'doughnut', data: { labels: D.cat.labels, datasets: [{ data: D.cat.values, backgroundColor: PAL, borderWidth: 0 }] }, options: { ...base, cutout: '62%', plugins: { legend: { position: 'bottom' } } } });
            if (D.src) make('chSrc', D.src, { type: 'bar', data: { labels: D.src.labels, datasets: [{ data: D.src.values, backgroundColor: GOLD, borderRadius: 3 }] },
                options: { ...base, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } });
            if (D.inq) make('chInq', D.inq, { type: 'doughnut', data: { labels: D.inq.labels, datasets: [{ data: D.inq.values, backgroundColor: PAL, borderWidth: 0 }] }, options: { ...base, cutout: '62%', plugins: { legend: { position: 'bottom' } } } });
        })();
    </script>
<?php crm_foot(); ?>
</body>

</html>
