<?php
/**
 * عرض مرفقات (صور الشيكات/الفواتير) بعد التحقق من تسجيل الدخول.
 * بدل ما تنفتح الصور مباشرة من uploads/ (اللي صار ممنوع بالـ .htaccess): الرابط صار
 *   api/attachment.php?f=2026-11/اسم_الملف.jpg
 */
require_once __DIR__ . '/../includes/auth.php';

if (!current_user()) {
    http_response_code(401);
    exit('غير مصرّح');
}

$f = (string) ($_GET['f'] ?? '');
// شكل المسار المتوقع فقط: YYYY-MM/اسم.jpg|png|gif|webp - أي شي ثاني (.. أو مجلدات إضافية) مرفوض
if (!preg_match('#^\d{4}-\d{2}/[A-Za-z0-9_.\-]{1,150}\.(jpg|png|gif|webp)$#', $f) || strpos($f, '..') !== false) {
    http_response_code(404);
    exit('غير موجود');
}

$base = realpath(__DIR__ . '/../uploads/attachments');
$path = $base ? realpath($base . '/' . $f) : false;
if (!$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('غير موجود');
}

// النوع من محتوى الملف نفسه، مش من امتداده
$info = @getimagesize($path);
$mimes = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];
if (!$info || !isset($mimes[$info[2]])) {
    http_response_code(404);
    exit('غير موجود');
}

session_write_close(); // ما نحجز الجلسة أثناء إرسال الملف
header('Content-Type: ' . $mimes[$info[2]]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
readfile($path);
exit;
