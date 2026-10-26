<?php
/**
 * ==========================================================
 * سكربت التنبيهات اليومي - نظام المحاسبة الذكي
 * ==========================================================
 * وظيفته: يفحص الشيكات يلي لسا "pending" ويرسل تنبيه بالإيميل
 * بس بـ 3 نقاط محددة: قبل أسبوع، وقبل يوم، ونفس يوم الاستحقاق
 * (وكمان تنبيه يومي لو الشيك صار متأخر، حتى ما ننساه)
 *
 * طريقة التشغيل التلقائي (مرة كل يوم):
 * من cPanel -> Cron Jobs -> أضف مهمة جديدة، واختار "مرة كل يوم" (Once a day)
 * والأمر (Command):
 *   php /home/USERNAME/public_html/cron_reminders.php
 * (بدّل المسار حسب مكان رفع المشروع الفعلي عندك)
 *
 * ملاحظة أمان: هذا الملف لازم يشتغل من الـ Cron فقط (سطر أوامر السيرفر)،
 * مو من المتصفح مباشرة. بالإمكان تحذفه من public access بعد التأكد إنه شغال
 * عن طريق .htaccess (موجود مثال أسفل الملف بالتعليقات).
 */

require_once __DIR__ . '/includes/actions.php'; // بيجيب معه db.php و config.php
require_once __DIR__ . '/includes/notifications.php';

// أمان بسيط: يمنع تشغيل الملف من المتصفح بالغلط (لازم يشتغل من CLI بس)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('هذا الملف يشتغل فقط عن طريق Cron Job من السيرفر.');
}

$pdo = get_db();

// نجيب كل الشيكات يلي لسا pending وما انبعث لها تنبيه اليوم
// (الفلترة الدقيقة على الأيام المحددة - 7/1/0 أو متأخر - بتصير بالـ PHP تحت)
$stmt = $pdo->prepare("
    SELECT * FROM checks
    WHERE status = 'pending'
      AND (notified_at IS NULL OR notified_at < CURDATE())
");
$stmt->execute();
$allPendingChecks = $stmt->fetchAll();

$sentCount = 0;
$checkedCount = 0;

foreach ($allPendingChecks as $check) {
    $daysLeft = (strtotime($check['due_date']) - strtotime(date('Y-m-d')));
    $daysLeft = intval($daysLeft / 86400);

    // نبعت تنبيه بس بهاي الحالات: قبل أسبوع بالضبط، قبل يوم بالضبط، نفس اليوم، أو متأخر (كل يوم لغاية ما ينحل)
    $shouldNotify = in_array($daysLeft, [7, 1, 0], true) || $daysLeft < 0;
    if (!$shouldNotify) {
        continue;
    }

    $checkedCount++;
    $directionText = $check['direction'] === 'incoming' ? 'شيك مستلم من' : 'شيك مطلوب دفعه لـ';

    if ($daysLeft < 0) {
        $timeText = 'متأخر ' . abs($daysLeft) . ' يوم! ⚠️';
    } elseif ($daysLeft === 0) {
        $timeText = 'يستحق اليوم! ⏰';
    } elseif ($daysLeft === 1) {
        $timeText = 'يستحق غداً! ⏳';
    } else {
        $timeText = 'يستحق خلال أسبوع (' . $daysLeft . ' أيام)';
    }

    $title = 'تنبيه شيك - ' . APP_NAME;
    $message = "$directionText {$check['party_name']} بمبلغ {$check['amount']} {$check['bank_name']} - $timeText";

    $ok = send_push_notification($title, $message, 'checks.php');

    if ($ok) {
        $sentCount++;
        $upd = $pdo->prepare("UPDATE checks SET notified_at = NOW() WHERE id = ?");
        $upd->execute([$check['id']]);
    }
}

echo date('Y-m-d H:i:s') . " - تم فحص $checkedCount شيك من ضمن " . count($allPendingChecks) . "، وأُرسل $sentCount تنبيه.\n";

// --- مثال .htaccess لمنع فتح هذا الملف من المتصفح مباشرة (اختياري) ---
// أضف هذا السطر داخل ملف .htaccess بجذر المشروع:
// <Files "cron_reminders.php">
//     Order Deny,Allow
//     Deny from all
// </Files>
