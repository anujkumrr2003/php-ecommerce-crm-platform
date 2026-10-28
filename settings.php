<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/* =====================================================================
 *  CRM - settings.php   (General + Billing settings, saved in MySQL)
 *  Page + JSON API in one file:
 *    settings.php?api=brand           GET   store name + logo (sidebar, used by app.js)
 *    settings.php?api=save_general    POST  business profile + logo upload
 *    settings.php?api=save_billing    POST  invoice prefix / number / GST / terms ...
 *  Table: `settings` (run settings.sql once).
 * ===================================================================== */

if (session_status() !== PHP_SESSION_ACTIVE) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings-helper.php';

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'your_database_name');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

const AUTH_SESSION_KEY = 'crm_uid';        // your login session key (example 'admin_id'). null = no server check
const LOGIN_PAGE       = 'login.php';
const DEBUG            = false;
const UPLOAD_DIR       = __DIR__ . '/assets/uploads';
const UPLOAD_URL       = 'assets/uploads';
const LOGO_MAX_BYTES   = 2097152;     // 2 MB

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
    error_log('[settings.php connect] ' . $e->getMessage());
    throw new RuntimeException('Database connection failed. Please check DB_HOST, DB_NAME, DB_USER and DB_PASS in config.php.');
  }
  return $pdo;
}

function db_error_message(Throwable $e): string
{
  if ($e instanceof RuntimeException) return $e->getMessage();
  if ($e instanceof PDOException && (string)$e->getCode() === '42S02') {
    return 'Table "settings" not found in database "' . DB_NAME . '". Please run settings.sql once in phpMyAdmin.';
  }
  return DEBUG ? $e->getMessage() : 'Server error. Please try again (turn DEBUG on in settings.php to see details).';
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
  if (!is_string($sent) || !hash_equals($_SESSION['csrf_settings'] ?? '', $sent)) {
    json_out(['success' => false, 'message' => 'Security token expired. Please refresh the page and try again.'], 419);
  }
}

function post_str(string $k, int $max): string
{
  $v = $_POST[$k] ?? '';
  return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function fail(string $msg): void
{
  json_out(['success' => false, 'message' => $msg], 422);
}

/** Save many settings in one transaction */
function save_settings(array $kv): void
{
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($kv as $k => $v) $st->execute([$k, (string)$v]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function delete_logo_file(string $path): void
{
  if (preg_match('#^assets/uploads/(logo_[a-f0-9]+\.(png|jpe?g|webp))$#', $path, $m)) {
    $f = UPLOAD_DIR . '/' . $m[1];
    if (is_file($f)) @unlink($f);
  }
}

/* ------------------------------ API -------------------------------- */
function api_brand(): void
{
  $s = get_settings(db());
  json_out(['success' => true, 'name' => $s['store_name'], 'logo' => logo_url($s)]);
}

function api_save_general(): void
{
  require_post_csrf();
  $cur = get_settings(db());

  $name  = post_str('store_name', 80);
  $email = post_str('business_email', 120);
  $phone = post_str('phone', 20);
  $gstin = strtoupper(post_str('gstin', 15));
  $addr  = post_str('address', 250);
  $pos   = post_str('place_of_supply', 60);
  $curcy = post_str('currency', 20);
  $tz    = post_str('timezone', 40);
  $dfmt  = post_str('date_format', 20);
  $v1    = post_str('variant_label_1', 30) ?: 'Size';
  $v2    = post_str('variant_label_2', 30) ?: 'Color';
  $grp   = post_str('product_group_label', 30) ?: 'Suitable for';
  $mat   = post_str('product_material_label', 40) ?: 'Material / specification';
  $prefs = post_str('customer_preferences', 500);

  if ($name === '') fail('Store name is required.');
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid business email.');
  if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) fail('Enter a valid phone number.');
  if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) fail('Enter a valid 15 character GSTIN.');
  if (!isset(settings_currencies()[$curcy]))   fail('Please choose a currency.');
  if (!isset(settings_timezones()[$tz]))       fail('Please choose a time zone.');
  if (!isset(settings_date_formats()[$dfmt]))  fail('Please choose a date format.');

  /* ---- logo: remove and / or upload ---- */
  $logo = (string)$cur['logo'];
  if (!empty($_POST['remove_logo'])) {
    delete_logo_file($logo);
    $logo = '';
  }
  if (isset($_FILES['logo']) && is_array($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['logo'];
    if ($f['error'] !== UPLOAD_ERR_OK)  fail('Logo upload failed. Please try a smaller image.');
    if ($f['size'] > LOGO_MAX_BYTES)    fail('Logo must be smaller than 2 MB.');
    $info = @getimagesize($f['tmp_name']);
    $map  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!$info || !isset($map[$info['mime']])) fail('Logo must be a PNG, JPG or WEBP image.');
    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) fail('Upload folder assets/uploads could not be created.');
    $fn = 'logo_' . bin2hex(random_bytes(8)) . '.' . $map[$info['mime']];
    if (!@move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $fn)) fail('Could not save the logo. Check write permission on assets/uploads.');
    delete_logo_file($logo);
    $logo = UPLOAD_URL . '/' . $fn;
  }

  save_settings([
    'store_name' => $name,
    'business_email' => $email,
    'phone' => $phone,
    'gstin' => $gstin,
    'address' => $addr,
    'place_of_supply' => $pos,
    'currency' => $curcy,
    'timezone' => $tz,
    'date_format' => $dfmt,
    'logo' => $logo,
    'variant_label_1' => $v1,
    'variant_label_2' => $v2,
    'product_group_label' => $grp,
    'product_material_label' => $mat,
    'customer_preferences' => $prefs,
  ]);
  $s = get_settings(db());
  json_out(['success' => true, 'message' => 'General settings saved', 'name' => $s['store_name'], 'logo' => logo_url($s), 'gstin' => $s['gstin']]);
}

function api_save_billing(): void
{
  require_post_csrf();

  $prefix = post_str('invoice_prefix', 10);
  $next   = post_str('next_invoice_number', 9);
  $gst    = post_str('default_gst', 3);
  $terms  = post_str('invoice_terms', 1000);
  $foot   = post_str('footer_note', 200);

  if ($prefix === '' || !preg_match('/^[A-Za-z0-9\-\/_]{1,10}$/', $prefix)) fail('Invoice prefix: use 1 to 10 letters, numbers, - / or _.');
  if (!ctype_digit($next) || (int)$next < 1) fail('Next invoice number must be 1 or more.');
  if (!ctype_digit($gst) || !in_array((int)$gst, SETTINGS_GST_RATES, true)) fail('Please choose a default GST rate.');

  save_settings([
    'invoice_prefix' => $prefix,
    'next_invoice_number' => (string)(int)$next,
    'default_gst' => (string)(int)$gst,
    'invoice_terms' => str_replace("\r\n", "\n", $terms),
    'footer_note' => $foot,
    'round_off' => !empty($_POST['round_off']) ? '1' : '0',
    'show_gstin' => !empty($_POST['show_gstin']) ? '1' : '0',
  ]);
  $s = get_settings(db());
  json_out(['success' => true, 'message' => 'Billing settings saved', 'next' => preview_invoice_no(db(), $s)]);
}

/* ----------------------------- ROUTER ------------------------------ */
if (empty($_SESSION['csrf_settings'])) {
  $_SESSION['csrf_settings'] = bin2hex(random_bytes(32));
}

if (isset($_GET['api'])) {
  ini_set('display_errors', '0');
  require_auth(true);
  try {
    switch ($_GET['api']) {
      case 'brand':
        api_brand();
        break;
      case 'save_general':
        api_save_general();
        break;
      case 'save_billing':
        api_save_billing();
        break;
      default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 400);
    }
  } catch (Throwable $e) {
    error_log('[settings.php] ' . $e->getMessage());
    json_out(['success' => false, 'message' => db_error_message($e)], 500);
  }
  exit;
}

require_auth(false);
$csrf = $_SESSION['csrf_settings'];
$pageError = '';
try {
  $SET = get_settings(db());
  $nextPreview = preview_invoice_no(db(), $SET);
} catch (Throwable $e) {
  error_log('[settings.php page] ' . $e->getMessage());
  $SET = settings_defaults();
  $nextPreview = $SET['invoice_prefix'] . $SET['next_invoice_number'];
  $pageError = db_error_message($e);
}
$logo = logo_url($SET);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?= h($csrf) ?>" />
  <title>Settings | <?= crm_e(crm_brand()['name']) ?> CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link href="assets/css/style.css?v=1" rel="stylesheet" />
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
          <h1 id="pageTitle">General settings</h1>
          <p class="text-muted mb-0">Business profile, notifications, roles and billing defaults.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap"></div>
      </div>

      <?php if ($pageError !== ''): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= h($pageError) ?></div>
        </div>
      <?php endif; ?>

      <ul class="nav nav-pills mb-3 flex-wrap gap-1">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#general">General</button></li>
        <!--<li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#notifications">Notifications</button></li>-->
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#roles">User roles</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#billing">Billing</button></li>
      </ul>

      <div class="tab-content">

        <!-- ============ GENERAL (saved in MySQL) ============ -->
        <div class="tab-pane fade show active" id="general">
          <form id="fGeneral" class="needs-validation" novalidate data-custom enctype="multipart/form-data">
            <div class="panel">
              <div class="panel-title">
                <h5>Business profile</h5>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="sn">Store name <span class="text-danger">*</span></label>
                  <input type="text" id="sn" name="store_name" class="form-control" maxlength="80" required value="<?= h($SET['store_name']) ?>" />
                  <div class="invalid-feedback">Enter the store name.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="se">Business email</label>
                  <input type="email" id="se" name="business_email" class="form-control" maxlength="120" value="<?= h($SET['business_email']) ?>" />
                  <div class="invalid-feedback">Enter a valid business email.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="sp">Phone</label>
                  <input type="tel" id="sp" name="phone" class="form-control" maxlength="20" pattern="[0-9+\-\s()]{6,20}" value="<?= h($SET['phone']) ?>" />
                  <div class="invalid-feedback">Enter a valid phone number.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="sg">GSTIN</label>
                  <input type="text" id="sg" name="gstin" class="form-control text-uppercase" maxlength="15" pattern="[0-9]{2}[A-Za-z]{5}[0-9]{4}[A-Za-z][1-9A-Za-z]Z[0-9A-Za-z]" value="<?= h($SET['gstin']) ?>" />
                  <div class="invalid-feedback">Enter a valid 15 character GSTIN (example 07ABCDE1234F1Z5).</div>
                </div>
                <div class="col-md-8">
                  <label class="form-label" for="sa">Address</label>
                  <textarea id="sa" name="address" class="form-control" rows="2" maxlength="250"><?= h($SET['address']) ?></textarea>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="pos">Place of supply</label>
                  <input type="text" id="pos" name="place_of_supply" class="form-control" maxlength="60" value="<?= h($SET['place_of_supply']) ?>" />
                  <div class="form-text">Shown on the invoice, example Delhi (07)</div>
                </div>
                <div class="col-12"><hr class="my-1"><h6 class="mb-0">Products &amp; customers (works for any kind of business)</h6><div class="form-text mb-0">Rename the product fields to match what you sell. Example: Size / Colour, or Weight / Brand, or Model / Capacity.</div></div>
                <div class="col-md-3"><label class="form-label" for="v1">Variant option 1</label><input id="v1" name="variant_label_1" class="form-control" maxlength="30" value="<?= h($SET['variant_label_1']) ?>" /></div>
                <div class="col-md-3"><label class="form-label" for="v2">Variant option 2</label><input id="v2" name="variant_label_2" class="form-control" maxlength="30" value="<?= h($SET['variant_label_2']) ?>" /></div>
                <div class="col-md-3"><label class="form-label" for="pg">Product group label</label><input id="pg" name="product_group_label" class="form-control" maxlength="30" value="<?= h($SET['product_group_label']) ?>" /></div>
                <div class="col-md-3"><label class="form-label" for="pm">Material / spec label</label><input id="pm" name="product_material_label" class="form-control" maxlength="40" value="<?= h($SET['product_material_label']) ?>" /></div>
                <div class="col-12"><label class="form-label" for="cp">Customer "Product preferences" options</label><input id="cp" name="customer_preferences" class="form-control" maxlength="500" placeholder="Comma separated, e.g. Furniture, Electronics, Gifts  (empty = default list)" value="<?= h($SET['customer_preferences']) ?>" /></div>
                <div class="col-md-4">
                  <label class="form-label" for="cur">Currency</label>
                  <select id="cur" name="currency" class="form-select" required>
                    <?php foreach (array_keys(settings_currencies()) as $k): ?>
                      <option <?= $k === $SET['currency'] ? 'selected' : '' ?>><?= h($k) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="invalid-feedback">Please choose an option.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="tz">Time zone</label>
                  <select id="tz" name="timezone" class="form-select" required>
                    <?php foreach (settings_timezones() as $k => $label): ?>
                      <option value="<?= h($k) ?>" <?= $k === $SET['timezone'] ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="invalid-feedback">Please choose an option.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="df">Date format</label>
                  <select id="df" name="date_format" class="form-select" required>
                    <?php foreach (array_keys(settings_date_formats()) as $k): ?>
                      <option <?= $k === $SET['date_format'] ? 'selected' : '' ?>><?= h($k) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="invalid-feedback">Please choose an option.</div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="logoFile">Logo (shown on the invoice and sidebar)</label>
                  <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div id="logoBox" class="border rounded d-flex align-items-center justify-content-center bg-white" style="width:72px;height:72px;overflow:hidden">
                      <?php if ($logo !== ''): ?>
                        <img id="logoPrev" src="<?= h($logo) ?>" alt="Current logo" style="width:100%;height:100%;object-fit:contain" />
                      <?php else: ?>
                        <i class="bi bi-image fs-3 text-muted" id="logoIcon"></i>
                      <?php endif; ?>
                    </div>
                    <div class="flex-grow-1" style="min-width:220px">
                      <input type="file" id="logoFile" name="logo" class="form-control" accept="image/png,image/jpeg,image/webp" />
                      <div class="form-text">PNG, JPG or WEBP, up to 2 MB. Square image looks best.</div>
                      <?php if ($logo !== ''): ?>
                        <div class="form-check mt-1">
                          <input class="form-check-input" type="checkbox" id="rmLogo" name="remove_logo" value="1" />
                          <label class="form-check-label" for="rmLogo">Remove current logo</label>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="col-12">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Save changes</button>
                </div>
              </div>
            </div>
          </form>
        </div>

        <!-- ============ NOTIFICATIONS (demo, unchanged) ============ -->
        <div class="tab-pane fade" id="notifications">
          <div class="panel">
            <div class="panel-title">
              <h5>Notification preferences</h5>
            </div>
            <?php foreach ([['New website inquiry', 1], ['Pending payment alerts', 1], ["Today's birthdays and anniversaries", 1], ['Task due reminders', 1], ['Daily sales summary email', 0]] as $i => $n): ?>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="nsw<?= $i ?>" <?= $n[1] ? 'checked' : '' ?> />
                <label class="form-check-label" for="nsw<?= $i ?>"><?= h($n[0]) ?></label>
              </div>
            <?php endforeach; ?>
            <div class="mt-3">
              <button class="btn btn-primary" data-toast="Settings saved (demo, not stored)"><i class="bi bi-check2 me-1"></i>Save changes</button>
            </div>
          </div>
        </div>

        <!-- ============ USER ROLES (real screen: roles.php, Super Admin only) ============ -->
        <div class="tab-pane fade" id="roles">
          <div class="panel">
            <div class="panel-title"><h5>User roles &amp; permissions</h5></div>
            <?php if (crm_is_super()): ?>
              <p class="mb-3">Choose what Admin and Manager can see and do, and manage your employees.</p>
              <a class="btn btn-primary" href="roles.php"><i class="bi bi-shield-check me-1"></i>Roles &amp; permissions</a>
              <a class="btn btn-outline-primary" href="employees.php"><i class="bi bi-people me-1"></i>Employees</a>
            <?php else: ?>
              <p class="text-muted mb-0">Only the Super Admin can change roles and permissions.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- ============ BILLING (saved in MySQL) ============ -->
        <div class="tab-pane fade" id="billing">
          <form id="fBilling" class="needs-validation" novalidate data-custom>
            <div class="panel">
              <div class="panel-title">
                <h5>Billing defaults</h5>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="ip">Invoice prefix <span class="text-danger">*</span></label>
                  <input type="text" id="ip" name="invoice_prefix" class="form-control" maxlength="10" required pattern="[A-Za-z0-9\-\/_]{1,10}" value="<?= h($SET['invoice_prefix']) ?>" />
                  <div class="invalid-feedback">Use 1 to 10 letters, numbers, - / or _.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="nn">Next invoice number <span class="text-danger">*</span></label>
                  <input type="number" id="nn" name="next_invoice_number" class="form-control" min="1" step="1" required value="<?= h($SET['next_invoice_number']) ?>" />
                  <div class="invalid-feedback">Enter a number, 1 or more.</div>
                  <div class="form-text">Next invoice will be: <b id="invPreview"><?= h($nextPreview) ?></b></div>
                </div>
                <div class="col-md-4 d-none">
                  <label class="form-label" for="dg">Default GST</label>
                  <select id="dg" name="default_gst" class="form-select" required>
                    <?php foreach (SETTINGS_GST_RATES as $r): ?>
                      <option value="<?= $r ?>" <?= (string)$r === (string)(int)$SET['default_gst'] ? 'selected' : '' ?>><?= $r ?>%</option>
                    <?php endforeach; ?>
                  </select>
                  <div class="invalid-feedback">Please choose an option.</div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="it">Invoice terms &amp; conditions</label>
                  <textarea id="it" name="invoice_terms" class="form-control" rows="4" maxlength="1000"><?= h($SET['invoice_terms']) ?></textarea>
                  <div class="form-text">One point per line. Printed at the bottom of every invoice.</div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="fn">Invoice footer message</label>
                  <input type="text" id="fn" name="footer_note" class="form-control" maxlength="200" value="<?= h($SET['footer_note']) ?>" />
                  <div class="form-text">Use <code>{store_name}</code> to insert your store name automatically.</div>
                </div>
                <div class="col-12">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="swRound" name="round_off" value="1" <?= $SET['round_off'] === '1' ? 'checked' : '' ?> />
                    <label class="form-check-label" for="swRound">Round off invoice totals</label>
                  </div>
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="swGstin" name="show_gstin" value="1" <?= $SET['show_gstin'] === '1' ? 'checked' : '' ?> />
                    <label class="form-check-label" for="swGstin">Show GSTIN on invoice</label>
                  </div>
                </div>
                <div class="col-12">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Save changes</button>
                </div>
              </div>
            </div>
          </form>
        </div>

      </div>
    </main>
  </div>
  <div id="printArea"></div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/app.js"></script>
  <script>
    (function() {
      'use strict';
      const $ = (s, r = document) => r.querySelector(s);
      const CSRF = $('meta[name="csrf-token"]').content;
      const toast = (m, t) => window.CRMApp ? CRMApp.toast(m, t) : alert(m);

      async function post(action, fd) {
        try {
          const res = await fetch('settings.php?api=' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-Token': CSRF
            },
            body: fd
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

      /* ---- save helper: app.js validates first, then fires the "valid" event ---- */
      function wire(form, action, after) {
        form.addEventListener('valid', async () => {
          const btn = form.querySelector('[type=submit]'),
            old = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
          const fd = new FormData(form);
          const j = await post(action, fd);
          btn.disabled = false;
          btn.innerHTML = old;
          if (!j.success) {
            toast(j.message || 'Could not save.', 'error');
            return;
          }
          toast(j.message || 'Saved');
          after && after(j);
        });
      }

      /* ---- General ---- */
      const g = $('#fGeneral');
      $('#sg').addEventListener('input', e => {
        e.target.value = e.target.value.toUpperCase();
      });
      $('#logoFile').addEventListener('change', e => {
        const f = e.target.files[0],
          box = $('#logoBox');
        if (!f) return;
        if (f.size > 2097152) {
          toast('Logo must be smaller than 2 MB.', 'error');
          e.target.value = '';
          return;
        }
        box.innerHTML = '<img id="logoPrev" alt="New logo" style="width:100%;height:100%;object-fit:contain">';
        $('#logoPrev').src = URL.createObjectURL(f);
        const rm = $('#rmLogo');
        if (rm) rm.checked = false;
      });
      wire(g, 'save_general', j => {
        $('#logoFile').value = '';
        const box = $('#logoBox');
        box.innerHTML = j.logo ? '<img id="logoPrev" src="' + j.logo + '" alt="Current logo" style="width:100%;height:100%;object-fit:contain">' : '<i class="bi bi-image fs-3 text-muted"></i>';
        const rm = $('#rmLogo');
        if (rm) {
          rm.checked = false;
          if (!j.logo) rm.closest('.form-check').remove();
        }
        if (window.CRMApp) CRMApp.setBrand({
          name: j.name,
          logo: j.logo
        });
        g.classList.remove('was-validated');
      });

      /* ---- Billing ---- */
      const b = $('#fBilling');
      const prev = () => {
        $('#invPreview').textContent = ($('#ip').value || '') + ($('#nn').value || '');
      };
      $('#ip').addEventListener('input', prev);
      $('#nn').addEventListener('input', prev);
      wire(b, 'save_billing', j => {
        if (j.next) $('#invPreview').textContent = j.next;
        b.classList.remove('was-validated');
      });
    })();
  </script>
<?php crm_foot(); ?>
</body>

</html>
