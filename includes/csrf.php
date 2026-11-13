<?php
/**
 * حماية CSRF - بتمنع أي موقع خارجي من إرسال طلبات POST (حذف/تعديل/إضافة) باسم المستخدم المسجّل دخول.
 *
 * كيف بتشتغل (كلها أوتوماتيك، ما بتحتاج تعدّل الفورمات):
 *  1) أي طلب POST لازم يحمل التوكن (حقل csrf_token أو هيدر X-CSRF-Token)، وإلا بيترفض (403).
 *  2) كل <form method="post"> بصفحات HTML بينضاف له حقل التوكن المخفي تلقائياً قبل ما الصفحة توصل للمتصفح.
 *  3) طلبات fetch (الشات) بتبعت التوكن بالهيدر عن طريق window.CSRF_TOKEN.
 *
 * بتتفعّل من includes/auth.php. للاستثناء (مثل Webhook خارجي): define('CSRF_SKIP', true) قبل الـ require.
 */

function csrf_token() {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** حقل مخفي جاهز - للاستخدام اليدوي لو احتجته (الحقن الأوتوماتيكي بيغطي الفورمات العادية) */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_is_json_request() {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return stripos($ct, 'json') !== false
        || stripos($accept, 'application/json') !== false
        || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;
}

function csrf_reject() {
    http_response_code(403);
    if (csrf_is_json_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'reply' => '⚠️ انتهت صلاحية الجلسة أو الطلب غير موثوق. حدّث الصفحة (F5) وجرب تاني.',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div dir="rtl" style="font-family:sans-serif;max-width:480px;margin:60px auto;padding:24px;border:2px solid #e11d48;border-radius:10px;background:#fff5f5;">'
           . '<h3 style="margin-top:0;color:#b91c1c;">⚠️ تعذّر تنفيذ العملية</h3>'
           . '<p>انتهت صلاحية الصفحة أو الطلب غير موثوق (حماية CSRF). ما تم تنفيذ أي شي.</p>'
           . '<p><a href="javascript:history.back()">⬅️ رجوع</a> ثم حدّث الصفحة (F5) وجرب تاني.</p></div>';
    }
    exit;
}

function csrf_verify_request() {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $real = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || $real === '' || !hash_equals($real, $sent)) {
        csrf_reject();
    }
}

/** بينادى على كل مخرجات الصفحة: بيضيف حقل التوكن بعد كل <form method="post"> */
function csrf_inject_buffer($html) {
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) {
            return $html; // JSON / تحميل ملف (Excel, PDF) - ما منلمسه
        }
    }
    if (stripos($html, '<form') === false) return $html;
    $field = csrf_field();
    return preg_replace_callback(
        '/<form\b[^>]*\bmethod\s*=\s*(["\']?)post\1[^>]*>/i',
        function ($m) use ($field) { return $m[0] . $field; },
        $html
    );
}

function csrf_bootstrap() {
    if (PHP_SAPI === 'cli' || defined('CSRF_SKIP')) return;
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_verify_request();
    }
    csrf_token(); // نتأكد إنه التوكن موجود بالجلسة
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') === false) {
        ob_start('csrf_inject_buffer');
    }
}
