<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$db = get_db();

function table_exists_tb($db, $name) {
    $s = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$name]);
    return (bool)$s->fetch();
}
$has_by_account = table_exists_tb($db, 'v_trial_balance_by_account');

$rows = $has_by_account
    ? $db->query("SELECT * FROM v_trial_balance_by_account ORDER BY account_code")->fetchAll()
    : $db->query("SELECT * FROM v_trial_balance ORDER BY account_type, account_ref_id")->fetchAll();

$total_debit = 0; $total_credit = 0;
foreach ($rows as $r) { $total_debit += $r['total_debit']; $total_credit += $r['total_credit']; }

require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h2>⚖️ ميزان المراجعة (Trial Balance)</h2>
  <p>لو مجموع المدين = مجموع الدائن، الدفاتر متوازنة. أي حساب <b>حساب معلّق (Suspense)</b> معناه في حركات ما تحدد لها حساب مصدر (بنك/كاش) - راجعها.
  <?php if (!$has_by_account): ?>
    <br>⚠️ شغّل ملف <code>database/migration_fix_trial_balance_view.sql</code> عشان الميزان يتجمّع حسب رقم الحساب الفعلي بدليل الحسابات بدل نوع الحركة الداخلي.
  <?php endif; ?>
  </p>
  <table class="table">
    <thead><tr>
      <?php if ($has_by_account): ?>
      <th>رقم الحساب</th><th>اسم الحساب</th><th>النوع</th>
      <?php else: ?>
      <th>نوع الحساب</th><th>مرجع</th>
      <?php endif; ?>
      <th>مدين</th><th>دائن</th><th>الصافي</th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr class="<?= ($has_by_account ? $r['account_code'] === '1200' : $r['account_type'] === 'suspense') ? 'table-warning' : '' ?>">
        <?php if ($has_by_account): ?>
        <td><?= htmlspecialchars($r['account_code'] ?? 'غير مربوط') ?></td>
        <td><?= htmlspecialchars($r['account_name_ar'] ?? '⚠️ نوع حركة غير مربوط بدليل الحسابات') ?></td>
        <td><?= htmlspecialchars($r['account_type'] ?? '-') ?></td>
        <?php else: ?>
        <td><?= htmlspecialchars($r['account_type']) ?></td>
        <td><?= htmlspecialchars($r['account_ref_id'] ?? '-') ?></td>
        <?php endif; ?>
        <td><?= number_format($r['total_debit'],2) ?></td>
        <td><?= number_format($r['total_credit'],2) ?></td>
        <td><?= number_format($r['net_balance'],2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="<?= $has_by_account ? 3 : 2 ?>">الإجمالي</th><th><?= number_format($total_debit,2) ?></th><th><?= number_format($total_credit,2) ?></th>
      <th><?= abs($total_debit-$total_credit) < 0.01 ? '✅ متوازن' : '⚠️ غير متوازن: '.number_format($total_debit-$total_credit,2) ?></th></tr>
    </tfoot>
  </table>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
