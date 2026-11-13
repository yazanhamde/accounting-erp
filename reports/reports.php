<?php
// ============================================================
// LOGIC
// ============================================================
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/report_data.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = 'التقارير الشهرية';
$month      = $_GET['month'] ?? date('Y-m');
$report     = get_monthly_report_data($month);

require __DIR__ . '/../includes/header.php';
?>

<!-- ============================================================
     HTML
     ============================================================ -->

<div class="panel">
    <form method="get" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
        <div>
            <label style="display:block; font-size:13px; margin-bottom:4px;">اختر الشهر</label>
            <input type="month" name="month" value="<?= htmlspecialchars($month) ?>">
        </div>
        <button type="submit">عرض التقرير</button>
        <a href="/accounting-system/reports/export_pdf.php?month=<?= urlencode($month) ?>" class="btn-export" target="_blank">📄 تصدير PDF</a>
        <a href="/accounting-system/reports/export_excel.php?month=<?= urlencode($month) ?>" class="btn-export">📊 تصدير Excel</a>
    </form>
</div>

<div class="cards">
    <div class="card positive">
        <div class="label">تحصيل من العملاء</div>
        <div class="value"><?= number_format($report['totals']['customers_credit'], 2) ?></div>
    </div>
    <div class="card">
        <div class="label">مبيعات/ديون جديدة على العملاء</div>
        <div class="value"><?= number_format($report['totals']['customers_debit'], 2) ?></div>
    </div>
    <div class="card negative">
        <div class="label">مدفوع للموردين</div>
        <div class="value"><?= number_format($report['totals']['suppliers_credit'], 2) ?></div>
    </div>
    <div class="card">
        <div class="label">مصاريف الشهر</div>
        <div class="value"><?= number_format($report['totals']['expenses_total'], 2) ?></div>
    </div>
    <div class="card positive">
        <div class="label">إيرادات خارجية</div>
        <div class="value"><?= number_format($report['totals']['external_revenues_total'], 2) ?></div>
    </div>
    <div class="card">
        <div class="label">أرباح موزّعة</div>
        <div class="value"><?= number_format($report['totals']['profits_paid'], 2) ?></div>
    </div>
    <div class="card positive">
        <div class="label">إيداعات البنوك</div>
        <div class="value"><?= number_format($report['totals']['banks_deposit'], 2) ?></div>
    </div>
    <div class="card negative">
        <div class="label">سحوبات البنوك</div>
        <div class="value"><?= number_format($report['totals']['banks_withdraw'], 2) ?></div>
    </div>
</div>

<div class="panel">
    <h2>🧾 ذمم العملاء (<?= count($report['customers']) ?> حركة)</h2>
    <?php if (empty($report['customers'])): ?>
        <p style="color:#64748b; font-size:14px;">لا يوجد حركات هالشهر.</p>
    <?php else: ?>
    <table>
        <tr><th>التاريخ</th><th>الاسم</th><th>النوع</th><th>المبلغ</th><th>الوصف</th></tr>
        <?php foreach ($report['customers'] as $r): ?>
        <tr>
            <td><?= $r['tx_date'] ?></td>
            <td><?= htmlspecialchars($r['party']) ?></td>
            <td><?= $r['type'] === 'credit' ? 'تسديد' : 'دين جديد' ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>📦 ذمم الموردين (<?= count($report['suppliers']) ?> حركة)</h2>
    <?php if (empty($report['suppliers'])): ?>
        <p style="color:#64748b; font-size:14px;">لا يوجد حركات هالشهر.</p>
    <?php else: ?>
    <table>
        <tr><th>التاريخ</th><th>الاسم</th><th>النوع</th><th>المبلغ</th><th>الوصف</th></tr>
        <?php foreach ($report['suppliers'] as $r): ?>
        <tr>
            <td><?= $r['tx_date'] ?></td>
            <td><?= htmlspecialchars($r['party']) ?></td>
            <td><?= $r['type'] === 'credit' ? 'تسديد لنا' : 'دين جديد علينا' ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>🤝 دائن ومدين شخصي (<?= count($report['personal']) ?> حركة)</h2>
    <?php if (empty($report['personal'])): ?>
        <p style="color:#64748b; font-size:14px;">لا يوجد حركات هالشهر.</p>
    <?php else: ?>
    <table>
        <tr><th>التاريخ</th><th>الاسم</th><th>النوع</th><th>المبلغ</th><th>الوصف</th></tr>
        <?php foreach ($report['personal'] as $r): ?>
        <tr>
            <td><?= $r['tx_date'] ?></td>
            <td><?= htmlspecialchars($r['party']) ?></td>
            <td><?= $r['type'] === 'lend' ? 'سلفة' : 'تسديد' ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>💰 توزيع الأرباح (<?= count($report['profits']) ?> حركة)</h2>
    <?php if (empty($report['profits'])): ?>
        <p style="color:#64748b; font-size:14px;">لا يوجد توزيعات هالشهر.</p>
    <?php else: ?>
    <table>
        <tr><th>التاريخ</th><th>الشريك</th><th>المبلغ</th><th>الفترة</th><th>الوصف</th></tr>
        <?php foreach ($report['profits'] as $r): ?>
        <tr>
            <td><?= $r['tx_date'] ?></td>
            <td><?= htmlspecialchars($r['party']) ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['period_label'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>🧻 المصاريف (<?= count($report['expenses']) ?> حركة)</h2>
    <?php if (empty($report['expenses'])): ?>
        <p style="color:#64748b; font-size:14px;">لا يوجد مصاريف هالشهر.</p>
    <?php else: ?>
    <table>
        <tr><th>التاريخ</th><th>النوع</th><th>المبلغ</th><th>مدفوع من</th><th>الوصف</th></tr>
        <?php foreach ($report['expenses'] as $r): ?>
        <tr>
            <td><?= $r['tx_date'] ?></td>
            <td><?= htmlspecialchars($r['category']) ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['paid_from_bank'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>💚 الإيرادات الخارجية (<?= count($report['external_revenues']) ?> حركة)</h2>
    <?php if (empty($report['external_revenues'])): ?>
        <p style="color:#64748b; font-size:14px;">لا يوجد إيرادات خارجية هالشهر.</p>
    <?php else: ?>
    <table>
        <tr><th>التاريخ</th><th>المصدر</th><th>المبلغ</th><th>دخلت لـ</th><th>الوصف</th></tr>
        <?php foreach ($report['external_revenues'] as $r): ?>
        <tr>
            <td><?= $r['tx_date'] ?></td>
            <td><?= htmlspecialchars($r['source_person']) ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['paid_from_bank'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>📄 الشيكات المستحقة هالشهر (<?= count($report['checks']) ?>)</h2>
    <?php if (empty($report['checks'])): ?>
        <p style="color:#64748b; font-size:14px;">لا يوجد شيكات مستحقة هالشهر.</p>
    <?php else: ?>
    <table>
        <tr><th>التاريخ</th><th>الجهة</th><th>الاتجاه</th><th>المبلغ</th><th>البنك</th><th>الحالة</th></tr>
        <?php foreach ($report['checks'] as $r): ?>
        <tr>
            <td><?= $r['due_date'] ?></td>
            <td><?= htmlspecialchars($r['party_name']) ?></td>
            <td><?= $r['direction'] === 'incoming' ? 'مستلم' : 'مدفوع' ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['bank_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['status']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
