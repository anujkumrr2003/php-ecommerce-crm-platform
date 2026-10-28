<?php
/*
|--------------------------------------------------------------------------
| includes/inquiry.php  -  one place that creates an inquiry
|--------------------------------------------------------------------------
| Used by: inquiry-api.php (your website form), inquiries.php (manual add),
|          cron-sync-apis.php (leads from Housing / 99acres / REI ...)
| Every new inquiry also creates a notification  ->  bell rings + sound.
*/
require_once __DIR__ . '/auth.php';

const CRM_INQUIRY_STATUSES = ['New', 'In Progress', 'Follow-up', 'Converted', 'Closed'];

/**
 * @param array $d name, mobile, email, message, type (Product|Contact), product, product_id, source, ip
 * @return int new inquiry id
 */
function crm_inquiry_create(array $d): int
{
    $name   = mb_substr(trim((string)($d['name'] ?? '')), 0, 150);
    $mobile = preg_replace('/\D+/', '', (string)($d['mobile'] ?? ''));
    if (strlen($mobile) > 10) {
        $mobile = substr($mobile, -10);                      // drop +91 / 0
    }
    $email  = mb_substr(trim((string)($d['email'] ?? '')), 0, 190);
    $msg    = mb_substr(trim((string)($d['message'] ?? '')), 0, 3000);
    $prod   = mb_substr(trim((string)($d['product'] ?? '')), 0, 150);
    $type   = ($d['type'] ?? '') === 'Product' || $prod !== '' ? 'Product' : 'Contact';
    $source = mb_substr(trim((string)($d['source'] ?? '')) ?: 'Website', 0, 60);
    $pid    = (int)($d['product_id'] ?? 0) ?: null;
    $ip     = mb_substr((string)($d['ip'] ?? ''), 0, 45);

    if ($pid && $prod === '') {
        $p = crm_one('SELECT name FROM products WHERE id = ?', 'i', [$pid]);
        $prod = $p ? (string)$p['name'] : '';
    }
    crm_run(
        'INSERT INTO inquiries (name, mobile, email, inquiry_type, product_id, product_name, message, source, ip_address) VALUES (?,?,?,?,?,?,?,?,?)',
        'ssssissss',
        [$name, $mobile, $email, $type, $pid, $prod, $msg, $source, $ip]
    );
    $id = crm_insert_id();

    $what = $prod !== '' ? 'Interested in: ' . $prod : ($msg !== '' ? mb_strimwidth($msg, 0, 90, '...') : 'Contact request');
    crm_notify([
        'type' => 'new_inquiry', 'icon' => 'chat-dots', 'color' => 'blue', 'perm' => 'inquiries.view',
        'title' => 'New inquiry from ' . $name,
        'message' => $what . ' (' . $source . ')',
        'link' => 'inquiries.php?view=new', 'ref' => 'inq:' . $id,
    ]);
    return $id;
}

/** website API key (created on first use) */
function crm_website_key(bool $regenerate = false): string
{
    $k = '';
    $r = crm_one("SELECT setting_value FROM settings WHERE setting_key = 'website_api_key'");
    if ($r) {
        $k = (string)$r['setting_value'];
    }
    if ($k === '' || $regenerate) {
        $k = bin2hex(random_bytes(20));
        crm_run("INSERT INTO settings (setting_key, setting_value) VALUES ('website_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", 's', [$k]);
    }
    return $k;
}
