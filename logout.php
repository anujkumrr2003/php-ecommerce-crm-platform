<?php
/* logout.php - ends the session and returns to the login page */
require_once __DIR__ . '/includes/auth.php';
crm_logout();
header('Location: login.php?out=1');
exit;
