<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/bank_icons.php';
$page_title = 'نظرة عامة';
$overview   = get_overview();

// ---- دليل الحسابات (COA) ----
$db = get_db();
function table_exists_coa($db, $name) {
    $s = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$name]);
    return (bool)$s->fetch();
}
$coa_ready   = table_exists_coa($db, 'v_dashboard_summary');
$coa_summary = [];
$coa_unmapped = [];
$coa_assets = $coa_liab = $coa_equity = $coa_revenue = $coa_expense = 0;
if ($coa_ready) {
    foreach ($db->query("SELECT * FROM v_dashboard_summary")->fetchAll() as $r) {
        $coa_summary[$r['category']] = (float)$r['total'];
    }
    $coa_assets   = $coa_summary['asset']     ?? 0;
    $coa_liab     = $coa_summary['liability'] ?? 0;
    $coa_equity   = $coa_summary['equity']    ?? 0;
    $coa_revenue  = $coa_summary['revenue']   ?? 0;
    $coa_expense  = $coa_summary['expense']   ?? 0;
    $coa_unmapped = $db->query("SELECT * FROM v_dashboard_unmapped")->fetchAll();
}
$coa_net_income   = $coa_revenue - $coa_expense;
$coa_golden_diff  = $coa_assets - ($coa_liab + $coa_equity + $coa_net_income);
$coa_balanced     = abs($coa_golden_diff) < 0.01;

// ---- ملخص الشهر الحالي ----
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$month_label = date('m/Y');

$emp_month_salary = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM employee_transactions
     WHERE type='salary' AND tx_date BETWEEN '$month_start' AND '$month_end'"
)->fetchColumn();
$emp_unpaid_count = (int)$db->query(
    "SELECT COUNT(*) FROM employees WHERE balance > 0"
)->fetchColumn();
$emp_unpaid_total = (float)$db->query(
    "SELECT COALESCE(SUM(balance),0) FROM employees WHERE balance > 0"
)->fetchColumn();
$emp_all_paid = $emp_unpaid_total <= 0;

$car_revenue_month = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM car_employee_transactions
     WHERE type='revenue' AND tx_date BETWEEN '$month_start' AND '$month_end'"
)->fetchColumn();
$car_expense_month = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM car_employee_transactions
     WHERE type IN ('expense','advance','deduction','ads','salary','bonus','fuel','parts','vehicle_maintenance','secretary_salary')
     AND tx_date BETWEEN '$month_start' AND '$month_end'"
)->fetchColumn();
$car_profit_month = $car_revenue_month - $car_expense_month;

$ws_revenue_month = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM workshop_transactions
     WHERE type='revenue' AND tx_date BETWEEN '$month_start' AND '$month_end'"
)->fetchColumn();
$ws_expense_month = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM workshop_transactions
     WHERE type='expense' AND tx_date BETWEEN '$month_start' AND '$month_end'"
)->fetchColumn();
$ws_profit_month = $ws_revenue_month - $ws_expense_month;

$exp_month = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM expenses
     WHERE category='expense' AND tx_date BETWEEN '$month_start' AND '$month_end'"
)->fetchColumn();
$withdraw_month = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM expenses
     WHERE category='personal_withdrawal' AND tx_date BETWEEN '$month_start' AND '$month_end'"
)->fetchColumn();

$ext_rev_month = (float)$db->query(
    "SELECT COALESCE(SUM(amount),0) FROM external_revenues
     WHERE tx_date BETWEEN '$month_start' AND '$month_end'"
)->fetchColumn();

// ---- قيم جاهزة للعرض ----
$upcoming_checks  = $overview['upcoming_checks'] ?? [];
$has_checks       = !empty($upcoming_checks);
$has_coa_unmapped = !empty($coa_unmapped);
$coa_panel_class  = ($coa_ready && !$coa_balanced) ? 'panel table-warning' : 'panel';

require __DIR__ . '/../includes/header.php';
?>

<!-- ① الذمم والمركز المالي -->
<div class="cards">
    <a href="<?= APP_BASE_URL ?>/pages/customers.php" class="card c-teal" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">🧾 إجمالي ذمم العملاء (لنا عندهم)</div>
        <div class="value"><?= number_format($overview['customers_total'], 2) ?> <?= APP_CURRENCY ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/suppliers.php" class="card c-orange" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">📦 إجمالي ذمم الموردين (علينا لهم)</div>
        <div class="value"><?= number_format($overview['suppliers_total'], 2) ?> <?= APP_CURRENCY ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/cash_box.php" class="card c-cyan" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">💰 صندوق الكاش (الرصيد الفعلي)</div>
        <div class="value"><?= number_format($overview['cash_real_balance'], 2) ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/cash_box.php" class="card c-amber" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">💵 صندوق الكاش (عجز/زيادة)</div>
        <div class="value"><?= number_format($overview['cash_box_total'], 2) ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/profits.php" class="card c-indigo" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">🤝 رصيد المساهمين (إجمالي)</div>
        <div class="value"><?= number_format($overview['shareholders_total'], 2) ?> <?= APP_CURRENCY ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/personal.php" class="card c-slate" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">⚖️ دائن/مدين شخصي (صافي)</div>
        <div class="value"><?= number_format($overview['personal_total'], 2) ?> <?= APP_CURRENCY ?></div>
    </a>
</div>

<!-- ② البنوك -->
<div class="cards">
    <a href="<?= APP_BASE_URL ?>/pages/banks.php?bank=jordan_business" class="card bank-blue" style="text-decoration:none;color:inherit;display:block;">
        <div class="label"><?= bank_icon('jordan_business') ?> بنك الأردن - مؤسسة</div>
        <div class="value"><?= number_format($overview['bank_jordan_business'], 2) ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/banks.php?bank=jordan_personal" class="card bank-blue" style="text-decoration:none;color:inherit;display:block;">
        <div class="label"><?= bank_icon('jordan_personal') ?> بنك الأردن - شخصي</div>
        <div class="value"><?= number_format($overview['bank_jordan_personal'], 2) ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/banks.php?bank=islami" class="card bank-green" style="text-decoration:none;color:inherit;display:block;">
        <div class="label"><?= bank_icon('islami') ?> البنك الإسلامي</div>
        <div class="value"><?= number_format($overview['bank_islami'], 2) ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/banks.php?bank=etihad" class="card bank-red" style="text-decoration:none;color:inherit;display:block;">
        <div class="label"><?= bank_icon('etihad') ?> بنك الاتحاد</div>
        <div class="value"><?= number_format($overview['bank_etihad'], 2) ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/banks.php?bank=dinarak" class="card bank-yellow" style="text-decoration:none;color:inherit;display:block;">
        <div class="label"><?= bank_icon('dinarak') ?> محفظة دينارك</div>
        <div class="value"><?= number_format($overview['bank_dinarak'], 2) ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/banks.php?bank=investment" class="card bank-purple" style="text-decoration:none;color:inherit;display:block;">
        <div class="label"><?= bank_icon('investment') ?> البنك الاستثماري</div>
        <div class="value"><?= number_format($overview['bank_investment'], 2) ?></div>
    </a>
</div>

<!-- ③ ملخص الشهر الحالي -->
<div class="cards">
    <a href="<?= APP_BASE_URL ?>/pages/external_revenues.php" class="card c-emerald" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">💚 إيرادات خارجية — <?= $month_label ?></div>
        <div class="value"><?= number_format($ext_rev_month, 2) ?> <?= APP_CURRENCY ?></div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/expenses.php" class="card c-crimson" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">💸 مصاريف وسحوبات — <?= $month_label ?></div>
        <div class="value"><?= number_format($exp_month + $withdraw_month, 2) ?> <?= APP_CURRENCY ?></div>
        <div style="font-size:12px;margin-top:4px;opacity:.7;">
            مصاريف <?= number_format($exp_month, 2) ?> | سحوبات <?= number_format($withdraw_month, 2) ?>
        </div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/employees.php" class="card c-navy" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">👷 رواتب الموظفين — <?= $month_label ?></div>
        <div class="value"><?= number_format($emp_month_salary, 2) ?> <?= APP_CURRENCY ?></div>
        <?= $emp_all_paid
            ? '<div style="font-size:12px;margin-top:4px;color:#86efac;">✅ كل الرواتب مسدّدة</div>'
            : '<div style="font-size:12px;margin-top:4px;color:#fca5a5;">⚠️ ' . $emp_unpaid_count . ' موظف غير مسدّد — ' . number_format($emp_unpaid_total, 2) . ' ' . APP_CURRENCY . '</div>'
        ?>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/car_employees.php" class="card c-brown" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">🚗 صيانة خارجية — <?= $month_label ?></div>
        <div class="value"><?= number_format($car_profit_month, 2) ?> <?= APP_CURRENCY ?></div>
        <div style="font-size:12px;margin-top:4px;opacity:.7;">
            إيراد <?= number_format($car_revenue_month, 2) ?> | مصروف <?= number_format($car_expense_month, 2) ?>
        </div>
    </a>
    <a href="<?= APP_BASE_URL ?>/pages/workshop.php" class="card c-violet" style="text-decoration:none;color:inherit;display:block;">
        <div class="label">🔧 المشغل — <?= $month_label ?></div>
        <div class="value"><?= number_format($ws_profit_month, 2) ?> <?= APP_CURRENCY ?></div>
        <div style="font-size:12px;margin-top:4px;opacity:.7;">
            إيراد <?= number_format($ws_revenue_month, 2) ?> | مصروف <?= number_format($ws_expense_month, 2) ?>
        </div>
    </a>
</div>

<!-- ④ الداشبورد المحاسبي -->
<div class="<?= $coa_panel_class ?>">
    <h2>⚖️ الداشبورد المحاسبي (دليل الحسابات)</h2>
    <?php if (!$coa_ready): ?>
        <p style="color:#64748b;font-size:14px;">
            لسا ما اتشغّل ملف <code>database/migration_chart_of_accounts_dashboard.sql</code> على هالقاعدة —
            شغّله من phpMyAdmin حتى يظهر هالقسم.
        </p>
    <?php else: ?>
        <div class="cards">
            <div class="card <?= $coa_assets >= 0 ? 'positive' : 'negative' ?>">
                <div class="label">إجمالي الأصول</div>
                <div class="value"><?= number_format($coa_assets, 2) ?></div>
            </div>
            <div class="card">
                <div class="label">إجمالي الالتزامات</div>
                <div class="value"><?= number_format($coa_liab, 2) ?></div>
            </div>
            <div class="card">
                <div class="label">حقوق الملكية</div>
                <div class="value"><?= number_format($coa_equity, 2) ?></div>
            </div>
            <div class="card <?= $coa_net_income >= 0 ? 'positive' : 'negative' ?>">
                <div class="label">صافي الدخل للفترة الحالية</div>
                <div class="value"><?= number_format($coa_net_income, 2) ?></div>
            </div>
        </div>
        <p style="font-size:14px;color:#475569;">
            الفحص الذهبي (أصول = التزامات + حقوق ملكية + صافي الدخل):
            <?= number_format($coa_assets,2) ?> = <?= number_format($coa_liab,2) ?> + <?= number_format($coa_equity,2) ?> + <?= number_format($coa_net_income,2) ?>
            <?= $coa_balanced
                ? '<span class="badge badge-green">✅ متوازنة</span>'
                : '<span class="badge badge-yellow">⚠️ فرق ' . number_format($coa_golden_diff, 2) . ' — الفترة لسا ما انقفلت محاسبياً أو في حركات بحساب suspense</span>'
            ?>
        </p>
        <?php if ($has_coa_unmapped): ?>
            <p style="font-size:13px;color:#b45309;">
                ⚠️ في <?= count($coa_unmapped) ?> نوع حساب بدفتر الأستاذ ما إله تصنيف بدليل الحسابات بعد —
                ضيفه لجدول <code>account_type_map</code>.
            </p>
        <?php endif; ?>
        <p style="font-size:13px;"><a href="<?= APP_BASE_URL ?>/reports/coa_dashboard.php">🔍 عرض تفصيل كل حساب →</a></p>
    <?php endif; ?>
</div>

<!-- ⑤ شيكات مستحقة -->
<div class="panel">
    <h2>📄 شيكات مستحقة خلال 7 أيام</h2>
    <?php if (!$has_checks): ?>
        <p style="color:#64748b;font-size:14px;">لا يوجد شيكات مستحقة قريباً.</p>
    <?php else: ?>
        <table>
            <tr><th>الجهة</th><th>المبلغ</th><th>الاتجاه</th><th>تاريخ الاستحقاق</th><th>البنك</th><th>ملاحظات</th></tr>
            <?php foreach ($upcoming_checks as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['party_name']) ?></td>
                <td><?= number_format($c['amount'], 2) ?></td>
                <td><?= $c['direction'] === 'incoming' ? '<span class="badge badge-green">مستلم</span>' : '<span class="badge badge-red">مدفوع</span>' ?></td>
                <td><?= $c['due_date'] ?></td>
                <td><?= htmlspecialchars($c['bank_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($c['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<!-- ⑥ تعليمات البوت -->
<div class="panel">
    <h2>💡 كيف تستخدم الشات بوت بالأسفل يسار الشاشة</h2>
    <p style="font-size:14px;color:#475569;line-height:1.8;">
        جرب تكتب جمل عادية مثل:<br>
        • "سجلي فلان أخذ سلفة 200 دينار"<br>
        • "خالد سدد 100 دينار من دين المحل"<br>
        • "صرفت 50 دينار مصاريف كهرباء"<br>
        • "حولت لشريكنا سامر أرباح 300 دينار عن شهر حزيران"<br>
        • "استلمت شيك من أبو محمد 500 دينار تاريخ استحقاقه 2026-08-01"<br>
        • "شو وضعي المالي؟"
    </p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
