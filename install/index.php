<?php
/*
|--------------------------------------------------------------------------
| CRM installer  -  open  https://your-domain/crm/install/  in the browser
|--------------------------------------------------------------------------
| 1. creates the tables in an EMPTY database   2. creates the Super Admin   3. writes config.php
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
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$checks = [
    'PHP 7.4 or newer (you have ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'mysqli extension' => extension_loaded('mysqli'),
    'mbstring extension' => extension_loaded('mbstring'),
    'curl extension (Razorpay, WhatsApp)' => extension_loaded('curl'),
    'openssl extension (e-mail)' => extension_loaded('openssl'),
    'fileinfo / image functions (uploads)' => function_exists('getimagesize'),
    'Folder is writable: main folder (config.php)' => is_writable($root),
    'Folder is writable: uploads' => is_writable($root . '/uploads'),
    'Folder is writable: logs' => is_writable($root . '/logs'),
];
$ok = !in_array(false, $checks, true);
$err = '';
$done = false;
$v = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'store' => '', 'email' => '', 'phone' => '', 'address' => '', 'aname' => '', 'aemail' => '', 'demo' => ''];
$configText = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ok) {
    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $pass  = (string)($_POST['db_pass'] ?? '');
    $apass = (string)($_POST['apass'] ?? '');
    try {
        if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) {
            throw new Exception('Session expired. Please reload the page.');
        }
        if ($v['db_name'] === '' || $v['db_user'] === '') {
            throw new Exception('Please enter the database name and user.');
        }
        if ($v['store'] === '' || !filter_var($v['aemail'], FILTER_VALIDATE_EMAIL) || $v['aname'] === '') {
            throw new Exception('Please enter your business name and the Super Admin name and e-mail.');
        }
        if (strlen($apass) < 8 || !preg_match('/[A-Za-z]/', $apass) || !preg_match('/[0-9]/', $apass)) {
            throw new Exception('The Super Admin password needs at least 8 characters with letters and numbers.');
        }
        if ($apass !== (string)($_POST['apass2'] ?? '')) {
            throw new Exception('The two passwords do not match.');
        }
        $c = @mysqli_connect($v['db_host'], $v['db_user'], $pass, $v['db_name']);
        if (!$c) {
            throw new Exception('Could not connect to the database: ' . mysqli_connect_error());
        }
        mysqli_set_charset($c, 'utf8mb4');
        $n = (int)mysqli_fetch_row(mysqli_query($c, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"))[0];
        if ($n > 0) {
            throw new Exception('This database is not empty (' . $n . ' tables found). Use a NEW empty database for every client. To add the online store to an existing CRM database, ask your developer for the upgrade SQL file instead.');
        }
        $files = ['schema_fresh.sql'];
        if ($v['demo'] === '1') {
            $files[] = 'demo_content.sql';
        }
        foreach ($files as $f) {
            $sql = @file_get_contents(__DIR__ . '/sql/' . $f);
            if ($sql === false || $sql === '') {
                throw new Exception('Missing file install/sql/' . $f);
            }
            if (!mysqli_multi_query($c, $sql)) {
                throw new Exception('Database error in ' . $f . ': ' . mysqli_error($c));
            }
            do {
                if ($r = mysqli_store_result($c)) {
                    mysqli_free_result($r);
                }
            } while (mysqli_more_results($c) && mysqli_next_result($c));
            if (mysqli_errno($c)) {
                throw new Exception('Database error in ' . $f . ': ' . mysqli_error($c));
            }
        }
        $st = mysqli_prepare($c, "INSERT INTO users (name, email, designation, password_hash, must_change_password, role_id, status, joining_date) VALUES (?,?,'Owner',?,0,1,'active',CURDATE())");
        $hash = password_hash($apass, PASSWORD_DEFAULT);
        $em = strtolower($v['aemail']);
        mysqli_stmt_bind_param($st, 'sss', $v['aname'], $em, $hash);
        mysqli_stmt_execute($st);
        $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
        $crmUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir;
        $set = ['store_name' => $v['store'], 'business_email' => $v['email'], 'phone' => $v['phone'], 'address' => $v['address'], 'web_crm_url' => $crmUrl, 'web_mail_from_name' => $v['store']];
        $st = mysqli_prepare($c, 'INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($set as $k => $val) {
            mysqli_stmt_bind_param($st, 'ss', $k, $val);
            mysqli_stmt_execute($st);
        }
        $tpl = file_get_contents($root . '/config.sample.php');
        $q = fn($s) => str_replace(["\\", "'"], ["\\\\", "\\'"], $s);
        $configText = strtr($tpl, [
            "'your_database_name'" => "'" . $q($v['db_name']) . "'",
            "'your_database_user'" => "'" . $q($v['db_user']) . "'",
            "'your_database_password'" => "'" . $q($pass) . "'",
            "'localhost'" => "'" . $q($v['db_host']) . "'",
            "'change-this-random-key'" => "'" . bin2hex(random_bytes(16)) . "'"
        ]);
        if (@file_put_contents($root . '/config.php', $configText) === false) {
            $_SESSION['cfg'] = $configText;
        } else {
            $configText = '';
        }
        @file_put_contents(__DIR__ . '/.lock', date('c'));
        $done = true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CRM installer</title>
    <style>
        body {
            font-family: system-ui, Segoe UI, Arial, sans-serif;
            background: #f3f1ec;
            margin: 0;
            color: #222
        }

        .w {
            max-width: 760px;
            margin: 30px auto;
            padding: 0 16px
        }

        .c {
            background: #fff;
            border: 1px solid #ddd5c4;
            border-radius: 5px;
            padding: 26px;
            margin-bottom: 18px
        }

        h1 {
            margin: 0 0 4px;
            font-size: 26px
        }

        h2 {
            font-size: 17px;
            margin: 0 0 14px;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px
        }

        label {
            display: block;
            font-size: 13px;
            margin: 12px 0 4px;
            color: #555
        }

        input[type=text],
        input[type=email],
        input[type=password] {
            width: 100%;
            padding: 10px;
            border: 1px solid #cfc8b8;
            border-radius: 4px;
            font: inherit;
            box-sizing: border-box
        }

        .r {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 14px
        }

        .ok {
            color: #1d7a3e
        }

        .no {
            color: #b3261e
        }

        button {
            background: #1b1b1f;
            color: #fff;
            border: 0;
            padding: 12px 28px;
            border-radius: 4px;
            font: inherit;
            cursor: pointer;
            margin-top: 18px
        }

        .e {
            background: #fbeceb;
            border: 1px solid #f0c5c1;
            color: #8e1d15;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 14px
        }

        .s {
            background: #eaf6ee;
            border: 1px solid #bfe0c9;
            color: #1f6b3c;
            padding: 14px;
            border-radius: 4px
        }

        code,
        textarea {
            font-family: monospace
        }

        textarea {
            width: 100%;
            height: 230px
        }

        @media(max-width:600px) {
            .r {
                grid-template-columns: 1fr
            }
        }
    </style>
</head>

<body>
    <div class="w">
        <div class="c">
            <h1>CRM installation</h1>
            <div style="color:#777">Takes about two minutes. One client = one installation = one empty database.</div>
        </div>
        <?php if ($done): ?>
            <div class="c">
                <div class="s"><b>Installed successfully.</b><br>Super Admin login: <b><?= h($v['aemail']) ?></b></div>
                <?php if (!empty($_SESSION['cfg'])): ?><div class="e" style="margin-top:14px">The installer could not write <code>config.php</code>. Create a file named <code>config.php</code> in the main folder and paste this:<textarea readonly onfocus="this.select()"><?= h($_SESSION['cfg']) ?></textarea></div><?php endif; ?>
                <h2 style="margin-top:20px">What to do next</h2>
                <ol>
                    <li><b>Delete the <code>install</code> folder</b> from the server.</li>
                    <li><a href="../login.php">Open the CRM login</a>.</li>
                    <li>Go to <b>Online Store &rarr; Website settings</b> and enter your website address, Razorpay keys and e-mail (SMTP) details.</li>
                    <li>Upload the <b>website</b> project and open its <code>/install</code>.</li>
                </ol>
            </div>
        <?php else: ?>
            <div class="c">
                <h2>1. Server check</h2><?php foreach ($checks as $l => $o): ?><div class="<?= $o ? 'ok' : 'no' ?>"><?= $o ? '&#10003;' : '&#10007;' ?> <?= h($l) ?></div><?php endforeach; ?>
                <?php if (!$ok): ?><p class="no"><b>Please fix the red items (ask your hosting provider), then reload this page.</b></p><?php endif; ?>
            </div>
            <?php if ($ok): ?>
                <form method="post" autocomplete="off"><input type="hidden" name="t" value="<?= h($_SESSION['t']) ?>">
                    <?php if ($err !== ''): ?><div class="e"><?= h($err) ?></div><?php endif; ?>
                    <div class="c">
                        <h2>2. Database (create an empty one in your hosting panel first)</h2>
                        <div class="r">
                            <div><label>Database host</label><input type="text" name="db_host" value="<?= h($v['db_host']) ?>"></div>
                            <div><label>Database name</label><input type="text" name="db_name" value="<?= h($v['db_name']) ?>" required></div>
                            <div><label>Database user</label><input type="text" name="db_user" value="<?= h($v['db_user']) ?>" required></div>
                            <div><label>Database password</label><input type="password" name="db_pass"></div>
                        </div>
                    </div>
                    <div class="c">
                        <h2>3. Your business</h2><label>Business / store name *</label><input type="text" name="store" value="<?= h($v['store']) ?>" required>
                        <div class="r">
                            <div><label>Business e-mail</label><input type="email" name="email" value="<?= h($v['email']) ?>"></div>
                            <div><label>Phone</label><input type="text" name="phone" value="<?= h($v['phone']) ?>"></div>
                        </div><label>Address</label><input type="text" name="address" value="<?= h($v['address']) ?>">
                    </div>
                    <div class="c">
                        <h2>4. Super Admin (the owner login)</h2>
                        <div class="r">
                            <div><label>Name *</label><input type="text" name="aname" value="<?= h($v['aname']) ?>" required></div>
                            <div><label>E-mail (used to log in) *</label><input type="email" name="aemail" value="<?= h($v['aemail']) ?>" required></div>
                            <div><label>Password *</label><input type="password" name="apass" required></div>
                            <div><label>Confirm password *</label><input type="password" name="apass2" required></div>
                        </div>
                        <label style="margin-top:18px"><input type="checkbox" name="demo" value="1" <?= $v['demo'] === '1' ? 'checked' : '' ?>> Also load demo content (sample products, banners, a coupon) so I can see the website working. You can delete them later.</label>
                        <button type="submit">Install now</button>
                    </div>
                </form>
        <?php endif;
        endif; ?>
    </div>
</body>

</html>