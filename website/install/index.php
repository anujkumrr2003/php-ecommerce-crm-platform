<?php
/*
|--------------------------------------------------------------------------
| Website installer  -  open  https://your-domain/install/  in the browser
|--------------------------------------------------------------------------
| The website shares the database of the CRM, so install the CRM FIRST.
| This wizard only connects the website to that database and writes config.php.
| After it finishes, DELETE the install folder.
*/
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);
session_start();
$root = dirname(__DIR__);
if (is_file($root . '/config.php') || is_file(__DIR__ . '/.lock')) {
    http_response_code(403);
    exit('<h2 style="font-family:sans-serif">Already installed.</h2><p style="font-family:sans-serif">For safety this installer is locked. Please delete the <code>install</code> folder from the server.</p>');
}
if (empty($_SESSION['t'])) {
    $_SESSION['t'] = bin2hex(random_bytes(16));
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base  = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$siteDefault = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $base;
$checks = [
    'PHP 7.4 or newer (you have ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '7.4.0', '>='), 'mysqli extension' => extension_loaded('mysqli'), 'mbstring extension' => extension_loaded('mbstring'),
    'curl extension (Razorpay)' => extension_loaded('curl'), 'openssl extension (e-mail)' => extension_loaded('openssl'), 'iconv extension' => function_exists('iconv'),
    'Folder is writable: main folder (config.php, .htaccess)' => is_writable($root), 'Folder is writable: logs' => is_writable($root . '/logs'),
];
$ok = !in_array(false, $checks, true);
$err = '';
$done = false;
$v = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'site' => $siteDefault, 'crm' => ''];
$cfgShow = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ok) {
    foreach ($v as $k => $_) {
        $v[$k] = rtrim(trim((string)($_POST[$k] ?? '')), '/');
    }
    $pass = (string)($_POST['db_pass'] ?? '');
    try {
        if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) {
            throw new Exception('Session expired. Please reload the page.');
        }
        if (!preg_match('#^https?://[^\s]+$#i', $v['site'])) {
            throw new Exception('Please enter the website address starting with http:// or https://');
        }
        $c = @mysqli_connect($v['db_host'], $v['db_user'], $pass, $v['db_name']);
        if (!$c) {
            throw new Exception('Could not connect to the database: ' . mysqli_connect_error());
        }
        mysqli_set_charset($c, 'utf8mb4');
        foreach (['settings', 'products', 'web_orders', 'web_users'] as $t) {
            if (!mysqli_fetch_row(mysqli_query($c, "SHOW TABLES LIKE '" . $t . "'"))) {
                throw new Exception('Table "' . $t . '" was not found in this database. Install the CRM first (same database), or import database/upgrade_to_ecommerce.sql into your existing CRM database.');
            }
        }
        $row = mysqli_fetch_row(mysqli_query($c, "SELECT setting_value FROM settings WHERE setting_key = 'web_crm_url'"));
        $crm = $v['crm'] !== '' ? $v['crm'] : ($row[0] ?? '');
        if ($crm === '' || !preg_match('#^https?://#i', $crm)) {
            throw new Exception('Please enter the CRM address (where the CRM is installed). Product and banner pictures are loaded from there.');
        }
        $set = ['web_site_url' => $v['site'], 'web_crm_url' => rtrim($crm, '/')];
        $st = mysqli_prepare($c, 'INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($set as $k => $val) {
            mysqli_stmt_bind_param($st, 'ss', $k, $val);
            mysqli_stmt_execute($st);
        }
        $q = fn($s) => str_replace(["\\", "'"], ["\\\\", "\\'"], $s);
        $cfg = strtr(file_get_contents($root . '/config.sample.php'), ["'your_database_name'" => "'" . $q($v['db_name']) . "'", "'your_database_user'" => "'" . $q($v['db_user']) . "'",
            "'your_database_password'" => "'" . $q($pass) . "'", "'localhost'" => "'" . $q($v['db_host']) . "'", "define('BASE_PATH', '')" => "define('BASE_PATH', '" . $q($base) . "')"]);
        if (@file_put_contents($root . '/config.php', $cfg) === false) {
            $cfgShow = $cfg;
        }
        if ($base !== '' && is_file($root . '/.htaccess')) {                     // website lives in a sub-folder
            $ht = file_get_contents($root . '/.htaccess');
            $ht = str_replace('# RewriteBase /', 'RewriteBase ' . $base . '/', $ht);
            $ht = str_replace('ErrorDocument 404 /404.php', 'ErrorDocument 404 ' . $base . '/404.php', $ht);
            @file_put_contents($root . '/.htaccess', $ht);
        }
        @file_put_contents(__DIR__ . '/.lock', date('c'));
        $done = true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Website installer</title>
<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f3f1ec;margin:0;color:#222}.w{max-width:720px;margin:30px auto;padding:0 16px}.c{background:#fff;border:1px solid #ddd5c4;border-radius:4px;padding:26px;margin-bottom:18px}
h1{margin:0 0 4px;font-size:26px}h2{font-size:17px;margin:0 0 14px;border-bottom:1px solid #eee;padding-bottom:8px}label{display:block;font-size:13px;margin:12px 0 4px;color:#555}input[type=text],input[type=password]{width:100%;padding:10px;border:1px solid #cfc8b8;border-radius:3px;font:inherit;box-sizing:border-box}
.r{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}.ok{color:#1d7a3e}.no{color:#b3261e}button{background:#1b1b1f;color:#fff;border:0;padding:12px 28px;border-radius:3px;font:inherit;cursor:pointer;margin-top:18px}.e{background:#fbeceb;border:1px solid #f0c5c1;color:#8e1d15;padding:12px;border-radius:3px;margin-bottom:14px}.s{background:#eaf6ee;border:1px solid #bfe0c9;color:#1f6b3c;padding:14px;border-radius:3px}.h{font-size:12px;color:#888}
textarea{width:100%;height:200px;font-family:monospace}@media(max-width:600px){.r{grid-template-columns:1fr}}</style></head><body><div class="w">
<div class="c"><h1>Website installation</h1><div class="h">Install the CRM first - the website uses the same database.</div></div>
<?php if ($done): ?><div class="c"><div class="s"><b>Website connected.</b></div>
<?php if ($cfgShow !== ''): ?><div class="e" style="margin-top:14px">Could not write <code>config.php</code>. Create it in the main folder and paste:<textarea readonly onfocus="this.select()"><?= h($cfgShow) ?></textarea></div><?php endif; ?>
<h2 style="margin-top:20px">Next</h2><ol><li><b>Delete the <code>install</code> folder.</b></li><li><a href="<?= h($v['site']) ?>/">Open your website</a>.</li><li>In the CRM open <b>Online Store &rarr; Website settings</b>: set colours, Razorpay keys (Payments tab) and e-mail (SMTP).</li><li>Add the Razorpay webhook URL shown there: <code><?= h($v['site']) ?>/payment/webhook</code></li></ol></div>
<?php else: ?>
<div class="c"><h2>1. Server check</h2><?php foreach ($checks as $l => $o): ?><div class="<?= $o ? 'ok' : 'no' ?>"><?= $o ? '&#10003;' : '&#10007;' ?> <?= h($l) ?></div><?php endforeach; ?></div>
<?php if ($ok): ?><form method="post" autocomplete="off"><input type="hidden" name="t" value="<?= h($_SESSION['t']) ?>">
<?php if ($err !== ''): ?><div class="e"><?= h($err) ?></div><?php endif; ?>
<div class="c"><h2>2. The CRM database (same one the CRM uses)</h2><div class="r"><div><label>Database host</label><input type="text" name="db_host" value="<?= h($v['db_host']) ?>"></div><div><label>Database name</label><input type="text" name="db_name" value="<?= h($v['db_name']) ?>" required></div>
<div><label>Database user</label><input type="text" name="db_user" value="<?= h($v['db_user']) ?>" required></div><div><label>Database password</label><input type="password" name="db_pass"></div></div></div>
<div class="c"><h2>3. Addresses</h2><label>This website's address</label><input type="text" name="site" value="<?= h($v['site']) ?>" required><div class="h">Detected automatically. Change it if you use a different domain.</div>
<label>CRM address (where the CRM is installed)</label><input type="text" name="crm" value="<?= h($v['crm']) ?>" placeholder="https://your-domain.com/crm"><div class="h">Leave empty if the CRM installer already saved it. Product pictures are loaded from this address.</div>
<button type="submit">Connect website</button></div></form><?php endif; endif; ?></div></body></html>
