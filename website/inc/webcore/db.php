<?php
/* webcore/db.php - database helpers, settings, utilities */

function wc_db(): mysqli
{
    global $conn;
    return $conn;
}

function wc_all(string $sql, string $types = '', array $params = []): array
{
    $st = mysqli_prepare(wc_db(), $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($st, $types, ...$params);
    }
    mysqli_stmt_execute($st);
    $res  = mysqli_stmt_get_result($st);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
    return $rows;
}

function wc_one(string $sql, string $types = '', array $params = []): ?array
{
    $r = wc_all($sql, $types, $params);
    return $r[0] ?? null;
}

/** INSERT / UPDATE / DELETE - returns affected rows */
function wc_run(string $sql, string $types = '', array $params = []): int
{
    $st = mysqli_prepare(wc_db(), $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($st, $types, ...$params);
    }
    mysqli_stmt_execute($st);
    $n = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    return (int)$n;
}

function wc_insert_id(): int
{
    return (int)mysqli_insert_id(wc_db());
}

/** run $fn inside a transaction; exceptions roll back and are re-thrown */
function wc_tx(callable $fn)
{
    mysqli_begin_transaction(wc_db());
    try {
        $r = $fn();
        mysqli_commit(wc_db());
        return $r;
    } catch (Throwable $e) {
        mysqli_rollback(wc_db());
        throw $e;
    }
}

/* ------------------------------------------------------------- settings */

function wc_settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (wc_all('SELECT setting_key, setting_value FROM settings') as $r) {
            $cache[$r['setting_key']] = (string)$r['setting_value'];
        }
    }
    return $cache;
}

function wc_setting(string $key, string $default = ''): string
{
    $all = wc_settings_all();
    return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
}

function wc_set(string $key, string $value): void
{
    wc_run('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', 'ss', [$key, $value]);
    wc_settings_all(true);
}

/* ------------------------------------------------------------ utilities */

function wc_e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function wc_slug(string $s, string $fallback = 'item'): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s), '-'));
    return $s !== '' ? substr($s, 0, 80) : $fallback;
}

/** Indian digit grouping: 1234567.5 -> 12,34,567.50 ;  whole numbers have no decimals */
function wc_num($n): string
{
    $n   = round((float)$n, 2);
    $neg = $n < 0;
    $n   = abs($n);
    $int = (string)floor($n);
    $dec = round(($n - floor($n)) * 100);
    if (strlen($int) > 3) {
        $last = substr($int, -3);
        $rest = substr($int, 0, -3);
        $int  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last;
    }
    return ($neg ? '-' : '') . $int . ($dec > 0 ? '.' . str_pad((string)(int)$dec, 2, '0', STR_PAD_LEFT) : '');
}

function wc_money($n): string
{
    return "\u{20B9}" . wc_num($n);
}

function wc_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** public URL of the website (set by the website installer / CRM Website settings) */
function wc_site_url(): string
{
    return rtrim(wc_setting('web_site_url', ''), '/');
}

/** public URL of the CRM folder - product / banner images live in <crm>/uploads */
function wc_crm_url(): string
{
    return rtrim(wc_setting('web_crm_url', ''), '/');
}

function wc_upload_url(string $folder, string $file): string
{
    return $file === '' ? '' : wc_crm_url() . '/uploads/' . $folder . '/' . rawurlencode($file);
}

function wc_brand(): array
{
    $logo = wc_setting('logo', '');
    return [
        'name'  => wc_setting('store_name', 'Our Store'),
        'logo'  => $logo !== '' ? wc_crm_url() . '/' . ltrim($logo, '/') : '',
        'email' => wc_setting('business_email', ''),
        'phone' => wc_setting('phone', ''),
        'addr'  => wc_setting('address', ''),
    ];
}

/** rate limit: returns false when more than $max hits happened in $window seconds */
function wc_throttle(string $bucket, int $max, int $window): bool
{
    $bucket = substr($bucket, 0, 100);
    $row = wc_one('SELECT hits, window_start FROM web_throttle WHERE bucket = ?', 's', [$bucket]);
    if (!$row || strtotime($row['window_start']) < time() - $window) {
        wc_run('INSERT INTO web_throttle (bucket, hits, window_start) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE hits = 1, window_start = NOW()', 's', [$bucket]);
        return true;
    }
    if ((int)$row['hits'] >= $max) {
        return false;
    }
    wc_run('UPDATE web_throttle SET hits = hits + 1 WHERE bucket = ?', 's', [$bucket]);
    return true;
}

function wc_log_activity(?int $webUserId, string $action, string $detail = ''): void
{
    try {
        wc_run('INSERT INTO web_activity (web_user_id, action, detail, ip_address) VALUES (?,?,?,?)', 'isss', [$webUserId, $action, mb_substr($detail, 0, 300), wc_ip()]);
    } catch (Throwable $e) {
        error_log('wc_log_activity: ' . $e->getMessage());
    }
}

/** bell notification inside the CRM (same table the CRM uses) */
function wc_crm_notify(string $type, string $title, string $message, string $link, string $icon, string $color, string $perm, string $ref): void
{
    try {
        wc_run('INSERT IGNORE INTO notifications (type, title, message, icon, color, link, perm_key, ref_key) VALUES (?,?,?,?,?,?,?,?)', 'ssssssss',
            [$type, mb_substr($title, 0, 190), mb_substr($message, 0, 500), $icon, $color, $link, $perm, $ref]);
    } catch (Throwable $e) {
        error_log('wc_crm_notify: ' . $e->getMessage());
    }
}

/** state list used for addresses + shipping rates */
function wc_states(): array
{
    return ['Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry'];
}

const WC_STATUSES = [
    'pending_payment'  => 'Pending payment',
    'payment_failed'   => 'Payment failed',
    'placed'           => 'Order placed',
    'processing'       => 'Processing',
    'shipped'          => 'Shipped',
    'out_for_delivery' => 'Out for delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
    'return_requested' => 'Return requested',
    'return_approved'  => 'Return approved',
    'returned'         => 'Returned',
    'refunded'         => 'Refunded',
];


/** every products.category gets a row in web_categories (so it can have an image, slug and SEO text) */
function wc_sync_categories(): void
{
    foreach (wc_all("SELECT DISTINCT p.category AS name FROM products p LEFT JOIN web_categories c ON c.name = p.category WHERE c.id IS NULL AND p.category <> ''") as $r) {
        $slug = wc_slug($r['name'], 'category');
        $base = $slug;
        for ($i = 2; wc_one('SELECT id FROM web_categories WHERE slug = ?', 's', [$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        wc_run('INSERT IGNORE INTO web_categories (name, slug, sort_order) VALUES (?,?,?)', 'ssi', [$r['name'], $slug, 100]);
    }
}
