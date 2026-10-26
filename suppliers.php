<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'ذمم الموردين';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        update_supplier_transaction(
            (int)$_POST['tx_id'], trim($_POST['name']), $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'),
            $_POST['paid_from_bank'] ?: null, trim($_POST['no_bank_reason'] ?? '') ?: null
        );
    } elseif ($action === 'delete') {
        delete_supplier_transaction((int)$_POST['tx_id']);
    } elseif ($action === 'update_phone') {
        update_supplier_phone((int)$_POST['entity_id'], trim($_POST['phone']));
    } elseif ($action === 'edit_profile') {
        $result = update_supplier_profile((int)$_POST['supplier_id'], trim($_POST['profile_name']), trim($_POST['profile_phone'] ?? ''));
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } elseif ($action === 'delete_supplier') {
        require_owner(); // حذف مورد كامل عملية حساسة - المالك بس
        $result = delete_supplier((int)$_POST['supplier_id']);
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } else {
        $result = add_supplier_transaction(
            trim($_POST['name']), $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'), $user['id'],
            trim($_POST['phone'] ?? '') ?: null,
            $_POST['paid_from_bank'] ?: null,
            trim($_POST['no_bank_reason'] ?? '') ?: null
        );
        if (!$result['success']) {
            header('Location: suppliers.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: suppliers.php');
    exit;
}

require_once __DIR__ . '/includes/pagination.php';
$suppliers = $db->query('SELECT * FROM suppliers ORDER BY balance DESC')->fetchAll();
$per_page = 100;
$page = get_page_number();
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $total_tx = paginate_count($db, 'SELECT COUNT(*) FROM supplier_transactions st JOIN suppliers s ON s.id = st.supplier_id WHERE s.name LIKE ?', ['%' . $search . '%']);
    $total_pages = max(1, (int)ceil($total_tx / $per_page));
    $offset = ($page - 1) * $per_page;
    $stmt = $db->prepare('SELECT st.*, s.name AS supplier_name FROM supplier_transactions st JOIN suppliers s ON s.id = st.supplier_id WHERE s.name LIKE ? ORDER BY st.tx_date DESC, st.id DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
    $stmt->execute(['%' . $search . '%']);
    $transactions = $stmt->fetchAll();
} else {
    $total_tx = paginate_count($db, 'SELECT COUNT(*) FROM supplier_transactions');
    $total_pages = max(1, (int)ceil($total_tx / $per_page));
    $offset = ($page - 1) * $per_page;
    $stmt = $db->prepare('SELECT st.*, s.name AS supplier_name FROM supplier_transactions st JOIN suppliers s ON s.id = st.supplier_id ORDER BY st.tx_date DESC, st.id DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
    $stmt->execute();
    $transactions = $stmt->fetchAll();
}
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
require __DIR__ . '/includes/header.php';
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
    <h2>➕ تسجيل حركة جديدة لمورد</h2>
    <form method="post" id="txForm">
        <div class="form-row">
            <div>
                <label>اسم المورد</label>
                <input type="text" name="name" list="suppliersList" required>
                <datalist id="suppliersList">
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= htmlspecialchars($s['name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label>نوع الحركة</label>
                <select name="type">
                    <option value="debit">فاتورة ذمم</option>
                    <option value="credit">دفعة من الحساب</option>
                </select>
            </div>
            <div>
                <label>المبلغ</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div>
                <label>رقم الهاتف (اختياري)</label>
                <input type="text" name="phone" placeholder="07xxxxxxxx">
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>من/إلى أي حساب؟ (إجباري إلا لو كتبت سبب)</label>
                <select name="paid_from_bank" id="paid_from_bank_select">
                    <option value="">-- بدون تحديد (لازم تكتب سبب) --</option>
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
        </div>
        <div class="form-row">
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
    <h2>📋 أرصدة الموردين الحالية</h2>
    <table>
        <tr><th>المورد</th><th>الهاتف</th><th>الرصيد الحالي</th><th>الحالة</th><th>إجراءات</th></tr>
        <?php foreach ($suppliers as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td>
                <form method="post" style="display:flex; gap:5px; align-items:center;">
                    <input type="hidden" name="action" value="update_phone">
                    <input type="hidden" name="entity_id" value="<?= $s['id'] ?>">
                    <input type="text" name="phone" value="<?= htmlspecialchars($s['phone'] ?? '') ?>" placeholder="أضف رقم هاتف" style="width:130px; padding:5px 8px; font-size:13px; border:1px solid #ddd; border-radius:6px;">
                    <button type="submit" class="btn btn-sm">حفظ</button>
                </form>
            </td>
            <td><?= number_format($s['balance'], 2) ?></td>
            <td>
                <?php if ($s['balance'] > 0): ?>
                    <span class="badge badge-red" title="إحنا مدينين للمورد بهالمبلغ">رصيد مدين</span>
                <?php elseif ($s['balance'] < 0): ?>
                    <span class="badge badge-green" title="دفعنا للمورد أكتر من المستحق">رصيد دائن</span>
                <?php else: ?>
                    <span class="badge badge-yellow" title="لا يوجد رصيد مستحق بأي اتجاه">مسدّد</span>
                <?php endif; ?>
            </td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditProfile(<?= htmlspecialchars(json_encode([
                    "id" => $s['id'], "name" => $s['name'], "phone" => $s['phone'],
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <?php if (is_owner()): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف المورد \'<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>\' نهائياً؟ (رح يترفض تلقائياً لو عنده حركات مسجّلة).');">
                    <input type="hidden" name="action" value="delete_supplier">
                    <input type="hidden" name="supplier_id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p style="font-size:12px;color:#94a3b8;margin-top:8px;">حذف مورد بالكامل متاح للمالك فقط، ومرفوض تلقائياً لو عنده أي حركة مسجّلة (حماية للتاريخ المالي).</p>
</div>

<div class="panel">
    <h2>🧾 سجل حركات الموردين</h2>
    <form method="get" class="form-row" style="margin-bottom:12px; align-items:end;">
        <div style="flex:3">
            <label>🔍 بحث باسم المورد</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="اكتب اسم المورد لعرض حركاته فقط" list="suppliersList">
        </div>
        <div>
            <button class="btn" type="submit">بحث</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-sm btn-cancel" href="suppliers.php">إلغاء البحث</a>
            <?php endif; ?>
        </div>
    </form>
    <table>
        <tr><th>المورد</th><th>نوع الحركة</th><th>المبلغ</th><th>ملاحظات</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php if (empty($transactions)): ?>
        <tr><td colspan="6" style="text-align:center;color:#94a3b8;">لا توجد حركات<?= $search !== '' ? ' لمورد باسم "' . htmlspecialchars($search) . '"' : '' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($transactions as $t): ?>
        <tr>
            <td><?= htmlspecialchars($t['supplier_name']) ?></td>
            <td><?= $t['type'] === 'debit' ? 'فاتورة ذمم' : 'دفعة من الحساب' ?></td>
            <td><?= number_format($t['amount'], 2) ?></td>
            <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
            <td><?= $t['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $t['id'], "name" => $t['supplier_name'], "type" => $t['type'],
                    "amount" => $t['amount'], "description" => $t['description'], "tx_date" => $t['tx_date'],
                    "paid_from_bank" => $t['paid_from_bank'], "no_bank_reason" => $t['no_bank_reason'],
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاي الحركة؟ الرصيد رح ينعدّل تلقائياً.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tx_id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php render_pagination($page, $total_pages, $search !== '' ? ['q' => $search] : []); ?>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل حركة المورد</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>اسم المورد</label>
                    <input type="text" name="name" id="edit_name" list="suppliersList" required>
                </div>
                <div>
                    <label>نوع الحركة</label>
                    <select name="type" id="edit_type">
                        <option value="debit">فاتورة ذمم</option>
                        <option value="credit">دفعة من الحساب</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
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
                    <label>من/إلى أي حساب؟ (إجباري إلا لو كتبت سبب بالأسفل)</label>
                    <select name="paid_from_bank" id="edit_paid_from_bank_select">
                        <option value="">-- بدون تحديد (لازم تكتب سبب) --</option>
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
                    <input type="text" name="no_bank_reason" id="edit_no_bank_reason_input" placeholder="مثال: سلفة مفتوحة لسا ما تحدد مصدرها">
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn" type="submit">حفظ التعديل</button>
                <button class="btn btn-cancel" type="button" onclick="closeModal('editModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="editProfileModal">
    <div class="modal-box">
        <h3>✏️ تعديل بيانات المورد</h3>
        <form method="post" id="editProfileForm">
            <input type="hidden" name="action" value="edit_profile">
            <input type="hidden" name="supplier_id" id="profile_supplier_id">
            <div class="form-row">
                <div>
                    <label>الاسم</label>
                    <input type="text" name="profile_name" id="profile_name" required>
                </div>
                <div>
                    <label>رقم الهاتف</label>
                    <input type="text" name="profile_phone" id="profile_phone" placeholder="07xxxxxxxx">
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn" type="submit">حفظ التعديل</button>
                <button class="btn btn-cancel" type="button" onclick="closeModal('editProfileModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditTx(data) {
    document.getElementById('edit_tx_id').value = data.id;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_type').value = data.type;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_description').value = data.description || '';
    document.getElementById('edit_tx_date').value = data.tx_date;
    document.getElementById('edit_paid_from_bank_select').value = data.paid_from_bank || '';
    document.getElementById('edit_no_bank_reason_input').value = data.no_bank_reason || '';
    document.getElementById('edit_paid_from_bank_select').dispatchEvent(new Event('change'));
    openModal('editModal');
}

function openEditProfile(data) {
    document.getElementById('profile_supplier_id').value = data.id;
    document.getElementById('profile_name').value = data.name;
    document.getElementById('profile_phone').value = data.phone || '';
    openModal('editProfileModal');
}

// إجبار اختيار بنك/كاش أو كتابة سبب صريح قبل الحفظ - يمنع تراكم حساب suspense صامتاً
function setupBankReasonToggle(selectId, wrapId, reasonInputId) {
    var sel = document.getElementById(selectId);
    var wrap = document.getElementById(wrapId);
    var reasonInput = document.getElementById(reasonInputId);
    if (!sel || !wrap || !reasonInput) return;
    function toggle() {
        if (sel.value === '') { wrap.style.display = ''; reasonInput.required = true; }
        else { wrap.style.display = 'none'; reasonInput.required = false; }
    }
    sel.addEventListener('change', toggle);
    toggle();
}
setupBankReasonToggle('paid_from_bank_select', 'no_bank_reason_wrap', 'no_bank_reason_input');
setupBankReasonToggle('edit_paid_from_bank_select', 'edit_no_bank_reason_wrap', 'edit_no_bank_reason_input');
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
