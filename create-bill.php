<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/* =====================================================================
 *  CRM - create-bill.php   (PHP + MySQL, no dummy data)
 *  Shop details, invoice prefix / next number, default GST, round off,
 *  terms, logo, currency ... all come from the `settings` table
 *  (Settings page -> General / Billing).
 *  Page + JSON API in one file:
 *    create-bill.php?api=catalog   GET   active products / variants for the item picker
 *    create-bill.php?api=save      POST  save a bill (+ items, + stock deduction)
 *    create-bill.php?api=get&id=1  GET   one saved bill (for All Bills page / re-print)
 *  Customers come from the `customers` table (same table add-customer.php fills).
 *
 *  NEW in this version
 *   - Customer: type-to-search by name / mobile / city
 *   - Product : type-to-search by name / SKU / size / colour, click to auto-fill
 *   - SKU, Size, Colour, Price, GST are filled from Products (read only)
 *   - Server re-reads price / GST / SKU / size / colour from the database,
 *     so a bill can only contain real products added by admin
 *   - Stock is deducted automatically when the bill is saved
 * ===================================================================== */

if (session_status() !== PHP_SESSION_ACTIVE) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
date_default_timezone_set('Asia/Kolkata');          // replaced by the Settings time zone below

/* ----------------------------- CONFIG ------------------------------ */
require_once __DIR__ . '/config.php';               // your mysqli config ($conn)
require_once __DIR__ . '/settings-helper.php';

// PDO credentials: same database as config.php
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'your_database_name');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

const AUTH_SESSION_KEY = 'crm_uid';        // your login session key (example 'admin_id'). null = no server check
const LOGIN_PAGE       = 'login.php';
const DEBUG            = false;       // true = show real DB errors in the toast
const DEDUCT_STOCK     = true;        // true = reduce product stock when a bill is saved
const PAY_METHODS      = ['Cash', 'Card', 'UPI', 'Bank Transfer'];
const PAY_STATUS       = ['Paid', 'Pending', 'Partial'];
const GST_RATES        = [0, 5, 12, 18];

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
    error_log('[create-bill.php connect] ' . $e->getMessage());
    throw new RuntimeException('Database connection failed. Please check DB_HOST, DB_NAME, DB_USER and DB_PASS in config.php.');
  }
  return $pdo;
}

function db_error_message(Throwable $e): string
{
  if ($e instanceof RuntimeException) return $e->getMessage();
  if ($e instanceof PDOException) {
    $code = (string)$e->getCode();
    if ($code === '42S02') return 'A table is missing in database "' . DB_NAME . '". Please run bills.sql, products.sql and settings.sql once in phpMyAdmin.';
    if ($code === '42S22') return 'A column is missing. Please re-create the tables using bills.sql.';
  }
  return DEBUG ? $e->getMessage() : 'Server error. Please try again (turn DEBUG on in create-bill.php to see details).';
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
  if (!is_string($sent) || !hash_equals($_SESSION['csrf_bill'] ?? '', $sent)) {
    json_out(['success' => false, 'message' => 'Security token expired. Please refresh the page and try again.'], 419);
  }
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
function api_catalog(): void
{
  $st = db()->query("SELECT p.id AS pid, p.name, p.brand, p.category, p.gst, p.price AS base_price,
                            v.id AS vid, v.size, v.color, v.sku, v.price AS vprice, v.stock
                       FROM products p
                       JOIN product_variants v ON v.product_id = p.id
                      WHERE p.status = 'Active'
                      ORDER BY p.name, v.id");
  $items = [];
  foreach ($st->fetchAll() as $r) {
    $items[] = [
      'label' => $r['name'] . (trim($r['size'] . $r['color']) !== '' ? ' · ' . trim($r['size'] . ' / ' . $r['color'], ' /') : ''),
      'name'  => $r['name'],
      'brand' => (string)$r['brand'],
      'cat'   => (string)$r['category'],
      'pid'   => (int)$r['pid'],
      'vid'   => (int)$r['vid'],
      'sku'   => (string)$r['sku'],
      'size'  => (string)$r['size'],
      'color' => (string)$r['color'],
      'price' => (float)($r['vprice'] ?? $r['base_price']),
      'gst'   => (int)$r['gst'],
      'stock' => (int)$r['stock'],
    ];
  }
  json_out(['success' => true, 'items' => $items]);
}

function api_get(): void
{
  $b = load_bill((int)($_GET['id'] ?? 0));
  if (!$b) json_out(['success' => false, 'message' => 'Bill not found.'], 404);
  json_out(['success' => true, 'invoice' => $b]);
}

function api_save(): void
{
  global $SET;
  require_post_csrf();
  $pdo = db();

  /* ---- read + validate ---- */
  $custId = (int)($_POST['customer_id'] ?? 0);
  $method = (string)($_POST['method'] ?? 'Cash');
  $status = (string)($_POST['status'] ?? 'Paid');
  $dateIn = trim((string)($_POST['invoice_date'] ?? ''));
  $items  = json_decode((string)($_POST['items'] ?? '[]'), true);

  if (!in_array($method, PAY_METHODS, true)) json_out(['success' => false, 'message' => 'Invalid payment method.'], 422);
  if (!in_array($status, PAY_STATUS, true))  json_out(['success' => false, 'message' => 'Invalid payment status.'], 422);

  $d = DateTime::createFromFormat('!Y-m-d', $dateIn);
  $date = ($d && $d->format('Y-m-d') === $dateIn) ? $dateIn : date('Y-m-d');

  $st = $pdo->prepare('SELECT id, name, mobile, city FROM customers WHERE id = ?');
  $st->execute([$custId]);
  $cust = $st->fetch();
  if (!$cust) json_out(['success' => false, 'message' => 'Please select a valid customer.'], 422);

  if (!is_array($items) || !$items)  json_out(['success' => false, 'message' => 'Add at least one item.'], 422);
  if (count($items) > 100)           json_out(['success' => false, 'message' => 'Too many items (maximum 100).'], 422);

  /* Product details (name, SKU, size, colour, price, GST) are read from the database,
     only the variant id, quantity and discount are taken from the browser. */
  $vst = $pdo->prepare('SELECT v.id AS vid, v.product_id AS pid, v.size, v.color, v.sku, v.price AS vprice, v.stock,
                               p.name, p.gst, p.price AS base_price, p.status
                          FROM product_variants v
                          JOIN products p ON p.id = v.product_id
                         WHERE v.id = ?');
  $lines = [];
  $need  = [];
  $sub = $disc = $gst = 0.0;
  foreach ($items as $i => $it) {
    $no = $i + 1;
    if (!is_array($it)) json_out(['success' => false, 'message' => "Item $no is invalid."], 422);
    $vid = (int)($it['vid'] ?? 0);
    $qty = $it['qty'] ?? 0;
    $dp  = $it['disc'] ?? 0;

    if ($vid < 1) json_out(['success' => false, 'message' => "Item $no: please pick the product from the search list."], 422);
    if (!is_numeric($qty) || (int)$qty < 1 || (int)$qty > 100000) json_out(['success' => false, 'message' => "Item $no: enter a valid quantity."], 422);
    if (!is_numeric($dp) || (float)$dp < 0 || (float)$dp > 100)   json_out(['success' => false, 'message' => "Item $no: discount must be between 0 and 100."], 422);

    $vst->execute([$vid]);
    $v = $vst->fetch();
    if (!$v) json_out(['success' => false, 'message' => "Item $no: this product no longer exists. Please refresh the page."], 422);

    $label = $v['name'] . ' (' . trim($v['size'] . ' / ' . $v['color'], ' /') . ')';
    if ($v['status'] !== 'Active') json_out(['success' => false, 'message' => "Item $no: $label is inactive."], 422);

    $qty = (int)$qty;
    $need[$vid] = ($need[$vid] ?? 0) + $qty;
    if (DEDUCT_STOCK && $need[$vid] > (int)$v['stock']) {
      json_out(['success' => false, 'message' => 'Not enough stock for ' . $label . '. Available: ' . (int)$v['stock'] . '.'], 422);
    }

    $rate = (float)($v['vprice'] ?? $v['base_price']);
    if ($rate <= 0) json_out(['success' => false, 'message' => "Item $no: price is not set for $label."], 422);
    $gp = (float)$v['gst'];
    $dp = (float)$dp;

    $gross = round($qty * $rate, 2);
    $dis   = round($gross * $dp / 100, 2);
    $tax   = round(($gross - $dis) * $gp / 100, 2);
    $tot   = round($gross - $dis + $tax, 2);
    $sub  += $gross;
    $disc += $dis;
    $gst  += $tax;

    $lines[] = [
      'pid'   => (int)$v['pid'],
      'vid'   => (int)$v['vid'],
      'name'  => (string)$v['name'],
      'label' => $label,
      'sku'   => mb_substr((string)$v['sku'], 0, 100),
      'size'  => mb_substr((string)$v['size'], 0, 40),
      'color' => mb_substr((string)$v['color'], 0, 40),
      'qty'   => $qty,
      'rate'  => $rate,
      'dp'    => $dp,
      'gp'    => $gp,
      'gross' => $gross,
      'dis'   => $dis,
      'tax'   => $tax,
      'tot'   => $tot,
    ];
  }
  $sub   = round($sub, 2);
  $disc  = round($disc, 2);
  $gst   = round($gst, 2);
  $total = round($sub - $disc + $gst, 2);
  if (($SET['round_off'] ?? '1') === '1') {            // Settings -> Billing -> "Round off invoice totals"
    $total = (float)round($total);
  }

  /* ---- save (one transaction: invoice number + bill + items + stock) ---- */
  $pdo->beginTransaction();
  try {
    $invNo = take_invoice_no($pdo);                    // prefix + next number from Settings -> Billing
    $pdo->prepare('INSERT INTO bills (invoice_no, invoice_date, customer_id, customer_name, customer_mobile, customer_city,
                                      payment_method, payment_status, subtotal, discount, gst, total)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([
        $invNo,
        $date,
        (int)$cust['id'],
        $cust['name'],
        (string)$cust['mobile'],
        (string)$cust['city'],
        $method,
        $status,
        $sub,
        $disc,
        $gst,
        $total
      ]);
    $billId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare('INSERT INTO bill_items (bill_id, product_id, variant_id, item_name, sku, size, color, qty, rate,
                                                  disc_percent, gst_percent, line_subtotal, line_discount, line_gst, line_total)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    // "AND stock >= ?" makes the deduction safe even if two bills are saved at the same moment
    $stk = $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?');
    foreach ($lines as $l) {
      $ins->execute([
        $billId,
        $l['pid'],
        $l['vid'],
        $l['name'],
        $l['sku'],
        $l['size'],
        $l['color'],
        $l['qty'],
        $l['rate'],
        $l['dp'],
        $l['gp'],
        $l['gross'],
        $l['dis'],
        $l['tax'],
        $l['tot']
      ]);
      if (DEDUCT_STOCK) {
        $stk->execute([$l['qty'], $l['vid'], $l['qty']]);
        if ($stk->rowCount() < 1) {
          throw new RuntimeException('Not enough stock for ' . $l['label'] . '. Reduce the quantity and try again.');
        }
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[create-bill.php save] ' . $e->getMessage());
    json_out(['success' => false, 'message' => db_error_message($e)], $e instanceof RuntimeException ? 422 : 500);
  }

  $SET = get_settings($pdo);                           // the counter has moved on
  json_out([
    'success'      => true,
    'id'           => $billId,
    'message'      => 'Bill ' . $invNo . ' saved',
    'invoice'      => load_bill($billId),
    'next_invoice' => preview_invoice_no($pdo, $SET),
  ]);
}

/* ----------------------------- ROUTER ------------------------------ */
if (empty($_SESSION['csrf_bill'])) {
  $_SESSION['csrf_bill'] = bin2hex(random_bytes(32));
}

/* settings from the database (Settings page): time zone, shop details, invoice rules ... */
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
      case 'catalog':
        api_catalog();
        break;
      case 'save':
        api_save();
        break;
      case 'get':
        api_get();
        break;
      default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 400);
    }
  } catch (Throwable $e) {
    error_log('[create-bill.php] ' . $e->getMessage());
    json_out(['success' => false, 'message' => db_error_message($e)], 500);
  }
  exit;
}

require_auth(false);
$csrf = $_SESSION['csrf_bill'];
$SHOP = shop_for_js($SET);

/* ---- data for the page (customers list + next invoice number) ---- */
$customers = [];
$nextInvoice = $SET['invoice_prefix'] . $SET['next_invoice_number'];
$pageError = '';
try {
  $customers = db()->query('SELECT id, name, mobile, city FROM customers ORDER BY name ASC, id ASC')->fetchAll();
} catch (Throwable $e) {
  error_log('[create-bill.php customers] ' . $e->getMessage());
  $pageError = 'Customers could not be loaded: ' . db_error_message($e);
}
try {
  $nextInvoice = preview_invoice_no(db(), $SET);
} catch (Throwable $e) {
  error_log('[create-bill.php invoice no] ' . $e->getMessage());
  $pageError = $pageError ?: db_error_message($e);
}
$customersJson = json_encode(
  array_map(static function ($c) {
    return [
      'id'     => (int)$c['id'],
      'name'   => (string)$c['name'],
      'mobile' => (string)$c['mobile'],
      'city'   => (string)$c['city'],
    ];
  }, $customers),
  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($customersJson === false) $customersJson = '[]';
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?= h($csrf) ?>" />
  <title>Create Bill | <?= h($SET['store_name']) ?> CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link href="assets/css/style.css" rel="stylesheet" />
  <style>
    /* ---------- search boxes (customer + product) ---------- */
    .cb-field { position: relative; }
    .cb-field .cb-ico { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #8a93a6; pointer-events: none; font-size: 13px; }
    .cb-field .form-control.cb-input-ico { padding-left: 32px; padding-right: 34px; background-image: none !important; }
    .cb-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: #9aa3b5; padding: 2px 4px; font-size: 15px; line-height: 1; }
    .cb-clear:hover { color: #dc3545; }
    .cb-info { font-size: 12px; color: #6b7385; margin-top: 4px; min-height: 16px; }
    .cb-info i { color: #198754; }

    .cb-drop { position: fixed; z-index: 2000; background: #fff; border: 1px solid #d9deea; border-radius: 4px; box-shadow: 0 12px 32px rgba(16, 24, 40, .18); overflow-y: auto; padding: 4px; }
    .cb-opt { padding: 7px 10px; border-radius: 4px; cursor: pointer; font-size: 13px; line-height: 1.3; }
    .cb-opt:not(.dis):hover { background: #f3f5fb; }
    .cb-opt.active { background: #eaf0ff; }
    .cb-opt.dis { opacity: .55; cursor: not-allowed; }
    .cb-min0 { min-width: 0; }
    .cb-t { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cb-s { font-size: 11.5px; color: #6b7385; }
    .cb-p { font-weight: 600; white-space: nowrap; }
    .cb-st { font-size: 11px; white-space: nowrap; }
    .cb-st.ok { color: #198754; }
    .cb-st.low { color: #b7791f; }
    .cb-st.out { color: #dc3545; }
    .cb-empty { padding: 12px; text-align: center; color: #6b7385; font-size: 13px; }
    .cb-foot { padding: 7px 10px 5px; border-top: 1px solid #eef0f5; margin-top: 4px; font-size: 12.5px; }

    /* ---------- compact items table ---------- */
    .items-table thead th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
    .items-table td { padding: 5px 4px; vertical-align: middle; }
    .items-table .form-control-sm { font-size: 12.5px; height: 31px; padding: 3px 8px; }
    .items-table .cb-ro { background: #f5f7fb; color: #4b5563; }
    .items-table .p-hint { font-size: 10.5px; color: #6b7385; margin-top: 2px; line-height: 1.1; }
    .items-table .p-hint.bad { color: #dc3545; font-weight: 600; }
    .items-table .p-tot { font-size: 13px; white-space: nowrap; }
    .sum-line { font-size: 13.5px; }
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
          <h1 id="pageTitle">Create bill</h1>
          <p class="text-muted mb-0">
            Search the customer, search products by name or SKU, then generate a print-ready invoice. Stock is reduced automatically.
          </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <a href="billing.php" class="btn btn-outline-primary">All bills</a>
        </div>
      </div>

      <?php if ($pageError !== ''): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= h($pageError) ?></div>
        </div>
      <?php endif; ?>

      <form id="billForm" class="needs-validation" novalidate data-custom autocomplete="off">
        <div class="d-grid gap-3">

          <!-- 1. Customer & invoice -->
          <div class="panel">
            <div class="panel-title">
              <h5><i class="bi bi-person-lines-fill"></i>Customer &amp; invoice</h5>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="custSearch">Customer <span class="text-danger">*</span></label>
                <div class="cb-field">
                  <i class="bi bi-search cb-ico"></i>
                  <input id="custSearch" class="form-control cb-input-ico" autocomplete="off" required
                    placeholder="Search by name, mobile number or city..." />
                  <button type="button" class="cb-clear d-none" id="custClear" aria-label="Clear customer"><i class="bi bi-x-circle-fill"></i></button>
                  <div class="invalid-feedback">Select a customer from the list.</div>
                </div>
                <input type="hidden" id="custSel" value="" />
                <div class="cb-info" id="custInfo"></div>
                <?php if (!$customers && $pageError === ''): ?>
                  <div class="form-text">No customers yet. <a href="add-customer.php">Add a customer</a> first.</div>
                <?php endif; ?>
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label" for="invNo">Invoice no.</label>
                <input id="invNo" class="form-control" value="<?= h($nextInvoice) ?>" readonly />
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label" for="invDate">Date</label>
                <input type="date" id="invDate" class="form-control" value="<?= h(date('Y-m-d')) ?>" required />
              </div>
            </div>
          </div>

          <!-- 2. Items -->
          <div class="panel">
            <div class="panel-title">
              <h5><i class="bi bi-bag"></i>Items<span class="sec-sub">Type product name or SKU and click the result. SKU, size, colour, price and GST fill automatically.</span></h5>
            </div>
            <div class="table-responsive">
              <table class="table items-table align-middle">
                <thead>
                  <tr>
                    <th style="min-width: 220px">Product</th>
                    <th style="min-width: 115px">SKU</th>
                    <th style="width: 72px">Size</th>
                    <th style="min-width: 90px">Colour</th>
                    <th style="width: 78px">Qty</th>
                    <th style="width: 100px">Price (<?= h(trim($SHOP['sym'])) ?>)</th>
                    <th style="width: 72px">Disc %</th>
                    <th style="width: 64px">GST %</th>
                    <th class="text-end" style="min-width: 100px">Total</th>
                    <th style="width: 40px"></th>
                  </tr>
                </thead>
                <tbody id="items"></tbody>
              </table>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" id="addRow">
              <i class="bi bi-plus-lg me-1"></i>Add item
            </button>
            <small class="text-muted ms-2 d-none d-md-inline">Tip: press Enter in Qty to jump to the next product row.</small>
          </div>

          <!-- 3. Payment + Summary -->
          <div class="row g-3">
            <div class="col-md-6">
              <div class="panel">
                <div class="panel-title">
                  <h5><i class="bi bi-credit-card"></i>Payment</h5>
                </div>
                <div class="row g-3">
                  <div class="col-sm-6">
                    <label class="form-label" for="payMethod">Payment method</label>
                    <select id="payMethod" class="form-select">
                      <?php foreach (PAY_METHODS as $m): ?><option><?= h($m) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-sm-6">
                    <label class="form-label" for="payStatus">Payment status</label>
                    <select id="payStatus" class="form-select">
                      <?php foreach (PAY_STATUS as $s): ?><option><?= h($s) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="panel">
                <div class="panel-title">
                  <h5><i class="bi bi-calculator"></i>Summary</h5>
                </div>
                <div class="d-flex justify-content-between mb-2 sum-line text-muted"><span>Items</span><b id="sCount">0 items · 0 pcs</b></div>
                <div class="d-flex justify-content-between mb-2 sum-line"><span>Subtotal</span><b id="sSub"><?= h($SHOP['sym']) ?>0.00</b></div>
                <div class="d-flex justify-content-between mb-2 sum-line"><span>Discount</span><b id="sDisc">- <?= h($SHOP['sym']) ?>0.00</b></div>
                <div class="d-flex justify-content-between mb-2 sum-line"><span>GST</span><b id="sGst"><?= h($SHOP['sym']) ?>0.00</b></div>
                <div class="d-flex justify-content-between mb-2 sum-line d-none" id="rRound"><span>Round off</span><b id="sRound"></b></div>
                <hr />
                <div class="d-flex justify-content-between fs-5"><b>Grand total</b><b id="sTotal"><?= h($SHOP['sym']) ?>0.00</b></div>
              </div>
            </div>
          </div>

          <div class="text-end desk-submit">
            <button class="btn btn-gold btn-lg px-4" type="submit">
              <i class="bi bi-receipt me-1"></i>Generate invoice
            </button>
          </div>
        </div>

        <!-- Mobile sticky total bar -->
        <div class="bill-bar">
          <div>
            <small class="text-muted d-block">Grand total</small><b id="barTotal"><?= h($SHOP['sym']) ?>0.00</b>
          </div>
          <button class="btn btn-gold" type="submit">
            <i class="bi bi-receipt me-1"></i>Generate invoice
          </button>
        </div>
      </form>
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
          <button class="btn btn-primary" data-ba="print"><i class="bi bi-printer me-1"></i>Print / Save PDF</button>
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
      const CUSTOMERS = <?= $customersJson ?>;
      const DEDUCT = <?= DEDUCT_STOCK ? 'true' : 'false' ?>;

      const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      } [c]));
      const round2 = x => Math.round((x + Number.EPSILON) * 100) / 100;
      const money = n => SHOP.sym + (+n || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });

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
          const res = await fetch('create-bill.php?api=' + action + (opt.qs ? '&' + opt.qs : ''), {
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

      /* ---- invoice (same layout as billing.php; all shop details come from Settings) ---- */
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
      const invModal = new bootstrap.Modal('#invModal');

      const bf = $('#billForm'),
        tb = $('#items');

      /* =================================================================
         Floating suggestion list (one shared box used by customer + product)
         It is position:fixed, so the scrolling table can never clip it.
         ================================================================= */
      const drop = document.createElement('div');
      drop.className = 'cb-drop d-none';
      drop.setAttribute('role', 'listbox');
      document.body.append(drop);
      let D = null; // currently open list: {input, list, idx, render, disabled, pick, empty, footer, minW}

      function hideDrop() {
        D = null;
        drop.classList.add('d-none');
      }

      function placeDrop() {
        if (!D) return;
        const r = D.input.getBoundingClientRect(),
          vw = document.documentElement.clientWidth,
          vh = window.innerHeight,
          w = Math.min(Math.max(r.width, D.minW || 0), vw - 16),
          below = vh - r.bottom;
        let left = Math.min(r.left, vw - w - 8);
        left = Math.max(8, left);
        drop.style.width = w + 'px';
        drop.style.left = left + 'px';
        if (below < 220 && r.top > below) { // not enough room below: open upwards
          drop.style.top = 'auto';
          drop.style.bottom = (vh - r.top + 4) + 'px';
          drop.style.maxHeight = Math.min(320, r.top - 12) + 'px';
        } else {
          drop.style.bottom = 'auto';
          drop.style.top = (r.bottom + 4) + 'px';
          drop.style.maxHeight = Math.min(320, below - 12) + 'px';
        }
      }

      function renderDrop() {
        const L = D.list;
        drop.innerHTML = (L.length ?
          L.map((x, i) => `<div class="cb-opt${i === D.idx ? ' active' : ''}${D.disabled(x) ? ' dis' : ''}" data-i="${i}" role="option">${D.render(x)}</div>`).join('') :
          `<div class="cb-empty">${D.empty}</div>`) + (D.footer || '');
        const a = drop.querySelector('.cb-opt.active');
        if (a) a.scrollIntoView({
          block: 'nearest'
        });
      }

      function showDrop(input, cfg) {
        D = Object.assign({
          input,
          idx: 0,
          disabled: () => false
        }, cfg);
        // start on the first selectable row
        const first = D.list.findIndex(x => !D.disabled(x));
        D.idx = first < 0 ? 0 : first;
        renderDrop();
        drop.classList.remove('d-none');
        placeDrop();
      }

      function moveIdx(step) {
        if (!D || !D.list.length) return;
        let i = D.idx,
          n = D.list.length;
        for (let k = 0; k < n; k++) {
          i = (i + step + n) % n;
          if (!D.disabled(D.list[i])) {
            D.idx = i;
            break;
          }
        }
        renderDrop();
      }

      function pickCurrent() {
        if (!D) return;
        const x = D.list[D.idx];
        if (x && !D.disabled(x)) {
          const pick = D.pick;
          hideDrop();
          pick(x);
        }
      }

      drop.addEventListener('mousedown', e => {
        if (e.target.closest('a')) return; // let links work
        e.preventDefault(); // keep the focus in the input
        const o = e.target.closest('.cb-opt');
        if (!o || !D || o.classList.contains('dis')) return;
        const x = D.list[+o.dataset.i],
          pick = D.pick;
        hideDrop();
        pick(x);
      });
      window.addEventListener('scroll', placeDrop, true);
      window.addEventListener('resize', placeDrop);

      /* =================================================================
         Customer search  (name / mobile / city)
         ================================================================= */
      const custIn = $('#custSearch'),
        custId = $('#custSel'),
        custClr = $('#custClear'),
        custInfo = $('#custInfo');
      const digits = s => String(s || '').replace(/\D/g, '');
      CUSTOMERS.forEach(c => {
        c._h = (c.name + ' ' + c.mobile + ' ' + c.city).toLowerCase();
        c._d = digits(c.mobile);
      });

      function findCustomers(q) {
        q = q.trim().toLowerCase();
        if (!q) return CUSTOMERS.slice(0, 50);
        const toks = q.split(/\s+/),
          qd = digits(q),
          out = [];
        for (const c of CUSTOMERS) {
          const ok = toks.every(t => c._h.includes(t) || (/^[\d+\-]+$/.test(t) && digits(t) && c._d.includes(digits(t))));
          if (!ok) continue;
          const s = (qd && c._d.startsWith(qd)) ? 0 : (c.name.toLowerCase().startsWith(q) ? 1 : 2);
          out.push({
            c,
            s
          });
        }
        return out.sort((a, b) => a.s - b.s).slice(0, 50).map(o => o.c);
      }

      function openCustDrop() {
        const q = custId.value ? '' : custIn.value;
        showDrop(custIn, {
          list: findCustomers(q),
          empty: CUSTOMERS.length ? 'No customer found for this search.' : 'No customers yet.',
          render: c => `<div class="d-flex justify-content-between gap-2"><div class="cb-t">${esc(c.name)}</div><div class="cb-s">${esc(c.mobile)}</div></div>${c.city ? `<div class="cb-s">${esc(c.city)}</div>` : ''}`,
          pick: pickCustomer,
          // footer: '<div class="cb-foot"><a href="add-customer.php" target="_blank" rel="noopener"><i class="bi bi-person-plus me-1"></i>Add new customer</a> <span class="text-muted">(refresh this page after adding)</span></div>'

          
        });
      }

      function pickCustomer(c) {
        custId.value = c.id;
        custIn.value = c.name + ' (' + c.mobile + ')';
        custIn.setCustomValidity('');
        custClr.classList.remove('d-none');
        custInfo.innerHTML = '<i class="bi bi-person-check me-1"></i>' + esc(c.name) + ' &middot; ' + esc(c.mobile) + (c.city ? ' &middot; ' + esc(c.city) : '');
        const r = $$('tr', tb).find(x => !+x.dataset.vid);
        if (r) $('.p-name', r).focus(); // next step: product
      }

      function clearCustomer() {
        custId.value = '';
        custIn.value = '';
        custIn.setCustomValidity('Select a customer from the list.');
        custClr.classList.add('d-none');
        custInfo.innerHTML = '';
      }
      custIn.setCustomValidity('Select a customer from the list.');
      custIn.addEventListener('focus', () => {
        if (custId.value) custIn.select();
        openCustDrop();
      });
      custIn.addEventListener('click', () => {
        if (!D || D.input !== custIn) openCustDrop();
      });
      custIn.addEventListener('input', () => {
        custId.value = '';
        custInfo.innerHTML = '';
        custIn.setCustomValidity('Select a customer from the list.');
        custClr.classList.toggle('d-none', !custIn.value);
        openCustDrop();
      });
      custIn.addEventListener('blur', () => {
        if (D && D.input === custIn) hideDrop();
      });
      custClr.addEventListener('click', () => {
        clearCustomer();
        custIn.focus();
      });

      /* =================================================================
         Product search  (name / SKU / size / colour / brand / category)
         ================================================================= */
      let PR = [],
        PRV = new Map();

      async function loadCatalog() {
        const j = await call('catalog');
        if (!j.success) {
          toast(j.message || 'Could not load products.', 'error');
          return;
        }
        PR = j.items;
        PR.forEach(p => {
          p._h = [p.name, p.sku, p.size, p.color, p.brand, p.cat].join(' ').toLowerCase();
        });
        PRV = new Map(PR.map(p => [p.vid, p]));
        calc(); // refresh stock hints
      }

      function findProducts(q) {
        q = q.trim().toLowerCase();
        if (!q) return PR.slice(0, 40);
        const toks = q.split(/\s+/),
          out = [];
        for (const p of PR) {
          if (!toks.every(t => p._h.includes(t))) continue;
          const sku = p.sku.toLowerCase();
          const s = sku === q ? 0 : sku.startsWith(q) ? 1 : p.name.toLowerCase().startsWith(q) ? 2 : 3;
          out.push({
            p,
            s
          });
        }
        return out.sort((a, b) => a.s - b.s).slice(0, 40).map(o => o.p);
      }

      const productOpt = p => {
        const st = p.stock <= 0 ? ['out', 'Out of stock'] : p.stock <= 3 ? ['low', 'Only ' + p.stock + ' left'] : ['ok', p.stock + ' in stock'];
        return `<div class="d-flex justify-content-between gap-2"><div class="cb-min0"><div class="cb-t">${esc(p.name)}</div><div class="cb-s">SKU: ${esc(p.sku)} &middot; ${esc(p.size)} &middot; ${esc(p.color)}</div></div><div class="text-end"><div class="cb-p">${money(p.price)}</div><div class="cb-st ${st[0]}">${st[1]}</div></div></div>`;
      };

      function openProductDrop(input) {
        showDrop(input, {
          list: findProducts(input.value),
          minW: 400,
          empty: PR.length ? 'No product found. Check the name / SKU or add it in Products.' : 'No active products.',
          render: productOpt,
          disabled: p => p.stock <= 0 && DEDUCT,
          pick: p => fillRow(input.closest('tr'), p)
        });
      }

      /* ---- rows ---- */
      const rowHtml = () => `<tr data-vid="0" data-pid="0">
<td class="c-wide c-name" data-label="Product"><input class="form-control form-control-sm p-name" autocomplete="off" maxlength="150" placeholder="Search product name or SKU..." required></td>
<td data-label="SKU"><input class="form-control form-control-sm p-sku cb-ro" readonly tabindex="-1" placeholder="-"></td>
<td data-label="Size"><input class="form-control form-control-sm p-size cb-ro" readonly tabindex="-1" placeholder="-"></td>
<td data-label="Colour"><input class="form-control form-control-sm p-col cb-ro" readonly tabindex="-1" placeholder="-"></td>
<td data-label="Qty"><input type="number" min="1" step="1" value="1" class="form-control form-control-sm p-qty" required><div class="p-hint"></div></td>
<td data-label="Price (${esc(SHOP.sym.trim())})"><input type="number" step="0.01" class="form-control form-control-sm p-price cb-ro" readonly tabindex="-1" placeholder="0"></td>
<td data-label="Disc %"><input type="number" min="0" max="100" step="0.01" value="0" class="form-control form-control-sm p-disc"></td>
<td data-label="GST %"><input class="form-control form-control-sm p-gst cb-ro" readonly tabindex="-1" placeholder="-"></td>
<td class="text-end fw-semibold p-tot c-tot" data-label="Item total">${esc(SHOP.sym)}0.00</td>
<td class="c-del"><button type="button" class="btn btn-sm btn-icon text-danger" data-ba="rm" title="Remove this item" aria-label="Remove item"><i class="bi bi-x-lg"></i></button></td></tr>`;

      function addRow(focus) {
        tb.insertAdjacentHTML('beforeend', rowHtml());
        calc();
        if (focus) $('.p-name', tb.lastElementChild).focus();
      }

      function clearRowData(r) {
        r.dataset.vid = 0;
        r.dataset.pid = 0;
        ['.p-sku', '.p-size', '.p-col', '.p-price', '.p-gst'].forEach(c => {
          $(c, r).value = '';
        });
      }

      function fillRow(r, p) {
        const dup = $$('tr', tb).find(x => x !== r && +x.dataset.vid === p.vid);
        if (dup) { // same product + size + colour already in the bill: just add one more piece
          const q = $('.p-qty', dup);
          q.value = (parseInt(q.value) || 0) + 1;
          toast(p.name + ' (' + p.size + ' / ' + p.color + ') is already added. Quantity increased.');
          if ($$('tr', tb).length > 1) r.remove();
          else {
            clearRowData(r);
            $('.p-name', r).value = '';
          }
          calc();
          q.focus();
          q.select();
          return;
        }
        r.dataset.vid = p.vid;
        r.dataset.pid = p.pid;
        $('.p-name', r).value = p.name;
        $('.p-sku', r).value = p.sku;
        $('.p-size', r).value = p.size;
        $('.p-col', r).value = p.color;
        $('.p-price', r).value = p.price;
        $('.p-gst', r).value = p.gst;
        const q = $('.p-qty', r);
        if (!(parseInt(q.value) > 0)) q.value = 1;
        calc();
        q.focus();
        q.select();
      }

      /* ---- totals (same rounding as the server) ---- */
      function calc() {
        const rows = $$('tr', tb),
          need = {};
        rows.forEach(r => {
          const v = +r.dataset.vid;
          if (v) need[v] = (need[v] || 0) + (parseInt($('.p-qty', r).value) || 0);
        });
        let s = 0,
          d = 0,
          g = 0,
          pcs = 0,
          n = 0;
        rows.forEach(r => {
          const vid = +r.dataset.vid,
            nameEl = $('.p-name', r),
            qtyEl = $('.p-qty', r),
            hint = $('.p-hint', r);
          nameEl.setCustomValidity(vid ? '' : 'Select a product from the list');
          const q = parseInt(qtyEl.value) || 0,
            rate = +$('.p-price', r).value || 0,
            dp = Math.min(100, Math.max(0, +$('.p-disc', r).value || 0)),
            gp = +$('.p-gst', r).value || 0;
          const gross = round2(q * rate),
            dis = round2(gross * dp / 100),
            tx = round2((gross - dis) * gp / 100),
            tot = round2(gross - dis + tx);
          s += gross;
          d += dis;
          g += tx;
          if (vid) {
            n++;
            pcs += q;
          }
          $('.p-tot', r).textContent = money(tot);

          const p = vid ? PRV.get(vid) : null;
          if (p) {
            const bad = DEDUCT && need[vid] > p.stock;
            qtyEl.setCustomValidity(bad ? 'Only ' + p.stock + ' in stock' : '');
            hint.textContent = bad ? 'Only ' + p.stock + ' in stock' : 'Stock: ' + p.stock;
            hint.classList.toggle('bad', bad);
          } else {
            qtyEl.setCustomValidity('');
            hint.textContent = '';
            hint.classList.remove('bad');
          }
        });
        s = round2(s);
        d = round2(d);
        g = round2(g);
        let total = round2(s - d + g);
        const ro = SHOP.round ? Math.round(total) - total : 0; // "Round off invoice totals" from Settings
        total = round2(total + ro);
        $('#sCount').textContent = n + (n === 1 ? ' item' : ' items') + ' \u00b7 ' + pcs + ' pcs';
        $('#sSub').textContent = money(s);
        $('#sDisc').textContent = '- ' + money(d);
        $('#sGst').textContent = money(g);
        $('#rRound').classList.toggle('d-none', Math.abs(ro) < 0.005);
        $('#sRound').textContent = (ro > 0 ? '+ ' : '- ') + SHOP.sym + Math.abs(ro).toFixed(2);
        $('#sTotal').textContent = money(total);
        $('#barTotal').textContent = money(total);
      }

      /* ---- events on the table ---- */
      tb.addEventListener('input', e => {
        const t = e.target,
          r = t.closest('tr');
        if (!r) return;
        if (t.classList.contains('p-name')) {
          if (+r.dataset.vid) clearRowData(r); // typed over a chosen product: pick again
          openProductDrop(t);
        }
        calc();
      });
      tb.addEventListener('focusin', e => {
        const t = e.target;
        if (t.classList.contains('p-name') && !+t.closest('tr').dataset.vid) openProductDrop(t);
      });
      tb.addEventListener('focusout', e => {
        if (D && D.input === e.target) hideDrop();
      });
      tb.addEventListener('click', e => {
        const t = e.target;
        if (t.classList.contains('p-name') && !+t.closest('tr').dataset.vid && (!D || D.input !== t)) openProductDrop(t);
      });

      /* ---- keyboard: arrows / Enter / Esc in the lists, Enter never submits the form by accident ---- */
      bf.addEventListener('keydown', e => {
        const t = e.target;
        if (D && D.input === t) {
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            moveIdx(1);
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            moveIdx(-1);
          } else if (e.key === 'Enter') {
            e.preventDefault();
            pickCurrent();
          } else if (e.key === 'Escape') {
            hideDrop();
          } else if (e.key === 'Tab') {
            hideDrop();
          }
          return;
        }
        if (e.key === 'ArrowDown' && t.classList.contains('p-name') && !+t.closest('tr').dataset.vid) {
          e.preventDefault();
          openProductDrop(t);
          return;
        }
        if (e.key === 'ArrowDown' && t === custIn) {
          e.preventDefault();
          openCustDrop();
          return;
        }
        if (e.key === 'Enter' && t.tagName === 'INPUT') {
          e.preventDefault();
          if (t.classList.contains('p-qty') || t.classList.contains('p-disc')) {
            const r = t.closest('tr');
            if (!r.nextElementSibling) addRow(false);
            $('.p-name', r.nextElementSibling).focus();
          }
        }
      });

      /* ---- clicks ---- */
      $('#addRow').onclick = () => addRow(true);
      document.addEventListener('click', e => {
        const b = e.target.closest('[data-ba]');
        if (!b) return;
        if (b.dataset.ba === 'rm') {
          if ($$('tr', tb).length > 1) {
            b.closest('tr').remove();
          } else {
            tb.innerHTML = '';
            addRow(false);
          }
          calc();
        } else if (b.dataset.ba === 'print') {
          $('#printArea').innerHTML = $('#invBody').innerHTML;
          window.print();
        }
      });

      /* ---- save (app.js validates the form first, then fires the "valid" event) ---- */
      bf.addEventListener('valid', async () => {
        hideDrop();
        calc();
        if (!custId.value) {
          toast('Select a customer from the list.', 'error');
          custIn.focus();
          return;
        }
        const rows = $$('tr', tb);
        for (const r of rows) {
          if (!+r.dataset.vid) {
            toast('Pick every product from the search list.', 'error');
            $('.p-name', r).focus();
            return;
          }
        }
        if (!bf.checkValidity()) {
          bf.classList.add('was-validated');
          const bad = bf.querySelector(':invalid');
          if (bad) {
            if (bad.validationMessage) toast(bad.validationMessage, 'error');
            bad.focus();
          }
          return;
        }

        // only the variant, quantity and discount are sent; price / GST / SKU are read again from the database on the server
        const items = rows.map(r => ({
          pid: +r.dataset.pid || 0,
          vid: +r.dataset.vid || 0,
          qty: $('.p-qty', r).value,
          disc: $('.p-disc', r).value || 0
        }));
        const btns = $$('[type=submit]', bf),
          old = btns.map(b => b.innerHTML);
        btns.forEach(b => {
          b.disabled = true;
          b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
        });

        const j = await call('save', {
          body: new URLSearchParams({
            customer_id: custId.value,
            invoice_date: $('#invDate').value,
            method: $('#payMethod').value,
            status: $('#payStatus').value,
            items: JSON.stringify(items)
          })
        });

        btns.forEach((b, i) => {
          b.disabled = false;
          b.innerHTML = old[i];
        });
        if (!j.success) {
          toast(j.message || 'Could not save the bill.', 'error');
          if (/stock/i.test(j.message || '')) loadCatalog(); // refresh the stock numbers
          return;
        }

        $('#invTitle').textContent = 'Invoice ' + j.invoice.no;
        $('#invBody').innerHTML = invoice(j.invoice);
        invModal.show();
        toast(j.message || 'Bill saved');

        /* fresh form for the next bill */
        tb.innerHTML = '';
        addRow(false);
        clearCustomer();
        $('#payMethod').selectedIndex = 0;
        $('#payStatus').selectedIndex = 0;
        $('#invNo').value = j.next_invoice;
        bf.classList.remove('was-validated');
        loadCatalog(); // stock has changed
      });

      $('#invModal').addEventListener('hidden.bs.modal', () => {
        if (!matchMedia('(pointer:coarse)').matches) custIn.focus();
      });

      addRow(false);
      loadCatalog();
      if (!matchMedia('(pointer:coarse)').matches) custIn.focus();
    })();
  </script>
<?php crm_foot(); ?>
</body>

</html>