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

date_default_timezone_set(APP_TIMEZONE);

// -------- جلسات المستخدم --------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------- عرض الأخطاء (اجعلها false على الموقع الفعلي) --------
define('DEBUG_MODE', filter_var(env('DEBUG_MODE', 'true'), FILTER_VALIDATE_BOOLEAN));
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
