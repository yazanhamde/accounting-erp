<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'إيرادات خارجية';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        update_external_revenue(
            (int)$_POST['tx_id'], trim($_POST['source_person']), (float)$_POST['amount'], trim($_POST['description']),
            $_POST['paid_from_bank'] ?: null, $_POST['tx_date'] ?: date('Y-m-d')
        );
    } elseif ($action === 'delete') {
        delete_external_revenue((int)$_POST['tx_id']);
    } else {
        $result = add_external_revenue(
            trim($_POST['source_person']) ?: 'رمزي', (float)$_POST['amount'], trim($_POST['description']),
            $_POST['paid_from_bank'] ?: null, $_POST['tx_date'] ?: date('Y-m-d'), $user['id'],
            trim($_POST['no_bank_reason'] ?? '') ?: null
        );
        if (!$result['success']) {
            header('Location: external_revenues.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: external_revenues.php');
    exit;
}

require_once __DIR__ . '/includes/pagination.php';
$per_page = 100;
$page = get_page_number();
$total_tx = paginate_count($db, 'SELECT COUNT(*) FROM external_revenues');
$total_pages = max(1, (int)ceil($total_tx / $per_page));
$offset = ($page - 1) * $per_page;
$stmt = $db->prepare('SELECT * FROM external_revenues ORDER BY tx_date DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
$stmt->execute();
$rows = $stmt->fetchAll();
$total = get_external_revenues_total();
require __DIR__ . '/includes/header.php';
?>

<?php if (!empty($_GET['error'])): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    <strong>⚠️ ما انحفظت الحركة:</strong> <?= htmlspecialchars($_GET['error']) ?>
</div>
<?php endif; ?>

<div class="panel">
    <h2>💚 إجمالي الإيرادات الخارجية</h2>
    <p style="font-size:1.4em;font-weight:bold"><?= number_format($total, 2) ?> د.أ</p>
</div>

<div class="panel">
    <h2>➕ تسجيل إيراد خارجي جديد</h2>
    <form method="post">
        <div class="form-row">
            <div>
                <label>مين جابها (المصدر)</label>
                <input type="text" name="source_person" value="رمزي" required>
            </div>
            <div>
                <label>المبلغ</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div>
                <label>دخلت لـ</label>
                <select name="paid_from_bank">
                    <option value="cash">كاش</option>
                    <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                    <option value="etihad">بنك الاتحاد</option>
                    <option value="islami">البنك الإسلامي</option>
                    <option value="dinarak">محفظة دينارك</option>
                    <option value="investment">حساب الاستثمار</option>
                </select>
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="form-row">
            <div style="flex:3">
                <label>الوصف</label>
                <input type="text" name="description" placeholder="مثلاً: رزقة براني">
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
        <tr><th>المصدر</th><th>المبلغ</th><th>دخلت لـ</th><th>الوصف</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['source_person']) ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($r['paid_from_bank'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
            <td><?= $r['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $r['id'], "source_person" => $r['source_person'], "amount" => $r['amount'],
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
                    <label>مين جابها (المصدر)</label>
                    <input type="text" name="source_person" id="edit_source_person" required>
                </div>
                <div>
                    <label>المبلغ</label>
                    <input type="number" step="0.01" name="amount" id="edit_amount" required>
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>دخلت لـ</label>
                    <select name="paid_from_bank" id="edit_paid_from_bank">
                        <option value="cash">كاش</option>
                        <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                        <option value="etihad">بنك الاتحاد</option>
                        <option value="islami">البنك الإسلامي</option>
                        <option value="dinarak">محفظة دينارك</option>
                        <option value="investment">حساب الاستثمار</option>
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
    document.getElementById('edit_source_person').value = data.source_person;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_paid_from_bank').value = data.paid_from_bank || 'cash';
    document.getElementById('edit_description').value = data.description || '';
    document.getElementById('edit_tx_date').value = data.tx_date;
    openModal('editModal');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
