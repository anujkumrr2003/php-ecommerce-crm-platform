<?php
/*
|--------------------------------------------------------------------------
| inquiry-api.php  -  PUBLIC endpoint: your website posts inquiries here
|--------------------------------------------------------------------------
| POST  inquiry-api.php            (form fields or JSON body)
|   key        API key  (or header  X-API-Key)      required
|   name       customer name                          required
|   mobile     10 digit mobile                        mobile or email required
|   email
|   message
|   type       Product | Contact       (Product when "product" is sent)
|   product    product name           product_id   product id (optional)
|   source     default "Website"
|   hp         leave EMPTY (spam trap: hidden field in your form)
|
| Reply: {"success":true,"id":123}  -> the CRM bell rings for the team.
| The key is shown on the Website Inquiries page (Website integration).
*/
require_once __DIR__ . '/includes/inquiry.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    crm_json(['success' => false, 'message' => 'Use POST.'], 405);
}

$in = $_POST;
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $j = json_decode((string)file_get_contents('php://input'), true);
    $in = is_array($j) ? $j : [];
}

try {
    $stored = crm_website_key();
    $sent   = (string)($in['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($sent === '' || !hash_equals($stored, $sent)) {
        crm_json(['success' => false, 'message' => 'Invalid API key.'], 401);
    }
    if (trim((string)($in['hp'] ?? '')) !== '') {                       // bots fill hidden fields
        crm_json(['success' => true, 'id' => 0]);
    }
    $name   = trim((string)($in['name'] ?? ''));
    $mobile = preg_replace('/\D+/', '', (string)($in['mobile'] ?? ''));
    $email  = trim((string)($in['email'] ?? ''));
    if ($name === '') {
        crm_json(['success' => false, 'message' => 'Name is required.'], 422);
    }
    if (strlen($mobile) < 10 && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        crm_json(['success' => false, 'message' => 'A valid mobile number or email is required.'], 422);
    }
    $recent = crm_one('SELECT COUNT(*) c FROM inquiries WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)', 's', [crm_ip()]);
    if ((int)($recent['c'] ?? 0) >= 10) {
        crm_json(['success' => false, 'message' => 'Too many requests. Please try later.'], 429);
    }
    $id = crm_inquiry_create([
        'name' => $name, 'mobile' => $mobile, 'email' => $email, 'message' => $in['message'] ?? '',
        'type' => $in['type'] ?? '', 'product' => $in['product'] ?? '', 'product_id' => $in['product_id'] ?? 0,
        'source' => $in['source'] ?? 'Website', 'ip' => crm_ip(),
    ]);
    crm_json(['success' => true, 'id' => $id]);
} catch (Throwable $ex) {
    error_log('inquiry-api.php: ' . $ex->getMessage());
    crm_json(['success' => false, 'message' => 'Server error.'], 500);
}
