<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/* =====================================================================
 *  CRM - products.php   (fully dynamic: PHP + MySQL, no dummy data)
 *  Page + JSON API in one file:
 *    products.php?api=list        GET   list / search / filter
 *    products.php?api=get&id=1    GET   single product
 *    products.php?api=save        POST  add or edit (multipart, with photos)
 *    products.php?api=delete      POST  delete
 *    products.php?api=billing     GET   active variants for Create Bill
 *    products.php?api=low_count   GET   number of low / out of stock products
 * ===================================================================== */

if (session_status() !== PHP_SESSION_ACTIVE) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
date_default_timezone_set('Asia/Kolkata');          // Delhi / Noida (IST)

/* ----------------------------- CONFIG ------------------------------ */
// Your existing config.php (mysqli $conn). It stays untouched for your other pages.
require_once __DIR__ . '/config.php';

// PDO credentials: same database as config.php.
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'your_database_name');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// Put your login session key here (example: 'admin_id'). null = no server side check.
const AUTH_SESSION_KEY = 'crm_uid';
const LOGIN_PAGE       = 'login.php';

const DEBUG            = false;                      // true = show real DB errors in the toast
const UPLOAD_DIR       = __DIR__ . '/uploads/products/';
const UPLOAD_URL       = 'uploads/products/';
const MAX_IMAGES       = 4;
const MAX_IMAGE_BYTES  = 3 * 1024 * 1024;            // 3 MB per photo
/* categories are NOT fixed any more: type any category, existing ones are suggested (see product_categories()) */

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
        // IST for every MySQL connection (CURRENT_TIMESTAMP, NOW() etc.)
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+05:30'",
      ]
    );
  } catch (PDOException $e) {
    error_log('[products.php connect] ' . $e->getMessage());
    throw new RuntimeException('Database connection failed. Please check DB_HOST, DB_NAME, DB_USER and DB_PASS in config.php.');
  }
  return $pdo;
}

/** Friendly text for common database problems (shown in the toast) */
function db_error_message(Throwable $e): string
{
  if ($e instanceof RuntimeException) return $e->getMessage();
  if ($e instanceof PDOException) {
    $code = (string)$e->getCode();
    $msg  = $e->getMessage();
    if ($code === '42S02') return 'Products tables not found in database "' . DB_NAME . '". Please run products.sql once in phpMyAdmin.';
    if ($code === '42S22') return 'A column is missing in the products tables. Please re-create the tables using products.sql.';
    if ($code === '23000' && stripos($msg, 'Duplicate') !== false) return 'This SKU or size/colour combination already exists.';
  }
  return DEBUG ? $e->getMessage() : 'Server error. Please try again (turn DEBUG on in products.php to see details).';
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
    if ($json) {
      json_out(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
    }
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
  if (!is_string($sent) || !hash_equals($_SESSION['csrf_products'] ?? '', $sent)) {
    json_out(['success' => false, 'message' => 'Security token expired. Please refresh the page and try again.'], 419);
  }
}

function stock_state(int $total, int $min): string
{
  if ($total <= 0) return 'Out of stock';
  return $total <= $min ? 'Low stock' : 'In stock';
}

/** Load products (with variants + images). $f = filters, $onlyId = single product */
function load_products(array $f = [], ?int $onlyId = null): array
{
  $pdo = db();
  $where = [];
  $args  = [];

  if ($onlyId !== null) {
    $where[] = 'p.id = ?';
    $args[]  = $onlyId;
  }
  $q = trim((string)($f['q'] ?? ''));
  if ($q !== '') {
    $like    = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ? OR p.category LIKE ? OR p.fabric LIKE ?
                     OR EXISTS (SELECT 1 FROM product_variants sv WHERE sv.product_id = p.id
                                AND (sv.color LIKE ? OR sv.size LIKE ? OR sv.sku LIKE ?)))';
    for ($i = 0; $i < 8; $i++) $args[] = $like;
  }
  if (!empty($f['cat'])) {
    $where[] = 'p.category = ?';
    $args[]  = (string)$f['cat'];
  }
  $view = $f['view'] ?? 'all';
  if (!empty($f['status']) && in_array($f['status'], ['Active', 'Inactive'], true)) {
    $where[] = 'p.status = ?';
    $args[]  = $f['status'];
  }
  $having = '';
  if ($view === 'low') {
    $where[] = "p.status = 'Active'";
    $having  = 'HAVING total_stock <= p.min_stock';
  } elseif ($view === 'inactive') {
    $where[] = "p.status = 'Inactive'";
  }

  $sql = 'SELECT p.*, COALESCE(SUM(v.stock), 0) AS total_stock
            FROM products p
            LEFT JOIN product_variants v ON v.product_id = p.id '
    . ($where ? 'WHERE ' . implode(' AND ', $where) : '')
    . ' GROUP BY p.id ' . $having . ' ORDER BY p.created_at DESC, p.id DESC';
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll();
  if (!$rows) return [];

  $ids = array_column($rows, 'id');
  $in  = implode(',', array_fill(0, count($ids), '?'));

  $vars = [];
  $st = $pdo->prepare("SELECT * FROM product_variants WHERE product_id IN ($in) ORDER BY id");
  $st->execute($ids);
  foreach ($st->fetchAll() as $v) {
    $vars[$v['product_id']][] = [
      'id'    => (int)$v['id'],
      'size'  => $v['size'],
      'color' => $v['color'],
      'sku'   => $v['sku'],
      'price' => $v['price'] === null ? null : (float)$v['price'],
      'stock' => (int)$v['stock'],
    ];
  }
  $imgs = [];
  $st = $pdo->prepare("SELECT id, product_id, file_name FROM product_images WHERE product_id IN ($in) ORDER BY sort_order, id");
  $st->execute($ids);
  foreach ($st->fetchAll() as $im) {
    $imgs[$im['product_id']][] = ['id' => (int)$im['id'], 'url' => UPLOAD_URL . $im['file_name']];
  }

  $out = [];
  foreach ($rows as $r) {
    $total = (int)$r['total_stock'];
    $min   = (int)$r['min_stock'];
    $out[] = [
      'id'         => (int)$r['id'],
      'name'       => $r['name'],
      'sku'        => $r['sku'],
      'cat'        => $r['category'],
      'brand'      => $r['brand'],
      'gender'     => $r['gender'],
      'fabric'     => $r['fabric'],
      'hsn'        => $r['hsn'],
      'desc'       => (string)$r['description'],
      'mrp'        => (float)$r['mrp'],
      'price'      => (float)$r['price'],
      'cost'       => (float)$r['cost'],
      'gst'        => (int)$r['gst'],
      'min'        => $min,
      'status'     => $r['status'],
      'total'      => $total,
      'state'      => stock_state($total, $min),
      'imgs'       => $imgs[$r['id']] ?? [],
      'variants'   => $vars[$r['id']] ?? [],
      'created'    => date('d M Y, h:i A', strtotime($r['created_at'])),
      'updated'    => date('d M Y, h:i A', strtotime($r['updated_at'])),
    ];
  }
  return $out;
}

function delete_files(array $names): void
{
  foreach ($names as $n) {
    $p = UPLOAD_DIR . basename((string)$n);
    if (is_file($p)) @unlink($p);
  }
}

/* ------------------------------ API -------------------------------- */
function api_list(): void
{
  $f = [
    'q'      => $_GET['q'] ?? '',
    'cat'    => $_GET['cat'] ?? '',
    'status' => $_GET['status'] ?? '',
    'view'   => $_GET['view'] ?? 'all',
  ];
  $rows = load_products($f);
  $pieces = 0;
  $low = 0;
  $value = 0.0;
  foreach ($rows as $r) {
    $pieces += $r['total'];
    if ($r['state'] !== 'In stock') $low++;
    foreach ($r['variants'] as $v) {
      $value += $v['stock'] * ($v['price'] ?? $r['price']);
    }
  }
  json_out([
    'success' => true,
    'products' => $rows,
    'summary' => ['products' => count($rows), 'pieces' => $pieces, 'low' => $low, 'value' => round($value, 2)]
  ]);
}

function api_get(): void
{
  $id = (int)($_GET['id'] ?? 0);
  $r  = $id ? load_products([], $id) : [];
  if (!$r) json_out(['success' => false, 'message' => 'Product not found.'], 404);
  json_out(['success' => true, 'product' => $r[0]]);
}

function api_billing(): void
{
  $st = db()->query("SELECT p.name, p.gst, p.price AS base_price, v.size, v.color, v.sku, v.price AS vprice, v.stock
                       FROM products p JOIN product_variants v ON v.product_id = p.id
                       WHERE p.status = 'Active' ORDER BY p.name, v.id");
  $items = [];
  foreach ($st->fetchAll() as $r) {
    $items[] = [
      'label' => $r['name'] . (trim($r['size'] . $r['color']) !== '' ? ' · ' . trim($r['size'] . ' / ' . $r['color'], ' /') : ''),
      'name'  => $r['name'],
      'sku'   => $r['sku'],
      'size'  => $r['size'],
      'color' => $r['color'],
      'price' => (float)($r['vprice'] ?? $r['base_price']),
      'gst'   => (int)$r['gst'],
      'stock' => (int)$r['stock'],
    ];
  }
  json_out(['success' => true, 'items' => $items]);
}

function api_low_count(): void
{
  $n = db()->query("SELECT COUNT(*) FROM (
                        SELECT p.id, p.min_stock FROM products p
                        LEFT JOIN product_variants v ON v.product_id = p.id
                        WHERE p.status = 'Active'
                        GROUP BY p.id, p.min_stock
                        HAVING COALESCE(SUM(v.stock), 0) <= p.min_stock
                      ) t")->fetchColumn();
  json_out(['success' => true, 'count' => (int)$n]);
}

/* categories already used + the labels the owner chose in Settings */
function product_categories(): array
{
  $rows = db()->query("SELECT DISTINCT category FROM products WHERE category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
  return $rows ?: [];
}
function product_groups(): array
{
  $rows = db()->query("SELECT DISTINCT gender FROM products WHERE gender <> '' ORDER BY gender")->fetchAll(PDO::FETCH_COLUMN);
  return $rows ?: [];
}
function api_save(): void
{
  require_post_csrf();
  $pdo = db();
  $id  = (int)($_POST['id'] ?? 0);

  $name   = trim((string)($_POST['name'] ?? ''));
  $sku    = trim((string)($_POST['sku'] ?? ''));
  $cat    = trim((string)($_POST['cat'] ?? ''));
  $brand  = trim((string)($_POST['brand'] ?? ''));
  $gender = trim((string)($_POST['gender'] ?? ''));
  $fabric = trim((string)($_POST['fabric'] ?? ''));
  $hsn    = trim((string)($_POST['hsn'] ?? ''));
  $desc   = trim((string)($_POST['desc'] ?? ''));
  $mrp    = (float)($_POST['mrp'] ?? 0);
  $price  = (float)($_POST['price'] ?? 0);
  $cost   = (float)($_POST['cost'] ?? 0);
  $gst    = (int)($_POST['gst'] ?? 12);
  $min    = (int)($_POST['min'] ?? 0);
  $status = (($_POST['status'] ?? 'Active') === 'Inactive') ? 'Inactive' : 'Active';

  /* ---- validation ---- */
  $err = null;
  if ($name === '' || mb_strlen($name) > 80)               $err = 'Product name is required (maximum 80 characters).';
  elseif ($sku === '' || mb_strlen($sku) > 60)             $err = 'Style code / SKU is required (maximum 60 characters).';
  elseif ($cat === '' || mb_strlen($cat) > 40)             $err = 'Enter a category (maximum 40 characters).';
  elseif (mb_strlen($gender) > 40)                         $err = 'The group / audience text is too long (maximum 40).';
  elseif (mb_strlen($brand) > 60 || mb_strlen($fabric) > 80) $err = 'Brand or material text is too long.';
  elseif (!preg_match('/^\d{0,8}$/', $hsn))                $err = 'HSN code must contain digits only (maximum 8).';
  elseif ($mrp <= 0)                                       $err = 'MRP must be greater than 0.';
  elseif ($price <= 0)                                     $err = 'Selling price must be greater than 0.';
  elseif ($price > $mrp)                                   $err = 'Selling price cannot be more than MRP.';
  elseif ($cost < 0)                                       $err = 'Cost price cannot be negative.';
  elseif (!in_array($gst, GST_RATES, true))                $err = 'Invalid GST rate.';
  elseif ($min < 0)                                        $err = 'Low-stock alert cannot be negative.';

  $variants = json_decode((string)($_POST['variants'] ?? '[]'), true);
  $clean = [];
  if (!$err) {
    if (!is_array($variants) || !$variants)  $err = 'Add at least one stock row.';
    elseif (count($variants) > 60)           $err = 'Too many variants (maximum 60).';
  }
  if (!$err) {
    $seen = [];
    foreach ($variants as $v) {
      $size  = trim((string)($v['size'] ?? ''));
      $color = trim((string)($v['color'] ?? ''));
      if (mb_strlen($size) > 40 || mb_strlen($color) > 40) {
        $err = 'Variant option text is too long (maximum 40).';
        break;
      }
      $key = mb_strtolower($size . '|' . $color);
      if (isset($seen[$key])) {
        $err = "Variant $size / $color is added twice.";
        break;
      }
      $seen[$key] = true;
      $stock = $v['stock'] ?? 0;
      if (!is_numeric($stock) || $stock < 0) {
        $err = "Enter a valid stock for $size / $color.";
        break;
      }
      $vp = $v['price'] ?? '';
      if ($vp === '' || $vp === null) {
        $vp = null;
      } elseif (!is_numeric($vp) || $vp < 0) {
        $err = "Enter a valid price for $size / $color.";
        break;
      } else {
        $vp = (float)$vp;
      }

      /* Variant SKU: use what was sent, else auto-generate on the server too */
      $vsku = trim((string)($v['sku'] ?? ''));
      if ($vsku === '') {
        $vsku = $sku . ($size !== '' ? '-' . $size : '') . ($color !== '' ? '-' . strtoupper(mb_substr($color, 0, 3)) : '');
      }
      $vsku = mb_substr($vsku, 0, 100);

      /* Case-insensitive duplicate check within the submitted variants */
      foreach ($clean as $existingVariant) {
        if (strcasecmp($existingVariant['sku'], $vsku) === 0) {
          $err = 'Variant SKU "' . $vsku . '" is duplicated. Please use a unique Variant SKU.';
          break 2;
        }
      }

      $clean[] = [
        'id'    => (int)($v['id'] ?? 0),
        'size'  => $size,
        'color' => $color,
        'sku'   => $vsku,
        'price' => $vp,
        'stock' => (int)$stock
      ];
    }
  }

  /* existing product + duplicate SKU */
  $existingImgs = [];
  if (!$err) {
    if ($id) {
      $st = $pdo->prepare('SELECT id FROM products WHERE id = ?');
      $st->execute([$id]);
      if (!$st->fetch()) json_out(['success' => false, 'message' => 'This product no longer exists.'], 404);
      $st = $pdo->prepare('SELECT id, file_name FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
      $st->execute([$id]);
      $existingImgs = $st->fetchAll();
    }
    $st = $pdo->prepare('SELECT name FROM products WHERE sku = ? AND id <> ? LIMIT 1');
    $st->execute([$sku, $id]);
    if ($d = $st->fetch()) $err = 'This SKU is already used by "' . $d['name'] . '".';
  }

  /* Variant SKU must not clash with variants of OTHER products */
  if (!$err) {
    $st = $pdo->prepare('SELECT sku FROM product_variants WHERE product_id <> ?');
    $st->execute([$id]);
    $taken = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) {
      $taken[mb_strtolower(trim((string)$s))] = true;
    }
    foreach ($clean as $variant) {
      if (isset($taken[mb_strtolower(trim($variant['sku']))])) {
        $err = 'Variant SKU "' . $variant['sku'] . '" already exists. Please enter a unique Variant SKU.';
        break;
      }
    }
  }

  /* photos */
  $keepIds = [];
  $files   = [];
  if (!$err) {
    $k = json_decode((string)($_POST['keep_images'] ?? '[]'), true);
    $valid = array_map('intval', array_column($existingImgs, 'id'));
    foreach ((array)$k as $x) if (in_array((int)$x, $valid, true)) $keepIds[] = (int)$x;

    if (!empty($_FILES['images']['name'])) {
      $fi = new finfo(FILEINFO_MIME_TYPE);
      $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
      foreach ((array)$_FILES['images']['name'] as $i => $n) {
        $e = $_FILES['images']['error'][$i];
        if ($e === UPLOAD_ERR_NO_FILE) continue;
        if ($e !== UPLOAD_ERR_OK) {
          $err = 'A photo could not be uploaded (error ' . $e . ').';
          break;
        }
        if ($_FILES['images']['size'][$i] > MAX_IMAGE_BYTES) {
          $err = 'Each photo must be 3 MB or smaller.';
          break;
        }
        $tmp  = $_FILES['images']['tmp_name'][$i];
        $mime = $fi->file($tmp);
        if (!isset($ok[$mime])) {
          $err = 'Only JPG, PNG or WEBP photos are allowed.';
          break;
        }
        $files[] = ['tmp' => $tmp, 'ext' => $ok[$mime]];
      }
    }
    if (!$err && count($keepIds) + count($files) > MAX_IMAGES) $err = 'Maximum ' . MAX_IMAGES . ' photos allowed.';
  }

  if ($err) json_out(['success' => false, 'message' => $err], 422);

  /* ---- save (transaction) ---- */
  $newFiles = [];
  $oldFiles = [];
  $isEdit   = $id > 0;
  $pdo->beginTransaction();
  try {
    if ($id) {
      $pdo->prepare('UPDATE products SET name=?, sku=?, category=?, brand=?, gender=?, fabric=?, hsn=?, description=?,
                           mrp=?, price=?, cost=?, gst=?, min_stock=?, status=? WHERE id=?')
        ->execute([$name, $sku, $cat, $brand, $gender, $fabric, $hsn, $desc, $mrp, $price, $cost, $gst, $min, $status, $id]);
    } else {
      $pdo->prepare('INSERT INTO products (name, sku, category, brand, gender, fabric, hsn, description,
                           mrp, price, cost, gst, min_stock, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$name, $sku, $cat, $brand, $gender, $fabric, $hsn, $desc, $mrp, $price, $cost, $gst, $min, $status]);
      $id = (int)$pdo->lastInsertId();
    }

    /* variants: delete removed, update existing, insert new */
    $st = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ?');
    $st->execute([$id]);
    $have = array_map('intval', array_column($st->fetchAll(), 'id'));
    $sent = array_filter(array_column($clean, 'id'));
    foreach (array_diff($have, $sent) as $vid) {
      $pdo->prepare('DELETE FROM product_variants WHERE id = ? AND product_id = ?')->execute([$vid, $id]);
    }
    $upd = $pdo->prepare('UPDATE product_variants SET size=?, color=?, sku=?, price=?, stock=? WHERE id=? AND product_id=?');
    $ins = $pdo->prepare('INSERT INTO product_variants (product_id, size, color, sku, price, stock) VALUES (?,?,?,?,?,?)');
    foreach ($clean as $v) {
      if ($v['id'] && in_array($v['id'], $have, true)) {
        $upd->execute([$v['size'], $v['color'], $v['sku'], $v['price'], $v['stock'], $v['id'], $id]);
      } else {
        $ins->execute([$id, $v['size'], $v['color'], $v['sku'], $v['price'], $v['stock']]);
      }
    }

    /* photos: remove unchecked ones */
    foreach ($existingImgs as $im) {
      if (!in_array((int)$im['id'], $keepIds, true)) {
        $pdo->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?')->execute([$im['id'], $id]);
        $oldFiles[] = $im['file_name'];
      }
    }
    /* photos: add new ones */
    if ($files) {
      if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
        throw new RuntimeException('Upload folder "uploads/products" could not be created. Please create it and make it writable.');
      }
      $order = count($keepIds);
      $insImg = $pdo->prepare('INSERT INTO product_images (product_id, file_name, sort_order) VALUES (?,?,?)');
      foreach ($files as $f) {
        $fn = 'p' . $id . '_' . bin2hex(random_bytes(8)) . '.' . $f['ext'];
        if (!move_uploaded_file($f['tmp'], UPLOAD_DIR . $fn)) {
          throw new RuntimeException('Could not save an uploaded photo. Check folder permission of uploads/products.');
        }
        $newFiles[] = $fn;
        $insImg->execute([$id, $fn, $order++]);
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    delete_files($newFiles);
    $msg = db_error_message($e);
    error_log('[products.php save] ' . $e->getMessage());
    json_out(['success' => false, 'message' => $msg], 500);
  }
  delete_files($oldFiles);
  json_out(['success' => true, 'id' => $id, 'message' => $isEdit ? 'Product updated' : 'Product added']);
}

function api_delete(): void
{
  require_post_csrf();
  $id = (int)($_POST['id'] ?? 0);
  $pdo = db();
  $st = $pdo->prepare('SELECT file_name FROM product_images WHERE product_id = ?');
  $st->execute([$id]);
  $files = array_column($st->fetchAll(), 'file_name');
  $del = $pdo->prepare('DELETE FROM products WHERE id = ?');   // variants + image rows are removed by FOREIGN KEY CASCADE
  $del->execute([$id]);
  if ($del->rowCount() < 1) json_out(['success' => false, 'message' => 'Product not found or already deleted.'], 404);
  delete_files($files);
  json_out(['success' => true, 'message' => 'Product deleted']);
}

/* ----------------------------- ROUTER ------------------------------ */
if (empty($_SESSION['csrf_products'])) {
  $_SESSION['csrf_products'] = bin2hex(random_bytes(32));
}

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
      case 'save':
        api_save();
        break;
      case 'delete':
        api_delete();
        break;
      case 'billing':
        api_billing();
        break;
      case 'low_count':
        api_low_count();
        break;
      default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 400);
    }
  } catch (Throwable $e) {
    error_log('[products.php] ' . $e->getMessage());
    json_out(['success' => false, 'message' => db_error_message($e)], 500);
  }
  exit;
}

require_auth(false);
$csrf = $_SESSION['csrf_products'];
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?= h($csrf) ?>" />
  <title>Products | <?= crm_e(crm_brand()['name']) ?> CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link href="assets/css/style.css" rel="stylesheet" />
  <?php crm_head(); ?>
  
  <style>
      /* ================= PRODUCTS TABLE STYLE ================= */

#prodPanel .table-responsive {
  width: 100%;
  overflow-x: auto;
  border: 1px solid #dfe3eb;
  border-radius: 4px;
  background: #ffffff;
}

#pTable {
  width: 100%;
  margin-bottom: 0;
  border-collapse: separate;
  border-spacing: 0;
  color: #263247;
  font-family: 'Inter', sans-serif;
  font-size: 13px;
  background: #ffffff;
}

/* Table Header */
#pTable thead th {
  padding: 14px 16px;
  background: #f4f6fa;
  color: #344054;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  white-space: nowrap;
  border-bottom: 1px solid #dfe3eb;
  border-right: 1px solid #e5e9f0;
}

#pTable thead th:last-child {
  border-right: none;
}

/* Table Body Cells */
#pTable tbody td {
  padding: 13px 16px;
  vertical-align: middle;
  border-bottom: 1px solid #e5e9f0;
  border-right: 1px solid #edf0f5;
  color: #344054;
  background: #ffffff;
}

#pTable tbody td:last-child {
  border-right: none;
}

/* Alternating Row Background */
#pTable tbody tr:nth-child(even) td {
  background: #fafbfc;
}

/* Row Hover Effect */
#pTable tbody tr:hover td {
  background: #f0f5ff;
  transition: background-color 0.2s ease;
}

/* Last Row Border */
#pTable tbody tr:last-child td {
  border-bottom: none;
}

/* Product Name */
#pTable .fw-semibold {
  color: #1f2937;
  font-size: 13px;
  font-weight: 600;
}

/* Product SKU */
#pTable small.text-muted {
  color: #7b8495 !important;
  font-size: 11px;
}

/* Product Thumbnail */
#pTable .prod-thumb {
  width: 42px;
  height: 42px;
  min-width: 42px;
  object-fit: cover;
  border: 1px solid #e0e5ed;
  border-radius: 4px;
  background: #f4f6fa;
}

#pTable .prod-thumb.ph {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #98a2b3;
}

/* Price */
#pTable td b {
  color: #202b3c;
  font-size: 13px;
  font-weight: 700;
}

/* Status Badges */
#pTable .badge-s {
  display: inline-block;
  padding: 5px 9px;
  border: 1px solid transparent;
  border-radius: 5px;
  font-size: 10px;
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;
}

/* Action Buttons */
#pTable .btn-icon {
  width: 30px;
  height: 30px;
  margin-left: 4px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #475467;
  background: #ffffff;
  border: 1px solid #dfe3eb;
  border-radius: 4px;
  transition: all 0.2s ease;
}

#pTable .btn-icon:hover {
  color: #b58a35;
  background: #fffaf0;
  border-color: #d5b56c;
}

#pTable .btn-icon:focus {
  box-shadow: 0 0 0 3px rgba(181, 138, 53, 0.12);
}

/* Variants Table Inside Product Details */
#vBody .table-responsive {
  overflow-x: auto;
  border: 1px solid #dfe3eb;
  border-radius: 4px;
  background: #ffffff;
}

#vBody .table {
  width: 100%;
  margin-bottom: 0;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12px;
}

#vBody .table thead th {
  padding: 11px 12px;
  color: #344054;
  background: #f4f6fa;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  white-space: nowrap;
  border-bottom: 1px solid #dfe3eb;
  border-right: 1px solid #e5e9f0;
}

#vBody .table thead th:last-child {
  border-right: none;
}

#vBody .table tbody td {
  padding: 10px 12px;
  color: #344054;
  vertical-align: middle;
  border-bottom: 1px solid #e5e9f0;
  border-right: 1px solid #edf0f5;
}

#vBody .table tbody td:last-child {
  border-right: none;
}

#vBody .table tbody tr:nth-child(even) td {
  background: #fafbfc;
}

#vBody .table tbody tr:hover td {
  background: #f0f5ff;
}

#vBody .table tbody tr:last-child td {
  border-bottom: none;
}

/* Responsive Table */
@media (max-width: 768px) {
  #pTable {
    min-width: 800px;
  }

  #pTable thead th,
  #pTable tbody td {
    padding: 11px 12px;
  }

  #pTable .prod-thumb {
    width: 36px;
    height: 36px;
    min-width: 36px;
  }

  #vBody .table {
    min-width: 550px;
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
          <h1 id="pageTitle">All products</h1>
          <p class="text-muted mb-0">Add any kind of product, track stock and use them while billing.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php if (crm_can('products.add')): ?><button class="btn btn-gold" data-pa="add"><i class="bi bi-plus-circle me-1"></i>Add product</button><?php endif; ?>
        </div>
      </div>

      <div id="summary"></div>

      <div class="panel" id="prodPanel">
        <div class="row g-2 toolbar">
          <div class="col-12 col-md-4">
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
              <input class="form-control" id="pSearch" placeholder="Search by name, SKU, category..." aria-label="Search products" />
            </div>
          </div>
          <div class="col-6 col-md-3">
            <select class="form-select" id="fCat" aria-label="Category">
              <option value="">All categories</option>
              <?php foreach (product_categories() as $c): ?>
                <option value="<?= h($c) ?>"><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select class="form-select" id="fStatus" aria-label="Status">
              <option value="">All status</option>
              <option>Active</option>
              <option>Inactive</option>
            </select>
          </div>
          <div class="col text-md-end align-self-center">
            <small class="text-muted" id="pCount"></small>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle mb-0" id="pTable" data-crm-table data-crm-search="off">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Price</th>
                <th>Variants</th>
                <th>Stock</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div class="text-center text-muted py-5 d-none" id="pLoading">
          <span class="spinner-border spinner-border-sm me-2"></span>Loading products...
        </div>
        <div class="empty d-none text-center" id="pEmpty">
          <i class="bi bi-box-seam fs-1"></i>
          <p class="mb-2">No products found.</p>
          <?php if (crm_can('products.add')): ?><button class="btn btn-gold btn-sm" data-pa="add"><i class="bi bi-plus-circle me-1"></i>Add your first product</button><?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <!-- Add / Edit modal -->
  <div class="modal fade" id="pModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="pTitle"></h5>
          <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="pBody"></div>
        <div class="modal-footer">
          <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-gold" data-pa="save"><i class="bi bi-check-lg me-1"></i>Save product</button>
        </div>
      </div>
    </div>
  </div>

  <!-- View modal -->
  <div class="modal fade" id="vModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Product details</h5>
          <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="vBody"></div>
        <div class="modal-footer">
          <button class="btn btn-light me-auto" data-bs-dismiss="modal">Close</button>
          <button class="btn btn-outline-danger<?= crm_can('products.delete') ? '' : ' d-none' ?>" data-pa="view-del"><i class="bi bi-trash me-1"></i>Delete</button>
          <button class="btn btn-primary<?= crm_can('products.edit') ? '' : ' d-none' ?>" data-pa="view-edit"><i class="bi bi-pencil me-1"></i>Edit product</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Delete confirm modal -->
  <div class="modal fade" id="dModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
      <div class="modal-content">
        <div class="modal-body text-center p-4">
          <div class="ic-warn"><i class="bi bi-exclamation-triangle"></i></div>
          <h5 class="mt-3">Delete product?</h5>
          <p class="text-muted mb-4" id="dText"></p>
          <button class="btn btn-light me-2" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger" data-pa="confirm-del">Yes, delete</button>
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
      const CATS = <?= json_encode(product_categories()) ?>,
        GENDERS = <?= json_encode(product_groups()) ?>,
        LBL = <?= json_encode(['l1' => crm_setting('variant_label_1', 'Size'), 'l2' => crm_setting('variant_label_2', 'Color'), 'group' => crm_setting('product_group_label', 'Suitable for'), 'mat' => crm_setting('product_material_label', 'Material / specification')]) ?>,
        GSTS = <?= json_encode(GST_RATES) ?>;
      const SIZES = /^size$/i.test(LBL.l1) ? ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'Free'] : [];   // quick-add buttons only for a Size label

      /* ==================== SKU HELPERS (global to this page) ====================
         NOTE: these live at the top level of the IIFE so that BOTH openP() and the
         click handler (v-add / v-quick) can use them. */
      function makeStyleSKU(name) {
        const cleanName = String(name || '')
          .toUpperCase()
          .replace(/[^A-Z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '')
          .slice(0, 30);
        return cleanName ? 'SKU-' + cleanName : '';
      }

      function makeVariantSKU(styleSKU, size, color) {
        const cleanSize = String(size || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '');
        const cleanColor = String(color || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '');
        const base = String(styleSKU || '').trim().toUpperCase() || 'AUR-PRODUCT';
        return (base + (cleanSize ? '-' + cleanSize : '') + (cleanColor ? '-' + cleanColor : '')).slice(0, 100);
      }

      /* Fill the Variant SKU of one row (only if the user has not typed their own).
         SKU is generated from the style code and whichever option fields are filled. */
      function refreshVariantSKU(row) {
        if (!row) return;
        const skuField = $('.v-sku', row),
          sizeField = $('.v-size', row),
          colorField = $('.v-color', row);
        if (!skuField || !sizeField || !colorField) return;
        if (skuField.dataset.edited === '1') return;      // manual SKU: do not overwrite
        const size = sizeField.value.trim(),
          color = colorField.value.trim();
        const style = ($('#pSku') || {}).value || '';
        skuField.value = style ? makeVariantSKU(style, size, color) : '';
      }

      const refreshAllVariantSKUs = () => $$('#pVars [data-var]').forEach(refreshVariantSKU);

      /* Delegated listeners on the modal body: they survive every re-render of the form. */
      $('#pBody').addEventListener('input', e => {
        const t = e.target;
        if (t.matches('.v-size, .v-color')) {
          refreshVariantSKU(t.closest('[data-var]'));
        } else if (t.matches('.v-sku')) {
          // typed by the user = manual; cleared by the user = go back to auto
          t.dataset.edited = t.value.trim() ? '1' : '';
          if (!t.value.trim()) refreshVariantSKU(t.closest('[data-var]'));
        } else if (t.id === 'pSku') {
          t.dataset.edited = t.value.trim() ? '1' : '';
          refreshAllVariantSKUs();
        } else if (t.id === 'pName' && !curP) {
          const sk = $('#pSku');
          if (sk && !sk.dataset.edited) {
            sk.value = makeStyleSKU(t.value);
            refreshAllVariantSKUs();
          }
        }
      });

      const MAX_IMG = <?= (int)MAX_IMAGES ?>,
        MAX_BYTES = <?= (int)MAX_IMAGE_BYTES ?>;
      const TITLES = {
        all: 'All products',
        low: 'Low stock products',
        inactive: 'Inactive products'
      };

      const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      } [c]));
      const inr = n => '₹' + Math.round(n).toLocaleString('en-IN');
      const TONE = {
        Active: 'green',
        Inactive: 'gray',
        'In stock': 'green',
        'Low stock': 'amber',
        'Out of stock': 'red'
      };
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
          const res = await fetch('products.php?api=' + action + (opt.qs ? '&' + opt.qs : ''), {
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

      /* ---- state ---- */
      const params = new URLSearchParams(location.search);
      const view = TITLES[params.get('view')] ? params.get('view') : 'all';
      $('#pageTitle').textContent = TITLES[view];
      let rows = [],
        curP = null,
        keepImgs = [],
        newImgs = [],
        delTarget = null,
        seq = 0;
      const pm = new bootstrap.Modal('#pModal'),
        vm = new bootstrap.Modal('#vModal'),
        dm = new bootstrap.Modal('#dModal');

      const thumb = p => p.imgs.length ? `<img class="prod-thumb" src="${esc(p.imgs[0].url)}" alt="">` : `<span class="prod-thumb ph"><i class="bi bi-bag"></i></span>`;
      const CAN = { add: <?= crm_can('products.add') ? 'true' : 'false' ?>, edit: <?= crm_can('products.edit') ? 'true' : 'false' ?>, del: <?= crm_can('products.delete') ? 'true' : 'false' ?> };
      const ib = (a, i, t) => `<button class="btn btn-sm btn-icon" data-pa="${a}" title="${t}" aria-label="${t}"><i class="bi bi-${i}"></i></button>`;

      /* ---- list ---- */
      async function load() {
        const my = ++seq;
        $('#pLoading').classList.remove('d-none');
        const qs = new URLSearchParams({
          view,
          q: $('#pSearch').value.trim(),
          cat: $('#fCat').value,
          status: $('#fStatus').value
        }).toString();
        const j = await call('list', {
          qs
        });
        if (my !== seq) return;
        $('#pLoading').classList.add('d-none');
        if (!j.success) {
          toast(j.message || 'Could not load products.', 'error');
          return;
        }
        rows = j.products;
        draw(j.summary);
      }

      function draw(s) {
        $('#pTable tbody').innerHTML = rows.map(r => `<tr data-id="${r.id}">
<td><div class="d-flex align-items-center gap-2">${thumb(r)}<div class="min-w-0" style="font-size:12px"><div class="fw-semibold">${esc(r.name)}</div><small class="text-muted">${esc(r.sku)}</small></div></div></td>
<td>${esc(r.cat)}</td>
<td><b>${inr(r.price)}</b>${r.mrp > r.price ? `<br><small class="text-muted text-decoration-line-through">${inr(r.mrp)}</small>` : ''}</td>
<td>${r.variants.length} ${r.variants.length === 1 ? 'variant' : 'variants'}</td>
<td>${badge(r.state)}<br><small class="text-muted">${r.total} pcs</small></td>
<td>${badge(r.status)}</td>
<td class="text-end text-nowrap">${ib('view', 'eye', 'View details')}${CAN.edit ? ib('edit', 'pencil-square', 'Edit product') : ''}${CAN.del ? ib('del', 'trash3', 'Delete product') : ''}</td></tr>`).join('');
        $('#pEmpty').classList.toggle('d-none', rows.length > 0);
        $('#pCount').textContent = rows.length + ' records';
        $('#summary').innerHTML = `<div class="row g-3 mb-3">
<div class="col-6 col-md-3"><div class="stat"><span class="ico"><i class="bi bi-box-seam"></i></span><div><small>Products</small><b>${s.products}</b></div></div></div>
<div class="col-6 col-md-3"><div class="stat"><span class="ico"><i class="bi bi-boxes"></i></span><div><small>Total pieces</small><b>${s.pieces}</b></div></div></div>
<div class="col-6 col-md-3"><div class="stat"><span class="ico"><i class="bi bi-exclamation-triangle"></i></span><div><small>Low / out of stock</small><b>${s.low}</b></div></div></div>
<div class="col-6 col-md-3"><div class="stat"><span class="ico"><i class="bi bi-currency-rupee"></i></span><div><small>Stock value</small><b>${inr(s.value)}</b></div></div></div></div>`;
      }

      /* ---- view ---- */
      function viewP(r) {
        curP = r;
        const disc = r.mrp > r.price ? Math.round((r.mrp - r.price) / r.mrp * 100) : 0;
        const gal = r.imgs.length ?
          `<img class="prod-hero mb-2" id="pHero" src="${esc(r.imgs[0].url)}" alt="${esc(r.name)}"><div class="img-prev mt-0">${r.imgs.map(m => `<span><img src="${esc(m.url)}" data-pa="hero" data-src="${esc(m.url)}" style="cursor:pointer" alt=""></span>`).join('')}</div>` :
          `<div class="prod-hero text-center py-5 text-muted" style="background:#eef1f7;display:grid;place-items:center"><div><i class="bi bi-image fs-1"></i><div class="small">No photo added</div></div></div>`;
        $('#vBody').innerHTML = `<div class="row g-4"><div class="col-md-5">${gal}</div><div class="col-md-7">
<h4 class="mb-1">${esc(r.name)}</h4><div class="mb-2">${badge(r.status)} ${badge(r.state)} <span class="badge-s b-blue">${esc(r.cat)}</span></div>
<div class="d-flex align-items-baseline gap-2 flex-wrap mb-3"><span class="fs-3 fw-bold" style="color:var(--navy)">${inr(r.price)}</span>${r.mrp > r.price ? `<s class="text-muted">${inr(r.mrp)}</s><span class="badge-s b-green">${disc}% off</span>` : ''}<small class="text-muted">+ ${r.gst}% GST</small></div>
<div class="row g-3 kv">
<div class="col-6"><small>SKU</small><div>${esc(r.sku)}</div></div><div class="col-6"><small>Brand</small><div>${esc(r.brand) || '-'}</div></div>
<div class="col-6"><small>${esc(LBL.mat)}</small><div>${esc(r.fabric) || '-'}</div></div><div class="col-6"><small>${esc(LBL.group)}</small><div>${esc(r.gender)}</div></div>
<div class="col-6"><small>HSN code</small><div>${esc(r.hsn) || '-'}</div></div><div class="col-6"><small>Cost price</small><div>${r.cost ? inr(r.cost) : '-'}</div></div>
<div class="col-6"><small>Total stock</small><div>${r.total} pcs</div></div><div class="col-6"><small>Low-stock alert</small><div>at ${r.min} pcs</div></div>
<div class="col-6"><small>Added on</small><div>${esc(r.created)}</div></div><div class="col-6"><small>Last updated</small><div>${esc(r.updated)}</div></div></div></div>
<div class="col-12"><small class="text-muted fw-semibold text-uppercase">Description</small><p class="mb-0">${esc(r.desc) || '<span class="text-muted">No description</span>'}</p></div>
<div class="col-12"><h6 class="mb-2"><i class="bi bi-boxes text-gold me-1"></i>Variants (${r.variants.length})</h6><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>${esc(LBL.l1)}</th><th>${esc(LBL.l2)}</th><th>Variant SKU</th><th class="text-end">Price</th><th class="text-end">Stock</th></tr></thead><tbody>${r.variants.map(v => `<tr><td>${esc(v.size)}</td><td>${esc(v.color)}</td><td><small>${esc(v.sku)}</small></td><td class="text-end">${inr(v.price === null ? r.price : v.price)}</td><td class="text-end">${v.stock === 0 ? '<span class="badge-s b-red">Out</span>' : v.stock <= 2 ? `<span class="badge-s b-amber">${v.stock}</span>` : v.stock}</td></tr>`).join('')}</tbody></table></div></div></div>`;
        vm.show();
      }

      /* ---- add / edit form ---- */
      const fld = (id, label, icon, input, hint, col = 'col-md-6') => `<div class="${col}"><label class="form-label" for="${id}"><i class="bi bi-${icon}"></i> ${label}</label>${input}${hint ? `<div class="form-text">${hint}</div>` : ''}<div class="invalid-feedback">Please fill this correctly.</div></div>`;

      // Saved variants (that already have a SKU) are marked data-edited="1" so editing
      // size/colour never silently changes an existing SKU.
      const varRow = (v = {}) => `<div class="var-card" data-var data-vid="${v.id || 0}"><button type="button" class="btn btn-sm btn-icon rm" data-pa="v-rm" title="Remove this variant" aria-label="Remove variant"><i class="bi bi-x-lg"></i></button><div class="row g-2">
<div class="col-6 col-md-2"><label class="form-label">${esc(LBL.l1)}</label><input class="form-control form-control-sm v-size" list="sizeList" maxlength="40" value="${esc(v.size || '')}" placeholder="optional"></div>
<div class="col-6 col-md-3"><label class="form-label">${esc(LBL.l2)}</label><input class="form-control form-control-sm v-color" maxlength="40" value="${esc(v.color || '')}" placeholder="optional"></div>
<div class="col-12 col-md-3"><label class="form-label">Variant SKU</label><input class="form-control form-control-sm v-sku" maxlength="100" value="${esc(v.sku || '')}" ${v.sku ? 'data-edited="1"' : ''} placeholder="Auto if empty"></div>
<div class="col-6 col-md-2"><label class="form-label">Price (₹)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm v-price" value="${v.price ?? ''}" placeholder="Same as base"></div>
<div class="col-6 col-md-2"><label class="form-label">Stock</label><input type="number" min="0" step="1" class="form-control form-control-sm v-stock" value="${v.stock ?? ''}" placeholder="0" required></div></div></div>`;

      function addVariantRow(data) {
        $('#pVars').insertAdjacentHTML('beforeend', varRow(data));
        const row = $('#pVars').lastElementChild;
        refreshVariantSKU(row);
        return row;
      }

      function drawImgs() {
        $('#pImgPrev').innerHTML =
          keepImgs.map((m, i) => `<span><img src="${esc(m.url)}" alt="Product photo"><button type="button" data-pa="img-rm-old" data-i="${i}" aria-label="Remove photo">&times;</button></span>`).join('') +
          newImgs.map((m, i) => `<span><img src="${m.url}" alt="New photo"><button type="button" data-pa="img-rm-new" data-i="${i}" aria-label="Remove photo">&times;</button></span>`).join('');
      }

      function checkPrice() {
        const m = $('#pMrp'),
          p = $('#pPrice');
        if (!m || !p) return;
        p.setCustomValidity(+p.value > +m.value ? 'Selling price cannot be more than MRP' : '');
        p.closest('div').querySelector('.invalid-feedback').textContent = p.validationMessage || 'Please fill this correctly.';
      }

      function openP(r) {
        curP = r || null;
        keepImgs = r ? r.imgs.map(m => ({
          ...m
        })) : [];
        newImgs.forEach(m => URL.revokeObjectURL(m.url));
        newImgs = [];
        const x = r || {
          name: '',
          sku: '',
          cat: '',
          brand: '',
          gender: '',
          fabric: '',
          hsn: '',
          desc: '',
          mrp: '',
          price: '',
          cost: '',
          gst: 12,
          min: 5,
          status: 'Active',
          variants: [{}]
        };
        $('#pTitle').innerHTML = r ? '<i class="bi bi-pencil-square me-2"></i>Edit product' : '<i class="bi bi-plus-circle me-2"></i>Add new product';
        $('#pBody').innerHTML = `<form id="pForm" novalidate>
<h6 class="mb-1"><i class="bi bi-info-circle text-gold me-1"></i>1. Basic details</h6><p class="text-muted small mb-3">Name and category help you find this product quickly while billing.</p>
<div class="row g-3 mb-4">
${fld('pName', 'Product name <span class="text-danger">*</span>', 'tag', `<input id="pName" class="form-control" required maxlength="80" placeholder="Product name" value="${esc(x.name)}">`, '', 'col-md-8')}
${fld('pSku', 'Style code / SKU <span class="text-danger">*</span>', 'upc-scan', `<input id="pSku" class="form-control" required maxlength="60" placeholder="e.g. PRD-101" value="${esc(x.sku)}" ${r ? 'data-edited="1"' : ''}>`, 'Must be unique', 'col-md-4')}
${fld('pCat', 'Category <span class="text-danger">*</span>', 'grid', `<input id="pCat" class="form-control" required maxlength="40" list="catList" placeholder="Type or pick a category" value="${esc(x.cat)}"><datalist id="catList">${CATS.map(c => `<option value="${esc(c)}">`).join('')}</datalist>`, '', 'col-md-4')}
${fld('pBrand', 'Brand', 'award', `<input id="pBrand" class="form-control" maxlength="60" placeholder="Brand (optional)" value="${esc(x.brand)}">`, '', 'col-md-4')}
${fld('pGender', esc(LBL.group), 'person', `<input id="pGender" class="form-control" maxlength="40" list="grpList" placeholder="optional" value="${esc(x.gender)}"><datalist id="grpList">${GENDERS.map(c => `<option value="${esc(c)}">`).join('')}</datalist>`, '', 'col-md-4')}
${fld('pFabric', esc(LBL.mat), 'layers', `<input id="pFabric" class="form-control" maxlength="80" placeholder="optional" value="${esc(x.fabric)}">`, '', 'col-md-6')}
${fld('pHsn', 'HSN code', 'hash', `<input id="pHsn" class="form-control" inputmode="numeric" maxlength="8" pattern="\\d{0,8}" placeholder="e.g. 6204" value="${esc(x.hsn)}">`, 'Used on the GST invoice', 'col-md-6')}
${fld('pDesc', 'Description', 'card-text', `<textarea id="pDesc" class="form-control" rows="2" placeholder="Details, specifications, care instructions">${esc(x.desc)}</textarea>`, '', 'col-12')}</div>
<h6 class="mb-1"><i class="bi bi-currency-rupee text-gold me-1"></i>2. Price &amp; tax</h6><p class="text-muted small mb-3">Selling price cannot be more than MRP.</p>
<div class="row g-3 mb-4">
${fld('pMrp', 'MRP (₹) <span class="text-danger">*</span>', 'tag', `<input type="number" min="1" step="0.01" id="pMrp" class="form-control" required placeholder="0" value="${x.mrp}">`, 'Printed price', 'col-6 col-md-3')}
${fld('pPrice', 'Selling price (₹) <span class="text-danger">*</span>', 'cash-coin', `<input type="number" min="1" step="0.01" id="pPrice" class="form-control" required placeholder="0" value="${x.price}">`, 'Price you charge', 'col-6 col-md-3')}
${fld('pCost', 'Cost price (₹)', 'wallet2', `<input type="number" min="0" step="0.01" id="pCost" class="form-control" placeholder="0" value="${x.cost || ''}">`, 'Only you can see this', 'col-6 col-md-3')}
${fld('pGst', 'GST rate', 'percent', `<select id="pGst" class="form-select">${GSTS.map(g => `<option value="${g}" ${+x.gst === g ? 'selected' : ''}>${g}%</option>`).join('')}</select>`, '', 'col-6 col-md-3')}</div>
<h6 class="mb-1"><i class="bi bi-images text-gold me-1"></i>3. Photos</h6><p class="text-muted small mb-2">Add up to ${MAX_IMG} photos (JPG, PNG or WEBP, max 3 MB each).</p>
<input type="file" id="pFile" class="form-control" accept="image/jpeg,image/png,image/webp" multiple><div class="img-prev mb-4" id="pImgPrev"></div>
<h6 class="mb-1"><i class="bi bi-boxes text-gold me-1"></i>4. Stock (variants optional)</h6><p class="text-muted small mb-2">Add one row per variant you stock (${esc(LBL.l1)} / ${esc(LBL.l2)} are optional: leave them empty for a single item). ${SIZES.length ? 'Quick add:' : ''}</p>
<div class="d-flex flex-wrap gap-2 mb-3">${SIZES.map(s => `<button type="button" class="btn btn-sm btn-outline-primary" data-pa="v-quick" data-s="${s}">+ ${s}</button>`).join('')}<button type="button" class="btn btn-sm btn-gold" data-pa="v-add"><i class="bi bi-plus-lg me-1"></i>Add row</button></div>
<datalist id="sizeList">${[...SIZES, '28', '30', '32', '34', '36', '38', '40'].map(s => `<option value="${s}">`).join('')}</datalist>
<div class="d-grid gap-2 mb-4" id="pVars">${x.variants.map(varRow).join('')}</div>
<h6 class="mb-1"><i class="bi bi-toggles text-gold me-1"></i>5. Status</h6>
<div class="row g-3">
${fld('pMin', 'Low-stock alert at', 'bell', `<input type="number" min="0" step="1" id="pMin" class="form-control" value="${x.min}">`, 'Warn me when total stock is this or less', 'col-md-6')}
<div class="col-md-6 align-self-center"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="pActive" ${x.status !== 'Inactive' ? 'checked' : ''}><label class="form-check-label" for="pActive">Active (show while billing)</label></div></div></div></form>`;

        // Variant SKUs for rows that do not have one yet (new product form)
        refreshAllVariantSKUs();

        drawImgs();
        $('#pFile').onchange = e => {
          for (const f of e.target.files) {
            if (keepImgs.length + newImgs.length >= MAX_IMG) {
              toast('Maximum ' + MAX_IMG + ' photos allowed.', 'error');
              break;
            }
            if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
              toast(f.name + ': only JPG, PNG or WEBP allowed.', 'error');
              continue;
            }
            if (f.size > MAX_BYTES) {
              toast(f.name + ' is larger than 3 MB.', 'error');
              continue;
            }
            newImgs.push({
              file: f,
              url: URL.createObjectURL(f)
            });
          }
          e.target.value = '';
          drawImgs();
        };
        $('#pMrp').addEventListener('input', checkPrice);
        $('#pPrice').addEventListener('input', checkPrice);

        pm.show();
      }

      async function saveProduct(btn) {
        const f = $('#pForm');
        if (!f) return;
        f.classList.add('was-validated');
        checkPrice();
        const vs = $$('[data-var]', f);
        if (!vs.length) {
          toast('Add at least one stock row.', 'error');
          return;
        }
        if (!f.checkValidity()) {
          toast('Please fix the highlighted fields.', 'error');
          const x = f.querySelector(':invalid');
          x && x.focus();
          return;
        }
        const variants = [],
          seen = new Set(),
          seenSku = new Set();
        for (const el of vs) {
          const g = k => $(k, el).value.trim(),
            size = g('.v-size'),
            color = g('.v-color'),
            key = (size + '|' + color).toLowerCase();
          if (seen.has(key)) {
            toast(`Variant ${size} / ${color} is added twice.`, 'error');
            return;
          }
          seen.add(key);
          let vsku = g('.v-sku');
          if (!vsku) vsku = makeVariantSKU($('#pSku').value, size, color);
          if (seenSku.has(vsku.toLowerCase())) {
            toast(`Variant SKU "${vsku}" is duplicated. Please use a unique Variant SKU.`, 'error');
            return;
          }
          seenSku.add(vsku.toLowerCase());
          variants.push({
            id: +el.dataset.vid || 0,
            size,
            color,
            sku: vsku,
            price: g('.v-price'),
            stock: g('.v-stock')
          });
        }
        const fd = new FormData();
        fd.append('id', curP ? curP.id : 0);
        fd.append('name', $('#pName').value.trim());
        fd.append('sku', $('#pSku').value.trim());
        fd.append('cat', $('#pCat').value);
        fd.append('brand', $('#pBrand').value.trim());
        fd.append('gender', $('#pGender').value.trim());
        fd.append('fabric', $('#pFabric').value.trim());
        fd.append('hsn', $('#pHsn').value.trim());
        fd.append('desc', $('#pDesc').value.trim());
        fd.append('mrp', $('#pMrp').value);
        fd.append('price', $('#pPrice').value);
        fd.append('cost', $('#pCost').value || 0);
        fd.append('gst', $('#pGst').value);
        fd.append('min', $('#pMin').value || 0);
        fd.append('status', $('#pActive').checked ? 'Active' : 'Inactive');
        fd.append('variants', JSON.stringify(variants));
        fd.append('keep_images', JSON.stringify(keepImgs.map(m => m.id)));
        newImgs.forEach(m => fd.append('images[]', m.file));

        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
        const j = await call('save', {
          body: fd
        });
        btn.disabled = false;
        btn.innerHTML = old;
        if (j.success) {
          const wasEdit = !!curP;
          pm.hide();
          toast(wasEdit ? 'Product updated' : 'Product added');
          load();
        } else toast(j.message || 'Could not save the product.', 'error');
      }

      /* ---- delete ---- */
      function askDelete(r) {
        delTarget = r;
        $('#dText').textContent = `Delete "${r.name}" and all its ${r.variants.length} variant(s)? This cannot be undone.`;
        dm.show();
      }
      async function confirmDelete(btn) {
        if (!delTarget) return;
        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting...';
        const j = await call('delete', {
          body: new URLSearchParams({
            id: delTarget.id
          })
        });
        btn.disabled = false;
        btn.innerHTML = old;
        if (j.success) {
          dm.hide();
          delTarget = null;
          toast('Product deleted');
          load();
        } else toast(j.message || 'Could not delete the product.', 'error');
      }
      const afterHide = (m, el, fn) => {
        el.addEventListener('hidden.bs.modal', fn, {
          once: true
        });
        m.hide();
      };

      /* ---- clicks ---- */
      document.addEventListener('click', e => {
        const b = e.target.closest('[data-pa]');
        if (!b) return;
        const a = b.dataset.pa,
          tr = b.closest('tr[data-id]'),
          row = tr ? rows.find(x => x.id === +tr.dataset.id) : null;
        switch (a) {
          case 'add':
            openP();
            break;
          case 'view':
            row && viewP(row);
            break;
          case 'edit':
            row && openP(row);
            break;
          case 'del':
            row && askDelete(row);
            break;
          case 'save':
            saveProduct(b);
            break;
          case 'confirm-del':
            confirmDelete(b);
            break;
          case 'view-edit': {
            const r = curP;
            afterHide(vm, $('#vModal'), () => openP(r));
            break;
          }
          case 'view-del': {
            const r = curP;
            afterHide(vm, $('#vModal'), () => askDelete(r));
            break;
          }
          case 'hero': {
            const h = $('#pHero');
            h && (h.src = b.dataset.src);
            break;
          }
          case 'v-add': {
            const nr = addVariantRow();          // SKU fills automatically once size + colour are typed
            $('.v-size', nr).focus();
            break;
          }
          case 'v-quick': {
            const nr = addVariantRow({
              size: b.dataset.s
            });
            $('.v-color', nr).focus();           // type colour -> Variant SKU is generated instantly
            break;
          }
          case 'v-rm':
            if ($$('[data-var]').length > 1) b.closest('[data-var]').remove();
            else toast('Keep at least one variant.', 'error');
            break;
          case 'img-rm-old':
            keepImgs.splice(+b.dataset.i, 1);
            drawImgs();
            break;
          case 'img-rm-new': {
            const m = newImgs.splice(+b.dataset.i, 1)[0];
            m && URL.revokeObjectURL(m.url);
            drawImgs();
            break;
          }
        }
      });

      /* ---- filters ---- */
      let t;
      $('#pSearch').addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(load, 300);
      });
      $('#fCat').addEventListener('change', load);
      $('#fStatus').addEventListener('change', load);

      load();
      if (params.get('new') && CAN.add) setTimeout(() => openP(), 250);
    })();
  </script>
<?php crm_foot(); ?>
</body>

</html>