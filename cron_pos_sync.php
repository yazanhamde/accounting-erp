<?php
/**
 * نقطة تشغيل المزامنة كل ربع ساعة من الـ POS API
 * =====================================================
 * إعداد Cron Job على cPanel:
 *   Minute: * /15   Hour: *   Day: *   Month: *   Weekday: *
 *   الأمر:
 *   /usr/local/bin/php /home/USERNAME/public_html/accounting-system/cron_pos_sync.php
 *   (غيّر المسار حسب مكان رفع المشروع الفعلي على السيرفر)
 *
 * ملاحظة أمان: إذا حبيت تسمح بتشغيله من المتصفح أيضاً (وليس فقط من الـ CLI)
 * فعّل الشرط تحت وحط CRON_SECRET بملف .env، وادخل عالرابط:
 *   cron_pos_sync.php?secret=القيمة-اللي-حطيتها
 */

require_once __DIR__ . '/includes/pos_sync.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    $secret = env('CRON_SECRET', '');
    if (!$secret || ($_GET['secret'] ?? '') !== $secret) {
        http_response_code(403);
        die('غير مسموح - هذا السكربت لتشغيل المزامنة عن طريق Cron Job أو بسر صحيح فقط');
    }
}

// =====================================================
// المزامنة التلقائية معطّلة عمداً (تسبب استهلاك سريع لحد
// الـ rate limit على مفتاح الـ POS API، وخصوصاً على sales).
// المزامنة الآن يدوية فقط عبر زر "شغّل المزامنة الآن" بصفحة sync_log.php
// إذا حبيت ترجع تفعّل التلقائية مستقبلاً: حط بملف .env
//   POS_AUTO_SYNC_ENABLED=true
// وأعد جدولة الـ Cron Job بفارق زمني أكبر (كل ساعة مثلاً بدل كل ربع ساعة).
// =====================================================
if (!filter_var(env('POS_AUTO_SYNC_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    $msg = sprintf(
        "[%s] المزامنة التلقائية معطّلة حالياً (POS_AUTO_SYNC_ENABLED=false). استخدم زر التشغيل اليدوي بصفحة sync_log.php.\n",
        date('Y-m-d H:i:s')
    );
    if ($is_cli) { echo $msg; } else { header('Content-Type: text/plain; charset=utf-8'); echo $msg; }
    exit;
}

$result = pos_run_full_sync(null, 'scheduled');

$msg = sprintf(
    "[%s] حالة المزامنة: %s | عملاء جدد: %d، تحديث: %d | موردين جدد: %d، تحديث: %d | حركات مبيعات: %d | حركات مشتريات: %d\n",
    date('Y-m-d H:i:s'),
    $result['status'],
    $result['counts']['customers_new'], $result['counts']['customers_updated'],
    $result['counts']['suppliers_new'], $result['counts']['suppliers_updated'],
    $result['counts']['sales_tx_new'], $result['counts']['purchases_tx_new']
);

if (!empty($result['errors'])) {
    $msg .= "أخطاء: " . implode(' | ', $result['errors']) . "\n";
}

if ($is_cli) {
    echo $msg;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
}
