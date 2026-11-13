<?php
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'صندوق الكاش (عجز / زيادة)';
$user = current_user();
$db = get_db();

// =====================================================================
// POST: معالجة النماذج
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'edit') {
        update_cash_count(
            (int)$_POST['tx_id'], $_POST['count_date'] ?: date('Y-m-d'),
            (float)$_POST['expected_amount'], (float)$_POST['actual_amount'], trim($_POST['notes'])
        );
    } elseif ($action === 'delete') {
        delete_cash_count((int)$_POST['tx_id']);
    } elseif ($action === 'set_opening_balance') {
        $key = ($_POST['opening_key'] ?? 'cash') === 'cash_diff' ? 'cash_diff' : 'cash';
        set_opening_balance(
            $key, (float)$_POST['opening_amount'],
            $_POST['opening_date'] ?: date('Y-m-d'), trim($_POST['opening_notes'] ?? ''), $user['id']
        );
    } else {
        add_cash_count(
            $_POST['count_date'] ?: date('Y-m-d'), (float)$_POST['expected_amount'],
            (float)$_POST['actual_amount'], trim($_POST['notes']), $user['id']
        );
    }
    header('Location: ' . APP_BASE_URL . '/pages/cash_box.php');
    exit;
}

// =====================================================================
// GET: جلب البيانات
// =====================================================================
require_once __DIR__ . '/../includes/pagination.php';
$per_page = 100;
$page = get_page_number();
$total_tx = paginate_count($db, 'SELECT COUNT(*) FROM cash_box');
$total_pages = max(1, (int)ceil($total_tx / $per_page));
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare('SELECT * FROM cash_box ORDER BY count_date DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
$stmt->execute();
$rows = $stmt->fetchAll();

$total        = get_cash_box_total();
$cash_opening = get_opening_balance('cash');
$diff_opening = get_opening_balance('cash_diff');
$real_cash    = get_real_cash_balance();

// =====================================================================
// HTML
// =====================================================================
require __DIR__ . '/../includes/header.php';
?>

<div class="cards">
    <div class="card <?= $total >= 0 ? 'positive' : 'negative' ?>">
        <div class="label">💵 إجمالي العجز/الزيادة التراكمي</div>
        <div class="value"><?= number_format($total, 2) ?></div>
    </div>
    <div class="card positive">
        <div class="label">🏦 الرصيد الفعلي الحالي بالكاش (شامل الرصيد الافتتاحي)</div>
        <div class="value"><?= number_format($real_cash, 2) ?></div>
    </div>
</div>

<div class="panel">
    <h2>🏁 الرصيد الافتتاحي لصندوق الكاش</h2>
    <p style="color:#64748b; font-size:13px; margin-top:-8px;">
        قديش كان معك كاش بالصندوق قبل ما تبلش تسجّل بالنظام. بينضاف تلقائياً على "الرصيد الفعلي الحالي" فوق.
        <?php if ($cash_opening): ?>
            الرصيد الافتتاحي الحالي: <strong><?= number_format($cash_opening['amount'], 2) ?></strong> بتاريخ <?= $cash_opening['balance_date'] ?>
            <?php if (!empty($cash_opening['notes'])): ?> - <?= htmlspecialchars($cash_opening['notes']) ?><?php endif; ?>
        <?php else: ?>
            ما في رصيد افتتاحي مسجّل بعد (يعتبر صفر).
        <?php endif; ?>
    </p>
    <form method="post">
        <input type="hidden" name="action" value="set_opening_balance">
        <input type="hidden" name="opening_key" value="cash">
        <div class="form-row">
            <div>
                <label>مبلغ الرصيد الافتتاحي</label>
                <input type="number" step="0.01" name="opening_amount" value="<?= $cash_opening ? $cash_opening['amount'] : '' ?>" required>
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="opening_date" value="<?= $cash_opening ? $cash_opening['balance_date'] : date('Y-m-d') ?>">
            </div>
            <div style="flex:2">
                <label>ملاحظات</label>
                <input type="text" name="opening_notes" value="<?= $cash_opening ? htmlspecialchars($cash_opening['notes'] ?? '') : '' ?>">
            </div>
            <div style="align-self:end">
                <button class="btn" type="submit"><?= $cash_opening ? '✏️ تعديل الرصيد الافتتاحي' : '➕ تسجيل رصيد افتتاحي' ?></button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h2>⚖️ الرصيد الافتتاحي لإجمالي العجز/الزيادة</h2>
    <p style="color:#64748b; font-size:13px; margin-top:-8px;">
        لو كان معك عجز أو زيادة متراكم من قبل ما تبلش تسجّل بالنظام، سجّله هون. بينضاف على "إجمالي العجز/الزيادة التراكمي" فوق (رقم سالب = عجز، موجب = زيادة).
        <?php if ($diff_opening): ?>
            الرصيد الافتتاحي الحالي: <strong><?= number_format($diff_opening['amount'], 2) ?></strong> بتاريخ <?= $diff_opening['balance_date'] ?>
            <?php if (!empty($diff_opening['notes'])): ?> - <?= htmlspecialchars($diff_opening['notes']) ?><?php endif; ?>
        <?php else: ?>
            ما في رصيد افتتاحي مسجّل بعد (يعتبر صفر).
        <?php endif; ?>
    </p>
    <form method="post">
        <input type="hidden" name="action" value="set_opening_balance">
        <input type="hidden" name="opening_key" value="cash_diff">
        <div class="form-row">
            <div>
                <label>مبلغ العجز/الزيادة الافتتاحي (سالب = عجز)</label>
                <input type="number" step="0.01" name="opening_amount" value="<?= $diff_opening ? $diff_opening['amount'] : '' ?>" required>
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="opening_date" value="<?= $diff_opening ? $diff_opening['balance_date'] : date('Y-m-d') ?>">
            </div>
            <div style="flex:2">
                <label>ملاحظات</label>
                <input type="text" name="opening_notes" value="<?= $diff_opening ? htmlspecialchars($diff_opening['notes'] ?? '') : '' ?>">
            </div>
            <div style="align-self:end">
                <button class="btn" type="submit"><?= $diff_opening ? '✏️ تعديل الرصيد الافتتاحي' : '➕ تسجيل رصيد افتتاحي' ?></button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h2>➕ تسجيل عد كاش يومي</h2>
    <p style="color:#64748b; font-size:13px; margin-top:-8px;">المفروض = المبلغ المتوقع بالدرج حسب المبيعات. الفعلي = المبلغ الحقيقي بعد العد. الفرق بيتحسب تلقائياً (موجب = زيادة، سالب = عجز).</p>
    <form method="post">
        <div class="form-row">
            <div>
                <label>التاريخ</label>
                <input type="date" name="count_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>المبلغ المفروض</label>
                <input type="number" step="0.01" name="expected_amount" required>
            </div>
            <div>
                <label>المبلغ الفعلي (بعد العد)</label>
                <input type="number" step="0.01" name="actual_amount" required>
            </div>
            <div style="flex:2">
                <label>ملاحظات</label>
                <input type="text" name="notes">
            </div>
            <div style="align-self:end">
                <button class="btn" type="submit">حفظ</button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h2>📋 سجل العد اليومي</h2>
    <table>
        <tr><th>التاريخ</th><th>المفروض</th><th>الفعلي</th><th>الحالة</th><th>الفرق</th><th>ملاحظات</th><th>إجراءات</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= $r['count_date'] ?></td>
            <td><?= number_format($r['expected_amount'], 2) ?></td>
            <td><?= number_format($r['actual_amount'], 2) ?></td>
            <td><?= $r['difference'] >= 0 ? '<span class="badge badge-green">زيادة</span>' : '<span class="badge badge-red">عجز</span>' ?></td>
            <td><?= number_format(abs($r['difference']), 2) ?></td>
            <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $r['id'], "count_date" => $r['count_date'], "expected_amount" => $r['expected_amount'],
                    "actual_amount" => $r['actual_amount'], "notes" => $r['notes'],
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاد السجل؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tx_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php render_pagination($page, $total_pages); ?>
</div>

<!-- Modal: تعديل عد الكاش -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل عد الكاش</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>التاريخ</label>
                    <input type="date" name="count_date" id="edit_count_date">
                </div>
                <div>
                    <label>المبلغ المفروض</label>
                    <input type="number" step="0.01" name="expected_amount" id="edit_expected_amount" required>
                </div>
                <div>
                    <label>المبلغ الفعلي</label>
                    <input type="number" step="0.01" name="actual_amount" id="edit_actual_amount" required>
                </div>
            </div>
            <div class="form-row">
                <div style="flex:3">
                    <label>ملاحظات</label>
                    <input type="text" name="notes" id="edit_notes">
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn" type="submit">حفظ التعديل</button>
                <button class="btn btn-cancel" type="button" onclick="closeModal('editModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditTx(data) {
    document.getElementById('edit_tx_id').value             = data.id;
    document.getElementById('edit_count_date').value        = data.count_date;
    document.getElementById('edit_expected_amount').value   = data.expected_amount;
    document.getElementById('edit_actual_amount').value     = data.actual_amount;
    document.getElementById('edit_notes').value             = data.notes || '';
    openModal('editModal');
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
