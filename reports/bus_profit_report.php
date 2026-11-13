<?php
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'تقرير أرباح الباصات الشهري';
$user = current_user();
$db = get_db();

$month = $_GET['month'] ?? date('Y-m'); // مثال: 2026-07
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$sql = "
    SELECT
        c.id, c.name, c.car_info,
        COALESCE(SUM(CASE WHEN t.type = 'revenue'   THEN t.amount ELSE 0 END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN t.type = 'expense'    THEN t.amount ELSE 0 END), 0) AS expense,
        COALESCE(SUM(CASE WHEN t.type = 'ads'        THEN t.amount ELSE 0 END), 0) AS ads,
        COALESCE(SUM(CASE WHEN t.type = 'advance'    THEN t.amount ELSE 0 END), 0) AS advance,
        COALESCE(SUM(CASE WHEN t.type = 'deduction'  THEN t.amount ELSE 0 END), 0) AS deduction,
        COALESCE(SUM(CASE WHEN t.type = 'revenue'    THEN t.customer_count ELSE 0 END), 0) AS customers
    FROM car_employees c
    LEFT JOIN car_employee_transactions t
        ON t.car_employee_id = c.id AND DATE_FORMAT(t.tx_date, '%Y-%m') = ?
    GROUP BY c.id, c.name, c.car_info
    ORDER BY c.name ASC
";
$stmt = $db->prepare($sql);
$stmt->execute([$month]);
$rows = $stmt->fetchAll();

$totals = ['revenue' => 0, 'expense' => 0, 'ads' => 0, 'advance' => 0, 'deduction' => 0, 'net' => 0, 'customers' => 0];
foreach ($rows as &$r) {
    // صافي ربح الباص (لصاحب المحل) كنشاط تجاري = الإيراد - (مصاريف التشغيل + الإعلانات)
    // (راتب السائق ما بيتحسب هون - هو راتب موظف بينسجل ويتحسب من صفحة "الموظفين"، مش تكلفة تشغيل على الباص)
    // (السلف والخصومات ما بتحسب هون لأنها تسوية شخصية مع السائق مش تكلفة تشغيل)
    $r['net'] = $r['revenue'] - $r['expense'] - $r['ads'];
    foreach (['revenue', 'expense', 'ads', 'advance', 'deduction', 'net', 'customers'] as $k) {
        $totals[$k] += $r[$k];
    }
}
unset($r);

require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <h2>📊 تقرير أرباح الباصات الشهري</h2>
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
    <h3>تفاصيل شهر <?= htmlspecialchars($month) ?></h3>
    <table>
        <tr>
            <th>الباص</th>
            <th>عدد الزبائن</th>
            <th>الإيراد</th>
            <th>مصاريف (قطع/صيانة/لوحات/يومية)</th>
            <th>حصة الإعلانات</th>
            <th>صافي الربح (للمحل)</th>
        </tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?><?= $r['car_info'] ? ' (' . htmlspecialchars($r['car_info']) . ')' : '' ?></td>
            <td><?= (int)$r['customers'] ?></td>
            <td><?= number_format($r['revenue'], 2) ?></td>
            <td><?= number_format($r['expense'], 2) ?></td>
            <td><?= number_format($r['ads'], 2) ?></td>
            <td style="font-weight:bold; color:<?= $r['net'] >= 0 ? '#16a34a' : '#dc2626' ?>">
                <?= number_format($r['net'], 2) ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:bold; background:#f8fafc;">
            <td>الإجمالي</td>
            <td><?= (int)$totals['customers'] ?></td>
            <td><?= number_format($totals['revenue'], 2) ?></td>
            <td><?= number_format($totals['expense'], 2) ?></td>
            <td><?= number_format($totals['ads'], 2) ?></td>
            <td style="color:<?= $totals['net'] >= 0 ? '#16a34a' : '#dc2626' ?>">
                <?= number_format($totals['net'], 2) ?>
            </td>
        </tr>
    </table>
    <p style="color:#64748b; font-size:13px;">
        ملاحظة: السلف والخصومات ما بتنحسب ضمن صافي الربح هون لأنها تسوية شخصية مع السائق (مش تكلفة تشغيل)، وراتب السائق
        نفسه ما بينحسب هون كمان (هو راتب موظف بينسجل ويتحسب من صفحة "الموظفين")، وبتظل كلها تنعرض وتنحسب براحتها من صفحة "صيانة خارجية".
    </p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
