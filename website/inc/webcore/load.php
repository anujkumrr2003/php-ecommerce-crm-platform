<?php
/*
|--------------------------------------------------------------------------
| webcore  -  code shared by the CRM and the Website (same copy in both ZIPs)
|--------------------------------------------------------------------------
|   db.php        database helpers + settings + small utilities
|   mail.php      e-mail sending (PHPMailer / SMTP) + luxury e-mail layout
|   emails.php    default e-mail templates (editable from the CRM)
|   razorpay.php  Razorpay API (create order, verify, capture, refund)
|   orders.php    order status flow, stock, invoice (CRM bill), refunds, notifications
| Everything here starts with wc_ so it never clashes with page code.
| Needs only a mysqli connection in the global variable $conn (config.php).
*/
if (defined('WC_LOADED')) {
    return;
}
define('WC_LOADED', true);
foreach (['db', 'mail', 'emails', 'razorpay', 'orders'] as $f) {
    require_once __DIR__ . '/' . $f . '.php';
}
