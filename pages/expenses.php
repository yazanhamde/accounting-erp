<?php
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'مصاريف وسحوبات شخصية وتبرعات';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        update_expense(
            (int)$_POST['tx_id'], $_POST['category'], (float)$_POST['amount'], trim($_POST['description']),
            $_POST['paid_from_bank'] ?: null, $_POST['tx_date'] ?: date('Y-m-d')
        );
    } elseif ($action === 'delete') {
        delete_expense((int)$_POST['tx_id']);
    } else {
        $result = add_expense(
            $_POST['category'], (float)$_POST['amount'], trim($_POST['description']),
            $_POST['paid_from_bank'] ?: null, $_POST['tx_date'] ?: date('Y-m-d'), $user['id'],
            trim($_POST['no_bank_reason'] ?? '') ?: null,
            trim($_POST['invoice_no'] ?? '') ?: null,
            $_POST['tax_rate'] !== '' && isset($_POST['tax_rate']) ? $_POST['tax_rate'] : null
        );
        if (!$result['success']) {
            header('Location: ' . APP_BASE_URL . '/pages/expenses.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: ' . APP_BASE_URL . '/pages/expenses.php');
    exit;
}

require_once __DIR__ . '/../includes/pagination.php';
$per_page = 100;
$page = get_page_number();
$total_tx = paginate_count($db, 'SELECT COUNT(*) FROM expenses');
$total_pages = max(1, (int)ceil($total_tx / $per_page));
$offset = ($page - 1) * $per_page;
$stmt = $db->prepare('SELECT * FROM expenses ORDER BY tx_date DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
$stmt->execute();
$rows = $stmt->fetchAll();
$labels = ['expense' => 'مصروف', 'personal_withdrawal' => 'سحب شخصي', 'donation' => 'تبرع'];
require __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($_GET['error'])): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    <strong>⚠️ ما انحفظت الحركة:</strong> <?= htmlspecialchars($_GET['error']) ?>
</div>
<?php endif; ?>
<div class="panel">
    <h2>➕ تسجيل مصروف / سحب / تبرع</h2>
    <form method="post">
        <div class="form-row">
            <div>
                <label>النوع</label>
                <select name="category">
                    <option value="expense">مصروف عام</option>
                    <option value="personal_withdrawal">سحب شخصي</option>
                    <option value="donation">تبرع</option>
                </select>
            </div>
            <div>
                <label>المبلغ</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div>
                <label>دُفع من</label>
                <select name="paid_from_bank">
                    <option value="cash">كاش</option>
                    <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                    <option value="etihad">بنك الاتحاد</option>
                    <option value="islami">البنك الإسلامي</option>
                    <option value="dinarak">محفظة دينارك</option>
                </select>
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>رقم فاتورة المورد (اختياري)</label>
                <input type="text" name="invoice_no">
            </div>
            <div>
                <label>نسبة الضريبة % (اختياري - لو فاتورة ضريبية معك)</label>
                <select name="tax_rate">
                    <option value="">بدون ضريبة</option>
                    <option value="16">16%</option>
                    <option value="0">0% (معفى)</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div style="flex:3">
                <label>الوصف</label>
                <input type="text" name="description">
            </div>
            <div style="align-self:end">
                <button class="btn" type="submit">حفظ</button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h2>📋 آخر الحركات</h2>
    <table>
        <tr><th>النوع</th><th>المبلغ</th><th>من حساب</th><th>الوصف</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= $labels[$r['category']] ?? $r['category'] ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['paid_from_bank'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
            <td><?= $r['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $r['id'], "category" => $r['category'], "amount" => $r['amount'],
                    "paid_from_bank" => $r['paid_from_bank'], "description" => $r['description'], "tx_date" => $r['tx_date'],
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاي الحركة؟');">
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

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل الحركة</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>النوع</label>
                    <select name="category" id="edit_category">
                        <option value="expense">مصروف عام</option>
                        <option value="personal_withdrawal">سحب شخصي</option>
                        <option value="donation">تبرع</option>
                    </select>
                </div>
                <div>
                    <label>المبلغ</label>
                    <input type="number" step="0.01" name="amount" id="edit_amount" required>
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>دُفع من</label>
                    <select name="paid_from_bank" id="edit_paid_from_bank">
                        <option value="cash">كاش</option>
                        <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                        <option value="etihad">بنك الاتحاد</option>
                        <option value="islami">البنك الإسلامي</option>
                        <option value="dinarak">محفظة دينارك</option>
                    </select>
                </div>
                <div>
                    <label>التاريخ</label>
                    <input type="date" name="tx_date" id="edit_tx_date">
                </div>
            </div>
            <div class="form-row">
                <div style="flex:3">
                    <label>الوصف</label>
                    <input type="text" name="description" id="edit_description">
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
    document.getElementById('edit_category').value = data.category;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_paid_from_bank').value = data.paid_from_bank || 'cash';
    document.getElementById('edit_description').value = data.description || '';
    document.getElementById('edit_tx_date').value = data.tx_date;
    openModal('editModal');
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
