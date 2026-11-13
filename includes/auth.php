<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/login_throttle.php';
csrf_bootstrap(); // حماية CSRF: بتتحقق من أي POST وبتضيف التوكن للفورمات أوتوماتيك

// مهلة الجلسة: خمول (افتراضي 8 ساعات) + عمر أقصى منذ تسجيل الدخول (افتراضي 7 أيام). تتغير من .env
function session_idle_seconds()     { return max(300, (int) env('SESSION_IDLE_SECONDS', 28800)); }
function session_absolute_seconds() { return max(900, (int) env('SESSION_ABSOLUTE_SECONDS', 604800)); }

function current_user() {
    if (empty($_SESSION['user'])) return null;
    $now = time();
    $last = (int) ($_SESSION['last_activity'] ?? $now);
    $login = (int) ($_SESSION['login_time'] ?? $now);
    if (($now - $last) > session_idle_seconds() || ($now - $login) > session_absolute_seconds()) {
        do_logout();
        return null;
    }
    $_SESSION['last_activity'] = $now;
    return $_SESSION['user'];
}

function require_login() {
    if (!current_user()) {
        header('Location: ' . APP_BASE_URL . '/login.php');
        exit;
    }
}

function attempt_login($username, $password) {
    // حد المحاولات: لو مقفول ما منفحص كلمة السر أصلاً (login.php بيعرض الرسالة قبل ما يوصل هون)
    if (login_lock_status($username)['locked']) {
        return false;
    }
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    // password_verify بيشتغل دايماً (حتى لو المستخدم مش موجود) حتى وقت الرد ما يكشف أسماء المستخدمين
    $ok = password_verify((string) $password, $user['password_hash'] ?? LOGIN_DUMMY_HASH) && $user;
    login_record_attempt($username, (bool) $ok);
    if ($ok) {
        session_regenerate_id(true); // حماية من session fixation
        unset($_SESSION['csrf_token']); // توكن جديد للجلسة الجديدة
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['full_name'],
            'role' => $user['role'],
        ];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}

function do_logout() {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
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
