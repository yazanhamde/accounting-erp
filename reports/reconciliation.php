<?php
// ============================================================
// LOGIC — معالجة الطلبات وجلب البيانات
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
require_owner(); // فقط المالك يشوف هاي الصفحة ويقفل الفترات

$db = get_db();

// الجداول المسموح تصحيح حساب البنك فيها — whitelist صارم يمنع SQL injection
const SUSPENSE_SOURCE_TABLES = [
    'supplier_transactions',
    'customer_transactions',
    'personal_transactions',
    'profit_distributions',
    'expenses',
];

// --- تصحيح حركة معلّقة: ربطها بحساب بنك/كاش حقيقي ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['resolve_suspense'])) {
    $source_table = $_POST['source_table'] ?? '';
    $source_id    = (int)($_POST['source_id'] ?? 0);
    $new_bank     = trim($_POST['new_paid_from_bank'] ?? '');

    if (!in_array($source_table, SUSPENSE_SOURCE_TABLES, true)) {
        header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php?resolve_error=' . urlencode('جدول غير مسموح'));
        exit;
    }
    if ($new_bank === '' || $source_id <= 0) {
        header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php?resolve_error=' . urlencode('لازم تختار حساب بنك/كاش حقيقي'));
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE `$source_table` SET paid_from_bank = ?, no_bank_reason = NULL WHERE id = ?");
        $stmt->execute([$new_bank, $source_id]);
        header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php?resolved=1');
    } catch (Exception $e) {
        header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php?resolve_error=' . urlencode($e->getMessage()));
    }
    exit;
}

// --- استيراد كشف حساب بنكي ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_statement'])) {
    $bank_name = $_POST['stmt_bank'];
    $batch     = $bank_name . '_' . date('Y-m-d_His');
    $lines     = preg_split('/\r\n|\r|\n/', trim($_POST['stmt_lines']));
    $stmt      = $db->prepare(
        'INSERT INTO bank_statement_lines (bank_name, stmt_date, description, amount, reference_no, imported_batch)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode(',', $line));
        if (count($parts) < 2) continue;
        $stmt_date = $parts[0];
        $amount    = (float)$parts[1];
        $desc      = $parts[2] ?? null;
        $ref       = $parts[3] ?? null;
        $stmt->execute([$bank_name, $stmt_date, $desc, $amount, $ref, $batch]);
    }
    header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php?imported=1');
    exit;
}

// --- مطابقة تلقائية ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['auto_match'])) {
    $unmatched = $db->query("SELECT * FROM bank_statement_lines WHERE status='unmatched'")->fetchAll();
    foreach ($unmatched as $sl) {
        $bank_table = 'bank_' . preg_replace('/_(personal|business)$/', '', $sl['bank_name']);
        if (!in_array($bank_table, ['bank_jordan','bank_etihad','bank_islami','bank_dinarak','bank_investment'], true)) continue;
        $tx_type    = $sl['amount'] >= 0 ? 'deposit' : 'withdraw';
        $abs_amount = abs($sl['amount']);
        $q = $db->prepare("SELECT id FROM $bank_table WHERE tx_type = ? AND amount = ? AND ABS(DATEDIFF(tx_date, ?)) <= 3 LIMIT 1");
        $q->execute([$tx_type, $abs_amount, $sl['stmt_date']]);
        $match = $q->fetch();
        if ($match) {
            $db->prepare("UPDATE bank_statement_lines SET status='auto_matched', matched_table=?, matched_id=?, matched_at=NOW() WHERE id = ?")
               ->execute([$bank_table, $match['id'], $sl['id']]);
        }
    }
    header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php?matched=1');
    exit;
}

// --- تجاهل سطر ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ignore_line'])) {
    $db->prepare("UPDATE bank_statement_lines SET status='ignored' WHERE id = ?")
       ->execute([(int)$_POST['ignore_line']]);
    header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php');
    exit;
}

// --- إقفال فترة ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['lock_period'])) {
    $db->prepare('INSERT IGNORE INTO period_locks (period, locked_by) VALUES (?, ?)')
       ->execute([$_POST['lock_period'], current_user()['id']]);
    header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php');
    exit;
}

// --- فتح فترة ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['unlock_period'])) {
    $db->prepare('DELETE FROM period_locks WHERE period = ?')
       ->execute([$_POST['unlock_period']]);
    header('Location: ' . APP_BASE_URL . '/reports/reconciliation.php');
    exit;
}

// --- جلب البيانات ---
$cust_diff = $db->query("SELECT * FROM v_reconciliation_customers")->fetchAll();
$sup_diff  = $db->query("SELECT * FROM v_reconciliation_suppliers")->fetchAll();
$per_diff  = $db->query("SELECT * FROM v_reconciliation_personal")->fetchAll();
$locks     = $db->query("SELECT * FROM period_locks ORDER BY period DESC")->fetchAll();

function table_exists($db, $name) {
    $s = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$name]);
    return (bool)$s->fetch();
}

$has_v3           = table_exists($db, 'bank_statement_lines');
$suspense_summary = $has_v3 ? $db->query("SELECT * FROM v_suspense_summary")->fetch() : null;
$suspense_lines   = $has_v3 ? $db->query("SELECT * FROM v_suspense_monitor LIMIT 50")->fetchAll() : [];
$stmt_totals      = $has_v3 ? $db->query("SELECT * FROM v_bank_reconciliation_totals ORDER BY period_to DESC LIMIT 20")->fetchAll() : [];
$stmt_unmatched   = $has_v3 ? $db->query("SELECT * FROM v_bank_statement_unmatched LIMIT 50")->fetchAll() : [];

// ============================================================
// VIEW — واجهة المستخدم
// ============================================================
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <h2>🔍 تسوية الأرصدة</h2>
  <p>الأرصدة المخزّنة يفترض تساوي مجموع الحركات. أي صف يظهر هون معناه في تضارب يحتاج تصحيح يدوي بجدول customers/suppliers/personal_accounts.</p>

  <!-- =================== العملاء =================== -->
  <h4>العملاء</h4>
  <table class="table">
    <thead>
      <tr><th>الاسم</th><th>الرصيد المخزّن</th><th>المحسوب من الحركات</th><th>الفرق</th></tr>
    </thead>
    <tbody>
      <?php foreach ($cust_diff as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= $r['stored_balance'] ?></td>
        <td><?= $r['computed_balance'] ?></td>
        <td class="text-danger"><?= $r['difference'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$cust_diff): ?>
        <tr><td colspan="4">✅ ما في تضارب</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- =================== الموردين =================== -->
  <h4>الموردين</h4>
  <table class="table">
    <thead>
      <tr><th>الاسم</th><th>الرصيد المخزّن</th><th>المحسوب من الحركات</th><th>الفرق</th></tr>
    </thead>
    <tbody>
      <?php foreach ($sup_diff as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= $r['stored_balance'] ?></td>
        <td><?= $r['computed_balance'] ?></td>
        <td class="text-danger"><?= $r['difference'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$sup_diff): ?>
        <tr><td colspan="4">✅ ما في تضارب</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- =================== الحسابات الشخصية =================== -->
  <h4>الحسابات الشخصية</h4>
  <table class="table">
    <thead>
      <tr><th>الاسم</th><th>الرصيد المخزّن</th><th>المحسوب من الحركات</th><th>الفرق</th></tr>
    </thead>
    <tbody>
      <?php foreach ($per_diff as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= $r['stored_balance'] ?></td>
        <td><?= $r['computed_balance'] ?></td>
        <td class="text-danger"><?= $r['difference'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$per_diff): ?>
        <tr><td colspan="4">✅ ما في تضارب</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if (!$has_v3): ?>
  <div class="alert alert-warning">
    ⚠️ شغّل ملف <code>database/migration_v3_five_fixes.sql</code> من phpMyAdmin حتى تظهر
    أقسام "حساب suspense" و"التسوية البنكية الحقيقية" تحت.
  </div>
  <?php else: ?>

  <hr>

  <!-- =================== حساب Suspense =================== -->
  <h4>🧹 حساب Suspense (المعلّق) - المفروض يقارب الصفر</h4>
  <p>
    صافي الرصيد المعلّق حالياً:
    <b class="<?= abs($suspense_summary['net_suspense_balance']) > 0 ? 'text-danger' : 'text-success' ?>">
      <?= number_format($suspense_summary['net_suspense_balance'] ?? 0, 2) ?>
    </b>
    — عدد الحركات بدون سبب موثّق:
    <b class="<?= ($suspense_summary['undocumented_count'] ?? 0) > 0 ? 'text-danger' : '' ?>">
      <?= $suspense_summary['undocumented_count'] ?? 0 ?>
    </b>
  </p>

  <?php if (isset($_GET['resolved'])): ?>
    <div class="alert alert-success">✅ تم ربط الحركة بحسابها الصحيح، وتصحّح قيد اليومية تلقائياً.</div>
  <?php elseif (isset($_GET['resolve_error'])): ?>
    <div class="alert alert-danger">⚠️ ما قدرنا نصحّح الحركة: <?= htmlspecialchars($_GET['resolve_error']) ?></div>
  <?php endif; ?>

  <p class="text-muted">
    لكل حركة معلّقة، اختار الحساب الحقيقي (البنك أو الكاش) اللي تحرّك منه/إليه المبلغ فعلياً
    واضغط "ربط" — القيد المحاسبي بينتقل تلقائياً من Suspense لهذا الحساب.
  </p>

  <table class="table">
    <thead>
      <tr><th>التاريخ</th><th>من أي جدول</th><th>الجهة</th><th>المبلغ</th><th>السبب الموثّق</th><th>تصحيح الحساب</th></tr>
    </thead>
    <tbody>
      <?php foreach ($suspense_lines as $sl): ?>
      <tr class="<?= $sl['reason'] ? '' : 'table-warning' ?>">
        <td><?= $sl['entry_date'] ?></td>
        <td><?= htmlspecialchars($sl['source_table']) ?></td>
        <td><?= htmlspecialchars($sl['description']) ?></td>
        <td><?= number_format($sl['amount'], 2) ?></td>
        <td>
          <?= $sl['reason']
              ? htmlspecialchars($sl['reason'])
              : '<span class="text-danger">بدون سبب - راجعها</span>' ?>
        </td>
        <td>
          <?php if (in_array($sl['source_table'], SUSPENSE_SOURCE_TABLES, true)): ?>
          <form method="post" class="d-flex gap-1" style="min-width:220px">
            <input type="hidden" name="source_table" value="<?= htmlspecialchars($sl['source_table']) ?>">
            <input type="hidden" name="source_id"    value="<?= (int)$sl['source_id'] ?>">
            <select name="new_paid_from_bank" class="form-control form-control-sm" required>
              <option value="">-- اختر الحساب --</option>
              <option value="cash">صندوق الكاش</option>
              <option value="jordan_business">البنك الأردني - مؤسسة</option>
              <option value="jordan_personal">البنك الأردني - شخصي</option>
              <option value="etihad">بنك الاتحاد</option>
              <option value="islami">البنك الإسلامي</option>
              <option value="dinarak">محفظة دينارك</option>
              <option value="investment">البنك الاستثماري</option>
            </select>
            <button type="submit" name="resolve_suspense" value="1"
                    class="btn btn-sm btn-primary"
                    onclick="return confirm('هل أنت متأكد من ربط هذه الحركة بالحساب المختار؟');">ربط</button>
          </form>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$suspense_lines): ?>
        <tr><td colspan="6">✅ ما في حركات معلّقة</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <hr>

  <!-- =================== تسوية بنكية حقيقية =================== -->
  <h4>🏦 تسوية بنكية حقيقية (مقابل كشف حساب البنك الرسمي)</h4>
  <p>
    الصق سطور كشف الحساب من إكسل/PDF بالصيغة:
    <code>التاريخ,المبلغ,البيان,رقم مرجعي</code> - سطر لكل حركة.
    المبلغ موجب لو إيداع وسالب لو سحب.
  </p>

  <form method="post" class="mb-3">
    <div class="form-row">
      <div>
        <label>البنك</label>
        <select name="stmt_bank">
          <option value="jordan_personal">البنك الأردني - شخصي</option>
          <option value="jordan_business">البنك الأردني - مؤسسة</option>
          <option value="etihad">بنك الاتحاد</option>
          <option value="islami">البنك الإسلامي</option>
          <option value="dinarak">محفظة دينارك</option>
          <option value="investment">البنك الاستثماري</option>
        </select>
      </div>
    </div>
    <textarea name="stmt_lines" class="form-control" rows="5"
              placeholder="2026-07-01,150.00,دفعة زبون,REF123&#10;2026-07-02,-80.00,مصروف كهرباء,REF124"
              required></textarea>
    <button class="btn mt-2" type="submit" name="import_statement" value="1">استيراد الكشف</button>
  </form>

  <form method="post" class="mb-3">
    <button class="btn btn-outline-secondary" type="submit" name="auto_match" value="1">
      🔄 حاول المطابقة التلقائية (نفس البنك + نفس المبلغ + فرق تاريخ ≤ 3 أيام)
    </button>
  </form>

  <h5>ملخص كل دفعة استيراد</h5>
  <table class="table">
    <thead>
      <tr>
        <th>البنك</th><th>الفترة</th><th>صافي حركة الكشف</th>
        <th>المطابق</th><th>غير المطابق</th><th>عدد السطور غير المطابقة</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($stmt_totals as $t): ?>
      <tr>
        <td><?= htmlspecialchars($t['bank_name']) ?></td>
        <td><?= $t['period_from'] ?> → <?= $t['period_to'] ?></td>
        <td><?= number_format($t['statement_net_movement'], 2) ?></td>
        <td><?= number_format($t['matched_amount'], 2) ?></td>
        <td class="<?= $t['unmatched_amount'] != 0 ? 'text-danger' : '' ?>">
          <?= number_format($t['unmatched_amount'], 2) ?>
        </td>
        <td><?= $t['unmatched_lines'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$stmt_totals): ?>
        <tr><td colspan="6">ما في كشوفات مستوردة لسا</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <h5>سطور لسا بدون مطابقة (راجعها - يمكن حركة نسيتها بالنظام)</h5>
  <table class="table">
    <thead>
      <tr><th>البنك</th><th>التاريخ</th><th>المبلغ</th><th>البيان</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($stmt_unmatched as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['bank_name']) ?></td>
        <td><?= $u['stmt_date'] ?></td>
        <td><?= number_format($u['amount'], 2) ?></td>
        <td><?= htmlspecialchars($u['description']) ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="ignore_line" value="<?= $u['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary">تجاهل</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$stmt_unmatched): ?>
        <tr><td colspan="5">✅ كل شي مطابق</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php endif; ?>

  <hr>

  <!-- =================== إقفال الفترات =================== -->
  <h4>🔒 إقفال الفترات المحاسبية</h4>
  <form method="post" class="d-flex gap-2 mb-3">
    <input type="month" name="lock_period" class="form-control" style="max-width:200px" required>
    <button class="btn btn-danger">إقفال هذا الشهر</button>
  </form>
  <table class="table">
    <thead>
      <tr><th>الفترة</th><th>تاريخ الإقفال</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($locks as $l): ?>
      <tr>
        <td><?= $l['period'] ?></td>
        <td><?= $l['locked_at'] ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="unlock_period" value="<?= $l['period'] ?>">
            <button class="btn btn-sm btn-outline-secondary">فتح</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>