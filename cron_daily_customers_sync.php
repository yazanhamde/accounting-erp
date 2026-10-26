<?php
/**
 * مزامنة يومية للعملاء فقط (دفتر عناوين العملاء + حركات المبيعات/الذمم)
 * ما بتلمس الموردين ولا المشتريات إطلاقاً - مزامنة مستقلة تماماً عن مزامنة الموردين.
 * =====================================================================
 * ⚠️ مقسّمة على دفعات (Batches) - عدد العملاء (346) كبير، وسحب كشف حساب كل
 * عميل لحاله بمرة وحدة كان بيضرب rate limit الـ POS API. الحل: كل تشغيلة
 * بتاخد دفعة صغيرة بس (حجمها CUSTOMER_LEDGER_BATCH_SIZE بملف .env، افتراضي
 * 30)، وبتحفظ "وين وصلت" (checkpoint) - التشغيلة الجاية بتكمل من بعدها.
 * لازم تشغّلها كذا مرة باليوم (مو مرة وحدة) عشان توصل لكل العملاء.
 *
 * إعداد Cron Job على cPanel - 12 تشغيلة موزّعة (كل نص ساعة تقريباً، من 8 الليل
 * لغاية 1:30 الفجر - نفس نافذة الوقت يلي كانت مخصصة لمزامنة العملاء، بس مقسّمة):
 *   Minute: 0,30   Hour: 20,21,22,23,0,1   Day: *   Month: *   Weekday: *
 *   الأمر:
 *   /usr/local/bin/php /home/USERNAME/public_html/accounting-system/cron_daily_customers_sync.php
 *   (غيّر المسار والـ USERNAME حسب مكان رفع المشروع الفعلي على السيرفر)
 *   (هيك = 12 تشغيلة: 20:00, 20:30, 21:00, 21:30, 22:00, 22:30, 23:00, 23:30,
 *    00:00, 00:30, 01:00, 01:30 - كل وحدة دفعة ~29 عميل من 346، فبتغطي الكل)
 *
 * ملاحظة أمان: إذا حبيت تسمح بتشغيله من المتصفح كمان (وليس فقط من CLI)
 * فعّل الشرط تحت وحط CRON_SECRET بملف .env، وادخل عالرابط:
 *   cron_daily_customers_sync.php?secret=القيمة-اللي-حطيتها
 *
 * هاد السكربت بيجيب "آخر يوم بس" (الحركات الجديدة من أمس لهلأ) - سريع وخفيف.
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
$log[] = sprintf("[%s] بدء المزامنة اليومية للعملاء (دفعة)...", date('Y-m-d H:i:s'));

$batch_size = (int) env('CUSTOMER_LEDGER_BATCH_SIZE', 30);

try {
    $result = pos_run_customer_ledgers_sync(null, 'daily_cron_customers', null, $batch_size);
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
