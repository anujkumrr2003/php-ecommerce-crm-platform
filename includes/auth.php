<?php
/*
|--------------------------------------------------------------------------
| includes/auth.php  -  login session, roles, permissions, request guard
|--------------------------------------------------------------------------
| Every protected page starts with:
|       require_once __DIR__ . '/includes/auth.php';
|       crm_guard();
|
| What it gives you
|   crm_user()        logged-in employee (array) or null
|   crm_can('key')    true if the role of this user has that permission
|   crm_is_super()    true for Super Admin (always full access)
|   crm_guard()       checks login + permission for this page / ?api= call
|   crm_log(...)      writes to the activity log
|   crm_notify(...)   creates a notification (bell + sound)
|   crm_csrf_*()      CSRF token helpers for forms / AJAX of the new pages
|
| All function names start with crm_ so they never clash with page code.
*/

if (defined('CRM_AUTH_LOADED')) {
    return;
}
define('CRM_AUTH_LOADED', true);

date_default_timezone_set('Asia/Kolkata');
if (!is_file(dirname(__DIR__) . '/config.php')) {          // not installed yet -> start the installer
    header('Location: install/');
    exit('Not installed yet. Open the /install folder in your browser.');
}
require_once dirname(__DIR__) . '/config.php';          // creates $conn (mysqli)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const CRM_SESSION_KEY  = 'crm_uid';
const CRM_REMEMBER_SEC = 2592000;                       // 30 days
const CRM_MAX_FAILS    = 5;                             // wrong passwords before lock
const CRM_LOCK_MINUTES = 10;

/* ------------------------------------------------------------------ basics */

function crm_e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function crm_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function crm_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function crm_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.gc_maxlifetime', (string)CRM_REMEMBER_SEC);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('CRMSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => crm_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------------------------------------------------------------- database */

function crm_db(): mysqli
{
    global $conn;
    return $conn;
}

function crm_all(string $sql, string $types = '', array $params = []): array
{
    $st = mysqli_prepare(crm_db(), $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($st, $types, ...$params);
    }
    mysqli_stmt_execute($st);
    $res  = mysqli_stmt_get_result($st);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
    return $rows;
}

function crm_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = crm_all($sql, $types, $params);
    return $rows[0] ?? null;
}

/** INSERT / UPDATE / DELETE. Returns affected rows. */
function crm_run(string $sql, string $types = '', array $params = []): int
{
    $st = mysqli_prepare(crm_db(), $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($st, $types, ...$params);
    }
    mysqli_stmt_execute($st);
    $n = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    return (int)$n;
}

function crm_insert_id(): int
{
    return (int)mysqli_insert_id(crm_db());
}

/** one value from the settings table */
function crm_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (crm_all('SELECT setting_key, setting_value FROM settings') as $r) {
                $cache[$r['setting_key']] = (string)$r['setting_value'];
            }
        } catch (Throwable $ex) {
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

/** options of the "Product preferences" check-boxes on the customer form (Settings -> General) */
function crm_customer_prefs(): array
{
    $v = crm_setting('customer_preferences', '');
    $list = array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
    return $list ?: ['Ethnic wear', 'Western wear', 'Formal wear', 'Casual wear', 'Bridal / wedding', 'Kids wear'];
}

/* ------------------------------------------------------------ current user */

function crm_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($refresh) {
        $user = false;
        crm_perms(true);
    }
    if ($user !== false) {
        return $user;
    }
    $user = null;
    crm_session_start();
    $id = (int)($_SESSION[CRM_SESSION_KEY] ?? 0);
    if ($id > 0) {
        $row = crm_one(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
               FROM users u LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? LIMIT 1',
            'i',
            [$id]
        );
        // an employee who was deactivated (or lost the role) is thrown out immediately
        if ($row && $row['status'] === 'active' && (int)$row['role_id'] > 0) {
            $user = $row;
        } else {
            unset($_SESSION[CRM_SESSION_KEY]);
        }
    }
    return $user;
}

function crm_is_super(): bool
{
    $u = crm_user();
    return $u !== null && $u['role_slug'] === 'super_admin';
}

/** all permission keys of the current role  [key => true] */
function crm_perms(bool $refresh = false): array
{
    static $perms = null;
    if ($refresh) {
        $perms = null;
        return [];
    }
    if ($perms !== null) {
        return $perms;
    }
    $perms = [];
    $u = crm_user();
    if ($u) {
        $rows = crm_all(
            'SELECT p.perm_key FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ?',
            'i',
            [(int)$u['role_id']]
        );
        foreach ($rows as $r) {
            $perms[$r['perm_key']] = true;
        }
    }
    return $perms;
}

/** '' = any logged-in user. Super Admin always passes. */
function crm_can(string $perm): bool
{
    if (!crm_user()) {
        return false;
    }
    if ($perm === '' || crm_is_super()) {
        return true;
    }
    return isset(crm_perms()[$perm]);
}

/* ------------------------------------------------------------------- login */

/** @return array{0:bool,1:string} [ok, message] */
function crm_login(string $email, string $password, bool $remember): array
{
    crm_session_start();
    $email = strtolower(trim($email));
    $u = $email !== '' ? crm_one('SELECT * FROM users WHERE email = ? LIMIT 1', 's', [$email]) : null;

    if ($u && $u['locked_until'] && strtotime((string)$u['locked_until']) > time()) {
        $mins = (int)ceil((strtotime((string)$u['locked_until']) - time()) / 60);
        return [false, 'Too many wrong attempts. Try again in ' . $mins . ' minute(s).'];
    }
    if (!$u || !password_verify($password, (string)$u['password_hash'])) {
        if ($u) {
            $fails = (int)$u['failed_attempts'] + 1;
            if ($fails >= CRM_MAX_FAILS) {
                crm_run('UPDATE users SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ' . CRM_LOCK_MINUTES . ' MINUTE) WHERE id = ?', 'i', [(int)$u['id']]);
            } else {
                crm_run('UPDATE users SET failed_attempts = ? WHERE id = ?', 'ii', [$fails, (int)$u['id']]);
            }
        }
        usleep(300000);
        return [false, 'Incorrect email or password.'];
    }
    if ($u['status'] === 'pending') {
        return [false, 'Your account is waiting for activation by the Super Admin.'];
    }
    if ($u['status'] !== 'active') {
        return [false, 'Your account is inactive. Please contact the Super Admin.'];
    }
    if (!(int)$u['role_id']) {
        return [false, 'No role is assigned to your account yet. Please contact the Super Admin.'];
    }

    session_regenerate_id(true);
    $_SESSION[CRM_SESSION_KEY] = (int)$u['id'];
    crm_run(
        'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
        'si',
        [crm_ip(), (int)$u['id']]
    );
    if ($remember) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + CRM_REMEMBER_SEC,
            'path'     => '/',
            'secure'   => crm_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    crm_user(true);
    crm_log('auth', 'login', 'Signed in', (int)$u['id'], (string)$u['name']);
    return [true, ''];
}

function crm_logout(): void
{
    crm_session_start();
    $u = crm_user();
    if ($u) {
        crm_log('auth', 'logout', 'Signed out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true]);
    }
    session_destroy();
}

function crm_password_error(string $p): string
{
    if (strlen($p) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[a-z]/', $p) || !preg_match('/[A-Z]/', $p) || !preg_match('/[0-9]/', $p) || !preg_match('/[^A-Za-z0-9]/', $p)) {
        return 'Use upper and lower case letters, a number and a symbol in the password.';
    }
    return '';
}

/* --------------------------------------------------------------- activity */

function crm_log(string $module, string $action, string $detail = '', ?int $uid = null, ?string $uname = null): void
{
    try {
        $u = crm_user();
        $uid   = $uid ?? ($u ? (int)$u['id'] : null);
        $uname = $uname ?? ($u ? (string)$u['name'] : '');
        crm_run(
            'INSERT INTO activity_log (user_id, user_name, module, action, detail, ip_address) VALUES (?,?,?,?,?,?)',
            'isssss',
            [$uid, $uname, $module, $action, mb_substr($detail, 0, 500), crm_ip()]
        );
    } catch (Throwable $ex) {
        error_log('crm_log: ' . $ex->getMessage());
    }
}

/* ------------------------------------------------------------------- CSRF */

function crm_csrf(): string
{
    crm_session_start();
    if (empty($_SESSION['crm_csrf'])) {
        $_SESSION['crm_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['crm_csrf'];
}

function crm_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . crm_e(crm_csrf()) . '">';
}

function crm_csrf_ok(): bool
{
    $sent = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $sent !== '' && hash_equals(crm_csrf(), $sent);
}

/** For the new pages: stop with a message when the token is wrong. */
function crm_csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crm_csrf_ok()) {
        if (crm_wants_json()) {
            crm_json(['success' => false, 'message' => 'Security token expired. Please refresh the page and try again.'], 419);
        }
        crm_flash('danger', 'Security token expired. Please try again.');
        header('Location: ' . basename((string)$_SERVER['SCRIPT_NAME']));
        exit;
    }
}

/* ------------------------------------------------------------ flash / json */

function crm_flash(string $type, string $msg): void
{
    crm_session_start();
    $_SESSION['crm_flash'][] = [$type, $msg];
}

/** Prints (and clears) the queued messages as Bootstrap alerts. */
function crm_flash_html(): string
{
    crm_session_start();
    $out = '';
    foreach ($_SESSION['crm_flash'] ?? [] as $f) {
        $ic = $f[0] === 'success' ? 'check-circle' : ($f[0] === 'danger' ? 'x-circle' : 'info-circle');
        $out .= '<div class="alert alert-' . crm_e($f[0]) . ' py-2 d-flex align-items-center gap-2" role="alert"><i class="bi bi-' . $ic . '"></i><span>' . crm_e($f[1]) . '</span></div>';
    }
    unset($_SESSION['crm_flash']);
    return $out;
}

function crm_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function crm_wants_json(): bool
{
    return isset($_GET['api']) || isset($_POST['api'])
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
}

/* ------------------------------------------------------------------ guard */

/**
 * Call at the top of every protected page (after auth.php).
 * Checks: logged in -> must change password? -> permission from routes.php
 */
function crm_guard(?string $forcePerm = null): void
{
    $file = basename((string)parse_url((string)$_SERVER['SCRIPT_NAME'], PHP_URL_PATH));
    $u    = crm_user();

    if (!$u) {
        if (crm_wants_json()) {
            crm_json(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
        }
        $next = ltrim((string)($_SERVER['REQUEST_URI'] ?? ''), '/');
        header('Location: login.php' . ($next !== '' && strpos($next, 'login.php') === false ? '?next=' . rawurlencode($next) : ''));
        exit;
    }

    if ((int)$u['must_change_password'] === 1 && $file !== 'change-password.php') {
        if (crm_wants_json()) {
            crm_json(['success' => false, 'message' => 'Please change your password first.'], 403);
        }
        header('Location: change-password.php?force=1');
        exit;
    }

    $routes = require __DIR__ . '/routes.php';
    $cfg    = $routes[$file] ?? null;
    $need   = $forcePerm;

    if ($need === null) {
        if ($cfg === null || !empty($cfg['super'])) {
            $need = '@super';
        } elseif (!empty($cfg['any'])) {
            $need = '';
        } else {
            $need = (string)($cfg['*'] ?? '@super');
            $api  = $_GET['api'] ?? ($_POST['api'] ?? null);
            if (is_string($api) && isset($cfg['api'][$api])) {
                $r    = $cfg['api'][$api];
                $need = is_callable($r) ? (string)$r() : (string)$r;
            } elseif (isset($_GET['export']) && isset($cfg['export'])) {
                $need = (string)$cfg['export'];
            }
        }
    }

    $ok = $need === '@super' ? crm_is_super() : crm_can($need);
    if (!$ok) {
        crm_forbidden();
    }
}

function crm_forbidden(): void
{
    if (crm_wants_json() || $_SERVER['REQUEST_METHOD'] === 'POST') {
        crm_json(['success' => false, 'message' => 'You do not have permission to do this.'], 403);
    }
    http_response_code(403);
    require_once __DIR__ . '/layout.php';
    crm_page_start('Access denied');
    echo '<div class="panel text-center py-5"><i class="bi bi-shield-lock display-4 text-gold"></i>'
        . '<h4 class="mt-3">Access denied</h4>'
        . '<p class="text-muted mb-3">Your role does not have permission to open this page.<br>Please ask the Super Admin if you need access.</p>'
        . '<a class="btn btn-primary" href="' . crm_e(crm_home_url()) . '"><i class="bi bi-house me-1"></i>Go to my home page</a></div>';
    crm_page_end();
    exit;
}

/** first page the user is allowed to see (used after login and on 403) */
function crm_home_url(): string
{
    foreach ([
        'dashboard.view' => 'index.php', 'customers.view' => 'customers.php', 'billing.view' => 'billing.php',
        'inquiries.view' => 'inquiries.php', 'tasks.view' => 'tasks.php', 'products.view' => 'products.php',
        'reminders.view' => 'reminders.php',
    ] as $perm => $url) {
        if (crm_can($perm)) {
            return $url;
        }
    }
    return 'profile.php';
}

require_once __DIR__ . '/notify.php';
