<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'الموظفين - رواتب وسلف وخصومات';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        $result = update_employee_transaction(
            (int)$_POST['tx_id'], trim($_POST['name']), $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'),
            $_POST['paid_from_bank'] ?: null, trim($_POST['no_bank_reason'] ?? '') ?: null
        );
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } elseif ($action === 'delete') {
        delete_employee_transaction((int)$_POST['tx_id']);
    } elseif ($action === 'update_phone') {
        update_employee_phone((int)$_POST['entity_id'], trim($_POST['phone']));
    } elseif ($action === 'edit_profile') {
        $result = update_employee_profile(
            (int)$_POST['employee_id'], trim($_POST['profile_name']),
            $_POST['base_salary'], trim($_POST['profile_phone'] ?? ''),
            $_POST['profile_employee_type'] ?? 'regular'
        );
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } elseif ($action === 'delete_employee') {
        require_owner(); // حذف موظف كامل عملية حساسة - المالك بس
        $result = delete_employee((int)$_POST['employee_id']);
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } else {
        $result = add_employee_transaction(
            trim($_POST['name']), $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'), $user['id'],
            trim($_POST['phone'] ?? '') ?: null,
            $_POST['paid_from_bank'] ?: null
        );
        if (!$result['success']) {
            header('Location: employees.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: employees.php');
    exit;
}

$employees = $db->query('SELECT * FROM employees ORDER BY name ASC')->fetchAll();
require_once __DIR__ . '/includes/pagination.php';
$per_page = 100;
$page = get_page_number();
$total_tx = paginate_count($db, 'SELECT COUNT(*) FROM employee_transactions');
$total_pages = max(1, (int)ceil($total_tx / $per_page));
$offset = ($page - 1) * $per_page;
$stmt = $db->prepare('SELECT et.*, e.name AS employee_name FROM employee_transactions et JOIN employees e ON e.id = et.employee_id ORDER BY et.tx_date DESC, et.id DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
$stmt->execute();
$transactions = $stmt->fetchAll();
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
require __DIR__ . '/includes/header.php';

$type_labels = [
    'salary' => 'راتب',
    'advance' => 'سلفة',
    'payment' => 'دفع راتب مستحق',
    'deduction' => 'خصم',
    'bonus' => 'مكافأة',
];
?>

<?php if ($flash_error): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    ⚠️ <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>

<div class="panel">
    <h2>➕ تسجيل حركة جديدة لموظف</h2>
    <form method="post" id="txForm">
        <div class="form-row">
            <div>
                <label>اسم الموظف</label>
                <input type="text" name="name" list="employeesList" required>
                <datalist id="employeesList">
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= htmlspecialchars($e['name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label>نوع الحركة</label>
                <select name="type" id="add_type">
                    <option value="salary">راتب (يزيد المستحق للموظف)</option>
                    <option value="bonus">مكافأة (تزيد المستحق للموظف)</option>
                    <option value="payment">دفع راتب مستحق (فلوس فعلية طلعت - ينقص المستحق)</option>
                    <option value="advance">سلفة (تنقص المستحق)</option>
                    <option value="deduction">خصم (ينقص المستحق)</option>
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
                <label>من أي حساب اتصرفت الفلوس؟ (إجباري للسلفة/دفع الراتب المستحق، أو اكتب سبب)</label>
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
                <label>سبب عدم تحديد البنك (إجباري للسلفة/دفع الراتب)</label>
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
    <h2>📋 أرصدة الموظفين الحالية</h2>
    <table>
        <tr><th>الموظف</th><th>النوع</th><th>الراتب الأساسي</th><th>الهاتف</th><th>الرصيد المستحق</th><th>الحالة</th><th>إجراءات</th></tr>
        <?php foreach ($employees as $e): ?>
        <tr>
            <td><?= htmlspecialchars($e['name']) ?></td>
            <td>
                <?php if (($e['employee_type'] ?? 'regular') === 'driver'): ?>
                    <span class="badge badge-blue">🚗 موظف صيانة</span>
                    <a href="car_employees.php" style="font-size:12px; display:block; margin-top:3px;">سجله بصيانة خارجية ↗</a>
                <?php else: ?>
                    <span class="badge">موظف عادي</span>
                <?php endif; ?>
            </td>
            <td><?= number_format($e['base_salary'] ?? 0, 2) ?></td>
            <td>
                <form method="post" style="display:flex; gap:5px; align-items:center;">
                    <input type="hidden" name="action" value="update_phone">
                    <input type="hidden" name="entity_id" value="<?= $e['id'] ?>">
                    <input type="text" name="phone" value="<?= htmlspecialchars($e['phone'] ?? '') ?>" placeholder="أضف رقم هاتف" style="width:130px; padding:5px 8px; font-size:13px; border:1px solid #ddd; border-radius:6px;">
                    <button type="submit" class="btn btn-sm">حفظ</button>
                </form>
            </td>
            <td><?= number_format($e['balance'], 2) ?></td>
            <td>
                <?php if ($e['balance'] > 0): ?>
                    <span class="badge badge-red" title="مستحق للموظف - إحنا مدينين له">رصيد دائن (مستحق للموظف)</span>
                <?php elseif ($e['balance'] < 0): ?>
                    <span class="badge badge-green" title="الموظف سحب أكتر من المستحق له">رصيد مدين (سحب زيادة)</span>
                <?php else: ?>
                    <span class="badge badge-yellow" title="لا يوجد رصيد مستحق بأي اتجاه">مسدّد</span>
                <?php endif; ?>
            </td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditProfile(<?= htmlspecialchars(json_encode([
                    "id" => $e['id'], "name" => $e['name'], "base_salary" => $e['base_salary'] ?? 0, "phone" => $e['phone'],
                    "employee_type" => $e['employee_type'] ?? 'regular',
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <?php if (is_owner()): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف الموظف \'<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>\' نهائياً؟ (رح يترفض تلقائياً لو عنده حركات مسجّلة).');">
                    <input type="hidden" name="action" value="delete_employee">
                    <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p style="font-size:12px;color:#94a3b8;margin-top:8px;">حذف موظف بالكامل متاح للمالك فقط، ومرفوض تلقائياً لو عنده أي حركة مسجّلة (حماية للتاريخ المالي).</p>
</div>

<div class="panel">
    <h2>🧾 سجل حركات الموظفين</h2>
    <table>
        <tr><th>الموظف</th><th>نوع الحركة</th><th>المبلغ</th><th>ملاحظات</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php foreach ($transactions as $t): ?>
        <tr>
            <td><?= htmlspecialchars($t['employee_name']) ?></td>
            <td><?= htmlspecialchars($type_labels[$t['type']] ?? $t['type']) ?></td>
            <td><?= number_format($t['amount'], 2) ?></td>
            <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
            <td><?= $t['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $t['id'], "name" => $t['employee_name'], "type" => $t['type'],
                    "amount" => $t['amount'], "description" => $t['description'], "tx_date" => $t['tx_date'],
                    "paid_from_bank" => $t['paid_from_bank'], "no_bank_reason" => $t['no_bank_reason'] ?? '',
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
    <?php render_pagination($page, $total_pages); ?>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل حركة الموظف</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>اسم الموظف</label>
                    <input type="text" name="name" id="edit_name" list="employeesList" required>
                </div>
                <div>
                    <label>نوع الحركة</label>
                    <select name="type" id="edit_type">
                        <option value="salary">راتب</option>
                        <option value="bonus">مكافأة</option>
                        <option value="payment">دفع راتب مستحق</option>
                        <option value="advance">سلفة</option>
                        <option value="deduction">خصم</option>
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
                    <label>من أي حساب اتصرفت الفلوس؟ (إجباري للسلفة/دفع الراتب المستحق، أو اكتب سبب)</label>
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
                    <label>سبب عدم تحديد البنك (إجباري للسلفة/دفع الراتب)</label>
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

<div class="modal-overlay" id="editProfileModal">
    <div class="modal-box">
        <h3>✏️ تعديل بيانات الموظف</h3>
        <form method="post" id="editProfileForm">
            <input type="hidden" name="action" value="edit_profile">
            <input type="hidden" name="employee_id" id="profile_employee_id">
            <div class="form-row">
                <div>
                    <label>الاسم</label>
                    <input type="text" name="profile_name" id="profile_name" required>
                </div>
                <div>
                    <label>الراتب الأساسي الشهري</label>
                    <input type="number" step="0.01" name="base_salary" id="profile_base_salary">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>رقم الهاتف</label>
                    <input type="text" name="profile_phone" id="profile_phone" placeholder="07xxxxxxxx">
                </div>
                <div>
                    <label>نوع الموظف</label>
                    <select name="profile_employee_type" id="profile_employee_type">
                        <option value="regular">موظف عادي</option>
                        <option value="driver">موظف صيانة (سائق - بيبين تلقائياً بصيانة خارجية)</option>
                    </select>
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
    document.getElementById('edit_type').dispatchEvent(new Event('change'));
    openModal('editModal');
}

function openEditProfile(data) {
    document.getElementById('profile_employee_id').value = data.id;
    document.getElementById('profile_name').value = data.name;
    document.getElementById('profile_base_salary').value = data.base_salary;
    document.getElementById('profile_phone').value = data.phone || '';
    document.getElementById('profile_employee_type').value = data.employee_type || 'regular';
    openModal('editProfileModal');
}

// السلفة ودفع الراتب المستحق لازم إلهم بنك/كاش أو سبب واضح (الراتب والخصم والمكافأة مجرد قيود مستحقات، ما فيهم حركة بنك)
function setupEmployeeBankReasonToggle(typeId, selectId, wrapId, reasonInputId) {
    var typeSel = document.getElementById(typeId);
    var sel = document.getElementById(selectId);
    var wrap = document.getElementById(wrapId);
    var reasonInput = document.getElementById(reasonInputId);
    if (!sel || !wrap || !reasonInput) return;
    function toggle() {
        var needsBank = !typeSel || typeSel.value === 'advance' || typeSel.value === 'payment';
        if (needsBank && sel.value === '') { wrap.style.display = ''; reasonInput.required = true; }
        else { wrap.style.display = 'none'; reasonInput.required = false; }
    }
    sel.addEventListener('change', toggle);
    if (typeSel) typeSel.addEventListener('change', toggle);
    toggle();
}
setupEmployeeBankReasonToggle('add_type', 'paid_from_bank_select', 'no_bank_reason_wrap', 'no_bank_reason_input');
setupEmployeeBankReasonToggle('edit_type', 'edit_paid_from_bank_select', 'edit_no_bank_reason_wrap', 'edit_no_bank_reason_input');
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
