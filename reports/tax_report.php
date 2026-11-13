<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'تقرير الضريبة';
$db = get_db();

function table_exists_tr($db, $name) {
    $s = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$name]);
    return (bool)$s->fetch();
}

$has_v3    = table_exists_tr($db, 'tax_settings');
$settings  = $has_v3 ? $db->query("SELECT * FROM tax_settings WHERE is_active = 1 ORDER BY is_default DESC")->fetchAll() : [];
$report    = $has_v3 ? $db->query("
    SELECT period,
           SUM(CASE WHEN side='output_sales'    THEN tax_total  ELSE 0 END) AS output_tax,
           SUM(CASE WHEN side='input_purchases' THEN tax_total  ELSE 0 END) AS input_tax,
           SUM(CASE WHEN side='output_sales'    THEN base_total ELSE 0 END) AS sales_base,
           SUM(CASE WHEN side='input_purchases' THEN base_total ELSE 0 END) AS purchases_base
    FROM v_tax_report GROUP BY period ORDER BY period DESC
")->fetchAll() : [];

$has_report = !empty($report);

require __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <h2>🧾 تقرير الضريبة (مبيعات / قيمة مضافة)</h2>

    <?php if (!$has_v3): ?>
        <div class="panel">
            ⚠️ شغّل ملف <code>database/migration_v3_five_fixes.sql</code> من phpMyAdmin أولاً حتى تنفعّل أعمدة وتقارير الضريبة.
        </div>
    <?php else: ?>

        <div class="panel">
            <h4>النسب المفعّلة حالياً</h4>
            <table class="table">
                <thead>
                    <tr><th>الاسم</th><th>النسبة %</th><th>الافتراضية؟</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($settings as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['tax_name']) ?></td>
                        <td><?= $s['tax_rate'] ?>%</td>
                        <td><?= $s['is_default'] ? '✅' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-muted">أضف/عدّل النسب مباشرة بجدول <code>tax_settings</code>.</p>
        </div>

        <div class="panel">
            <h4>ملخص شهري (صافي الضريبة الواجب توريده = ضريبة المخرجات - ضريبة المدخلات)</h4>
            <table class="table">
                <thead>
                    <tr>
                        <th>الشهر</th>
                        <th>مبيعات (بدون ضريبة)</th>
                        <th>ضريبة المخرجات</th>
                        <th>مشتريات (بدون ضريبة)</th>
                        <th>ضريبة المدخلات</th>
                        <th>الصافي الواجب توريده</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($has_report): ?>
                        <?php foreach ($report as $r):
                            $net = $r['output_tax'] - $r['input_tax'];
                        ?>
                        <tr>
                            <td><?= $r['period'] ?></td>
                            <td><?= number_format($r['sales_base'],     2) ?></td>
                            <td><?= number_format($r['output_tax'],     2) ?></td>
                            <td><?= number_format($r['purchases_base'], 2) ?></td>
                            <td><?= number_format($r['input_tax'],      2) ?></td>
                            <td class="<?= $net > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($net, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6">ما في حركات مسجّل عليها ضريبة لسا.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="text-muted">صافي موجب = فلوس واجب تحويلها لدائرة ضريبة الدخل والمبيعات. صافي سالب = رصيد ضريبة مسترد لصالحك.</p>
        </div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
