<?php
require_once __DIR__ . '/db.php';

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!current_user()) {
        header('Location: /login.php');
        exit;
    }
}

function attempt_login($username, $password) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['full_name'],
            'role' => $user['role'],
        ];
        return true;
    }
    return false;
}

function do_logout() {
    $_SESSION = [];
    session_destroy();
}

// يتحقق: هل المستخدم الحالي "مالك" (owner) بصلاحية حذف/تعديل قيود مرحّلة؟
function is_owner() {
    $user = current_user();
    return $user && ($user['role'] ?? '') === 'owner';
}

// يوقف الصفحة فوراً لو المستخدم مو مالك - استخدمها بأعلى أي صفحة/إجراء حساس
function require_owner() {
    require_login();
    if (!is_owner()) {
        http_response_code(403);
        die('هذا الإجراء يحتاج صلاحية المالك فقط.');
    }
}
