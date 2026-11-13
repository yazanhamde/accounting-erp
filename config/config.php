<?php
/**
 * ==========================================================
 * ملف الإعدادات الرئيسي
 * كل البيانات الحساسة (قاعدة البيانات ومفتاح OpenAI) صارت
 * تنقرأ من ملف .env الموجود بجذر المشروع - مش مكتوبة هون مباشرة
 * ==========================================================
 */

require_once __DIR__ . '/../includes/env_loader.php';
load_env(__DIR__ . '/../.env');

// -------- إعدادات قاعدة البيانات (من ملف .env) --------
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));

// -------- مفتاح OpenAI API (من ملف .env) --------
define('OPENAI_API_KEY', env('OPENAI_API_KEY', ''));
define('OPENAI_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini'));
define('OPENAI_VISION_MODEL', env('OPENAI_VISION_MODEL', 'gpt-4o-mini')); // موديل يدعم تحليل الصور (لازم يدعم Vision)

// -------- إعدادات التنبيهات عن طريق الإيميل (بدل OneSignal) --------
define('NOTIFY_EMAIL', env('NOTIFY_EMAIL', ''));
define('APP_URL', env('APP_URL', ''));

// -------- إعدادات بوت واتساب (WhatsApp Cloud API) --------
define('WHATSAPP_TOKEN', env('WHATSAPP_TOKEN', ''));            // Permanent Access Token من Meta for Developers
define('WHATSAPP_PHONE_NUMBER_ID', env('WHATSAPP_PHONE_NUMBER_ID', ''));
define('WHATSAPP_VERIFY_TOKEN', env('WHATSAPP_VERIFY_TOKEN', '')); // أي نص تختاره أنت، بيتحط نفسه بلوحة ميتا وقت ربط الـ Webhook
define('WHATSAPP_APP_SECRET', env('WHATSAPP_APP_SECRET', ''));   // من إعدادات الـ App بميتا - لتوثيق الطلبات الواردة
define('WHATSAPP_OWNER_PHONE', env('WHATSAPP_OWNER_PHONE', '')); // رقم صاحب الحساب المصرّح له، صيغة دولية بدون + مثال: 962791234567

// -------- إعدادات ربط نظام POS (Stock Manager Advance API) --------
define('POS_API_BASE_URL', env('POS_API_BASE_URL', ''));
define('POS_API_KEY', env('POS_API_KEY', ''));

// -------- إعدادات عامة --------
define('APP_NAME', env('APP_NAME', 'نظام المحاسبة الذكي'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Asia/Amman'));
define('APP_CURRENCY', env('APP_CURRENCY', 'دينار'));
// المسار الأساسي للتطبيق على الدومين (بدون / بالآخر). كل الروابط وإعادة التوجيه لازم تبدأ منه (مسارات مطلقة)
// لأن الصفحات موزعة بمجلدات مختلفة (pages/ reports/ admin/) والمسار النسبي بيتكسر. لو نقلت التطبيق: غيّره هون أو بملف .env
define('APP_BASE_URL', rtrim(env('APP_BASE_URL', '/accounting-system'), '/'));

date_default_timezone_set(APP_TIMEZONE);

// -------- جلسات المستخدم (مشدّدة) --------
// HTTPS: مباشر، أو خلف بروكسي/Cloudflare (X-Forwarded-Proto)
$__is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
define('APP_IS_HTTPS', $__is_https);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');      // يرفض أي session id ما أصدره السيرفر نفسه
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');        // ممنوع الـ session id بالرابط
    ini_set('session.gc_maxlifetime', (string) max(300, (int) env('SESSION_IDLE_SECONDS', 28800)));
    session_set_cookie_params([
        'lifetime' => 0,                           // كوكي جلسة (بتروح لما يسكّر المتصفح)
        'path'     => '/',
        'secure'   => APP_IS_HTTPS,                // ما بينبعت إلا على HTTPS (بس لما الموقع نفسه HTTPS)
        'httponly' => true,                        // JavaScript ما يقدر يقرأ الكوكي (حماية من سرقتها بـ XSS)
        'samesite' => 'Lax',                       // ما بينبعت مع طلبات من مواقع ثانية (طبقة CSRF إضافية)
    ]);
    session_start();
}

// -------- هيدرز أمان أساسية --------
// (ما حطينا Content-Security-Policy: الصفحات فيها JavaScript/CSS inline كتير وبتنكسر. بنتناولها كخطوة لاحقة)
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: microphone=(self), camera=(self), geolocation=()'); // الميكروفون مستخدم بالشات الصوتي
    if (APP_IS_HTTPS) {
        header('Strict-Transport-Security: max-age=15552000'); // 6 شهور
    }
}

// -------- عرض الأخطاء (اجعلها false على الموقع الفعلي) --------
define('DEBUG_MODE', filter_var(env('DEBUG_MODE', 'false'), FILTER_VALIDATE_BOOLEAN));
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
