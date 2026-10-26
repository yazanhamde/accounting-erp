<?php
// ⚠️ مؤقت فقط للتشخيص - رح نشيله بعد ما نلاقي المشكلة
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// ---------- تشخيص مؤقت: يمسك أي Fatal Error وبيطبعه بالصفحة مباشرة ----------
// هاد بيلف حتى لو إعدادات PHP بالسيرفر مسكّرة display_errors، لأنه بيشتغل
// وقت ما PHP بينتهي (حتى لو انتهى بسبب خطأ فادح) وبيطبع مباشرة كـ HTML.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        // لو صار Fatal Error، امسح أي مخرجات ناقصة واطبع تفاصيل واضحة
        if (ob_get_length()) { ob_end_clean(); }
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(200); // حتى يقدر المتصفح يعرض النص بدل صفحة 500 العامة
        echo '<div dir="rtl" style="font-family:sans-serif;padding:20px;background:#fff5f5;border:2px solid #f00;margin:20px;border-radius:8px">';
        echo '<h2 style="color:#b00">🔴 لقينا سبب الخطأ (Fatal Error)</h2>';
        echo '<p><b>الرسالة:</b> ' . htmlspecialchars($err['message']) . '</p>';
        echo '<p><b>الملف:</b> ' . htmlspecialchars($err['file']) . '</p>';
        echo '<p><b>السطر:</b> ' . htmlspecialchars((string) $err['line']) . '</p>';
        echo '</div>';
    }
});

try {
    require_once __DIR__ . '/includes/auth.php';
    require_owner(); // عملية حساسة بتلمس أرصدة مالية - المالك بس
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/pos_sync.php'; // عشان نقدر نسحب من الـ POS قبل أي مقارنة/تصحيح
    $page_title = 'تصحيح أرصدة العملاء';
    $db = get_db();
} catch (Throwable $e) {
    echo '<div dir="rtl" style="font-family:sans-serif;padding:20px;background:#fff5f5;border:2px solid #f00;margin:20px;border-radius:8px">';
    echo '<h2 style="color:#b00">🔴 لقينا سبب الخطأ (Exception)</h2>';
    echo '<p><b>الرسالة:</b> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><b>الملف:</b> ' . htmlspecialchars($e->getFile()) . '</p>';
    echo '<p><b>السطر:</b> ' . htmlspecialchars((string) $e->getLine()) . '</p>';
    echo '<pre style="white-space:pre-wrap;font-size:12px;background:#fff;padding:10px;border-radius:6px">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</div>';
    exit;
}

$message = null;
$sync_result = null;

// ==================== خطوة 0: سحب أحدث حركات العملاء من الـ POS قبل أي مقارنة ====================
// هاي أهم خطوة - بتمنع إنه "نصحح" الرصيد المحلي ليطابق بيانات ناقصة أصلاً
// (نفس درس الموردين - سيناريو حركات فاتت المزامنة). بتسحب تاريخ الحركات من
// تاريخ قطع الرصيد الافتتاحي (OPENING_BALANCE_CUTOFF_DATE) لهلأ - مش من أول
// النظام - عشان ما يصير تكرار: الفواتير القديمة قبل هالتاريخ ملخّصة أصلاً
// بحركة "رصيد افتتاحي" واحدة، فسحبها تاني كفواتير تفصيلية بيضاعف المبلغ.
// ملاحظة: هاي مزامنة يدوية بس - بتنفّذ فقط لما المستخدم يدوس الزر تحت، وما
// بتنضاف ولا بتشتغل من أي Cron Job تلقائي.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_then_check') {
    set_time_limit(0); // بيمرّ على كل عميل وبيسحب تاريخه من تاريخ القطع - ممكن ياخد دقايق حسب عدد العملاء
    $full_history_cutoff = strtotime(OPENING_BALANCE_CUTOFF_DATE . ' 00:00:00');
    $sync_result = pos_run_customer_ledgers_sync($full_history_cutoff, 'from_date_customers');

    if ($sync_result['status'] === 'skipped') {
        $message = ['type' => 'error', 'text' => '⚠️ ' . implode(' | ', $sync_result['errors'])];
    } else {
        $message = ['type' => 'success', 'text' => sprintf(
            '✅ تم سحب أحدث البيانات من الـ POS (عملاء جدد: %d، تحديث: %d | حركات مبيعات جديدة/محدثة: %d). دلوقتي الفحص تحت بيقارن على بيانات محدّثة.',
            $sync_result['counts']['customers_new'], $sync_result['counts']['customers_updated'], $sync_result['counts']['sales_tx_new']
        )];
        if (!empty($sync_result['errors'])) {
            $message['text'] .= ' — تنبيه: صارت أخطاء جزئية أثناء السحب: ' . implode(' | ', $sync_result['errors']);
        }
    }
}

// ==================== خطوة 1: فحص الفرق بين الرصيد المخزّن ومجموع الحركات الفعلي ====================
$rows = $db->query("
    SELECT * FROM (
        SELECT c.id, c.name, c.balance AS stored_balance,
               COALESCE(SUM(CASE WHEN ct.type = 'debit' THEN ct.amount ELSE -ct.amount END), 0) AS computed_balance
        FROM customers c
        LEFT JOIN customer_transactions ct ON ct.customer_id = c.id
        GROUP BY c.id, c.name, c.balance
    ) t
    WHERE ABS(stored_balance - computed_balance) > 0.01
    ORDER BY ABS(stored_balance - computed_balance) DESC
")->fetchAll();

// ==================== خطوة 2: تطبيق التصحيح على عميل أو أكتر (بعد ما توافق يدوياً) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_fix') {
    $ids = $_POST['customer_ids'] ?? [];
    $fixed = 0;
    foreach ($ids as $cid) {
        $cid = (int) $cid;
        $r = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0) FROM customer_transactions WHERE customer_id = ?");
        $r->execute([$cid]);
        $correct = (float) $r->fetchColumn();
        $db->prepare('UPDATE customers SET balance = ? WHERE id = ?')->execute([$correct, $cid]);
        $fixed++;
    }
    $message = ['type' => 'success', 'text' => "✅ اتصحح رصيد $fixed عميل حسب مجموع حركاتهم الفعلي بالنظام."];
    // أعد الفحص بعد التصحيح
    $rows = $db->query("
        SELECT * FROM (
            SELECT c.id, c.name, c.balance AS stored_balance,
                   COALESCE(SUM(CASE WHEN ct.type = 'debit' THEN ct.amount ELSE -ct.amount END), 0) AS computed_balance
            FROM customers c
            LEFT JOIN customer_transactions ct ON ct.customer_id = c.id
            GROUP BY c.id, c.name, c.balance
        ) t
        WHERE ABS(stored_balance - computed_balance) > 0.01
        ORDER BY ABS(stored_balance - computed_balance) DESC
    ")->fetchAll();
}

require __DIR__ . '/includes/header.php';
?>

<div class="panel">
    <h2>🔧 تصحيح أرصدة العملاء</h2>
    <p style="font-size:13px;color:#666">هاي الصفحة ما بتحذف ولا بتضيف أي حركة - بس بتقارن الرصيد المخزّن بمجموع الحركات الفعلي المسجّل، وبتخليك توافق يدوياً قبل أي تعديل.</p>

    <?php if ($message): ?>
        <div style="margin:12px 0;padding:10px;border-radius:8px;background:<?= $message['type'] === 'success' ? '#eafff0' : '#fff5f5' ?>">
            <?= htmlspecialchars($message['text']) ?>
        </div>
    <?php endif; ?>

    <h3 style="font-size:15px;margin-top:16px">0️⃣ اسحب أحدث حركات العملاء من الـ POS أولاً</h3>
    <p style="font-size:13px;color:#666">⚠️ دايماً اعمل هاي الخطوة قبل ما تشوف/تطبق أي تصحيح تحت. بتضمن إنه المقارنة والتصحيح مبنيين على أحدث بيانات من الـ POS، مش بيانات محلية ممكن يكون فاتها حركات. هاي مزامنة يدوية بس - بتشتغل بس لما تدوس الزر، وما إلها أي علاقة بالمزامنة اليومية التلقائية.</p>
    <p style="font-size:13px;color:#b00;background:#fff5f5;padding:8px 10px;border-radius:6px">🔒 السحب بيبلّش من تاريخ <?= OPENING_BALANCE_CUTOFF_DATE ?> (تاريخ الرصيد الافتتاحي المجمّع) لهلأ فقط - مش من أول النظام - عشان ما تتكرر الفواتير القديمة يلي ملخّصة أصلاً بالرصيد الافتتاحي.</p>
    <?php if ($sync_result): ?>
        <p style="font-size:12px;color:#666">آخر سحب: الحالة <b><?= htmlspecialchars($sync_result['status']) ?></b><?php if (!empty($sync_result['run_id'])): ?> (رقم العملية #<?= (int)$sync_result['run_id'] ?>)<?php endif; ?></p>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="action" value="sync_then_check">
        <button class="btn" type="submit">🔄 اسحب الحركات من الـ POS الآن (كل التاريخ) ثم افحص الفروقات</button>
    </form>

    <h3 style="font-size:15px;margin-top:24px">1️⃣ العملاء يلي رصيدهم المخزّن مش مطابق لمجموع حركاتهم</h3>
    <?php if (empty($rows)): ?>
        <p style="color:#16a34a">✅ كل أرصدة العملاء مطابقة لمجموع حركاتهم بالضبط.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="apply_fix">
            <table class="table">
                <thead><tr><th></th><th>العميل</th><th>الرصيد المخزّن حالياً</th><th>المفروض يكون (حسب الحركات)</th><th>الفرق</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="customer_ids[]" value="<?= $r['id'] ?>"></td>
                        <td><?= htmlspecialchars($r['name']) ?></td>
                        <td><?= number_format($r['stored_balance'], 2) ?></td>
                        <td><?= number_format($r['computed_balance'], 2) ?></td>
                        <td style="color:#b00"><?= number_format($r['stored_balance'] - $r['computed_balance'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:13px;color:#666;margin-top:8px">حدد العملاء يلي بدك تصحح رصيدهم (رح يصير = مجموع حركاتهم المسجّلة بالضبط)، وبعدين دوس الزر.</p>
            <button class="btn" type="submit" onclick="return confirm('متأكد؟ رح يتغيّر رصيد العملاء المحددين ليطابق مجموع حركاتهم بالضبط.');">✅ صحّح رصيد المحدد</button>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
