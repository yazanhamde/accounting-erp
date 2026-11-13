<?php
/**
 * مزامنتين موردين - كل مزامنة 28 مورد (دفتر عناوين الموردين + حركات المشتريات/الذمم)
 * ما بتلمس العملاء ولا المبيعات إطلاقاً - مزامنة مستقلة تماماً عن مزامنة العملاء.
 * =====================================================================
 * إعداد Cron Job على cPanel - تشغيلتين (8:00 و8:30 مساءً):
 *   Minute: 0,30   Hour: 20   Day: *   Month: *   Weekday: *
 *   الأمر:
 *   /usr/local/bin/php -d max_execution_time=0 /home/YOUR_CPANEL_USER/public_html/accounting-system/cron/cron_daily_suppliers_sync.php
 *   (تشغيلتين × دفعة 28 = 56 مورد، SUPPLIER_LEDGER_BATCH_SIZE بملف .env)
 *
 * (مزامنة العملاء منفصلة تماماً - بملف cron_daily_customers_sync.php - حتى ما تتزاحم
 *  الاثنتين على نفس مفتاح الـ API)
 *
 * ملاحظة أمان: إذا حبيت تسمح بتشغيله من المتصفح كمان (وليس فقط من CLI)
 * فعّل الشرط تحت وحط CRON_SECRET بملف .env، وادخل عالرابط:
 *   cron_daily_suppliers_sync.php?secret=القيمة-اللي-حطيتها
 *
 * هاد السكربت بيجيب "آخر كذا يوم" (حسب POS_SYNC_DAYS_BACK بملف .env) - سريع وخفيف.
 * وبيحذف محلياً أي حركة انحذفت من الـ POS ضمن نفس المدى.
 */

require_once __DIR__ . '/../includes/pos_sync.php';

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
$log[] = sprintf("[%s] بدء مزامنة الموردين (دفعة)...", date('Y-m-d H:i:s'));

$batch_size = (int) env('SUPPLIER_LEDGER_BATCH_SIZE', 28);

try {
    $result = pos_run_suppliers_sync($batch_size);
    $log[] = sprintf(
        "موردين → الحالة: %s | جديد: %d، تحديث: %d | حركات مشتريات: %d",
        $result['status'],
        $result['counts']['suppliers_new'], $result['counts']['suppliers_updated'],
        $result['counts']['purchases_tx_new']
    );
    if (!empty($result['counts']['supplier_ledger_batch_info'])) {
        $log[] = $result['counts']['supplier_ledger_batch_info'];
    }
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
