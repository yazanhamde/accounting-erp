<?php
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'تقرير أرباح المشغل الشهري';
$user = current_user();
$db = get_db();

$month = $_GET['month'] ?? date('Y-m'); // مثال: 2026-07
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$sql = "
    SELECT
        COALESCE(SUM(CASE WHEN type = 'revenue' THEN amount ELSE 0 END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense
    FROM workshop_transactions
    WHERE DATE_FORMAT(tx_date, '%Y-%m') = ?
";
$stmt = $db->prepare($sql);
$stmt->execute([$month]);
$totals = $stmt->fetch();
$totals['revenue'] = (float)($totals['revenue'] ?? 0);
$totals['expense'] = (float)($totals['expense'] ?? 0);
$totals['net'] = $totals['revenue'] - $totals['expense'];

$tx_stmt = $db->prepare("
    SELECT * FROM workshop_transactions
    WHERE DATE_FORMAT(tx_date, '%Y-%m') = ?
    ORDER BY tx_date ASC, id ASC
");
$tx_stmt->execute([$month]);
$rows = $tx_stmt->fetchAll();

$type_labels = [
    'revenue' => 'إيراد المشغل',
    'expense' => 'مصاريف المشغل',
];

require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <h2>📊 تقرير أرباح المشغل الشهري</h2>
    <form method="get" class="form-row">
        <div>
            <label>الشهر</label>
            <input type="month" name="month" value="<?= htmlspecialchars($month) ?>">
        </div>
        <div style="align-self:end">
            <button class="btn" type="submit">عرض</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>📊 صافي ربح/خسارة المشغل - شهر <?= htmlspecialchars($month) ?></h2>
    <div class="form-row">
        <div><strong>إجمالي إيراد المشغل:</strong> <?= number_format($totals['revenue'], 2) ?></div>
        <div><strong>إجمالي مصاريف المشغل:</strong> <?= number_format($totals['expense'], 2) ?></div>
        <div style="font-weight:bold; color:<?= $totals['net'] >= 0 ? '#16a34a' : '#dc2626' ?>">
            صافي ربح/خسارة المشغل: <?= number_format($totals['net'], 2) ?>
        </div>
    </div>
</div>

<div class="panel">
    <h3>تفاصيل حركات شهر <?= htmlspecialchars($month) ?></h3>
    <table>
        <tr>
            <th>نوع الحركة</th>
            <th>المبلغ</th>
            <th>ملاحظات</th>
            <th>التاريخ</th>
        </tr>
        <?php if (empty($rows)): ?>
        <tr><td colspan="4" style="text-align:center;color:#64748b;">ما في حركات مسجلة لهاد الشهر</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars($type_labels[$r['type']] ?? $r['type']) ?></td>
            <td style="font-weight:bold; color:<?= $r['type'] === 'revenue' ? '#16a34a' : '#dc2626' ?>">
                <?= number_format($r['amount'], 2) ?>
            </td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['tx_date']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:bold; background:#f8fafc;">
            <td>الإجمالي</td>
            <td style="color:<?= $totals['net'] >= 0 ? '#16a34a' : '#dc2626' ?>">
                <?= number_format($totals['net'], 2) ?>
            </td>
            <td colspan="2"></td>
        </tr>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
