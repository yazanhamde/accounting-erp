<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/bank_icons.php';
$page_title = 'نظرة عامة';
$overview = get_overview();

// ---- قسم الداشبورد المحاسبي (دليل الحسابات) ----
$db = get_db();
function table_exists_coa($db, $name) {
    $s = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$name]);
    return (bool)$s->fetch();
}
$coa_ready = table_exists_coa($db, 'v_dashboard_summary');
$coa_summary = []; $coa_unmapped = [];
$coa_assets = $coa_liab = $coa_equity = $coa_revenue = $coa_expense = 0;
if ($coa_ready) {
    foreach ($db->query("SELECT * FROM v_dashboard_summary")->fetchAll() as $r) {
        $coa_summary[$r['category']] = (float)$r['total'];
    }
    $coa_assets  = $coa_summary['asset'] ?? 0;
    $coa_liab    = $coa_summary['liability'] ?? 0;
    $coa_equity  = $coa_summary['equity'] ?? 0;
    $coa_revenue = $coa_summary['revenue'] ?? 0;
    $coa_expense = $coa_summary['expense'] ?? 0;
    $coa_unmapped = $db->query("SELECT * FROM v_dashboard_unmapped")->fetchAll();
}
$coa_net_income = $coa_revenue - $coa_expense;
$coa_golden_diff = $coa_assets - ($coa_liab + $coa_equity + $coa_net_income);

require __DIR__ . '/includes/header.php';
?>

<div class="cards">
    <div class="card <?= $overview['customers_total'] >= 0 ? 'positive' : 'negative' ?>">
        <div class="label">إجمالي ذمم العملاء (لنا عندهم)</div>
        <div class="value"><?= number_format($overview['customers_total'], 2) ?> <?= APP_CURRENCY ?></div>
    </div>
    <div class="card <?= $overview['suppliers_total'] <= 0 ? 'positive' : 'negative' ?>">
        <div class="label">إجمالي ذمم الموردين (علينا لهم)</div>
        <div class="value"><?= number_format($overview['suppliers_total'], 2) ?> <?= APP_CURRENCY ?></div>
    </div>
    <div class="card">
        <div class="label">دائن/مدين شخصي (صافي)</div>
        <div class="value"><?= number_format($overview['personal_total'], 2) ?> <?= APP_CURRENCY ?></div>
    </div>
    <a href="profits.php" class="card <?= $overview['shareholders_total'] >= 0 ? 'positive' : 'negative' ?>" style="text-decoration:none; color:inherit; display:block;">
        <div class="label">🤝 رصيد المساهمين (إجمالي)</div>
        <div class="value"><?= number_format($overview['shareholders_total'], 2) ?> <?= APP_CURRENCY ?></div>
    </a>
    <a href="banks.php?bank=jordan_personal" class="card bank-blue" style="text-decoration:none; color:inherit; display:block;">
        <div class="label"><?= bank_icon('jordan_personal') ?> بنك الأردن - شخصي</div>
        <div class="value"><?= number_format($overview['bank_jordan_personal'], 2) ?></div>
    </a>
    <a href="banks.php?bank=jordan_business" class="card bank-blue" style="text-decoration:none; color:inherit; display:block;">
        <div class="label"><?= bank_icon('jordan_business') ?> بنك الأردن - مؤسسة</div>
        <div class="value"><?= number_format($overview['bank_jordan_business'], 2) ?></div>
    </a>
    <div class="card bank-red">
        <div class="label"><?= bank_icon('etihad') ?> بنك الاتحاد</div>
        <div class="value"><?= number_format($overview['bank_etihad'], 2) ?></div>
    </div>
    <div class="card bank-green">
        <div class="label"><?= bank_icon('islami') ?> البنك الإسلامي</div>
        <div class="value"><?= number_format($overview['bank_islami'], 2) ?></div>
    </div>
    <div class="card bank-yellow">
        <div class="label"><?= bank_icon('dinarak') ?> محفظة دينارك</div>
        <div class="value"><?= number_format($overview['bank_dinarak'], 2) ?></div>
    </div>
    <div class="card bank-purple">
        <div class="label"><?= bank_icon('investment') ?> البنك الاستثماري</div>
        <div class="value"><?= number_format($overview['bank_investment'], 2) ?></div>
    </div>
    <div class="card <?= $overview['cash_box_total'] >= 0 ? 'positive' : 'negative' ?>">
        <div class="label">💵 صندوق الكاش (عجز/زيادة)</div>
        <div class="value"><?= number_format($overview['cash_box_total'], 2) ?></div>
    </div>
    <div class="card <?= $overview['cash_real_balance'] >= 0 ? 'positive' : 'negative' ?>">
        <div class="label">💰 صندوق الكاش (الرصيد الفعلي)</div>
        <div class="value"><?= number_format($overview['cash_real_balance'], 2) ?></div>
    </div>
</div>

<div class="panel <?= ($coa_ready && abs($coa_golden_diff) >= 0.01) ? 'table-warning' : '' ?>">
    <h2>⚖️ الداشبورد المحاسبي (دليل الحسابات)</h2>
    <?php if (!$coa_ready): ?>
        <p style="color:#64748b; font-size:14px;">
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
    <p style="font-size:14px; color:#475569;">
        الفحص الذهبي (أصول = التزامات + حقوق ملكية + صافي الدخل):
        <?= number_format($coa_assets,2) ?> = <?= number_format($coa_liab,2) ?> + <?= number_format($coa_equity,2) ?> + <?= number_format($coa_net_income,2) ?>
        <?php if (abs($coa_golden_diff) < 0.01): ?>
            <span class="badge badge-green">✅ متوازنة</span>
        <?php else: ?>
            <span class="badge badge-yellow">⚠️ فرق <?= number_format($coa_golden_diff, 2) ?> — الفترة لسا ما انقفلت محاسبياً أو في حركات بحساب suspense</span>
        <?php endif; ?>
    </p>
    <?php if ($coa_unmapped): ?>
        <p style="font-size:13px; color:#b45309;">
        ⚠️ في <?= count($coa_unmapped) ?> نوع حساب بدفتر الأستاذ ما إله تصنيف بدليل الحسابات بعد — ضيفه لجدول <code>account_type_map</code>.
        </p>
    <?php endif; ?>
    <p style="font-size:13px;"><a href="coa_dashboard.php">🔍 عرض تفصيل كل حساب →</a></p>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>📄 شيكات مستحقة خلال 7 أيام</h2>
    <?php if (empty($overview['upcoming_checks'])): ?>
        <p style="color:#64748b; font-size:14px;">لا يوجد شيكات مستحقة قريباً.</p>
    <?php else: ?>
    <table>
        <tr><th>الجهة</th><th>المبلغ</th><th>الاتجاه</th><th>تاريخ الاستحقاق</th><th>البنك</th><th>ملاحظات</th></tr>
        <?php foreach ($overview['upcoming_checks'] as $c): ?>
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

<div class="panel">
    <h2>💡 كيف تستخدم الشات بوت بالأسفل يسار الشاشة</h2>
    <p style="font-size:14px; color:#475569; line-height:1.8;">
    جرب تكتب جمل عادية مثل:<br>
    • "سجلي فلان أخذ سلفة 200 دينار"<br>
    • "خالد سدد 100 دينار من دين المحل"<br>
    • "صرفت 50 دينار مصاريف كهرباء"<br>
    • "حولت لشريكنا سامر أرباح 300 دينار عن شهر حزيران"<br>
    • "استلمت شيك من أبو محمد 500 دينار تاريخ استحقاقه 2026-08-01"<br>
    • "شو وضعي المالي؟"
    </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
