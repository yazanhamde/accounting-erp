<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$db = get_db();

$rows = $db->query("SELECT * FROM v_balance_sheet_summary ORDER BY category")->fetchAll();
$total_assets = 0; $total_liab = 0; $total_equity = 0; $total_revenue = 0; $total_expense = 0;
foreach ($rows as $r) {
    if ($r['category'] === 'أصول (Assets)') $total_assets = (float)$r['net_amount'];
    if ($r['category'] === 'التزامات (Liabilities)') $total_liab = (float)$r['net_amount'];
    if ($r['category'] === 'حقوق ملكية (Equity)') $total_equity = (float)$r['net_amount'];
    if ($r['category'] === 'إيرادات (Income)') $total_revenue = (float)$r['net_amount'];
    if ($r['category'] === 'مصاريف (Expenses)') $total_expense = (float)$r['net_amount'];
}
$net_income = $total_revenue - $total_expense;
$check_diff = $total_assets - ($total_liab + $total_equity + $net_income);

require __DIR__ . '/includes/header.php';
?>
<div class="panel">
  <h2>📊 ملخص الميزانية العمومية (مبسّط)</h2>
  <p>هذا ملخص مبني مباشرة من دفتر الأستاذ (journal_lines) مصنّف حسب دليل الحسابات الفعلي. أي رصيد بـ "أخرى/غير مصنّف" معناه حركات لسا ما تحدد لها حساب مصدر حقيقي (suspense) - راجع صفحة ميزان المراجعة لتفاصيلها.</p>
  <table>
    <tr><th>التصنيف</th><th>الصافي</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['category']) ?></td>
      <td><?= number_format($r['net_amount'], 2) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <hr>
  <p><b>صافي الربح (إيرادات - مصاريف):</b> <?= number_format($net_income, 2) ?></p>
  <p><b>المعادلة المحاسبية:</b> الأصول = الالتزامات + حقوق الملكية + صافي الربح</p>
  <p><?= number_format($total_assets, 2) ?> = <?= number_format($total_liab, 2) ?> + <?= number_format($total_equity, 2) ?> + <?= number_format($net_income, 2) ?>
    &nbsp; <?= abs($check_diff) < 0.01 ? '✅ متطابقة' : '⚠️ فرق: '.number_format($check_diff, 2) ?>
  </p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
