<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'الشيكات';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_status'])) {
        $check_id = (int)$_POST['check_id'];
        $new_status = $_POST['status'];
        $cleared_bank = $new_status !== 'pending' ? ($_POST['cleared_via_bank'] ?: null) : null;

        $old_check = $db->prepare('SELECT * FROM checks WHERE id = ?');
        $old_check->execute([$check_id]);
        $check_row = $old_check->fetch();
        $old_status = $check_row['status'] ?? null;
        $old_bank = $check_row['cleared_via_bank'] ?? null; // الحساب يلي كان محدد وقت "تم الصرف" (لو صار)

        $stmt = $db->prepare('UPDATE checks SET status = ?, cleared_via_bank = ? WHERE id = ?');
        $stmt->execute([$new_status, $cleared_bank, $check_id]);

        // لو الشيك ارتد أو رجع من ارتداد لحالة تانية، لازم نصحح أثره على حساب الجهة (عميل/مورد/شخص)
        update_check_entity_link_on_status_change($check_id, $old_status, $new_status);

        if ($check_row) {
            $direction_deposit = $check_row['direction'] === 'incoming'; // شيك وارد = إيداع، صادر = سحب

            // 1) لسا ما انسجلت حركة بنك حقيقية (كانت أصلاً pending)، وهلق صار "تم الصرف" -> نضيف الفلوس
            if ($old_status === 'pending' && $new_status === 'cleared' && $cleared_bank) {
                $tx_type = $direction_deposit ? 'deposit' : 'withdraw';
                mirror_bank_movement($cleared_bank, $tx_type, $check_row['amount'], "تحصيل شيك: {$check_row['party_name']}", date('Y-m-d'), $user['id'], 'checks', $check_id);
            }
            // 2) كانت "تم الصرف" (فلوس فعلاً انضافت للبنك القديم) وهلق رجعت "قيد الانتظار" أو صارت "مرتجع"
            //    -> لازم نسحب نفس المبلغ من نفس البنك القديم عكس الاتجاه الأصلي (تصحيح/إلغاء الحركة السابقة)
            elseif ($old_status === 'cleared' && $new_status !== 'cleared' && $old_bank) {
                $tx_type = $direction_deposit ? 'withdraw' : 'deposit'; // عكس الحركة الأصلية بالضبط
                mirror_bank_movement($old_bank, $tx_type, $check_row['amount'], "تراجع/ارتداد شيك: {$check_row['party_name']}", date('Y-m-d'), $user['id'], 'checks', $check_id);
            }
        }
    } elseif (($_POST['action'] ?? '') === 'edit') {
        update_check(
            (int)$_POST['tx_id'], $_POST['direction'], trim($_POST['party_name']), (float)$_POST['amount'],
            $_POST['due_date'], $_POST['bank_name'], trim($_POST['check_number']), trim($_POST['notes']),
            $_POST['entity_type'] ?: null, trim($_POST['entity_name'] ?? '')
        );
    } elseif (($_POST['action'] ?? '') === 'delete') {
        delete_check((int)$_POST['tx_id']);
    } else {
        $result = add_check(
            $_POST['direction'], trim($_POST['party_name']), (float)$_POST['amount'],
            $_POST['due_date'], $_POST['bank_name'], trim($_POST['check_number']),
            trim($_POST['notes']), $user['id'],
            $_POST['entity_type'] ?: null, trim($_POST['entity_name'] ?? '')
        );
        if (!empty($result) && empty($result['success'])) {
            header('Location: checks.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: checks.php');
    exit;
}

$checks = $db->query('SELECT * FROM checks ORDER BY due_date ASC')->fetchAll();
$checks_deferred = array_values(array_filter($checks, fn($c) => $c['direction'] === 'outgoing'));  // شيكات آجلة (صادرة منا)
$checks_collection = array_values(array_filter($checks, fn($c) => $c['direction'] === 'incoming')); // شيكات برسم تحصيل (واردة إلنا)
$total_deferred = array_sum(array_column(array_filter($checks_deferred, fn($c) => $c['status'] === 'pending'), 'amount'));
$total_collection = array_sum(array_column(array_filter($checks_collection, fn($c) => $c['status'] === 'pending'), 'amount'));
// نجيب أي صور محفوظة للشيكات حتى نعرض أيقونة 🖼 جنب الشيك يلي إله صورة
$check_attachments = [];
if ($checks) {
    $ids = array_column($checks, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $att_stmt = $db->prepare("SELECT entity_id, file_path FROM attachments WHERE entity_table='checks' AND entity_id IN ($placeholders)");
    $att_stmt->execute($ids);
    foreach ($att_stmt->fetchAll() as $a) {
        $check_attachments[$a['entity_id']] = $a['file_path'];
    }
}
require __DIR__ . '/includes/header.php';
?>

<?php if (!empty($_GET['error'])): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    <strong>⚠️ ما انحفظ الشيك:</strong> <?= htmlspecialchars($_GET['error']) ?>
</div>
<?php endif; ?>

<div class="panel">
    <h2>➕ تسجيل شيك جديد</h2>
    <form method="post">
        <div class="form-row">
            <div>
                <label>الاتجاه</label>
                <select name="direction">
                    <option value="incoming">شيك مستلم (برسم تحصيل - له عندنا)</option>
                    <option value="outgoing">شيك مدفوع (آجل - علينا)</option>
                </select>
            </div>
            <div>
                <label>اسم الجهة (كما يظهر بالشيك)</label>
                <input type="text" name="party_name" required>
            </div>
            <div>
                <label>المبلغ</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div>
                <label>تاريخ الاستحقاق</label>
                <input type="date" name="due_date" required>
            </div>
        </div>
        <div class="form-row">
            <div>
                <label>ربط الشيك بحساب (اختياري)</label>
                <select name="entity_type">
                    <option value="">بدون ربط - شيك حر</option>
                    <option value="customer">عميل</option>
                    <option value="supplier">مورد</option>
                    <option value="person">شخص (ذمم شخصية)</option>
                </select>
            </div>
            <div>
                <label>اسم العميل/المورد/الشخص</label>
                <input type="text" name="entity_name" placeholder="مثلاً: رمزي">
            </div>
            <div style="flex:2">
                <small style="color:#888">لو ربطته بجهة: يتأثر حسابها فوراً (تحصيل ينزل من دينها، آجل ينزل من دينك)، وحساب البنك ما يتأثر أبداً إلا لما تحدد الشيك "تم الصرف".</small>
            </div>
        </div>
        <div class="form-row">
            <div>
                <label>البنك</label>
                <select name="bank_name">
                    <option value="islami">البنك الإسلامي</option>
                    <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                    <option value="etihad">بنك الاتحاد</option>
                </select>
            </div>
            <div>
                <label>رقم الشيك</label>
                <input type="text" name="check_number">
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

<?php
function render_checks_table($title, $icon, $rows, $total, $totalLabel, $check_attachments) {
?>
<div class="panel">
    <h2><?= $icon ?> <?= htmlspecialchars($title) ?></h2>
    <p style="font-weight:bold"><?= htmlspecialchars($totalLabel) ?>: <?= number_format($total, 2) ?> د.أ (قيد الانتظار)</p>
    <table>
        <tr><th>الجهة</th><th>مربوط بحساب</th><th>المبلغ</th><th>الاستحقاق</th><th>البنك</th><th>الحالة</th><th>تحديث</th><th>إجراءات</th></tr>
        <?php foreach ($rows as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['party_name']) ?>
                <?php if (isset($check_attachments[$c['id']])): ?>
                    <a href="uploads/attachments/<?= htmlspecialchars($check_attachments[$c['id']]) ?>" target="_blank" title="فيه صورة محفوظة">🖼️</a>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $entityLabels = ['customer' => '👤 عميل', 'supplier' => '🏭 مورد', 'person' => '🧾 شخص'];
                echo $c['entity_type'] ? $entityLabels[$c['entity_type']] : '<span style="color:#aaa">بدون ربط</span>';
                ?>
            </td>
            <td><?= number_format($c['amount'], 2) ?></td>
            <td><?= $c['due_date'] ?></td>
            <td><?= htmlspecialchars($c['bank_name'] ?? '') ?></td>
            <td>
                <?php
                $statusBadge = ['pending' => 'badge-yellow', 'cleared' => 'badge-green', 'bounced' => 'badge-red'];
                $statusLabel = ['pending' => 'قيد الانتظار', 'cleared' => 'تم الصرف', 'bounced' => 'مرتجع'];
                ?>
                <span class="badge <?= $statusBadge[$c['status']] ?>"><?= $statusLabel[$c['status']] ?></span>
            </td>
            <td>
                <form method="post" style="display:flex; gap:5px; align-items:center;" onsubmit="return prepareCheckStatus(this)">
                    <input type="hidden" name="mark_status" value="1">
                    <input type="hidden" name="check_id" value="<?= $c['id'] ?>">
                    <select name="status" onchange="handleCheckStatusChange(this)">
                        <option value="pending" <?= $c['status']==='pending'?'selected':'' ?>>قيد الانتظار</option>
                        <option value="cleared" <?= $c['status']==='cleared'?'selected':'' ?>>تم الصرف</option>
                        <option value="bounced" <?= $c['status']==='bounced'?'selected':'' ?>>مرتجع</option>
                    </select>
                    <select name="cleared_via_bank" class="bank-select" style="display:none;">
                        <option value="">-- اختر الحساب --</option>
                        <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                        <option value="etihad">بنك الاتحاد</option>
                        <option value="islami">البنك الإسلامي</option>
                        <option value="dinarak">محفظة دينارك</option>
                        <option value="investment">البنك الاستثماري</option>
                        <option value="cash">صندوق الكاش</option>
                    </select>
                </form>
            </td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $c['id'], "direction" => $c['direction'], "party_name" => $c['party_name'],
                    "amount" => $c['amount'], "due_date" => $c['due_date'], "bank_name" => $c['bank_name'],
                    "check_number" => $c['check_number'], "notes" => $c['notes'],
                    "entity_type" => $c['entity_type'], "entity_name" => $c['entity_type'] ? $c['party_name'] : '',
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاد الشيك؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tx_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="8" style="text-align:center;color:#888">ما في شيكات بهاد القسم</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php
}
render_checks_table('شيكات آجلة (صادرة منا)', '📤', $checks_deferred, $total_deferred, 'إجمالي الشيكات الآجلة المعلّقة', $check_attachments);
render_checks_table('شيكات برسم تحصيل (واردة إلنا)', '📥', $checks_collection, $total_collection, 'إجمالي شيكات التحصيل المعلّقة', $check_attachments);
?>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل شيك</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>الاتجاه</label>
                    <select name="direction" id="edit_direction">
                        <option value="incoming">شيك مستلم (له عندنا)</option>
                        <option value="outgoing">شيك مدفوع (علينا)</option>
                    </select>
                </div>
                <div>
                    <label>اسم الجهة</label>
                    <input type="text" name="party_name" id="edit_party_name" required>
                </div>
                <div>
                    <label>المبلغ</label>
                    <input type="number" step="0.01" name="amount" id="edit_amount" required>
                </div>
                <div>
                    <label>تاريخ الاستحقاق</label>
                    <input type="date" name="due_date" id="edit_due_date" required>
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>البنك</label>
                    <select name="bank_name" id="edit_bank_name">
                        <option value="islami">البنك الإسلامي</option>
                        <option value="jordan_personal">البنك الأردني - شخصي</option>
                    <option value="jordan_business">البنك الأردني - مؤسسة</option>
                        <option value="etihad">بنك الاتحاد</option>
                    </select>
                </div>
                <div>
                    <label>رقم الشيك</label>
                    <input type="text" name="check_number" id="edit_check_number">
                </div>
                <div style="flex:2">
                    <label>ملاحظات</label>
                    <input type="text" name="notes" id="edit_notes">
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>ربط الشيك بحساب (اختياري)</label>
                    <select name="entity_type" id="edit_entity_type">
                        <option value="">بدون ربط - شيك حر</option>
                        <option value="customer">عميل</option>
                        <option value="supplier">مورد</option>
                        <option value="person">شخص (ذمم شخصية)</option>
                    </select>
                </div>
                <div>
                    <label>اسم العميل/المورد/الشخص</label>
                    <input type="text" name="entity_name" id="edit_entity_name">
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
function handleCheckStatusChange(sel) {
    const bankSelect = sel.form.querySelector('.bank-select');
    if (sel.value === 'cleared' || sel.value === 'bounced') {
        // نظهر قائمة اختيار الحساب (بدل الكتابة الحرة اليدوية) ونوقف حتى يختار المستخدم ويضغط تأكيد
        bankSelect.style.display = 'inline-block';
        bankSelect.required = true;
        bankSelect.focus();
    } else {
        bankSelect.style.display = 'none';
        bankSelect.required = false;
        bankSelect.value = '';
        sel.form.submit();
    }
}
function prepareCheckStatus(form) {
    const statusSel = form.querySelector('[name="status"]');
    const bankSelect = form.querySelector('.bank-select');
    if ((statusSel.value === 'cleared' || statusSel.value === 'bounced') && !bankSelect.value) {
        alert('لازم تحدد الحساب (بنك/كاش) قبل ما تأكد الحالة.');
        return false;
    }
    return true;
}
// لما يختار المستخدم الحساب من القائمة الجديدة، نرسل الفورم مباشرة
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('bank-select') && e.target.value) {
        if (prepareCheckStatus(e.target.form)) {
            e.target.form.submit();
        }
    }
});
function openEditTx(data) {
    document.getElementById('edit_tx_id').value = data.id;
    document.getElementById('edit_direction').value = data.direction;
    document.getElementById('edit_party_name').value = data.party_name;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_due_date').value = data.due_date;
    document.getElementById('edit_bank_name').value = data.bank_name;
    document.getElementById('edit_check_number').value = data.check_number || '';
    document.getElementById('edit_notes').value = data.notes || '';
    document.getElementById('edit_entity_type').value = data.entity_type || '';
    document.getElementById('edit_entity_name').value = data.entity_name || '';
    openModal('editModal');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
