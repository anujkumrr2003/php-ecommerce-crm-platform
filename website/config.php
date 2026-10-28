<?php
/*
 * config.php - website configuration. The installer (/install) writes this file for you.
 * The website uses the SAME database as the CRM (that is how products, orders and settings are shared).
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'u122031358_fashion_crm12');
define('DB_USER', 'u122031358_fashion_crm12');
define('DB_PASS', 'Anuj@427641');

/* '' when the website is in the main folder (public_html), or '/shop' when it is in a sub-folder */
define('BASE_PATH', 'https://grey-mongoose-278483.hostingersite.com/e-commerce-crm/website');

try {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (Throwable $ex) {
    $conn = false;
    error_log('MySQL connection failed: ' . $ex->getMessage());
}
if (!$conn) {
    http_response_code(500);
    die('We are having a technical problem. Please try again in a few minutes.');
}
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+05:30'");
