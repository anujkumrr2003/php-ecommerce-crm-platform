<?php
/*
|--------------------------------------------------------------------------
| inc/bootstrap.php  -  loaded by every page of the website
|--------------------------------------------------------------------------
| config + database + session + security headers + small helpers.
| Shared business code (orders, e-mail, Razorpay) is in inc/webcore (wc_*).
*/
if (defined('SHOP_BOOT')) {
    return;
}
define('SHOP_BOOT', true);
define('SHOP_ROOT', dirname(__DIR__));

if (!is_file(SHOP_ROOT . '/config.php')) {
    header('Location: install/');
    exit('Website is not installed yet. Open /install in your browser.');
}
require_once SHOP_ROOT . '/config.php';          // DB_*, BASE_PATH, $conn
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/webcore/load.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', '');
}

/* ------------------------------------------------------------- security */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

function shop_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
if (shop_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('SHOPSESSID');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => shop_https(), 'httponly' => true, 'samesite' => 'Lax']);
session_start();

/* ------------------------------------------------------------- helpers */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_PATH . '/' . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    $base = wc_site_url();
    if ($base === '') {
        $base = (shop_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_PATH;
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $f = SHOP_ROOT . '/' . $path;
    return url($path) . (is_file($f) ? '?v=' . filemtime($f) : '');
}

function S(string $key, string $default = ''): string
{
    return wc_setting($key, $default);
}

function redirect(string $to, int $code = 302): void
{
    header('Location: ' . (preg_match('#^https?://#i', $to) ? $to : url($to)), true, $code);
    exit;
}

function json_out(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf()) . '">';
}

function csrf_ok(): bool
{
    $t = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $t !== '' && hash_equals(csrf(), $t);
}

/** POST pages: stop when the token is wrong */
function csrf_require(bool $json = false): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
        if ($json) {
            json_out(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], 419);
        }
        flash('danger', 'Your session expired. Please try again.');
        redirect(ltrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/') ?: '');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flash_html(): string
{
    $o = '';
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) {
        $o .= '<div class="alert alert-' . e($t) . '" role="alert">' . e($m) . '</div>';
    }
    unset($_SESSION['flash']);
    return $o;
}

function post(string $k, int $max = 255): string
{
    return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
}

function user(bool $refresh = false): ?array
{
    static $u = false;
    if ($refresh) {
        $u = false;
    }
    if ($u === false) {
        $u = null;
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($id > 0) {
            $row = wc_one('SELECT * FROM web_users WHERE id = ?', 'i', [$id]);
            if ($row && $row['status'] === 'active' && $row['email_verified_at']) {
                $u = $row;
            } else {
                unset($_SESSION['uid']);
            }
        }
    }
    return $u;
}

function require_login(): array
{
    $u = user();
    if (!$u) {
        $_SESSION['after_login'] = ltrim((string)($_SERVER['REQUEST_URI'] ?? ''), '/');
        flash('info', 'Please log in to continue.');
        redirect('login');
    }
    return $u;
}

function safe_next(string $n): string
{
    $n = ltrim($n, '/');
    if ($n === '' || preg_match('#^[a-z]+:#i', $n) || strpos($n, '//') !== false || strpos($n, '\\') !== false) {
        return '';
    }
    return $n;
}

function img(string $file, string $folder = 'products'): string
{
    return $file === '' ? '' : wc_upload_url($folder, $file);
}

/* -------------------------------------------------------------- maintenance */
if (S('web_maintenance') === '1' && !defined('SHOP_ALLOW_MAINTENANCE') && empty($_SESSION['preview'])) {
    if (isset($_GET['preview']) && hash_equals(substr(sha1(DB_PASS . 'preview'), 0, 12), (string)$_GET['preview'])) {
        $_SESSION['preview'] = 1;
    } else {
        http_response_code(503);
        header('Retry-After: 3600');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Back soon</title><body style="font-family:Georgia,serif;text-align:center;padding:12vh 20px;background:' . e(S('web_color_bg', '#faf7f2')) . ';color:' . e(S('web_color_primary', '#1b1b1f')) . '"><h1 style="font-weight:normal;letter-spacing:3px;text-transform:uppercase">' . e(S('store_name', 'Our Store')) . '</h1><p style="font-size:20px">We are updating our store.<br>Please visit again soon.</p></body>';
        exit;
    }
}

/* abandoned online orders give their stock back (about once in 40 page views) */
if (random_int(1, 40) === 1 && !defined('SHOP_NO_HOUSEKEEPING')) {
    try {
        wc_expire_unpaid_orders();
    } catch (Throwable $ex) {
        error_log('housekeeping: ' . $ex->getMessage());
    }
}
