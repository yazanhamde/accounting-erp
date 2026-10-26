<?php
/**
 * مزامنة العملاء والموردين وكل حركاتهم/فواتيرهم من تاريخ محدد لليوم
 * =====================================================================
 * طريقة التشغيل من SSH بالسيرفر:
 *   cd /home/USERNAME/public_html/accounting-system
 *   php sync_from_date.php            (افتراضياً من 1/1/2026)
 *   php sync_from_date.php 2026-03-15 (أو أي تاريخ تحدده)
 *
 * ملاحظات:
 * - بتجيب كل العملاء والموردين (companies) وتربطهم بالاسم، وبتسجل كل
 *   فواتير المبيعات (sales) والمشتريات (purchases) الأحدث من التاريخ
 *   المحدد كحركات ذمم على كل عميل/مورد.
 * - آمنة لإعادة التشغيل: أي فاتورة موجودة مسبقاً (بربطها بـ pos_ref_id)
 *   ما بتتكرر، وبس بيتحدث المبلغ لو تغيّر.
 * - فيها وقفة 0.4 ثانية بين كل صفحة API لتفادي rate limit.
 */

require_once __DIR__ . '/includes/pos_sync.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("هاد السكربت لازم يشتغل من الـ Terminal/SSH فقط (php sync_from_date.php) مش من المتصفح.\n");
}

$from_date = $argv[1] ?? '2026-01-01';
$cutoff = strtotime($from_date . ' 00:00:00');
if ($cutoff === false) {
    die("تاريخ غير صالح: $from_date (الصيغة المطلوبة: YYYY-MM-DD)\n");
}

// حماية: ما نسمح نسحب من قبل تاريخ الرصيد الافتتاحي المجمّع للعملاء/الموردين،
// لأنه هاد رح يجيب فواتير قديمة ملخّصة أصلاً بحركة "رصيد افتتاحي" واحدة،
// وبيصير تكرار (المبلغ محسوب مرتين). لو فعلاً بدك تعيد بناء كل التاريخ من
// الصفر، لازم أول شي تمسح حركات "رصيد افتتاحي" يدوياً وتصفّر الأرصدة.
if ($cutoff < strtotime(OPENING_BALANCE_CUTOFF_DATE . ' 00:00:00')) {
    die("⚠️ التاريخ يلي حددته ($from_date) قبل تاريخ الرصيد الافتتاحي المجمّع (" . OPENING_BALANCE_CUTOFF_DATE . "). سحب فواتير من قبل هاد التاريخ رح يكرر المبلغ (مرة بالرصيد الافتتاحي، ومرة بالفواتير التفصيلية). استخدم تاريخ " . OPENING_BALANCE_CUTOFF_DATE . " أو بعده.\n");
}

echo "بدء المزامنة من $from_date لليوم... هاد ممكن ياخد وقت حسب عدد الفواتير، لا توقف السكربت.\n";
echo "[" . date('Y-m-d H:i:s') . "] جاري الجلب...\n";

$start_time = microtime(true);
$result = pos_run_full_sync($cutoff, 'from_date_cli');
$elapsed = round(microtime(true) - $start_time, 1);

printf(
    "[%s] انتهت المزامنة | الحالة: %s | استغرقت %s ثانية\n",
    date('Y-m-d H:i:s'), $result['status'], $elapsed
);
printf(
    "عملاء جدد: %d، تحديث: %d | موردين جدد: %d، تحديث: %d | حركات مبيعات: %d | حركات مشتريات: %d\n",
    $result['counts']['customers_new'], $result['counts']['customers_updated'],
    $result['counts']['suppliers_new'], $result['counts']['suppliers_updated'],
    $result['counts']['sales_tx_new'], $result['counts']['purchases_tx_new']
);

if (!empty($result['errors'])) {
    echo "أخطاء صارت أثناء التشغيل (ممكن تكون rate limit - جرب رجّع شغّل السكربت بعد شوي):\n";
    foreach ($result['errors'] as $e) {
        echo " - $e\n";
    }
}
