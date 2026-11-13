<?php
require_once __DIR__ . '/includes/auth.php';
do_logout();
header('Location: ' . APP_BASE_URL . '/login.php');
exit;
