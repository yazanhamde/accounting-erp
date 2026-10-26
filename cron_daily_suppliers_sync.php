<?php
/**
 * مزامنة يومية للموردين فقط (دفتر عناوين الموردين + حركات المشتريات/الذمم)
 * ما بتلمس العملاء ولا المبيعات إطلاقاً - مزامنة مستقلة تماماً عن مزامنة العملاء.
 * =====================================================================
 * إعداد Cron Job على cPanel (مرة واحدة الساعة 9 بالليل كل يوم):
 *   Minute: 0   Hour: 21   Day: *   Month: *   Weekday: *
 *   الأمر:
 *   /usr/local/bin/php /home/USERNAME/public_html/accounting-system/cron_daily_suppliers_sync.php
 *
 * (مزامنة العملاء منفصلة تماماً - الساعة 5 الفجر - بملف
 *  cron_daily_customers_sync.php - حتى ما تتزاحم الاثنتين على نفس مفتاح الـ API)
 *   (غيّر المسار والـ USERNAME حسب مكان رفع المشروع الفعلي على السيرفر)
 *
 * ملاحظة أمان: إذا حبيت تسمح بتشغيله من المتصفح كمان (وليس فقط من CLI)
 * فعّل الشرط تحت وحط CRON_SECRET بملف .env، وادخل عالرابط:
 *   cron_daily_suppliers_sync.php?secret=القيمة-اللي-حطيتها
 *
 * هاد السكربت بيجيب "آخر كذا يوم" (حسب POS_SYNC_DAYS_BACK بملف .env) - سريع وخفيف.
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

set_time_limit(0);
$log = [];
$log[] = sprintf("[%s] بدء المزامنة اليومية للموردين...", date('Y-m-d H:i:s'));

try {
    $result = pos_run_suppliers_sync(null, 'daily_cron_suppliers');
    $log[] = sprintf(
        "موردين → الحالة: %s | جديد: %d، تحديث: %d | حركات مشتريات: %d",
        $result['status'],
        $result['counts']['suppliers_new'], $result['counts']['suppliers_updated'],
        $result['counts']['purchases_tx_new']
    );
    if (!empty($result['errors'])) {
        $log[] = "أخطاء: " . implode(' | ', $result['errors']);
    }
} catch (Throwable $e) {
    $log[] = "فشلت مزامنة الموردين: " . $e->getMessage();
}

$log[] = sprintf("[%s] انتهت مزامنة الموردين.", date('Y-m-d H:i:s'));
$output = implode("\n", $log) . "\n";

if ($is_cli) {
    echo $output;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $output;
}
