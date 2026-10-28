<?php
require_once __DIR__ . '/inc/bootstrap.php';
if ($u = user()) {
    wc_log_activity((int)$u['id'], 'logout', '');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true]);
}
session_destroy();
session_start();
flash('success', 'You have been logged out.');
redirect('login');
