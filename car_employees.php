<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'صيانة خارجية - إيرادات ومصاريف وسلف وخصومات وإعلانات';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        $result = update_car_employee_transaction(
            (int)$_POST['tx_id'], trim($_POST['name']), $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'),
            $_POST['paid_from_bank'] ?: null, trim($_POST['no_bank_reason'] ?? '') ?: null
        );
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } elseif ($action === 'delete') {
        delete_car_employee_transaction((int)$_POST['tx_id']);
    } elseif ($action === 'update_phone') {
        update_car_employee_phone((int)$_POST['entity_id'], trim($_POST['phone']));
    } elseif ($action === 'update_car_info') {
        update_car_employee_car_info((int)$_POST['entity_id'], trim($_POST['car_info']));
    } elseif ($action === 'edit_profile') {
        $result = update_car_employee_profile(
            (int)$_POST['car_employee_id'], trim($_POST['profile_name']),
            trim($_POST['profile_phone'] ?? ''), trim($_POST['profile_car_info'] ?? ''), $_POST['profile_base_salary'] ?? 0
        );
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } elseif ($action === 'delete_car_employee') {
        require_owner(); // حذف موظف سيارة كامل عملية حساسة - المالك بس
        $result = delete_car_employee((int)$_POST['car_employee_id']);
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } elseif ($action === 'distribute_secretary_salary') {
        $selected_ids = array_map('intval', $_POST['secretary_employee_ids'] ?? []);
        $result = distribute_secretary_salary_equally(
            (float)$_POST['total_secretary_amount'], $_POST['tx_date'] ?: date('Y-m-d'),
            $user['id'], $_POST['secretary_paid_from_bank'] ?: null, trim($_POST['description'] ?? '') ?: null,
            $selected_ids
        );
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } else {
        $result = add_car_employee_transaction(
            trim($_POST['name']), $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'), $user['id'],
            trim($_POST['phone'] ?? '') ?: null,
            trim($_POST['car_info'] ?? '') ?: null,
            $_POST['customer_count'] !== '' ? (int)$_POST['customer_count'] : null,
            $_POST['paid_from_bank'] ?: null
        );
        if (!$result['success']) {
            header('Location: car_employees.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: car_employees.php');
    exit;
}

$car_employees = $db->query('SELECT * FROM car_employees ORDER BY name ASC')->fetchAll();
require_once __DIR__ . '/includes/pagination.php';
$per_page = 100;
$page = get_page_number();
$total_tx = paginate_count($db, 'SELECT COUNT(*) FROM car_employee_transactions');
$total_pages = max(1, (int)ceil($total_tx / $per_page));
$offset = ($page - 1) * $per_page;
$stmt = $db->prepare('SELECT ct.*, c.name AS car_employee_name FROM car_employee_transactions ct JOIN car_employees c ON c.id = ct.car_employee_id ORDER BY ct.tx_date DESC, ct.id DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
$stmt->execute();
$transactions = $stmt->fetchAll();
$pl_report = get_car_employee_pl_report();
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
require __DIR__ . '/includes/header.php';

$type_labels = [
    'revenue' => 'إيراد',
    'expense' => 'مصروف',
    'advance' => 'سلفة',
    'deduction' => 'خصم',
    'ads' => 'إعلانات',
    'fuel' => 'محروقات',
    'parts' => 'قطع و صيانة لوحات',
    'vehicle_maintenance' => 'صيانة مركبة',
    'secretary_salary' => 'حصة راتب سكرتارية',
];
$expense_row_labels = [
    'fuel' => 'مصاريف محروقات',
    'parts' => 'مصاريف قطع و صيانة لوحات',
    'vehicle_maintenance' => 'صيانة مركبة',
    'ads' => 'مصاريف إعلانات',
    'secretary_salary' => 'حصة راتب سكرتارية',
    'employee_salary' => 'راتب الموظف',
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
    <h2>➕ تسجيل حركة جديدة لموظف سيارة</h2>
    <form method="post" id="txForm">
        <div class="form-row">
            <div>
                <label>اسم الموظف / السائق</label>
                <input type="text" name="name" list="carEmployeesList" required>
                <datalist id="carEmployeesList">
                    <?php foreach ($car_employees as $c): ?>
                        <option value="<?= htmlspecialchars($c['name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label>نوع الحركة</label>
                <select name="type" id="typeSelect" onchange="toggleCustomerCount(this.value)">
                    <option value="revenue">إيراد (يزيد الصافي)</option>
                    <option value="advance">سلفة (تنقص الصافي)</option>
                    <option value="deduction">خصم (ينقص الصافي)</option>
                    <option value="ads">إعلانات (تنقص الصافي)</option>
                    <option value="fuel">مصاريف محروقات (تنقص الصافي)</option>
                    <option value="parts">مصاريف قطع و صيانة لوحات (تنقص الصافي)</option>
                    <option value="vehicle_maintenance">صيانة مركبة (تنقص الصافي)</option>
                </select>
            </div>
            <div>
                <label>المبلغ</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div id="customerCountWrap" style="display:none;">
                <label>عدد الزبائن (اختياري)</label>
                <input type="number" name="customer_count" min="0">
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="form-row">
            <div>
                <label>رقم الهاتف (اختياري)</label>
                <input type="text" name="phone" placeholder="07xxxxxxxx">
            </div>
            <div>
                <label>بيانات السيارة (اختياري)</label>
                <input type="text" name="car_info" placeholder="نوع السيارة / رقم اللوحة">
            </div>
            <div>
                <label>من/إلى أي حساب (إجباري للسلفة/الإعلانات/البونص/المحروقات/القطع/الصيانة/حصة السكرتارية، أو اكتب سبب)</label>
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
    <h2>📋 أرصدة صيانة خارجية الحالية</h2>
    <table>
        <tr><th>الاسم</th><th>الهاتف</th><th>السيارة</th><th>صافي ربح/خسارة المحل</th><th>الحالة</th><th>إجراءات</th></tr>
        <?php foreach ($car_employees as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td>
                <form method="post" style="display:flex; gap:5px; align-items:center;">
                    <input type="hidden" name="action" value="update_phone">
                    <input type="hidden" name="entity_id" value="<?= $c['id'] ?>">
                    <input type="text" name="phone" value="<?= htmlspecialchars($c['phone'] ?? '') ?>" placeholder="أضف رقم هاتف" style="width:130px; padding:5px 8px; font-size:13px; border:1px solid #ddd; border-radius:6px;">
                    <button type="submit" class="btn btn-sm">حفظ</button>
                </form>
            </td>
            <td>
                <form method="post" style="display:flex; gap:5px; align-items:center;">
                    <input type="hidden" name="action" value="update_car_info">
                    <input type="hidden" name="entity_id" value="<?= $c['id'] ?>">
                    <input type="text" name="car_info" value="<?= htmlspecialchars($c['car_info'] ?? '') ?>" placeholder="نوع/رقم السيارة" style="width:150px; padding:5px 8px; font-size:13px; border:1px solid #ddd; border-radius:6px;">
                    <button type="submit" class="btn btn-sm">حفظ</button>
                </form>
            </td>
            <td><?= number_format($c['balance'], 2) ?></td>
            <td>
                <?php if ($c['balance'] > 0): ?>
                    <span class="badge badge-green">ربح للمحل</span>
                <?php elseif ($c['balance'] < 0): ?>
                    <span class="badge badge-red">خسارة على المحل</span>
                <?php else: ?>
                    <span class="badge badge-yellow">متعادل</span>
                <?php endif; ?>
            </td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditProfile(<?= htmlspecialchars(json_encode([
                    "id" => $c['id'], "name" => $c['name'], "phone" => $c['phone'], "car_info" => $c['car_info'], "base_salary" => $c['base_salary'] ?? 0,
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <?php if (is_owner()): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف \\'<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>\\' نهائياً؟ (رح يترفض تلقائياً لو عنده حركات مسجّلة).');">
                    <input type="hidden" name="action" value="delete_car_employee">
                    <input type="hidden" name="car_employee_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p style="font-size:12px;color:#94a3b8;margin-top:8px;">حذف موظف سيارة بالكامل متاح للمالك فقط، ومرفوض تلقائياً لو عنده أي حركة مسجّلة (حماية للتاريخ المالي).</p>
</div>

<div class="panel">
    <h2>🧾 سجل حركات صيانة خارجية</h2>
    <table>
        <tr><th>الاسم</th><th>نوع الحركة</th><th>المبلغ</th><th>عدد الزبائن</th><th>ملاحظات</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php foreach ($transactions as $t): ?>
        <tr>
            <td><?= htmlspecialchars($t['car_employee_name']) ?></td>
            <td><?= htmlspecialchars($type_labels[$t['type']] ?? $t['type']) ?></td>
            <td><?= number_format($t['amount'], 2) ?></td>
            <td><?= $t['customer_count'] !== null ? (int)$t['customer_count'] : '—' ?></td>
            <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
            <td><?= $t['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $t['id'], "name" => $t['car_employee_name'], "type" => $t['type'],
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
        <h3>✏️ تعديل حركة موظف السيارة</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>الاسم</label>
                    <input type="text" name="name" id="edit_name" list="carEmployeesList" required>
                </div>
                <div>
                    <label>نوع الحركة</label>
                    <select name="type" id="edit_type">
                        <option value="revenue">إيراد</option>
                        <option value="advance">سلفة</option>
                        <option value="deduction">خصم</option>
                        <option value="ads">إعلانات</option>
                        <option value="fuel">مصاريف محروقات</option>
                        <option value="parts">مصاريف قطع و صيانة لوحات</option>
                        <option value="vehicle_maintenance">صيانة مركبة</option>
                        <option value="secretary_salary">حصة راتب سكرتارية</option>
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
                    <label>من/إلى أي حساب (إجباري للسلفة/الإعلانات/البونص/المحروقات/القطع/الصيانة/حصة السكرتارية، أو اكتب سبب)</label>
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

<div class="panel">
    <h2>👩‍💼 توزيع راتب السكرتارية بالتساوي على السائقين المحددين</h2>
    <p style="color:#64748b; font-size:13px;">دخّل المبلغ الكلي لراتب السكرتارية، وحدد (✔) مين من السائقين بده ياخذ حصة منه، والنظام رح يقسم المبلغ بالتساوي بس على المحددين ويسجل حركة "حصة راتب سكرتارية" منفصلة لكل واحد منهم، وتنعكس على البنك/الكاش يلي تحدده.</p>
    <form method="post">
        <input type="hidden" name="action" value="distribute_secretary_salary">
        <div class="form-row">
            <div>
                <label>إجمالي راتب السكرتارية</label>
                <input type="number" step="0.01" name="total_secretary_amount" required>
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>من أي حساب انصرف</label>
                <select name="secretary_paid_from_bank" required>
                    <option value="">-- اختر --</option>
                    <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                    <option value="etihad">بنك الاتحاد</option>
                    <option value="islami">البنك الإسلامي</option>
                    <option value="dinarak">محفظة دينارك</option>
                    <option value="investment">البنك الاستثماري</option>
                    <option value="cash">صندوق الكاش</option>
                </select>
            </div>
            <div style="flex:2">
                <label>ملاحظات (اختياري)</label>
                <input type="text" name="description" placeholder="مثال: راتب سكرتارية شهر 7">
            </div>
        </div>
        <table style="margin-top:10px;">
            <tr><th style="width:60px;">تحديد</th><th>السائق</th></tr>
            <?php foreach ($car_employees as $c): ?>
            <tr>
                <td style="text-align:center;"><input type="checkbox" name="secretary_employee_ids[]" value="<?= $c['id'] ?>" checked style="width:18px; height:18px;"></td>
                <td><?= htmlspecialchars($c['name']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div class="form-row" style="margin-top:10px;">
            <div style="align-self:end">
                <button class="btn" type="submit">توزيع بالتساوي على المحددين</button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h2>📈 صافي ربح/خسارة المحل من كل سائق (الإيراد الكامل مقابل كل بند مصروف تشغيل)</h2>
    <table>
        <tr>
            <th>الاسم</th>
            <?php foreach ($pl_report as $r): ?><th><strong><?= htmlspecialchars($r['name']) ?></strong></th><?php endforeach; ?>
        </tr>
        <tr>
            <td>الإيراد الكامل</td>
            <?php foreach ($pl_report as $r): ?><td><?= number_format($r['revenue'], 2) ?></td><?php endforeach; ?>
        </tr>
        <?php foreach ($expense_row_labels as $key => $lbl): ?>
        <tr>
            <td><?= htmlspecialchars($lbl) ?></td>
            <?php foreach ($pl_report as $r): ?><td><?= number_format($r['expenses'][$key], 2) ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td>إجمالي المصاريف</td>
            <?php foreach ($pl_report as $r): ?><td><?= number_format($r['total_expenses'], 2) ?></td><?php endforeach; ?>
        </tr>
        <tr>
            <td><strong>صافي ربح/خسارة المحل</strong></td>
            <?php foreach ($pl_report as $r): ?>
                <td style="font-weight:bold; color:<?= $r['net'] >= 0 ? '#16a34a' : '#dc2626' ?>"><?= number_format($r['net'], 2) ?></td>
            <?php endforeach; ?>
        </tr>
    </table>
    <p style="color:#64748b; font-size:13px;">صافي ربح/خسارة المحل من هالسائق = الإيراد الكامل − (محروقات + قطع + صيانة مركبة + مصاريف إعلانات + حصة راتب السكرتارية + راتب الموظف). "راتب الموظف" هون بيتسحب تلقائياً من صفحة "الموظفين" (بس المبالغ يلي فعلياً استلمها بنوع "دفع راتب مستحق"، بنفس اسم السائق) - التسجيل الفعلي للراتب بيضل من صفحة الموظفين، بس بينضاف هون للصافي عشان يعكس إنه أخذه من نفس الإيراد. باقي البنود بتتجمّع من حركاتها المسجّلة تحت نفس النوع لهاد السائق. ملاحظة: أي "إيراد" بينعكس فوراً لحظة تسجيله - على صندوق الكاش الفعلي لو ما حددت حساب، أو على البنك المحدد لو اخترت "من/إلى أي حساب" ببنك معيّن.</p>
</div>

<div class="modal-overlay" id="editProfileModal">
    <div class="modal-box">
        <h3>✏️ تعديل بيانات موظف السيارة</h3>
        <form method="post" id="editProfileForm">
            <input type="hidden" name="action" value="edit_profile">
            <input type="hidden" name="car_employee_id" id="profile_car_employee_id">
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
            <div class="form-row">
                <div>
                    <label>بيانات السيارة</label>
                    <input type="text" name="profile_car_info" id="profile_car_info" placeholder="نوع السيارة / رقم اللوحة">
                </div>
                <div>
                    <label>الراتب الأساسي الشهري</label>
                    <input type="number" step="0.01" name="profile_base_salary" id="profile_base_salary">
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
function toggleCustomerCount(type) {
    document.getElementById('customerCountWrap').style.display = (type === 'revenue' || type === 'ads') ? 'block' : 'none';
}
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
    document.getElementById('profile_car_employee_id').value = data.id;
    document.getElementById('profile_name').value = data.name;
    document.getElementById('profile_phone').value = data.phone || '';
    document.getElementById('profile_car_info').value = data.car_info || '';
    document.getElementById('profile_base_salary').value = data.base_salary;
    openModal('editProfileModal');
}

// السلفة/الإعلانات/الراتب/البونص/المحروقات/القطع/صيانة المركبة/حصة السكرتارية لازم إلهم بنك/كاش أو سبب واضح
// (الإيراد والمصروف العام والخصم قيود على حساب السائق نفسه)
var BANK_REQUIRED_TYPES = ['advance', 'ads', 'bonus', 'fuel', 'parts', 'vehicle_maintenance', 'secretary_salary'];
function setupCarEmployeeBankReasonToggle(typeId, selectId, wrapId, reasonInputId) {
    var typeSel = document.getElementById(typeId);
    var sel = document.getElementById(selectId);
    var wrap = document.getElementById(wrapId);
    var reasonInput = document.getElementById(reasonInputId);
    if (!typeSel || !sel || !wrap || !reasonInput) return;
    function toggle() {
        var needsBank = BANK_REQUIRED_TYPES.indexOf(typeSel.value) !== -1;
        if (needsBank && sel.value === '') { wrap.style.display = ''; reasonInput.required = true; }
        else { wrap.style.display = 'none'; reasonInput.required = false; }
    }
    sel.addEventListener('change', toggle);
    typeSel.addEventListener('change', toggle);
    toggle();
}
setupCarEmployeeBankReasonToggle('typeSelect', 'paid_from_bank_select', 'no_bank_reason_wrap', 'no_bank_reason_input');
setupCarEmployeeBankReasonToggle('edit_type', 'edit_paid_from_bank_select', 'edit_no_bank_reason_wrap', 'edit_no_bank_reason_input');
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
