<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$db = get_db();

// =====================================================================
// Logic: جلب بيانات قائمة الدخل
// =====================================================================

// نطاق التاريخ الافتراضي: من أول الشهر الحالي لليوم
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// حماية بسيطة: لو حدا بدّل الترتيب بالغلط بالرابط
if ($from > $to) { [$from, $to] = [$to, $from]; }

function table_or_view_exists_is($db, $name) {
    $s = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$name]);
    return (bool)$s->fetch();
}

$has_view = table_or_view_exists_is($db, 'v_journal_with_accounts');

$rows = [];
if ($has_view) {
    $stmt = $db->prepare("
        SELECT account_code, account_name_ar, category, SUM(signed_amount) AS total
        FROM v_journal_with_accounts
        WHERE entry_date BETWEEN ? AND ?
          AND category IN ('revenue','expense')
        GROUP BY account_code, account_name_ar, category
        ORDER BY category, account_code
    ");
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();
}

$revenue_rows = array_values(array_filter($rows, fn($r) => $r['category'] === 'revenue'));
$expense_rows = array_values(array_filter($rows, fn($r) => $r['category'] === 'expense'));

$total_revenue = array_sum(array_column($revenue_rows, 'total'));
$total_expense = array_sum(array_column($expense_rows, 'total'));
$net_income    = $total_revenue - $total_expense;

$page_title = 'قائمة الدخل';

// =====================================================================
// HTML
// =====================================================================
require_once __DIR__ . '/../includes/header.php';
?>
<div class="panel">
  <h2>💹 قائمة الدخل (Income Statement)</h2>
  <p>صافي الربح أو الخسارة الفعلي عن فترة محددة = إجمالي الإيرادات ناقص إجمالي المصاريف، محسوبة مباشرة من دفتر الأستاذ (journal_lines) حسب دليل الحسابات الفعلي.</p>

  <?php if (!$has_view): ?>
    <p style="color:#b00;">⚠️ العرض (view) المطلوب <code>v_journal_with_accounts</code> غير موجود بقاعدة بياناتك. هذا العرض جزء من ترحيلات سابقة (migration_v6_chart_of_accounts_seed أو ما بعدها) - تأكد إنها اتنفذت كلها.</p>
  <?php endif; ?>

  <!-- فلتر التاريخ -->
  <form method="get" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:20px;">
    <div>
      <label style="display:block; font-size:13px; margin-bottom:4px;">من تاريخ</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div>
      <label style="display:block; font-size:13px; margin-bottom:4px;">إلى تاريخ</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    </div>
    <button type="submit">عرض</button>
    <a href="/accounting-system/reports/income_statement.php?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">📅 هالشهر</a>
    <a href="/accounting-system/reports/income_statement.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>">📅 هالسنة</a>
  </form>

  <!-- بطاقات الملخص -->
  <div class="cards" style="margin-bottom:20px;">
    <div class="card positive">
      <div class="label">إجمالي الإيرادات</div>
      <div class="value"><?= number_format($total_revenue, 2) ?></div>
    </div>
    <div class="card negative">
      <div class="label">إجمالي المصاريف</div>
      <div class="value"><?= number_format($total_expense, 2) ?></div>
    </div>
    <div class="card <?= $net_income >= 0 ? 'positive' : 'negative' ?>">
      <div class="label"><?= $net_income >= 0 ? '✅ صافي الربح' : '⚠️ صافي الخسارة' ?></div>
      <div class="value"><?= number_format(abs($net_income), 2) ?></div>
    </div>
  </div>

  <!-- جدول الإيرادات -->
  <h3>الإيرادات</h3>
  <table class="table">
    <thead><tr><th>رقم الحساب</th><th>اسم الحساب</th><th>المبلغ</th></tr></thead>
    <tbody>
      <?php if (empty($revenue_rows)): ?>
        <tr><td colspan="3">ما في إيرادات مسجّلة بهاي الفترة</td></tr>
      <?php endif; ?>
      <?php foreach ($revenue_rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['account_code']) ?></td>
        <td><?= htmlspecialchars($r['account_name_ar']) ?></td>
        <td><?= number_format($r['total'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="2">إجمالي الإيرادات</th><th><?= number_format($total_revenue, 2) ?></th></tr>
    </tfoot>
  </table>

  <!-- جدول المصاريف -->
  <h3 style="margin-top:24px;">المصاريف</h3>
  <table class="table">
    <thead><tr><th>رقم الحساب</th><th>اسم الحساب</th><th>المبلغ</th></tr></thead>
    <tbody>
      <?php if (empty($expense_rows)): ?>
        <tr><td colspan="3">ما في مصاريف مسجّلة بهاي الفترة</td></tr>
      <?php endif; ?>
      <?php foreach ($expense_rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['account_code']) ?></td>
        <td><?= htmlspecialchars($r['account_name_ar']) ?></td>
        <td><?= number_format($r['total'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="2">إجمالي المصاريف</th><th><?= number_format($total_expense, 2) ?></th></tr>
    </tfoot>
  </table>

  <hr style="margin:24px 0;">
  <p style="font-size:18px;">
    <b><?= $net_income >= 0 ? '✅ صافي الربح للفترة' : '⚠️ صافي الخسارة للفترة' ?>:</b>
    <?= number_format(abs($net_income), 2) ?>
  </p>
  <p style="font-size:13px; color:#888;">ملاحظة: "سحوبات المالك الشخصية" ما بتظهر هون لأنها حساب حقوق ملكية مش مصروف تشغيلي - بتظهر بالميزانية العمومية كتخفيض لرأس المال، مش هون كمصروف.</p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
