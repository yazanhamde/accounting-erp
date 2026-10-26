<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$db = get_db();
$page_title = 'الداشبورد المالي (دليل الحسابات)';

// لو الترحيل ما اشتغل بعد، ما نكسر الصفحة - نعرض رسالة واضحة
function table_exists_coa($db, $name) {
    $s = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$name]);
    return (bool)$s->fetch();
}
$ready = table_exists_coa($db, 'v_dashboard_summary');

$summary = [];
$accounts = [];
$unmapped = [];
$assets = $liab = $equity = $revenue = $expense = 0;

if ($ready) {
    foreach ($db->query("SELECT * FROM v_dashboard_summary")->fetchAll() as $r) {
        $summary[$r['category']] = (float)$r['total'];
    }
    $assets  = $summary['asset'] ?? 0;
    $liab    = $summary['liability'] ?? 0;
    $equity  = $summary['equity'] ?? 0;
    $revenue = $summary['revenue'] ?? 0;
    $expense = $summary['expense'] ?? 0;

    $accounts = $db->query("SELECT * FROM v_dashboard_accounts")->fetchAll();
    $unmapped = $db->query("SELECT * FROM v_dashboard_unmapped")->fetchAll();
}

$net_income = $revenue - $expense;
$golden_diff = $assets - ($liab + $equity + $net_income);

$cat_labels = [
    'asset' => 'أصول', 'liability' => 'التزامات', 'equity' => 'حقوق ملكية',
    'revenue' => 'إيرادات', 'expense' => 'مصاريف',
];

require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h2>📊 الداشبورد المالي - حسب دليل الحسابات</h2>

  <?php if (!$ready): ?>
    <div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
      ⚠️ لسا ما اتشغّل ملف <code>database/migration_chart_of_accounts_dashboard.sql</code> على هالقاعدة.
      شغّله من phpMyAdmin (تبويب SQL) وبعدها رجّع افتح هالصفحة.
    </div>
  <?php else: ?>

  <p style="color:#64748b;margin-bottom:15px">
    هاي الأرقام محسوبة مباشرة من دفتر الأستاذ (<code>journal_lines</code>) بعد ما تصنّفت حسب دليل الحسابات -
    مو أرقام مخزّنة يدوياً، فهي بتعكس نفس الحقيقة المحاسبية يلي بميزان المراجعة.
  </p>

  <div class="cards">
    <div class="card <?= $assets >= 0 ? 'positive' : 'negative' ?>">
      <div class="label">إجمالي الأصول</div>
      <div class="value"><?= number_format($assets, 2) ?></div>
    </div>
    <div class="card">
      <div class="label">إجمالي الالتزامات</div>
      <div class="value"><?= number_format($liab, 2) ?></div>
    </div>
    <div class="card">
      <div class="label">حقوق الملكية (رأس المال)</div>
      <div class="value"><?= number_format($equity, 2) ?></div>
    </div>
    <div class="card <?= $net_income >= 0 ? 'positive' : 'negative' ?>">
      <div class="label">صافي الدخل للفترة الحالية (إيراد - مصروف)</div>
      <div class="value"><?= number_format($net_income, 2) ?></div>
    </div>
  </div>

  <div class="panel <?= abs($golden_diff) < 0.01 ? '' : 'table-warning' ?>">
    <h2>⚖️ الفحص الذهبي: الأصول = الالتزامات + حقوق الملكية + صافي الدخل</h2>
    <p>
      <?= number_format($assets,2) ?> = <?= number_format($liab,2) ?> + <?= number_format($equity,2) ?> + <?= number_format($net_income,2) ?>
      <br>
      <?php if (abs($golden_diff) < 0.01): ?>
        <span class="badge badge-green">✅ متوازنة</span>
      <?php else: ?>
        <span class="badge badge-red">⚠️ فرق قدره <?= number_format($golden_diff, 2) ?> دينار</span> —
        غالباً بسبب فترة محاسبية لسا ما انقفلت (صافي الدخل لسا ما ترحّل لحقوق الملكية)، أو حركات بحساب suspense لسا ما تحددلها مصدر.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($unmapped): ?>
  <div class="panel" style="border:2px solid #d97706;background:#fffbeb">
    <strong>⚠️ في أنواع حسابات بدفتر الأستاذ ما إلها تصنيف بدليل الحسابات بعد:</strong>
    <table class="table">
      <thead><tr><th>account_type</th><th>عدد الحركات</th><th>الإجمالي</th></tr></thead>
      <tbody>
      <?php foreach ($unmapped as $u): ?>
        <tr><td><?= htmlspecialchars($u['account_type']) ?></td><td><?= $u['lines_count'] ?></td><td><?= number_format($u['total_amount'],2) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p>ضيف سطر إله بجدول <code>account_type_map</code> حتى يظهر بالداشبورد.</p>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>تفصيل كل حساب</h2>
    <table class="table">
      <thead><tr><th>الرمز</th><th>اسم الحساب</th><th>التصنيف</th><th>الرصيد</th></tr></thead>
      <tbody>
      <?php foreach ($accounts as $a): ?>
        <?php if (abs($a['balance']) < 0.01) continue; ?>
        <tr class="<?= $a['category']==='asset' && $a['account_code']==='1200' ? 'table-warning' : '' ?>">
          <td><?= htmlspecialchars($a['account_code']) ?></td>
          <td><?= htmlspecialchars($a['account_name_ar']) ?></td>
          <td><?= htmlspecialchars($cat_labels[$a['category']] ?? $a['category']) ?></td>
          <td><?= number_format($a['balance'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
