<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/* =====================================================================
 *  CRM - billing.php   (All bills / Today / Monthly / Pending)
 *  Real data from MySQL tables `bills` and `bill_items` (created by bills.sql).
 *  Shop details, currency, date format, terms, logo ... come from the
 *  `settings` table (Settings page -> General / Billing).
 *  Page + JSON API in one file:
 *    billing.php?api=list      GET   list + filters + date range + summary + pages
 *    billing.php?api=get&id=1  GET   one bill (for the invoice popup)
 *    billing.php?api=paid      POST  mark a bill as Paid
 *    billing.php?api=delete    POST  delete a bill (stock is given back)
 *    billing.php?api=summary   GET   today / month / pending totals (dashboard, notifications)
 * ===================================================================== */

if (session_status() !== PHP_SESSION_ACTIVE) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
date_default_timezone_set('Asia/Kolkata');          // replaced by the Settings time zone below

/* ----------------------------- CONFIG ------------------------------ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings-helper.php';

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'your_database_name');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

const AUTH_SESSION_KEY    = 'crm_uid';      // your login session key (example 'admin_id'). null = no server check
const LOGIN_PAGE          = 'login.php';
const DEBUG               = false;     // true = show real DB errors in the toast
const RESTORE_STOCK       = true;      // true = deleting a bill gives the stock back to the products
const PER_PAGE            = 5000;     // paging is done by crm-table.js (30/60/100/All)
const PAY_METHODS         = ['Cash', 'Card', 'UPI', 'Bank Transfer'];
const PAY_STATUS          = ['Paid', 'Pending', 'Partial'];

/* ---------------------------- DATABASE ----------------------------- */
function db(): PDO
{
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  try {
    $pdo = new PDO(
      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER,
      DB_PASS,
      [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+05:30'",
      ]
    );
  } catch (PDOException $e) {
    error_log('[billing.php connect] ' . $e->getMessage());
    throw new RuntimeException('Database connection failed. Please check DB_HOST, DB_NAME, DB_USER and DB_PASS in config.php.');
  }
  return $pdo;
}

function db_error_message(Throwable $e): string
{
  if ($e instanceof RuntimeException) return $e->getMessage();
  if ($e instanceof PDOException) {
    $code = (string)$e->getCode();
    if ($code === '42S02') return 'A table is missing in database "' . DB_NAME . '". Please run bills.sql and settings.sql once in phpMyAdmin.';
    if ($code === '42S22') return 'A column is missing. Please re-create the tables using bills.sql.';
  }
  return DEBUG ? $e->getMessage() : 'Server error. Please try again (turn DEBUG on in billing.php to see details).';
}

/* ---------------------------- HELPERS ------------------------------ */
function h($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $d, int $code = 200): void
{
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

function require_auth(bool $json): void
{
  if (AUTH_SESSION_KEY !== null && empty($_SESSION[AUTH_SESSION_KEY])) {
    if ($json) json_out(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
    header('Location: ' . LOGIN_PAGE);
    exit;
  }
}

function require_post_csrf(): void
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Invalid request method.'], 405);
  }
  $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!is_string($sent) || !hash_equals($_SESSION['csrf_billing'] ?? '', $sent)) {
    json_out(['success' => false, 'message' => 'Security token expired. Please refresh the page and try again.'], 419);
  }
}

function valid_date(string $d): bool
{
  $x = DateTime::createFromFormat('!Y-m-d', $d);
  return $x && $x->format('Y-m-d') === $d;
}

/** WHERE part + values for the list and the summary (search, method, status, view, date range) */
function bill_filters(array $src): array
{
  $where = [];
  $args  = [];
  $view  = (string)($src['view'] ?? 'all');

  $hasDates = isset($src['from']) || isset($src['to']);
  $from = trim((string)($src['from'] ?? ''));
  $to   = trim((string)($src['to'] ?? ''));
  if (!valid_date($from)) $from = '';
  if (!valid_date($to))   $to = '';
  if (!$hasDates) {                                   // page opened from the sidebar link: use the preset
    if ($view === 'today')   $from = $to = date('Y-m-d');
    if ($view === 'monthly') {
      $from = date('Y-m-01');
      $to = date('Y-m-d');
    }
  }
  if ($from !== '' && $to !== '' && $from > $to) {    // swapped dates: fix silently
    [$from, $to] = [$to, $from];
  }

  $q = trim((string)($src['q'] ?? ''));
  if ($q !== '') {
    $like    = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(b.invoice_no LIKE ? OR b.customer_name LIKE ? OR b.customer_mobile LIKE ?)';
    array_push($args, $like, $like, $like);
  }
  if (in_array($src['method'] ?? '', PAY_METHODS, true)) {
    $where[] = 'b.payment_method = ?';
    $args[]  = $src['method'];
  }
  if (in_array($src['status'] ?? '', PAY_STATUS, true)) {
    $where[] = 'b.payment_status = ?';
    $args[]  = $src['status'];
  }
  if ($view === 'pending') {
    $where[] = "b.payment_status <> 'Paid'";
  }
  if ($from !== '') {
    $where[] = 'b.invoice_date >= ?';
    $args[]  = $from;
  }
  if ($to !== '') {
    $where[] = 'b.invoice_date <= ?';
    $args[]  = $to;
  }
  return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $args];
}

/** One saved bill in the shape the invoice template needs */
function load_bill(int $id): ?array
{
  global $SET;
  $pdo = db();
  $st = $pdo->prepare('SELECT * FROM bills WHERE id = ?');
  $st->execute([$id]);
  $b = $st->fetch();
  if (!$b) return null;

  $st = $pdo->prepare('SELECT * FROM bill_items WHERE bill_id = ? ORDER BY id');
  $st->execute([$id]);
  $items = [];
  foreach ($st->fetchAll() as $it) {
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
    'date'    => app_date($b['invoice_date'], $SET),
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

/* ------------------------------ API -------------------------------- */
function api_list(): void
{
  global $SET;
  $pdo = db();
  [$w, $args] = bill_filters($_GET);

  $st = $pdo->prepare("SELECT COUNT(*) AS n,
                              COALESCE(SUM(b.total), 0) AS total,
                              COALESCE(SUM(CASE WHEN b.payment_status = 'Paid' THEN b.total END), 0) AS paid,
                              COALESCE(SUM(CASE WHEN b.payment_status <> 'Paid' THEN b.total END), 0) AS pending
                         FROM bills b $w");
  $st->execute($args);
  $s = $st->fetch();

  $n     = (int)$s['n'];
  $per   = PER_PAGE;
  $pages = max(1, (int)ceil($n / $per));
  $page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
  $off   = ($page - 1) * $per;

  $st = $pdo->prepare("SELECT b.id, b.invoice_no, b.invoice_date, b.customer_name, b.customer_mobile,
                              b.payment_method, b.payment_status, b.total,
                              (SELECT COALESCE(SUM(i.qty), 0) FROM bill_items i WHERE i.bill_id = b.id) AS item_qty
                         FROM bills b $w
                        ORDER BY b.invoice_date DESC, b.id DESC
                        LIMIT $per OFFSET $off");
  $st->execute($args);
  $rows = [];
  foreach ($st->fetchAll() as $r) {
    $rows[] = [
      'id'     => (int)$r['id'],
      'no'     => $r['invoice_no'],
      'date'   => app_date($r['invoice_date'], $SET),
      'cust'   => $r['customer_name'],
      'mobile' => $r['customer_mobile'],
      'items'  => (int)$r['item_qty'],
      'amount' => (float)$r['total'],
      'method' => $r['payment_method'],
      'status' => $r['payment_status'],
    ];
  }
  json_out([
    'success' => true,
    'bills'   => $rows,
    'page'    => $page,
    'pages'   => $pages,
    'per'     => $per,
    'summary' => ['bills' => $n, 'total' => (float)$s['total'], 'paid' => (float)$s['paid'], 'pending' => (float)$s['pending']],
  ]);
}

function api_get(): void
{
  $b = load_bill((int)($_GET['id'] ?? 0));
  if (!$b) json_out(['success' => false, 'message' => 'Bill not found.'], 404);
  json_out(['success' => true, 'invoice' => $b]);
}

function api_paid(): void
{
  require_post_csrf();
  $id = (int)($_POST['id'] ?? 0);
  $st = db()->prepare("UPDATE bills SET payment_status = 'Paid' WHERE id = ?");
  $st->execute([$id]);
  $chk = db()->prepare('SELECT payment_status FROM bills WHERE id = ?');
  $chk->execute([$id]);
  $r = $chk->fetch();
  if (!$r) json_out(['success' => false, 'message' => 'Bill not found.'], 404);
  json_out(['success' => true, 'message' => 'Marked as Paid']);
}

function api_delete(): void
{
  require_post_csrf();
  $id  = (int)($_POST['id'] ?? 0);
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT id FROM bills WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    if (!$st->fetch()) {
      $pdo->rollBack();
      json_out(['success' => false, 'message' => 'Bill not found or already deleted.'], 404);
    }
    if (RESTORE_STOCK) {                               // give the sold pieces back to the products
      $st = $pdo->prepare('SELECT variant_id, qty FROM bill_items WHERE bill_id = ? AND variant_id IS NOT NULL');
      $st->execute([$id]);
      $up = $pdo->prepare('UPDATE product_variants SET stock = stock + ? WHERE id = ?');
      foreach ($st->fetchAll() as $it) {
        $up->execute([(int)$it['qty'], (int)$it['variant_id']]);
      }
    }
    $pdo->prepare('DELETE FROM bills WHERE id = ?')->execute([$id]);   // bill_items are removed by FOREIGN KEY CASCADE
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[billing.php delete] ' . $e->getMessage());
    json_out(['success' => false, 'message' => db_error_message($e)], 500);
  }
  json_out(['success' => true, 'message' => 'Bill deleted']);
}

function api_summary(): void
{
  $today = date('Y-m-d');
  $st = db()->prepare("SELECT COALESCE(SUM(CASE WHEN invoice_date = ? THEN total END), 0) AS today_total,
                              COALESCE(SUM(CASE WHEN invoice_date >= ? AND invoice_date <= ? THEN total END), 0) AS month_total,
                              COALESCE(SUM(CASE WHEN payment_status <> 'Paid' THEN total END), 0) AS pending_amt,
                              COALESCE(SUM(payment_status <> 'Paid'), 0) AS pending_n
                         FROM bills");
  $st->execute([$today, date('Y-m-01'), $today]);
  $r = $st->fetch();
  json_out([
    'success'     => true,
    'today'       => (float)$r['today_total'],
    'month'       => (float)$r['month_total'],
    'pending_amt' => (float)$r['pending_amt'],
    'pending_n'   => (int)$r['pending_n'],
  ]);
}

/* ----------------------------- ROUTER ------------------------------ */
if (empty($_SESSION['csrf_billing'])) {
  $_SESSION['csrf_billing'] = bin2hex(random_bytes(32));
}

/* settings from the database (Settings page): time zone, shop details, date format ... */
try {
  $SET = get_settings(db());
} catch (Throwable $e) {
  $SET = settings_defaults();
}
date_default_timezone_set(app_timezone($SET));

if (isset($_GET['api'])) {
  ini_set('display_errors', '0');
  require_auth(true);
  try {
    switch ($_GET['api']) {
      case 'list':
        api_list();
        break;
      case 'get':
        api_get();
        break;
      case 'paid':
        api_paid();
        break;
      case 'delete':
        api_delete();
        break;
      case 'summary':
        api_summary();
        break;
      default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 400);
    }
  } catch (Throwable $e) {
    error_log('[billing.php] ' . $e->getMessage());
    json_out(['success' => false, 'message' => db_error_message($e)], 500);
  }
  exit;
}

require_auth(false);
$csrf = $_SESSION['csrf_billing'];
$SHOP = shop_for_js($SET);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?= h($csrf) ?>" />
  <title>Billing | <?= h($SET['store_name']) ?> CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link href="assets/css/style.css" rel="stylesheet" />
  <?php crm_head(); ?>
  
  
  <style>
      /* ==================== BILLING TABLE CSS ==================== */

#billPanel .table-responsive {
  border: 1px solid #e2e6ed;
  border-radius: 4px;
  overflow-x: auto;
  background: #fff;
}

#billPanel #bTable {
  width: 100%;
  margin-bottom: 0;
  border-collapse: separate;
  border-spacing: 0;
  color: #344054;
  font-size: 13px;
  white-space: nowrap;
}

#billPanel #bTable thead th {
  background: #f5f7fa;
  color: #475467;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.45px;
  padding: 14px 16px;
  border-bottom: 1px solid #dfe4ec;
  border-right: 1px solid #e7eaf0;
  vertical-align: middle;
}

#billPanel #bTable thead th:last-child {
  border-right: none;
}

#billPanel #bTable tbody td {
  padding: 13px 16px;
  border-bottom: 1px solid #e7eaf0;
  border-right: 1px solid #edf0f4;
  vertical-align: middle;
  background: #fff;
}

#billPanel #bTable tbody td:last-child {
  border-right: none;
}

#billPanel #bTable tbody tr:last-child td {
  border-bottom: none;
}

#billPanel #bTable tbody tr:nth-child(even) td {
  background: #fafbfc;
}

#billPanel #bTable tbody tr:hover td {
  background: #f0f5ff;
  transition: background 0.2s ease;
}

#billPanel #bTable tbody td:first-child b {
  color: #344054;
  font-weight: 700;
}

#billPanel #bTable tbody td:nth-child(2) .fw-semibold {
  color: #263247;
  font-size: 13px;
}

#billPanel #bTable tbody td:nth-child(2) small {
  display: inline-block;
  margin-top: 3px;
  font-size: 11px;
}

#billPanel #bTable tbody td:nth-child(5) b {
  color: #1d2939;
  font-size: 13px;
  font-weight: 700;
}

/* Payment status badges */

#billPanel #bTable .badge-s {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 5px 10px;
  border-radius: 5px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.2;
  border: 1px solid transparent;
}

#billPanel #bTable .b-green {
  color: #16803d;
  background: #e9f8ef;
  border-color: #c7eed4;
}

#billPanel #bTable .b-amber {
  color: #a16207;
  background: #fff7df;
  border-color: #f5e4a8;
}

#billPanel #bTable .b-gray {
  color: #475467;
  background: #f2f4f7;
  border-color: #e4e7ec;
}

/* Action buttons */

#billPanel #bTable .btn-icon {
  width: 31px;
  height: 31px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  margin-left: 3px;
  border: 1px solid #dfe4ec;
  border-radius: 4px;
  background: #fff;
  color: #475467;
  font-size: 14px;
  transition: all 0.2s ease;
}

#billPanel #bTable .btn-icon:hover {
  background: #edf3ff;
  border-color: #b9ccf8;
  color: #2458c6;
  transform: translateY(-1px);
}

#billPanel #bTable .btn-icon[data-ba="paid"]:hover {
  background: #e9f8ef;
  border-color: #a7dfba;
  color: #16803d;
}

#billPanel #bTable .btn-icon[data-ba="del"]:hover {
  background: #fff0f0;
  border-color: #ffc9c9;
  color: #dc2626;
}

/* Toolbar and filters */

#billPanel .toolbar {
  padding: 18px;
  border-bottom: 1px solid #e2e6ed;
}

#billPanel .toolbar .form-control,
#billPanel .toolbar .form-select,
#billPanel .toolbar .input-group-text {
  min-height: 39px;
  border-color: #dfe4ec;
  font-size: 13px;
  box-shadow: none;
}

#billPanel .toolbar .form-control:focus,
#billPanel .toolbar .form-select:focus {
  border-color: #9bb7f5;
  box-shadow: 0 0 0 3px rgba(59, 110, 220, 0.08);
}

#billPanel .toolbar .input-group-text {
  color: #667085;
}

#billPanel [data-ba="reset"] {
  color: #667085;
  text-decoration: none;
}

#billPanel [data-ba="reset"]:hover {
  color: #2458c6;
}

/* Panel border */

#billPanel {
  overflow: hidden;
  border: 1px solid #e2e6ed;
  border-radius: 4px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(16, 24, 40, 0.03);
}

/* Pagination */

#bPager {
  border-top: 1px solid #e2e6ed;
  background: #fff;
}

#bPager .btn {
  min-width: 75px;
  font-size: 12px;
}

#bPager .btn-outline-primary {
  color: #344054;
  border-color: #dfe4ec;
}

#bPager .btn-outline-primary:hover:not(:disabled) {
  color: #2458c6;
  border-color: #b9ccf8;
  background: #edf3ff;
}

#bPager .btn:disabled {
  opacity: 0.45;
}

/* Mobile responsive */

@media (max-width: 767.98px) {
  #billPanel .toolbar {
    padding: 12px;
  }

  #billPanel #bTable {
    min-width: 850px;
  }

  #billPanel #bTable thead th,
  #billPanel #bTable tbody td {
    padding: 11px 12px;
  }

  #billPanel #bTable .btn-icon {
    width: 29px;
    height: 29px;
  }

  #bPager {
    gap: 10px;
    padding: 12px !important;
  }
}
  </style>
</head>

<body>
  <div class="overlay"></div>
  <?php crm_sidebar(); ?>
  <div class="main">
    <?php crm_topbar(); ?>
    <main class="content">
      <div class="page-head">
        <div>
          <h1 id="pageTitle">All bills</h1>
          <p class="text-muted mb-0">Review invoices, payments and daily sales.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php if (crm_can('billing.create')): ?><a href="create-bill.php" class="btn btn-gold"><i class="bi bi-plus-lg me-1"></i>Create bill</a><?php endif; ?>
        </div>
      </div>

      <div id="summary"></div>

      <div class="panel" id="billPanel">
        <div class="row g-2 toolbar">
          <div class="col-12 col-lg-3">
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
              <input class="form-control" id="bSearch" placeholder="Invoice no, customer, mobile" aria-label="Search bills" />
            </div>
          </div>
          <div class="col-6 col-lg-2">
            <select class="form-select" id="fMethod" aria-label="Method">
              <option value="">All methods</option>
              <?php foreach (PAY_METHODS as $m): ?><option><?= h($m) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-lg-2">
            <select class="form-select" id="fStatus" aria-label="Status">
              <option value="">All status</option>
              <?php foreach (PAY_STATUS as $s): ?><option><?= h($s) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-2">
            <select class="form-select" id="fRange" aria-label="Date range">
              <option value="any">Any date</option>
              <option value="today">Today</option>
              <option value="yesterday">Yesterday</option>
              <option value="last7">Last 7 days</option>
              <option value="month">This month</option>
              <option value="lastmonth">Last month</option>
              <option value="custom">Custom range</option>
            </select>
          </div>
          <div class="col-12 col-lg-3">
            <div class="input-group">
              <input type="date" class="form-control" id="fFrom" aria-label="From date" />
              <span class="input-group-text">to</span>
              <input type="date" class="form-control" id="fTo" aria-label="To date" />
            </div>
          </div>
          <div class="col-12 d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-link btn-sm p-0" data-ba="reset"><i class="bi bi-x-circle me-1"></i>Clear all filters</button>
            <small class="text-muted" id="bCount"></small>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle mb-0" id="bTable" data-crm-table data-crm-search="off">
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Items</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div class="text-center text-muted py-5 d-none" id="bLoading">
          <span class="spinner-border spinner-border-sm me-2"></span>Loading bills...
        </div>
        <div class="empty d-none text-center" id="bEmpty">
          <i class="bi bi-inbox fs-1"></i>
          <p class="mb-2">No bills found for these filters.</p>
          <a class="btn btn-gold btn-sm" href="create-bill.php"><i class="bi bi-plus-lg me-1"></i>Create a bill</a>
        </div>

        <div class="d-flex justify-content-between align-items-center p-3 d-none" id="bPager">
          <small class="text-muted" id="bRange"></small>
          <div class="btn-group">
            <button class="btn btn-sm btn-outline-primary" data-ba="prev"><i class="bi bi-chevron-left"></i> Prev</button>
            <button class="btn btn-sm btn-outline-primary" data-ba="next">Next <i class="bi bi-chevron-right"></i></button>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Invoice popup -->
  <div class="modal fade" id="invModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="invTitle">Invoice</h5>
          <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="invBody"></div>
        <div class="modal-footer">
          <button class="btn btn-light me-auto" data-bs-dismiss="modal">Close</button>
          <button class="btn btn-primary" data-ba="print"><i class="bi bi-printer me-1"></i>Print invoice</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Confirm popup (delete / mark paid) -->
  <div class="modal fade" id="cModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
      <div class="modal-content">
        <div class="modal-body text-center p-4">
          <div class="ic-warn"><i class="bi bi-exclamation-triangle"></i></div>
          <h5 class="mt-3" id="cTitle">Are you sure?</h5>
          <p class="text-muted mb-4" id="cText"></p>
          <button class="btn btn-light me-2" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger" id="cOk" data-ba="confirm">Yes, continue</button>
        </div>
      </div>
    </div>
  </div>

  <div id="printArea"></div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/app.js"></script>
  <script>
    (function() {
      'use strict';
      const $ = (s, r = document) => r.querySelector(s),
        $$ = (s, r = document) => [...r.querySelectorAll(s)];
      const CSRF = $('meta[name="csrf-token"]').content;
      const SHOP = <?= json_encode($SHOP, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>; // from Settings
      const TODAY = <?= json_encode(date('Y-m-d')) ?>; // server date in the Settings time zone
      const TITLES = {
        all: 'All bills',
        today: "Today's billing",
        monthly: 'Monthly sales',
        pending: 'Pending payments'
      };
      const TONE = {
        Paid: 'green',
        Pending: 'amber',
        Partial: 'amber'
      };

      const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      } [c]));
      const inr = n => SHOP.sym + Math.round(n).toLocaleString('en-IN');
      const money = n => SHOP.sym + (+n || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
      const badge = s => `<span class="badge-s b-${TONE[s] || 'gray'}">${esc(s)}</span>`;

      /* ---- toast ---- */
      function toast(msg, type = 'success') {
        const box = $('#toasts');
        if (!box) {
          alert(msg);
          return;
        }
        const el = document.createElement('div');
        el.className = 'toast crm-toast ' + type;
        el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-${type === 'error' ? 'x-circle' : 'check-circle'} me-2"></i>${esc(msg)}</div><button class="btn-close btn-close-white m-auto me-2" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
        box.append(el);
        new bootstrap.Toast(el, {
          delay: 3500
        }).show();
        el.addEventListener('hidden.bs.toast', () => el.remove());
      }

      /* ---- server call ---- */
      async function call(action, opt = {}) {
        try {
          const res = await fetch('billing.php?api=' + action + (opt.qs ? '&' + opt.qs : ''), {
            method: opt.body ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-Token': CSRF
            },
            body: opt.body || undefined
          });
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

      /* ---- invoice (same layout as create-bill.php; all shop details come from Settings) ---- */
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

      /* ---- date helpers (all based on the server's date in the Settings time zone) ---- */
      const toDate = s => {
        const [y, m, d] = s.split('-').map(Number);
        return new Date(Date.UTC(y, m - 1, d));
      };
      const ymd = d => d.toISOString().slice(0, 10);
      const addDays = (s, n) => {
        const d = toDate(s);
        d.setUTCDate(d.getUTCDate() + n);
        return ymd(d);
      };
      const firstOfMonth = s => s.slice(0, 8) + '01';
      const PRESETS = {
        any: () => ['', ''],
        today: () => [TODAY, TODAY],
        yesterday: () => [addDays(TODAY, -1), addDays(TODAY, -1)],
        last7: () => [addDays(TODAY, -6), TODAY],
        month: () => [firstOfMonth(TODAY), TODAY],
        lastmonth: () => {
          const end = addDays(firstOfMonth(TODAY), -1);
          return [firstOfMonth(end), end];
        }
      };

      const CAN = { edit: <?= crm_can('billing.edit') ? 'true' : 'false' ?>, del: <?= crm_can('billing.delete') ? 'true' : 'false' ?> };
      /* ---- state ---- */
      const params = new URLSearchParams(location.search);
      const view = TITLES[params.get('view')] ? params.get('view') : 'all';
      $('#pageTitle').textContent = TITLES[view];
      let page = 1,
        seq = 0,
        pages = 1,
        confirmFn = null;
      const invModal = new bootstrap.Modal('#invModal'),
        cModal = new bootstrap.Modal('#cModal');

      // opening preset for the sidebar links
      (function initRange() {
        const key = view === 'today' ? 'today' : view === 'monthly' ? 'month' : 'any';
        $('#fRange').value = key;
        const [f, t] = PRESETS[key]();
        $('#fFrom').value = f;
        $('#fTo').value = t;
        if (params.get('q')) $('#bSearch').value = params.get('q');
      })();

      /* ---- list ---- */
      async function load(p = 1) {
        const my = ++seq;
        page = p;
        $('#bLoading').classList.remove('d-none');
        const qs = new URLSearchParams({
          view,
          q: $('#bSearch').value.trim(),
          method: $('#fMethod').value,
          status: $('#fStatus').value,
          from: $('#fFrom').value,
          to: $('#fTo').value,
          page: p
        }).toString();
        const j = await call('list', {
          qs
        });
        if (my !== seq) return;
        $('#bLoading').classList.add('d-none');
        if (!j.success) {
          toast(j.message || 'Could not load bills.', 'error');
          return;
        }
        page = j.page;
        pages = j.pages;
        draw(j);
      }

      function draw(j) {
        const rows = j.bills,
          s = j.summary;
        $('#bTable tbody').innerHTML = rows.map(r => `<tr data-id="${r.id}">
<td><b>${esc(r.no)}</b></td>
<td><div class="fw-semibold">${esc(r.cust)}</div><small class="text-muted">${esc(r.mobile)}</small></td>
<td>${esc(r.date)}</td>
<td>${r.items}</td>
<td><b>${inr(r.amount)}</b></td>
<td>${esc(r.method)}</td>
<td>${badge(r.status)}</td>
<td class="text-end text-nowrap">
<button class="btn btn-sm btn-icon" data-ba="view" title="View invoice" aria-label="View invoice"><i class="bi bi-receipt"></i></button>
${CAN.edit && r.status !== 'Paid' ? '<button class="btn btn-sm btn-icon" data-ba="paid" title="Mark as paid" aria-label="Mark as paid"><i class="bi bi-check2-circle"></i></button>' : ''}
${CAN.del ? '<button class="btn btn-sm btn-icon" data-ba="del" title="Delete bill" aria-label="Delete bill"><i class="bi bi-trash"></i></button>' : ''}</td></tr>`).join('');
        $('#bEmpty').classList.toggle('d-none', rows.length > 0);
        $('#bCount').textContent = s.bills + (s.bills === 1 ? ' bill' : ' bills');

        const pager = $('#bPager');
        pager.classList.toggle('d-none', s.bills <= j.per);
        if (s.bills > j.per) {
          const a = (j.page - 1) * j.per + 1,
            b = a + rows.length - 1;
          $('#bRange').textContent = 'Showing ' + a + ' to ' + b + ' of ' + s.bills;
          $('[data-ba=prev]').disabled = j.page <= 1;
          $('[data-ba=next]').disabled = j.page >= j.pages;
        }

        $('#summary').innerHTML = `<div class="row g-3 mb-3">
<div class="col-6 col-md-3"><div class="stat"><span class="ico"><i class="bi bi-receipt"></i></span><div><small>Total billed</small><b>${inr(s.total)}</b></div></div></div>
<div class="col-6 col-md-3"><div class="stat"><span class="ico"><i class="bi bi-cash-coin"></i></span><div><small>Collected</small><b>${inr(s.paid)}</b></div></div></div>
<div class="col-6 col-md-3"><div class="stat"><span class="ico"><i class="bi bi-hourglass-split"></i></span><div><small>Pending</small><b>${inr(s.pending)}</b></div></div></div>
<div class="col-6 col-md-3"><div class="stat"><span class="ico"><i class="bi bi-files"></i></span><div><small>Bills</small><b>${s.bills}</b></div></div></div></div>`;
      }

      /* ---- actions ---- */
      async function viewBill(id) {
        const j = await call('get', {
          qs: 'id=' + id
        });
        if (!j.success) {
          toast(j.message || 'Could not load the invoice.', 'error');
          return;
        }
        $('#invTitle').textContent = 'Invoice ' + j.invoice.no;
        $('#invBody').innerHTML = invoice(j.invoice);
        invModal.show();
      }

      function askConfirm(title, text, label, cls, fn) {
        $('#cTitle').textContent = title;
        $('#cText').textContent = text;
        const ok = $('#cOk');
        ok.textContent = label;
        ok.className = 'btn ' + cls;
        confirmFn = fn;
        cModal.show();
      }

      document.addEventListener('click', async e => {
        const b = e.target.closest('[data-ba]');
        if (!b) return;
        const a = b.dataset.ba,
          tr = b.closest('tr[data-id]'),
          id = tr ? +tr.dataset.id : 0,
          no = tr ? tr.querySelector('b').textContent : '';
        if (a === 'view') viewBill(id);
        else if (a === 'paid') askConfirm('Mark as paid?', 'Invoice ' + no + ' will be marked as Paid.', 'Yes, mark paid', 'btn-primary', async () => {
          const j = await call('paid', {
            body: new URLSearchParams({
              id
            })
          });
          if (j.success) {
            toast('Invoice ' + no + ' marked as Paid');
            load(page);
          } else toast(j.message || 'Could not update the bill.', 'error');
        });
        else if (a === 'del') askConfirm('Delete bill?', 'Delete invoice ' + no + '? The sold pieces go back to stock. This cannot be undone.', 'Yes, delete', 'btn-danger', async () => {
          const j = await call('delete', {
            body: new URLSearchParams({
              id
            })
          });
          if (j.success) {
            toast('Invoice ' + no + ' deleted');
            load(page);
          } else toast(j.message || 'Could not delete the bill.', 'error');
        });
        else if (a === 'confirm') {
          b.disabled = true;
          cModal.hide();
          const fn = confirmFn;
          confirmFn = null;
          b.disabled = false;
          fn && await fn();
        } else if (a === 'print') {
          $('#printArea').innerHTML = $('#invBody').innerHTML;
          window.print();
        } else if (a === 'prev' && page > 1) load(page - 1);
        else if (a === 'next' && page < pages) load(page + 1);
        else if (a === 'reset') {
          $('#bSearch').value = '';
          $('#fMethod').value = '';
          $('#fStatus').value = '';
          $('#fRange').value = 'any';
          $('#fFrom').value = '';
          $('#fTo').value = '';
          load(1);
        }
      });

      /* ---- filters ---- */
      let t;
      $('#bSearch').addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => load(1), 300);
      });
      $('#fMethod').addEventListener('change', () => load(1));
      $('#fStatus').addEventListener('change', () => load(1));
      $('#fRange').addEventListener('change', () => {
        const k = $('#fRange').value;
        if (PRESETS[k]) {
          const [f, to] = PRESETS[k]();
          $('#fFrom').value = f;
          $('#fTo').value = to;
        }
        load(1);
      });
      const dateChanged = () => {
        $('#fRange').value = ($('#fFrom').value || $('#fTo').value) ? 'custom' : 'any';
        load(1);
      };
      $('#fFrom').addEventListener('change', dateChanged);
      $('#fTo').addEventListener('change', dateChanged);

      load(1);
    })();
  </script>
<?php crm_foot(); ?>
</body>

</html>
