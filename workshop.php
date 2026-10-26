<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'مشغل - إيراد ومصاريف المشغل';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        $result = update_workshop_transaction(
            (int)$_POST['tx_id'], $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description'] ?? ''), $_POST['tx_date'] ?: date('Y-m-d'),
            $_POST['paid_from_bank'] ?: null, trim($_POST['no_bank_reason'] ?? '') ?: null
        );
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } elseif ($action === 'delete') {
        delete_workshop_transaction((int)$_POST['tx_id']);
    } else {
        $result = add_workshop_transaction(
            $_POST['type'], (float)$_POST['amount'], trim($_POST['description'] ?? ''),
            $_POST['tx_date'] ?: date('Y-m-d'), $user['id'],
            $_POST['paid_from_bank'] ?: null, trim($_POST['no_bank_reason'] ?? '') ?: null
        );
        if (!$result['success']) {
            header('Location: workshop.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: workshop.php');
    exit;
}

$transactions = get_workshop_transactions();
$totals = get_workshop_totals();
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
require __DIR__ . '/includes/header.php';

$type_labels = [
    'revenue' => 'إيراد المشغل',
    'expense' => 'مصاريف المشغل',
];
?>

<?php if ($flash_error): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    ⚠️ <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    <strong>⚠️ ما انحفظت الحركة:</strong> <?= htmlspecialchars($_GET['error']) ?>
</div>
<?php endif; ?>

<div class="panel">
    <h2>➕ تسجيل حركة جديدة للمشغل</h2>
    <form method="post" id="txForm">
        <div class="form-row">
            <div>
                <label>نوع الحركة</label>
                <select name="type" id="typeSelect">
                    <option value="revenue">إيراد المشغل (يزيد الصافي)</option>
                    <option value="expense">مصاريف المشغل (تنقص الصافي)</option>
                </select>
            </div>
            <div>
                <label>المبلغ</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="form-row">
            <div>
                <label>من/إلى أي حساب (إجباري لمصاريف المشغل، أو اكتب سبب - الإيراد بدون تحديد بيحسب كاش تلقائياً)</label>
                <select name="paid_from_bank" id="paid_from_bank_select">
                    <option value="">-- بدون تحديد --</option>
                    <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                    <option value="etihad">بنك الاتحاد</option>
                    <option value="islami">البنك الإسلامي</option>
                    <option value="dinarak">محفظة دينارك</option>
                    <option value="investment">البنك الاستثماري</option>
                    <option value="cash">صندوق الكاش</option>
                </select>
            </div>
            <div id="no_bank_reason_wrap" style="display:none">
                <label>سبب عدم تحديد البنك (إجباري)</label>
                <input type="text" name="no_bank_reason" id="no_bank_reason_input" placeholder="اكتب السبب">
            </div>
            <div style="flex:3">
                <label>ملاحظات</label>
                <input type="text" name="description">
            </div>
            <div style="align-self:end">
                <button class="btn" type="submit">حفظ</button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h2>📊 صافي ربح/خسارة المشغل</h2>
    <div class="form-row">
        <div><strong>إجمالي إيراد المشغل:</strong> <?= number_format($totals['revenue'], 2) ?></div>
        <div><strong>إجمالي مصاريف المشغل:</strong> <?= number_format($totals['expense'], 2) ?></div>
        <div style="font-weight:bold; color:<?= $totals['net'] >= 0 ? '#16a34a' : '#dc2626' ?>">
            صافي ربح/خسارة المشغل: <?= number_format($totals['net'], 2) ?>
        </div>
    </div>
    <p style="color:#64748b; font-size:13px;">صافي ربح/خسارة المشغل = إجمالي إيراد المشغل − إجمالي مصاريف المشغل. أي "إيراد" بينعكس فوراً لحظة تسجيله - على صندوق الكاش الفعلي لو ما حددت حساب، أو على البنك المحدد لو اخترت "من/إلى أي حساب" ببنك معيّن (نفس منطق صيانة خارجية بالضبط).</p>
</div>

<div class="panel">
    <h2>🧾 سجل حركات المشغل</h2>
    <table>
        <tr><th>نوع الحركة</th><th>المبلغ</th><th>ملاحظات</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php foreach ($transactions as $t): ?>
        <tr>
            <td><?= htmlspecialchars($type_labels[$t['type']] ?? $t['type']) ?></td>
            <td style="font-weight:bold; color:<?= $t['type'] === 'revenue' ? '#16a34a' : '#dc2626' ?>"><?= number_format($t['amount'], 2) ?></td>
            <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
            <td><?= $t['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $t['id'], "type" => $t['type'], "amount" => $t['amount'],
                    "description" => $t['description'], "tx_date" => $t['tx_date'],
                    "paid_from_bank" => $t['paid_from_bank'], "no_bank_reason" => $t['no_bank_reason'] ?? '',
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاي الحركة؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tx_id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل حركة مشغل</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>نوع الحركة</label>
                    <select name="type" id="edit_type">
                        <option value="revenue">إيراد المشغل</option>
                        <option value="expense">مصاريف المشغل</option>
                    </select>
                </div>
                <div>
                    <label>المبلغ</label>
                    <input type="number" step="0.01" name="amount" id="edit_amount" required>
                </div>
                <div>
                    <label>التاريخ</label>
                    <input type="date" name="tx_date" id="edit_tx_date">
                </div>
            </div>
            <div class="form-row">
                <div style="flex:3">
                    <label>ملاحظات</label>
                    <input type="text" name="description" id="edit_description">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>من/إلى أي حساب (إجباري لمصاريف المشغل، أو اكتب سبب)</label>
                    <select name="paid_from_bank" id="edit_paid_from_bank_select">
                        <option value="">-- بدون تحديد --</option>
                        <option value="jordan_personal">البنك الأردني - شخصي</option>
                        <option value="jordan_business">البنك الأردني - مؤسسة</option>
                        <option value="etihad">بنك الاتحاد</option>
                        <option value="islami">البنك الإسلامي</option>
                        <option value="dinarak">محفظة دينارك</option>
                        <option value="investment">البنك الاستثماري</option>
                        <option value="cash">صندوق الكاش</option>
                    </select>
                </div>
                <div id="edit_no_bank_reason_wrap" style="display:none">
                    <label>سبب عدم تحديد البنك (إجباري)</label>
                    <input type="text" name="no_bank_reason" id="edit_no_bank_reason_input" placeholder="اكتب السبب">
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
    document.getElementById('edit_tx_id').value = data.id;
    document.getElementById('edit_type').value = data.type;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_description').value = data.description || '';
    document.getElementById('edit_tx_date').value = data.tx_date;
    document.getElementById('edit_paid_from_bank_select').value = data.paid_from_bank || '';
    document.getElementById('edit_no_bank_reason_input').value = data.no_bank_reason || '';
    document.getElementById('edit_type').dispatchEvent(new Event('change'));
    openModal('editModal');
}

// مصاريف المشغل فقط لازم إلها بنك/كاش أو سبب واضح (الإيراد بدون تحديد بيحسب كاش تلقائياً)
var WORKSHOP_BANK_REQUIRED_TYPES = ['expense'];
function setupWorkshopBankReasonToggle(typeId, selectId, wrapId, reasonInputId) {
    var typeSel = document.getElementById(typeId);
    var sel = document.getElementById(selectId);
    var wrap = document.getElementById(wrapId);
    var reasonInput = document.getElementById(reasonInputId);
    if (!typeSel || !sel || !wrap || !reasonInput) return;
    function toggle() {
        var needsBank = WORKSHOP_BANK_REQUIRED_TYPES.indexOf(typeSel.value) !== -1;
        if (needsBank && sel.value === '') { wrap.style.display = ''; reasonInput.required = true; }
        else { wrap.style.display = 'none'; reasonInput.required = false; }
    }
    sel.addEventListener('change', toggle);
    typeSel.addEventListener('change', toggle);
    toggle();
}
setupWorkshopBankReasonToggle('typeSelect', 'paid_from_bank_select', 'no_bank_reason_wrap', 'no_bank_reason_input');
setupWorkshopBankReasonToggle('edit_type', 'edit_paid_from_bank_select', 'edit_no_bank_reason_wrap', 'edit_no_bank_reason_input');
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
