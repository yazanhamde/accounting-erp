<?php
require_once __DIR__ . '/../config/config.php';

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die('خطأ بالاتصال بقاعدة البيانات: تأكد من بيانات الاتصال بملف config/config.php — ' .
                (DEBUG_MODE ? $e->getMessage() : ''));
        }

        // يخبر MySQL مين المستخدم الحالي، حتى الـ triggers (audit_log + صلاحية الحذف) تشتغل صح
        if (isset($_SESSION['user']['id'])) {
            $pdo->exec('SET @current_user_id = ' . (int) $_SESSION['user']['id']);
        }
    }
    return $pdo;
}
