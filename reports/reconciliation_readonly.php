<?php
/**
 * تقرير مطابقة الجرد — قراءة فقط (Read-Only)
 * =====================================================================
 * الهدف: يعرض فروقات الأرصدة (المخزّن مقابل المحسوب من الحركات) للعملاء
 * والموردين، لاستخدامه وقت الجرد مع المحاسب - بدون أي إمكانية تسجيل أو
 * تعديل أو حذف من هاي الصفحة إطلاقاً (ولا حتى POST واحد).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();
$db = get_db();

// =====================================================================
// Logic: جلب البيانات والإحصائيات
// =====================================================================

// فلترة اختيارية بالاسم (بحث فقط - GET، بدون أي تعديل بالبيانات)
$search = trim($_GET['q'] ?? '');

$cust_sql = "SELECT * FROM v_reconciliation_customers";
$sup_sql  = "SELECT * FROM v_reconciliation_suppliers";
$params_c = [];
$params_s = [];

if ($search !== '') {
    $cust_sql .= " WHERE name LIKE ?";
    $sup_sql  .= " WHERE name LIKE ?";
    $params_c[] = "%$search%";
    $params_s[] = "%$search%";
}
$cust_sql .= " ORDER BY ABS(difference) DESC";
$sup_sql  .= " ORDER BY ABS(difference) DESC";

$stmt_c = $db->prepare($cust_sql);
$stmt_c->execute($params_c);
$cust_diff = $stmt_c->fetchAll();

$stmt_s = $db->prepare($sup_sql);
$stmt_s->execute($params_s);
$sup_diff = $stmt_s->fetchAll();

// آخر وقت مزامنة ناجحة (للسياق فقط)
function last_sync_time($db, $type) {
    $stmt = $db->prepare("SELECT finished_at FROM pos_sync_runs WHERE sync_type = ? AND status = 'success' ORDER BY finished_at DESC LIMIT 1");
    try {
        $stmt->execute([$type]);
        return $stmt->fetchColumn() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

$last_customers_sync = last_sync_time($db, 'daily_cron_customers');
$last_suppliers_sync = last_sync_time($db, 'daily_cron_suppliers');

$total_cust_diff = array_sum(array_column($cust_diff, 'difference'));
$total_sup_diff  = array_sum(array_column($sup_diff,  'difference'));

// =====================================================================
// HTML
// =====================================================================
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .ro-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.8rem; background:#0f5132; color:#fff; }
  .ro-table td.num, .ro-table th.num { text-align:left; direction:ltr; font-variant-numeric: tabular-nums; }
  .ro-table tr.ok td { opacity:.55; }
  @media print {
    .sidebar, .app-topbar, .no-print { display:none !important; }
    .container { margin:0; max-width:100%; }
  }
</style>
<div class="container">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <h2>📋 تقرير مطابقة الجرد <span class="ro-badge">قراءة فقط</span></h2>
    <div class="no-print">
      <button onclick="window.print()" class="btn">🖨️ طباعة / تصدير PDF</button>
    </div>
  </div>

  <p>
    مقارنة بين <strong>الرصيد المخزّن</strong> بجدول العملاء/الموردين و<strong>الرصيد المحسوب</strong> فعليًا
    من مجموع كل الحركات المسجّلة (بما فيها المزامَنة تلقائيًا من نظام الـ POS).
    هاي الصفحة عرض فقط — أي فرق يظهر هون لازم يتراجع ويتصحّح يدويًا من صفحة
    <a href="/accounting-system/reports/reconciliation.php">تسوية الأرصدة</a> أو صفحات تصحيح الأرصدة، مش من هون.
  </p>

  <div class="no-print" style="margin-bottom:12px;">
    <form method="get" style="display:flex; gap:8px;">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="بحث باسم عميل/مورد..." style="flex:1; max-width:320px;">
      <button type="submit" class="btn">بحث</button>
      <?php if ($search !== ''): ?>
        <a href="/accounting-system/reports/reconciliation_readonly.php" class="btn btn-secondary">تفريغ</a>
      <?php endif; ?>
    </form>
  </div>

  <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
    <div class="card" style="padding:12px 16px;">
      <div>عدد عملاء فيهم تضارب</div>
      <strong style="font-size:1.4rem;"><?= count($cust_diff) ?></strong>
      <div style="font-size:.8rem; opacity:.7;">صافي الفروقات: <?= number_format($total_cust_diff, 2) ?></div>
    </div>
    <div class="card" style="padding:12px 16px;">
      <div>عدد موردين فيهم تضارب</div>
      <strong style="font-size:1.4rem;"><?= count($sup_diff) ?></strong>
      <div style="font-size:.8rem; opacity:.7;">صافي الفروقات: <?= number_format($total_sup_diff, 2) ?></div>
    </div>
    <?php if ($last_customers_sync): ?>
    <div class="card" style="padding:12px 16px;">
      <div>آخر مزامنة عملاء ناجحة</div>
      <strong><?= htmlspecialchars($last_customers_sync) ?></strong>
    </div>
    <?php endif; ?>
    <?php if ($last_suppliers_sync): ?>
    <div class="card" style="padding:12px 16px;">
      <div>آخر مزامنة موردين ناجحة</div>
      <strong><?= htmlspecialchars($last_suppliers_sync) ?></strong>
    </div>
    <?php endif; ?>
  </div>

  <h4>🧾 العملاء</h4>
  <table class="table ro-table">
    <thead><tr><th>الاسم</th><th class="num">الرصيد المخزّن</th><th class="num">المحسوب من الحركات</th><th class="num">الفرق</th></tr></thead>
    <tbody>
    <?php foreach ($cust_diff as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td class="num"><?= number_format($r['stored_balance'], 2) ?></td>
        <td class="num"><?= number_format($r['computed_balance'], 2) ?></td>
        <td class="num text-danger"><strong><?= number_format($r['difference'], 2) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$cust_diff): ?>
      <tr class="ok"><td colspan="4">✅ ما في تضارب — كل أرصدة العملاء مطابقة لحركاتها</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <h4>📦 الموردين</h4>
  <table class="table ro-table">
    <thead><tr><th>الاسم</th><th class="num">الرصيد المخزّن</th><th class="num">المحسوب من الحركات</th><th class="num">الفرق</th></tr></thead>
    <tbody>
    <?php foreach ($sup_diff as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td class="num"><?= number_format($r['stored_balance'], 2) ?></td>
        <td class="num"><?= number_format($r['computed_balance'], 2) ?></td>
        <td class="num text-danger"><strong><?= number_format($r['difference'], 2) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sup_diff): ?>
      <tr class="ok"><td colspan="4">✅ ما في تضارب — كل أرصدة الموردين مطابقة لحركاتها</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <p style="font-size:.85rem; opacity:.7; margin-top:24px;">
    تم إنشاء التقرير: <?= date('Y-m-d H:i') ?>
    — هاي الصفحة لا تحتوي على أي نموذج (form) أو مسار POST، فهي آمنة للفتح والمشاركة وقت الجرد من دون خطر التعديل بالخطأ على البيانات.
  </p>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
