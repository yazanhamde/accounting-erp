<?php
/**
 * دالة إرسال تنبيه عن طريق الإيميل (بدل OneSignal / Push Notifications)
 * بترجع true/false حسب نجاح الإرسال
 *
 * بتستخدم دالة mail() المدمجة بـ PHP، اللي بتشتغل مباشرة من أي استضافة
 * فيها cPanel بدون أي إعداد إضافي أو حساب خارجي.
 */
function send_push_notification($title, $message, $url = null, $target_user_id = null) {
    if (!defined('NOTIFY_EMAIL') || !NOTIFY_EMAIL) {
        return false; // النظام يشتغل عادي بدون تنبيهات لحد ما تعبّي الإيميل بملف .env
    }

    $to = NOTIFY_EMAIL;
    $subject = '=?UTF-8?B?' . base64_encode($title) . '?=';

    $body = $message;
    if ($url) {
        $full_url = rtrim(APP_URL ?? '', '/') . '/' . ltrim($url, '/');
        $body .= "\n\nرابط: " . $full_url;
    }

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: " . APP_NAME . " <no-reply@" . preg_replace('#^https?://#', '', parse_url(APP_URL ?? '', PHP_URL_HOST) ?: 'localhost') . ">\r\n";

    return @mail($to, $subject, $body, $headers);
}
