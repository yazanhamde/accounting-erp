<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bank_icons.php';
require_login();
$page_title = 'الحسابات البنكية';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        $result = update_bank_transaction(
            $_POST['bank'], (int)$_POST['tx_id'], $_POST['tx_type'], (float)$_POST['amount'],
            $_POST['usage_type'] ?? null, trim($_POST['description']),
            $_POST['tx_date'] ?: date('Y-m-d'), trim($_POST['related_customer'] ?? '')
        );
        if (!$result['success']) { $_SESSION['flash_error'] = $result['message']; }
    } elseif ($action === 'delete') {
        $result = delete_bank_transaction($_POST['bank'], (int)$_POST['tx_id']);
        if (!$result['success']) { $_SESSION['flash_error'] = $result['message']; }
    } elseif ($action === 'adjust_balance') {
        adjust_bank_balance_to_actual(
            $_POST['bank'], (float)$_POST['actual_amount'], $user['id'],
            $_POST['usage_type'] ?? null, $_POST['tx_date'] ?: date('Y-m-d')
        );
    } elseif ($action === 'set_opening_balance') {
        set_opening_balance(
            'bank_' . $_POST['bank'], (float)$_POST['opening_amount'],
            $_POST['opening_date'] ?: date('Y-m-d'), trim($_POST['opening_notes'] ?? ''), $user['id']
        );
    } else {
        add_bank_transaction(
            $_POST['bank'], $_POST['tx_type'], (float)$_POST['amount'],
            $_POST['usage_type'] ?? null, trim($_POST['description']),
            $_POST['tx_date'] ?: date('Y-m-d'), $user['id'], trim($_POST['related_customer'] ?? '')
        );
    }
    header('Location: banks.php?bank=' . urlencode($_POST['bank']));
    exit;
}

$bank_labels = [
    'jordan_personal' => 'بنك الأردن - شخصي',
    'jordan_business' => 'بنك الأردن - مؤسسة',
    'etihad' => 'بنك الاتحاد',
    'islami' => 'البنك الإسلامي',
    'dinarak' => 'محفظة دينارك',
    'investment' => 'البنك الاستثماري',
];

$tables = ['jordan_personal' => 'bank_jordan', 'jordan_business' => 'bank_jordan', 'etihad' => 'bank_etihad', 'islami' => 'bank_islami', 'dinarak' => 'bank_dinarak', 'investment' => 'bank_investment'];
$usage_options = [
    'etihad' => [['transfer','حوالة محل'], ['purchase','مشتريات']],
    'islami' => [['pos_machine','مكينة دفع'], ['check','شيك'], ['other','أخرى']],
    'investment' => [['capital','رأس مال'], ['profit','أرباح'], ['other','أخرى']],
];
// الحسابات يلي نوع الاستخدام فيها ثابت/تلقائي (مش قابل للاختيار) لأنه هو نفسه اسم الحساب
$fixed_usage = ['jordan_personal' => 'personal', 'jordan_business' => 'business'];

require_once __DIR__ . '/includes/pagination.php';
$selected_bank = $_GET['bank'] ?? 'jordan_personal';
$per_page = 100;
$page = get_page_number();
$offset = ($page - 1) * $per_page;
if (isset($fixed_usage[$selected_bank])) {
    $total_tx = paginate_count($db, "SELECT COUNT(*) FROM {$tables[$selected_bank]} WHERE usage_type = ?", [$fixed_usage[$selected_bank]]);
    $total_pages = max(1, (int)ceil($total_tx / $per_page));
    $stmt = $db->prepare("SELECT * FROM {$tables[$selected_bank]} WHERE usage_type = ? ORDER BY tx_date DESC LIMIT {$per_page} OFFSET {$offset}");
    $stmt->execute([$fixed_usage[$selected_bank]]);
    $rows = $stmt->fetchAll();
} else {
    $total_tx = paginate_count($db, "SELECT COUNT(*) FROM {$tables[$selected_bank]}");
    $total_pages = max(1, (int)ceil($total_tx / $per_page));
    $stmt = $db->prepare("SELECT * FROM {$tables[$selected_bank]} ORDER BY tx_date DESC LIMIT {$per_page} OFFSET {$offset}");
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

require __DIR__ . '/includes/header.php';
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<?php if ($flash_error): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    ⚠️ <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>

<div class="panel">
    <h2>➕ تسجيل حركة بنكية</h2>
    <form method="post">
        <div class="form-row">
            <div>
                <label>البنك</label>
                <select name="bank" id="bankSelect" onchange="toggleUsageField(this.value)">
                    <?php foreach ($bank_labels as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $key === $selected_bank ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>نوع الحركة</label>
                <select name="tx_type">
                    <option value="deposit">إيداع</option>
                    <option value="withdraw">سحب</option>
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
            <div id="usageFieldWrap">
                <label>نوع الاستخدام</label>
                <select name="usage_type" id="usageField">
                    <option value="personal">شخصي</option>
                    <option value="business">مؤسسة</option>
                </select>
            </div>
            <div id="fixedUsageHint" style="display:none; align-self:end; color:#64748b; font-size:13px;">
                (نوع الاستخدام محدد تلقائياً حسب الحساب المختار)
            </div>
            <div id="dinarakFieldWrap" style="display:none;">
                <label>اسم الزبون المرتبط بالحوالة</label>
                <input type="text" name="related_customer">
            </div>
            <div style="flex:2">
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
    <h2>🎯 ضبط الرصيد الفعلي - <?= bank_icon($selected_bank) ?> <?= $bank_labels[$selected_bank] ?></h2>
    <p>الرصيد المحسوب حالياً من الحركات: <strong><?= number_format(get_bank_balance($selected_bank), 2) ?></strong></p>
    <form method="post">
        <input type="hidden" name="action" value="adjust_balance">
        <input type="hidden" name="bank" value="<?= $selected_bank ?>">
        <div class="form-row">
            <div>
                <label>الرصيد الفعلي الحالي بالبنك</label>
                <input type="number" step="0.01" name="actual_amount" required>
            </div>
            <?php if (isset($usage_options[$selected_bank])): ?>
            <div>
                <label>نوع الاستخدام (لحركة التسوية)</label>
                <select name="usage_type">
                    <?php foreach ($usage_options[$selected_bank] as $opt): ?>
                        <option value="<?= $opt[0] ?>"><?= $opt[1] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label>التاريخ</label>
                <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div style="align-self:end">
                <button class="btn" type="submit">تسوية الرصيد</button>
            </div>
        </div>
    </form>
    <p style="color:#64748b; font-size:13px;">بيقارن النظام الرصيد المحسوب بالرصيد اللي بتدخله، وبيسجل حركة إيداع أو سحب تلقائياً بالفرق حتى يصير الرصيد مطابق للواقع.</p>
</div>

<?php $opening = get_opening_balance('bank_' . $selected_bank); ?>
<div class="panel">
    <h2>🏁 الرصيد الافتتاحي - <?= bank_icon($selected_bank) ?> <?= $bank_labels[$selected_bank] ?></h2>
    <p style="color:#64748b; font-size:13px; margin-top:-8px;">
        الرصيد اللي كان بالحساب قبل ما تبلش تسجّل حركات عليه بالنظام. بينضاف تلقائياً على كل أرصدة البنك (بالداشبورد والميزانية العمومية).
        <?php if ($opening): ?>
            الرصيد الافتتاحي الحالي: <strong><?= number_format($opening['amount'], 2) ?></strong> بتاريخ <?= $opening['balance_date'] ?>
            <?php if (!empty($opening['notes'])): ?> - <?= htmlspecialchars($opening['notes']) ?><?php endif; ?>
        <?php else: ?>
            ما في رصيد افتتاحي مسجّل لهذا الحساب بعد (يعتبر صفر).
        <?php endif; ?>
    </p>
    <form method="post">
        <input type="hidden" name="action" value="set_opening_balance">
        <input type="hidden" name="bank" value="<?= $selected_bank ?>">
        <div class="form-row">
            <div>
                <label>مبلغ الرصيد الافتتاحي</label>
                <input type="number" step="0.01" name="opening_amount" value="<?= $opening ? $opening['amount'] : '' ?>" required>
            </div>
            <div>
                <label>تاريخ الرصيد الافتتاحي</label>
                <input type="date" name="opening_date" value="<?= $opening ? $opening['balance_date'] : date('Y-m-d') ?>">
            </div>
            <div style="flex:2">
                <label>ملاحظات</label>
                <input type="text" name="opening_notes" value="<?= $opening ? htmlspecialchars($opening['notes'] ?? '') : '' ?>">
            </div>
            <div style="align-self:end">
                <button class="btn" type="submit"><?= $opening ? '✏️ تعديل الرصيد الافتتاحي' : '➕ تسجيل رصيد افتتاحي' ?></button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h2><?= bank_icon($selected_bank) ?> حركات <?= $bank_labels[$selected_bank] ?></h2>
    <div style="margin-bottom:12px;">
        <?php foreach ($bank_labels as $key => $label): ?>
            <a href="banks.php?bank=<?= $key ?>" class="btn btn-sm" style="<?= $key === $selected_bank ? '' : 'background:#94a3b8' ?>"><?= bank_icon($key, 16) ?> <?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <table>
        <tr>
            <th>نوع الحركة</th><th>المبلغ</th>
            <th><?= $selected_bank === 'dinarak' ? 'الزبون' : 'نوع الاستخدام' ?></th>
            <th>الوصف</th><th>التاريخ</th><th>إجراءات</th>
        </tr>
        <?php foreach ($rows as $r): $is_linked = !empty($r['linked_source_table']); ?>
        <tr>
            <td><?= $r['tx_type'] === 'deposit' ? '<span class="badge badge-green">إيداع</span>' : '<span class="badge badge-red">سحب</span>' ?></td>
            <td><?= number_format($r['amount'], 2) ?></td>
            <td><?= htmlspecialchars($selected_bank === 'dinarak' ? ($r['related_customer'] ?? '') : ($r['usage_type'] ?? '')) ?></td>
            <td>
                <?= htmlspecialchars($r['description'] ?? '') ?>
                <?php if ($is_linked): ?>
                    <br><span class="badge badge-yellow" title="حركة تلقائية مرتبطة بجهة تانية - عدّلها/احذفها من صفحتها الأصلية">🔗 مرتبطة تلقائياً (<?= htmlspecialchars(source_table_label($r['linked_source_table'])['name']) ?>)</span>
                <?php endif; ?>
            </td>
            <td><?= $r['tx_date'] ?></td>
            <td class="row-actions">
                <?php if ($is_linked): ?>
                    <span style="color:#94a3b8; font-size:12px;">عدّلها من <?= htmlspecialchars(source_table_label($r['linked_source_table'])['page'] ?? 'صفحتها الأصلية') ?></span>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $r['id'], "tx_type" => $r['tx_type'], "amount" => $r['amount'],
                    "usage_type" => $r['usage_type'] ?? '', "related_customer" => $r['related_customer'] ?? '',
                    "description" => $r['description'], "tx_date" => $r['tx_date'],
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاي الحركة؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="bank" value="<?= $selected_bank ?>">
                    <input type="hidden" name="tx_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php render_pagination($page, $total_pages, ['bank' => $selected_bank]); ?>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل حركة <?= $bank_labels[$selected_bank] ?></h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="bank" value="<?= $selected_bank ?>">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>نوع الحركة</label>
                    <select name="tx_type" id="edit_tx_type">
                        <option value="deposit">إيداع</option>
                        <option value="withdraw">سحب</option>
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
                <?php if ($selected_bank === 'dinarak'): ?>
                <div>
                    <label>اسم الزبون المرتبط بالحوالة</label>
                    <input type="text" name="related_customer" id="edit_related_customer">
                </div>
                <?php elseif (isset($usage_options[$selected_bank])): ?>
                <div>
                    <label>نوع الاستخدام</label>
                    <select name="usage_type" id="edit_usage_type">
                        <?php foreach ($usage_options[$selected_bank] as $opt): ?>
                            <option value="<?= $opt[0] ?>"><?= $opt[1] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="usage_type" value="<?= $fixed_usage[$selected_bank] ?? '' ?>">
                <?php endif; ?>
                <div style="flex:2">
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
    document.getElementById('edit_tx_type').value = data.tx_type;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_description').value = data.description || '';
    document.getElementById('edit_tx_date').value = data.tx_date;
    const usageField = document.getElementById('edit_usage_type');
    if (usageField) usageField.value = data.usage_type || '';
    const custField = document.getElementById('edit_related_customer');
    if (custField) custField.value = data.related_customer || '';
    openModal('editModal');
}
</script>

<script>
function toggleUsageField(bank) {
    const usageWrap = document.getElementById('usageFieldWrap');
    const usageSelect = document.getElementById('usageField');
    const dinarakWrap = document.getElementById('dinarakFieldWrap');
    const fixedHint = document.getElementById('fixedUsageHint');

    const options = {
        etihad: [['transfer','حوالة محل'], ['purchase','مشتريات']],
        islami: [['pos_machine','مكينة دفع'], ['check','شيك'], ['other','أخرى']],
        investment: [['capital','رأس مال'], ['profit','أرباح'], ['other','أخرى']],
        dinarak: []
    };
    const fixedUsage = { jordan_personal: 'personal', jordan_business: 'business' };

    if (bank === 'dinarak') {
        usageWrap.style.display = 'none';
        fixedHint.style.display = 'none';
        dinarakWrap.style.display = 'block';
    } else if (fixedUsage[bank]) {
        usageWrap.style.display = 'none';
        dinarakWrap.style.display = 'none';
        fixedHint.style.display = 'block';
        usageSelect.innerHTML = `<option value="${fixedUsage[bank]}" selected>${fixedUsage[bank]}</option>`;
    } else {
        usageWrap.style.display = 'block';
        fixedHint.style.display = 'none';
        dinarakWrap.style.display = 'none';
        usageSelect.innerHTML = options[bank].map(o => `<option value="${o[0]}">${o[1]}</option>`).join('');
    }
}
document.addEventListener('DOMContentLoaded', () => {
    toggleUsageField(document.getElementById('bankSelect').value);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
