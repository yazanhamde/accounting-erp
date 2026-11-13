<?php
/**
 * مزامنات عملاء - كل مزامنة 14 عميل (دفتر عناوين العملاء + حركات المبيعات/الذمم)
 * ما بتلمس الموردين ولا المشتريات إطلاقاً - مزامنة مستقلة تماماً عن مزامنة الموردين.
 * =====================================================================
 * ⚠️ مقسّمة على دفعات (Batches) - عدد العملاء (346) كبير، وسحب كشف حساب كل
 * عميل لحاله بمرة وحدة كان بيضرب rate limit الـ POS API. الحل: كل تشغيلة
 * بتاخد دفعة صغيرة بس (حجمها CUSTOMER_LEDGER_BATCH_SIZE بملف .env، الحالي 14)، وبتحفظ "وين وصلت" (checkpoint) - التشغيلة الجاية بتكمل من بعدها.
 * لازم تشغّلها كذا مرة باليوم (مو مرة وحدة) عشان توصل لكل العملاء.
 *
 * إعداد Cron Job على cPanel - 26 تشغيلة (كل نص ساعة، من 9 الليل لغاية 9:30 الصبح):
 *   Minute: 0,30   Hour: 21,22,23,0,1,2,3,4,5,6,7,8,9   Day: *   Month: *   Weekday: *
 *   الأمر:
 *   /usr/local/bin/php -d max_execution_time=0 /home/YOUR_CPANEL_USER/public_html/accounting-system/cron/cron_daily_customers_sync.php
 *   (26 تشغيلة × دفعة 14 = 364 ≥ ~346 عميل، فبتغطي الكل مرة كل ليلة)
 *
 * ملاحظة أمان: إذا حبيت تسمح بتشغيله من المتصفح كمان (وليس فقط من CLI)
 * فعّل الشرط تحت وحط CRON_SECRET بملف .env، وادخل عالرابط:
 *   cron_daily_customers_sync.php?secret=القيمة-اللي-حطيتها
 *
 * هاد السكربت بيجيب "آخر كذا يوم" (حسب POS_SYNC_DAYS_BACK بملف .env، الحالي 2) - سريع وخفيف.
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
$log[] = sprintf("[%s] بدء مزامنة العملاء (دفعة)...", date('Y-m-d H:i:s'));

$batch_size = (int) env('CUSTOMER_LEDGER_BATCH_SIZE', 30);

try {
    $result = pos_run_customer_ledgers_sync($batch_size);
    $log[] = sprintf(
        "عملاء → الحالة: %s | جديد: %d، تحديث: %d | حركات مبيعات: %d",
        $result['status'],
        $result['counts']['customers_new'], $result['counts']['customers_updated'],
        $result['counts']['sales_tx_new']
    );
    if (!empty($result['counts']['customer_ledger_batch_info'])) {
        $log[] = $result['counts']['customer_ledger_batch_info'];
    }
    if (!empty($result['errors'])) {
        $log[] = "أخطاء: " . implode(' | ', $result['errors']);
    }
} catch (Throwable $e) {
    $log[] = "فشلت مزامنة العملاء: " . $e->getMessage();
}

$log[] = sprintf("[%s] انتهت مزامنة العملاء.", date('Y-m-d H:i:s'));
$output = implode("\n", $log) . "\n";

if ($is_cli) {
    echo $output;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $output;
}
