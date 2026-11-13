<?php
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . APP_BASE_URL . '/' . (current_user() ? 'pages/dashboard.php' : 'login.php'));
exit;
