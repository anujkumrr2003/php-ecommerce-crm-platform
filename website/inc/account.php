<?php
/* inc/account.php - customer account helpers: addresses, OTP, CRM customer link */

/** @return array{0:?array,1:string} [clean address, error] */
function address_validate(array $in): array
{
    $a = [];
    foreach (['label' => 30, 'name' => 120, 'mobile' => 15, 'line1' => 200, 'line2' => 200, 'landmark' => 120, 'city' => 100, 'state' => 100, 'pin' => 6] as $k => $max) {
        $a[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
    }
    $a['mobile'] = preg_replace('/\D+/', '', $a['mobile']);
    if (strlen($a['mobile']) > 10) {
        $a['mobile'] = substr($a['mobile'], -10);
    }
    if ($a['label'] === '') {
        $a['label'] = 'Home';
    }
    if ($a['name'] === '' || $a['line1'] === '' || $a['city'] === '') {
        return [null, 'Please fill your name, address and city.'];
    }
    if (!preg_match('/^[6-9][0-9]{9}$/', $a['mobile'])) {
        return [null, 'Please enter a valid 10 digit mobile number.'];
    }
    if (!preg_match('/^[1-9][0-9]{5}$/', $a['pin'])) {
        return [null, 'Please enter a valid 6 digit PIN code.'];
    }
    if (!in_array($a['state'], wc_states(), true)) {
        return [null, 'Please choose your state.'];
    }
    return [$a, ''];
}

function address_save(int $userId, array $a, bool $default, int $id = 0): int
{
    $n = (int)wc_one('SELECT COUNT(*) c FROM web_addresses WHERE web_user_id = ?', 'i', [$userId])['c'];
    if ($n === 0) {
        $default = true;
    }
    if ($default) {
        wc_run('UPDATE web_addresses SET is_default = 0 WHERE web_user_id = ?', 'i', [$userId]);
    }
    if ($id > 0 && wc_one('SELECT id FROM web_addresses WHERE id = ? AND web_user_id = ?', 'ii', [$id, $userId])) {
        wc_run('UPDATE web_addresses SET label=?, name=?, mobile=?, line1=?, line2=?, landmark=?, city=?, state=?, pin=?, is_default = IF(?, 1, is_default) WHERE id = ? AND web_user_id = ?', 'sssssssssiii',
            [$a['label'], $a['name'], $a['mobile'], $a['line1'], $a['line2'], $a['landmark'], $a['city'], $a['state'], $a['pin'], $default ? 1 : 0, $id, $userId]);
        return $id;
    }
    wc_run('INSERT INTO web_addresses (web_user_id, label, name, mobile, line1, line2, landmark, city, state, pin, is_default) VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'isssssssssi',
        [$userId, $a['label'], $a['name'], $a['mobile'], $a['line1'], $a['line2'], $a['landmark'], $a['city'], $a['state'], $a['pin'], $default ? 1 : 0]);
    return wc_insert_id();
}

function addresses_of(int $userId): array
{
    return wc_all('SELECT * FROM web_addresses WHERE web_user_id = ? ORDER BY is_default DESC, id DESC', 'i', [$userId]);
}

function address_form(array $a = [], string $prefix = ''): string
{
    $v = fn(string $k) => e($a[$k] ?? '');
    $states = '<option value="">Choose state</option>';
    foreach (wc_states() as $s) {
        $states .= '<option' . (($a['state'] ?? '') === $s ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    return '<div class="row2"><div class="field"><label>Full name</label><input name="' . $prefix . 'name" value="' . $v('name') . '" maxlength="120" autocomplete="name"></div>'
        . '<div class="field"><label>Mobile number</label><input name="' . $prefix . 'mobile" value="' . $v('mobile') . '" inputmode="numeric" maxlength="12" autocomplete="tel"></div></div>'
        . '<div class="field"><label>Address (house no, street, area)</label><input name="' . $prefix . 'line1" value="' . $v('line1') . '" maxlength="200" autocomplete="address-line1"></div>'
        . '<div class="row2"><div class="field"><label>Apartment / landmark (optional)</label><input name="' . $prefix . 'line2" value="' . $v('line2') . '" maxlength="200"></div>'
        . '<div class="field"><label>Address type</label><select name="' . $prefix . 'label">' . implode('', array_map(fn($l) => '<option' . (($a['label'] ?? 'Home') === $l ? ' selected' : '') . '>' . $l . '</option>', ['Home', 'Work', 'Other'])) . '</select></div></div>'
        . '<div class="row2"><div class="field"><label>City</label><input name="' . $prefix . 'city" value="' . $v('city') . '" maxlength="100" autocomplete="address-level2"></div>'
        . '<div class="field"><label>PIN code</label><input name="' . $prefix . 'pin" value="' . $v('pin') . '" inputmode="numeric" maxlength="6" autocomplete="postal-code"></div></div>'
        . '<div class="field"><label>State</label><select name="' . $prefix . 'state" autocomplete="address-level1">' . $states . '</select></div>';
}

/* ---------------------------------------------------------------- OTP */

/** create + e-mail a 6 digit code. $purpose: register | reset.  Returns error text or '' */
function otp_send(array $u, string $purpose): string
{
    if ($u['otp_sent_at'] && strtotime((string)$u['otp_sent_at']) > time() - 45) {
        return 'Please wait a few seconds before asking for a new code.';
    }
    if (!wc_throttle('otp:' . $u['id'], 6, 3600) || !wc_throttle('otpip:' . wc_ip(), 20, 3600)) {
        return 'Too many codes requested. Please try again after some time.';
    }
    $code = (string)random_int(100000, 999999);
    wc_run("UPDATE web_users SET otp_hash = ?, otp_purpose = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE), otp_tries = 0, otp_sent_at = NOW() WHERE id = ?", 'ssi', [password_hash($code, PASSWORD_DEFAULT), $purpose, (int)$u['id']]);
    $tpl = $purpose === 'reset' ? 'reset_otp' : 'register_otp';
    wc_after_response(function () use ($tpl, $u, $code) {
        wc_send_template($tpl, $u['email'], ['name' => $u['name'], 'otp' => $code]);
    });
    return '';
}

/** @return array{0:bool,1:string} */
function otp_check(int $userId, string $purpose, string $code): array
{
    $u = wc_one('SELECT * FROM web_users WHERE id = ?', 'i', [$userId]);
    if (!$u || $u['otp_purpose'] !== $purpose || !$u['otp_hash'] || !$u['otp_expires'] || strtotime((string)$u['otp_expires']) < time()) {
        return [false, 'This code has expired. Please request a new one.'];
    }
    if ((int)$u['otp_tries'] >= 5) {
        return [false, 'Too many wrong attempts. Please request a new code.'];
    }
    if (!preg_match('/^[0-9]{6}$/', $code) || !password_verify($code, (string)$u['otp_hash'])) {
        wc_run('UPDATE web_users SET otp_tries = otp_tries + 1 WHERE id = ?', 'i', [$userId]);
        return [false, 'That code is not correct. Please check your email and try again.'];
    }
    wc_run("UPDATE web_users SET otp_hash = NULL, otp_expires = NULL, otp_tries = 0, otp_purpose = '' WHERE id = ?", 'i', [$userId]);
    return [true, ''];
}

function password_error(string $p): string
{
    if (strlen($p) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9]/', $p)) {
        return 'Password must contain letters and numbers.';
    }
    return '';
}

/** link (or create) the CRM customer for a verified website user, so the shop and the CRM know the same person */
function crm_customer_link(array $u): ?int
{
    $mobile = preg_replace('/\D+/', '', (string)$u['mobile']);
    if (strlen($mobile) !== 10) {
        return null;
    }
    $c = wc_one('SELECT id, email FROM customers WHERE mobile = ?', 's', [$mobile]);
    if ($c) {
        if ($c['email'] === null || $c['email'] === '') {
            wc_run('UPDATE customers SET email = ? WHERE id = ?', 'si', [$u['email'], (int)$c['id']]);
        }
        return (int)$c['id'];
    }
    try {
        wc_run("INSERT INTO customers (name, mobile, email, gender, city, state, pin, clothing_preferences, notes, category, registration_date, source, whatsapp_consent, sms_consent, wa_status)
                VALUES (?,?,?,'Other','-','-','000000','[]','Registered on the website','Regular',CURDATE(),'Website',0,0,'skipped')", 'sss', [$u['name'], $mobile, $u['email']]);
        return wc_insert_id();
    } catch (Throwable $e) {
        error_log('crm_customer_link: ' . $e->getMessage());
        return null;
    }
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    wc_run('UPDATE web_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', 'si', [wc_ip(), (int)$u['id']]);
    user(true);
    cart_adopt((int)$u['id']);
    wc_log_activity((int)$u['id'], 'login', '');
}

function account_nav(string $active): string
{
    $items = ['account' => ['person', 'Overview', 'account'], 'orders' => ['bag', 'My orders', 'account/orders'], 'wishlist' => ['heart', 'Wishlist', 'wishlist'], 'addresses' => ['geo-alt', 'Addresses', 'account/addresses'], 'profile' => ['gear', 'Profile & password', 'account/profile']];
    $h = '<nav class="acc-nav" aria-label="Account">';
    foreach ($items as $k => [$ic, $l, $path]) {
        $h .= '<a class="' . ($k === $active ? 'on' : '') . '" href="' . e(url($path)) . '"><i class="bi bi-' . $ic . '"></i> ' . e($l) . '</a>';
    }
    return $h . '<a href="' . e(url('logout')) . '"><i class="bi bi-box-arrow-right"></i> Log out</a></nav>';
}

function status_pill(string $s): string
{
    $cls = ['delivered' => 'ok', 'placed' => 'info', 'processing' => 'info', 'shipped' => 'warn', 'out_for_delivery' => 'warn', 'cancelled' => 'bad', 'payment_failed' => 'bad', 'refunded' => '', 'pending_payment' => 'warn', 'return_requested' => 'warn', 'return_approved' => 'warn', 'returned' => ''][$s] ?? '';
    return '<span class="pill ' . $cls . '">' . e(WC_STATUSES[$s] ?? $s) . '</span>';
}
