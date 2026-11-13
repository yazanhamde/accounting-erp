<?php
/* =====================================================================
 *  pages/customers.php — ذمم العملاء
 *  القسم 1: الـ PHP (logic + تجهيز البيانات للعرض)
 *  القسم 2: الـ HTML (عرض فقط - بدون منطق)
 * ===================================================================== */

// ---------------------------------------------------------------------
// 1) التهيئة والصلاحيات
// ---------------------------------------------------------------------
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pagination.php';

require_login();
$page_title = 'ذمم العملاء';
$user = current_user();
$db = get_db();

// ---------------------------------------------------------------------
// 2) معالجة الطلبات (POST) ثم redirect
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        update_customer_transaction(
            (int)$_POST['tx_id'], trim($_POST['name']), $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'),
            $_POST['paid_from_bank'] ?: null, trim($_POST['no_bank_reason'] ?? '') ?: null
        );
    } elseif ($action === 'delete') {
        delete_customer_transaction((int)$_POST['tx_id']);
    } elseif ($action === 'update_phone') {
        update_customer_phone((int)$_POST['entity_id'], trim($_POST['phone']));
    } elseif ($action === 'edit_profile') {
        $result = update_customer_profile((int)$_POST['customer_id'], trim($_POST['profile_name']), trim($_POST['profile_phone'] ?? ''));
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } elseif ($action === 'delete_customer') {
        require_owner(); // حذف عميل كامل عملية حساسة - المالك بس
        $result = delete_customer((int)$_POST['customer_id']);
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
        }
    } else {
        $result = add_customer_transaction(
            trim($_POST['name']), $_POST['type'], (float)$_POST['amount'],
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'), $user['id'],
            trim($_POST['phone'] ?? '') ?: null,
            $_POST['paid_from_bank'] ?: null,
            trim($_POST['no_bank_reason'] ?? '') ?: null,
            trim($_POST['invoice_no'] ?? '') ?: null,
            $_POST['tax_rate'] !== '' ? $_POST['tax_rate'] : null
        );
        if (!$result['success']) {
            header('Location: ' . APP_BASE_URL . '/pages/customers.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: ' . APP_BASE_URL . '/pages/customers.php');
    exit;
}

// ---------------------------------------------------------------------
// 3) جلب البيانات (Queries)
// ---------------------------------------------------------------------
$customers = $db->query('SELECT * FROM customers ORDER BY balance DESC')->fetchAll();

$per_page = 100;
$page = get_page_number();
$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $total_tx = paginate_count($db, 'SELECT COUNT(*) FROM customer_transactions ct JOIN customers c ON c.id = ct.customer_id WHERE c.name LIKE ?', ['%' . $search . '%']);
    $total_pages = max(1, (int)ceil($total_tx / $per_page));
    $offset = ($page - 1) * $per_page;
    $stmt = $db->prepare('SELECT ct.*, c.name AS customer_name FROM customer_transactions ct JOIN customers c ON c.id = ct.customer_id WHERE c.name LIKE ? ORDER BY ct.tx_date DESC, ct.id DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
    $stmt->execute(['%' . $search . '%']);
    $transactions = $stmt->fetchAll();
} else {
    $total_tx = paginate_count($db, 'SELECT COUNT(*) FROM customer_transactions');
    $total_pages = max(1, (int)ceil($total_tx / $per_page));
    $offset = ($page - 1) * $per_page;
    $stmt = $db->prepare('SELECT ct.*, c.name AS customer_name FROM customer_transactions ct JOIN customers c ON c.id = ct.customer_id ORDER BY ct.tx_date DESC, ct.id DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
    $stmt->execute();
    $transactions = $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// 4) تجهيز بيانات العرض (View Model)
//    كل الحسابات والشروط وتنسيق الأرقام هون - الـ HTML بس يطبع
// ---------------------------------------------------------------------

// رسائل الخطأ
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
$get_error = $_GET['error'] ?? '';

// قيم عامة للفورم
$today = date('Y-m-d');
$is_owner = is_owner();

// قائمة الحسابات (بتنستخدم بفورم الإضافة وفورم التعديل)
$bank_options = [
    'jordan_personal' => 'البنك الأردني - شخصي',
    'jordan_business' => 'البنك الأردني - مؤسسة',
    'etihad'          => 'بنك الاتحاد',
    'islami'          => 'البنك الإسلامي',
    'dinarak'         => 'محفظة دينارك',
    'investment'      => 'البنك الاستثماري',
    'cash'            => 'صندوق الكاش',
];

// أسماء أنواع الحركات
$tx_type_labels = [
    'debit'  => 'مبيعات ذمم',
    'credit' => 'سدد ذمة',
];

// جدول أرصدة العملاء
$customer_rows = [];
foreach ($customers as $c) {
    if ($c['balance'] > 0) {
        $badge_class = 'badge-red';
        $badge_title = 'العميل مدين لنا بهالمبلغ';
        $badge_label = 'رصيد مدين';
    } elseif ($c['balance'] < 0) {
        $badge_class = 'badge-green';
        $badge_title = 'العميل دفع أكتر من المطلوب - إحنا مدينين له';
        $badge_label = 'رصيد دائن';
    } else {
        $badge_class = 'badge-yellow';
        $badge_title = 'لا يوجد رصيد مستحق بأي اتجاه';
        $badge_label = 'مسدّد';
    }

    $customer_rows[] = [
        'id'            => $c['id'],
        'name'          => $c['name'],
        'name_attr'     => htmlspecialchars($c['name'], ENT_QUOTES),   // للاستخدام داخل confirm('...')
        'phone'         => $c['phone'] ?? '',
        'balance_fmt'   => number_format($c['balance'], 2),
        'badge_class'   => $badge_class,
        'badge_title'   => $badge_title,
        'badge_label'   => $badge_label,
        'profile_json'  => htmlspecialchars(json_encode([
            'id' => $c['id'], 'name' => $c['name'], 'phone' => $c['phone'],
        ]), ENT_QUOTES, 'UTF-8'),
    ];
}

// جدول سجل الحركات
$transaction_rows = [];
foreach ($transactions as $t) {
    $transaction_rows[] = [
        'id'            => $t['id'],
        'customer_name' => $t['customer_name'],
        'type_label'    => $t['type'] === 'debit' ? $tx_type_labels['debit'] : $tx_type_labels['credit'],
        'amount_fmt'    => number_format($t['amount'], 2),
        'description'   => $t['description'] ?? '',
        'tx_date'       => $t['tx_date'],
        'edit_json'     => htmlspecialchars(json_encode([
            'id' => $t['id'], 'name' => $t['customer_name'], 'type' => $t['type'],
            'amount' => $t['amount'], 'description' => $t['description'], 'tx_date' => $t['tx_date'],
            'paid_from_bank' => $t['paid_from_bank'], 'no_bank_reason' => $t['no_bank_reason'],
        ]), ENT_QUOTES, 'UTF-8'),
    ];
}

// البحث والترقيم
$has_search = ($search !== '');
$empty_msg = 'لا توجد حركات' . ($has_search ? ' لعميل باسم "' . htmlspecialchars($search) . '"' : '');
$pagination_params = $has_search ? ['q' => $search] : [];

// ---------------------------------------------------------------------
// 5) تحميل الـ header (بعد ما خلص كل الـ logic)
// ---------------------------------------------------------------------
require __DIR__ . '/../includes/header.php';
?>

<!-- =====================================================================
     HTML: عرض فقط
     ===================================================================== -->

<?php if ($flash_error): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    ⚠️ <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>
<?php if (!empty($get_error)): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    <strong>⚠️ ما انحفظت الحركة:</strong> <?= htmlspecialchars($get_error) ?>
</div>
<?php endif; ?>

<div class="panel">
    <h2>➕ تسجيل حركة جديدة لعميل</h2>
    <form method="post" id="custTxForm">
        <div class="form-row">
            <div>
                <label>اسم العميل</label>
                <input type="text" name="name" list="customersList" required>
                <datalist id="customersList">
                    <?php foreach ($customer_rows as $row): ?>
                        <option value="<?= htmlspecialchars($row['name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label>نوع الحركة</label>
                <select name="type">
                    <option value="debit">مبيعات ذمم</option>
                    <option value="credit">سدد ذمة</option>
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
                <input type="date" name="tx_date" value="<?= $today ?>">
            </div>
            <div>
                <label>من/إلى أي حساب؟ (إجباري إلا لو كتبت سبب بالأسفل)</label>
                <select name="paid_from_bank" id="paid_from_bank_select">
                    <option value="">-- بدون تحديد (لازم تكتب سبب) --</option>
                    <?php foreach ($bank_options as $value => $label): ?>
                        <option value="<?= $value ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="no_bank_reason_wrap" style="display:none">
                <label>سبب عدم تحديد البنك (إجباري)</label>
                <input type="text" name="no_bank_reason" id="no_bank_reason_input" placeholder="مثال: سلفة مفتوحة لسا ما تحدد مصدرها">
            </div>
            <div>
                <label>رقم الفاتورة (اختياري)</label>
                <input type="text" name="invoice_no" placeholder="INV-1001">
            </div>
            <div>
                <label>نسبة الضريبة % (اختياري - يعتبر المبلغ أعلاه شامل الضريبة)</label>
                <select name="tax_rate">
                    <option value="">بدون ضريبة</option>
                    <option value="16">16% (ضريبة المبيعات العامة)</option>
                    <option value="0">0% (معفى)</option>
                </select>
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
    <h2>📋 أرصدة العملاء الحالية</h2>
    <table>
        <tr><th>العميل</th><th>الهاتف</th><th>الرصيد الحالي</th><th>الحالة</th><th>إجراءات</th></tr>
        <?php foreach ($customer_rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td>
                <form method="post" style="display:flex; gap:5px; align-items:center;">
                    <input type="hidden" name="action" value="update_phone">
                    <input type="hidden" name="entity_id" value="<?= $row['id'] ?>">
                    <input type="text" name="phone" value="<?= htmlspecialchars($row['phone']) ?>" placeholder="أضف رقم هاتف" style="width:130px; padding:5px 8px; font-size:13px; border:1px solid #ddd; border-radius:6px;">
                    <button type="submit" class="btn btn-sm">حفظ</button>
                </form>
            </td>
            <td><?= $row['balance_fmt'] ?></td>
            <td>
                <span class="badge <?= $row['badge_class'] ?>" title="<?= $row['badge_title'] ?>"><?= $row['badge_label'] ?></span>
            </td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditProfile(<?= $row['profile_json'] ?>)'>✏️ تعديل</button>
                <?php if ($is_owner): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف العميل \'<?= $row['name_attr'] ?>\' نهائياً؟ (رح يترفض تلقائياً لو عنده حركات مسجّلة).');">
                    <input type="hidden" name="action" value="delete_customer">
                    <input type="hidden" name="customer_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p style="font-size:12px;color:#94a3b8;margin-top:8px;">حذف عميل بالكامل متاح للمالك فقط، ومرفوض تلقائياً لو عنده أي حركة مسجّلة (حماية للتاريخ المالي).</p>
</div>

<div class="panel">
    <h2>🧾 سجل حركات العملاء</h2>
    <form method="get" class="form-row" style="margin-bottom:12px; align-items:end;">
        <div style="flex:3">
            <label>🔍 بحث باسم العميل</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="اكتب اسم العميل لعرض حركاته فقط" list="customersList">
        </div>
        <div>
            <button class="btn" type="submit">بحث</button>
            <?php if ($has_search): ?>
                <a class="btn btn-sm btn-cancel" href="/accounting-system/pages/customers.php">إلغاء البحث</a>
            <?php endif; ?>
        </div>
    </form>
    <table>
        <tr><th>العميل</th><th>نوع الحركة</th><th>المبلغ</th><th>ملاحظات</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php if (empty($transaction_rows)): ?>
        <tr><td colspan="6" style="text-align:center;color:#94a3b8;"><?= $empty_msg ?></td></tr>
        <?php endif; ?>
        <?php foreach ($transaction_rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['customer_name']) ?></td>
            <td><?= $row['type_label'] ?></td>
            <td><?= $row['amount_fmt'] ?></td>
            <td><?= htmlspecialchars($row['description']) ?></td>
            <td><?= $row['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= $row['edit_json'] ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاي الحركة؟ الرصيد رح ينعدّل تلقائياً.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tx_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php render_pagination($page, $total_pages, $pagination_params); ?>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل حركة العميل</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>اسم العميل</label>
                    <input type="text" name="name" id="edit_name" list="customersList" required>
                </div>
                <div>
                    <label>نوع الحركة</label>
                    <select name="type" id="edit_type">
                        <option value="debit">مبيعات ذمم</option>
                        <option value="credit">سدد ذمة</option>
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
                        <?php foreach ($bank_options as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
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
        <h3>✏️ تعديل بيانات العميل</h3>
        <form method="post" id="editProfileForm">
            <input type="hidden" name="action" value="edit_profile">
            <input type="hidden" name="customer_id" id="profile_customer_id">
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
    document.getElementById('profile_customer_id').value = data.id;
    document.getElementById('profile_name').value = data.name;
    document.getElementById('profile_phone').value = data.phone || '';
    openModal('editProfileModal');
}

// إجبار اختيار بنك/كاش أو كتابة سبب صريح قبل الحفظ - يمنع تراكم حساب suspense صامتاً
// (نفس المنطق على فورم الإضافة وفورم التعديل معاً)
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
