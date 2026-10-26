<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'توزيع الأرباح على المساهمين';
$user = current_user();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if ($action === 'edit') {
        update_profit_distribution(
            (int)$_POST['tx_id'], trim($_POST['name']), (float)$_POST['amount'], trim($_POST['period_label']),
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'),
            $_POST['paid_from_bank'] ?: null, trim($_POST['no_bank_reason'] ?? '') ?: null
        );
    } elseif ($action === 'delete') {
        delete_profit_distribution((int)$_POST['tx_id']);
    } elseif ($action === 'contrib_add') {
        $result = add_shareholder_contribution(
            trim($_POST['contrib_name']), (float)$_POST['contrib_amount'],
            trim($_POST['contrib_description']), $_POST['contrib_tx_date'] ?: date('Y-m-d'), $user['id'],
            $_POST['contrib_paid_from_bank'] ?: null,
            trim($_POST['contrib_no_bank_reason'] ?? '') ?: null
        );
        if (!$result['success']) {
            header('Location: profits.php?error=' . urlencode($result['message']));
            exit;
        }
    } elseif ($action === 'contrib_edit') {
        update_shareholder_contribution(
            (int)$_POST['contrib_id'], trim($_POST['contrib_name']), (float)$_POST['contrib_amount'],
            trim($_POST['contrib_description']), $_POST['contrib_tx_date'] ?: date('Y-m-d'),
            $_POST['contrib_paid_from_bank'] ?: null, trim($_POST['contrib_no_bank_reason'] ?? '') ?: null
        );
    } elseif ($action === 'contrib_delete') {
        delete_shareholder_contribution((int)$_POST['contrib_id']);
    } else {
        $result = add_profit_distribution(
            trim($_POST['name']), (float)$_POST['amount'], trim($_POST['period_label']),
            trim($_POST['description']), $_POST['tx_date'] ?: date('Y-m-d'), $user['id'],
            $_POST['paid_from_bank'] ?: null,
            trim($_POST['no_bank_reason'] ?? '') ?: null
        );
        if (!$result['success']) {
            header('Location: profits.php?error=' . urlencode($result['message']));
            exit;
        }
    }
    header('Location: profits.php');
    exit;
}

require_once __DIR__ . '/includes/pagination.php';
$per_page = 50;
$shareholders = $db->query('SELECT * FROM shareholders ORDER BY name')->fetchAll();

$dist_page = get_page_number('pd');
$dist_total = paginate_count($db, 'SELECT COUNT(*) FROM profit_distributions');
$dist_total_pages = max(1, (int)ceil($dist_total / $per_page));
$dist_offset = ($dist_page - 1) * $per_page;
$stmt = $db->prepare('SELECT pd.*, s.name FROM profit_distributions pd JOIN shareholders s ON s.id = pd.shareholder_id ORDER BY pd.tx_date DESC LIMIT ' . $per_page . ' OFFSET ' . $dist_offset);
$stmt->execute();
$distributions = $stmt->fetchAll();

$contrib_page = get_page_number('pc');
$contrib_total = paginate_count($db, 'SELECT COUNT(*) FROM shareholder_contributions');
$contrib_total_pages = max(1, (int)ceil($contrib_total / $per_page));
$contrib_offset = ($contrib_page - 1) * $per_page;
$stmt = $db->prepare('SELECT sc.*, s.name FROM shareholder_contributions sc JOIN shareholders s ON s.id = sc.shareholder_id ORDER BY sc.tx_date DESC LIMIT ' . $per_page . ' OFFSET ' . $contrib_offset);
$stmt->execute();
$contributions = $stmt->fetchAll();
require __DIR__ . '/includes/header.php';
?>

<?php if (!empty($_GET['error'])): ?>
<div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    <strong>⚠️ ما انحفظت الحركة:</strong> <?= htmlspecialchars($_GET['error']) ?>
</div>
<?php endif; ?>
<div class="panel">
    <h2>💸 سحب توزيع أرباح (فلوس طالعة من الشركة للمساهم)</h2>
    <form method="post" id="txForm">
        <div class="form-row">
            <div>
                <label>اسم المساهم/الشريك</label>
                <input type="text" name="name" list="shareholdersList" required>
                <datalist id="shareholdersList">
                    <?php foreach ($shareholders as $s): ?>
                        <option value="<?= htmlspecialchars($s['name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label>المبلغ</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div>
                <label>الفترة (مثال: أرباح حزيران 2026)</label>
                <input type="text" name="period_label">
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>من أي حساب اتوزّعت؟</label>
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
    <h2>📋 آخر التوزيعات</h2>
    <table>
        <tr><th>الشريك</th><th>المبلغ</th><th>الفترة</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php foreach ($distributions as $d): ?>
        <tr>
            <td><?= htmlspecialchars($d['name']) ?></td>
            <td><?= number_format($d['amount'], 2) ?></td>
            <td><?= htmlspecialchars($d['period_label'] ?? '') ?></td>
            <td><?= $d['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditTx(<?= htmlspecialchars(json_encode([
                    "id" => $d['id'], "name" => $d['name'], "amount" => $d['amount'],
                    "period_label" => $d['period_label'], "description" => $d['description'], "tx_date" => $d['tx_date'],
                    "paid_from_bank" => $d['paid_from_bank'], "no_bank_reason" => $d['no_bank_reason'],
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاد التوزيع؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tx_id" value="<?= $d['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php render_pagination($dist_page, $dist_total_pages, [], 'pd'); ?>
</div>

<div class="panel">
    <h2>💰 رصيد كل مساهم (الفلوس اللي حاطينها بالشركة ولسا ما اتوزّعت)</h2>
    <table>
        <tr><th>المساهم</th><th>الرصيد الحالي</th><th>ملاحظة</th></tr>
        <?php foreach ($shareholders as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><?= number_format($s['capital_amount'], 2) ?></td>
            <td>
                <?php if ($s['capital_amount'] < 0): ?>
                    <span class="badge badge-red">سحب أكتر من رصيده</span>
                <?php else: ?>
                    <span class="badge badge-green">طبيعي</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p style="font-size:12px;color:#94a3b8;margin-top:8px;">
        الرصيد بيزيد لما المساهم يحط فلوس بالشركة (بالأسفل)، وبينقص لما ياخد توزيع أرباح (بالأعلى).<br>
        💡 <b>لتسجيل ربح الشهر بدون فلوس فعلية داخلة</b> (لأنه الفلوس أصلاً موجودة بحساب الشركة، بس هلق عم تحتسبها "حق المساهم"): سيب حقل "دخلت لـ" فاضي واكتب بخانة السبب مثلاً "أرباح شهر 7" - رصيد المساهم بيزيد بدون ما يتأثر أي بنك. وقت ما تسلّمه الفلوس فعلياً، استخدم فورم "توزيع أرباح" فوق واختار البنك/الكاش الحقيقي.
    </p>
</div>

<div class="panel">
    <h2>➕ تسجيل مساهمة / إضافة فلوس من مساهم (فلوس داخلة للشركة)</h2>
    <form method="post" id="contribForm">
        <input type="hidden" name="action" value="contrib_add">
        <div class="form-row">
            <div>
                <label>اسم المساهم/الشريك</label>
                <input type="text" name="contrib_name" list="shareholdersList" required>
            </div>
            <div>
                <label>المبلغ</label>
                <input type="number" step="0.01" name="contrib_amount" required>
            </div>
            <div>
                <label>التاريخ</label>
                <input type="date" name="contrib_tx_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>دخلت لـ</label>
                <select name="contrib_paid_from_bank" id="contrib_paid_from_bank_select">
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
            <div id="contrib_no_bank_reason_wrap" style="display:none">
                <label>سبب عدم تحديد البنك (إجباري)</label>
                <input type="text" name="contrib_no_bank_reason" id="contrib_no_bank_reason_input" placeholder="اكتب السبب">
            </div>
        </div>
        <div class="form-row">
            <div style="flex:3">
                <label>ملاحظات</label>
                <input type="text" name="contrib_description">
            </div>
            <div style="align-self:end">
                <button class="btn" type="submit">حفظ</button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h2>📋 سجل المساهمات (فلوس داخلة من المساهمين)</h2>
    <table>
        <tr><th>المساهم</th><th>المبلغ</th><th>الملاحظات</th><th>التاريخ</th><th>إجراءات</th></tr>
        <?php foreach ($contributions as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td><?= number_format($c['amount'], 2) ?></td>
            <td><?= htmlspecialchars($c['description'] ?? '') ?></td>
            <td><?= $c['tx_date'] ?></td>
            <td class="row-actions">
                <button type="button" class="btn btn-sm btn-edit" onclick='openEditContrib(<?= htmlspecialchars(json_encode([
                    "id" => $c['id'], "name" => $c['name'], "amount" => $c['amount'],
                    "description" => $c['description'], "tx_date" => $c['tx_date'],
                    "paid_from_bank" => $c['paid_from_bank'], "no_bank_reason" => $c['no_bank_reason'],
                ]), ENT_QUOTES, "UTF-8") ?>)'>✏️ تعديل</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('متأكد إنك بدك تحذف هاي المساهمة؟');">
                    <input type="hidden" name="action" value="contrib_delete">
                    <input type="hidden" name="contrib_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-delete">🗑️ حذف</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php render_pagination($contrib_page, $contrib_total_pages, [], 'pc'); ?>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏️ تعديل توزيع أرباح</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="tx_id" id="edit_tx_id">
            <div class="form-row">
                <div>
                    <label>اسم المساهم/الشريك</label>
                    <input type="text" name="name" id="edit_name" list="shareholdersList" required>
                </div>
                <div>
                    <label>المبلغ</label>
                    <input type="number" step="0.01" name="amount" id="edit_amount" required>
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>الفترة</label>
                    <input type="text" name="period_label" id="edit_period_label">
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
                    <label>من أي حساب اتوزّعت؟</label>
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

<div class="modal-overlay" id="editContribModal">
    <div class="modal-box">
        <h3>✏️ تعديل مساهمة</h3>
        <form method="post" id="editContribForm">
            <input type="hidden" name="action" value="contrib_edit">
            <input type="hidden" name="contrib_id" id="edit_contrib_id">
            <div class="form-row">
                <div>
                    <label>اسم المساهم/الشريك</label>
                    <input type="text" name="contrib_name" id="edit_contrib_name" list="shareholdersList" required>
                </div>
                <div>
                    <label>المبلغ</label>
                    <input type="number" step="0.01" name="contrib_amount" id="edit_contrib_amount" required>
                </div>
            </div>
            <div class="form-row">
                <div>
                    <label>التاريخ</label>
                    <input type="date" name="contrib_tx_date" id="edit_contrib_tx_date">
                </div>
                <div>
                    <label>دخلت لـ</label>
                    <select name="contrib_paid_from_bank" id="edit_contrib_paid_from_bank_select">
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
            </div>
            <div class="form-row">
                <div id="edit_contrib_no_bank_reason_wrap" style="display:none">
                    <label>سبب عدم تحديد البنك (إجباري)</label>
                    <input type="text" name="contrib_no_bank_reason" id="edit_contrib_no_bank_reason_input" placeholder="اكتب السبب">
                </div>
                <div style="flex:3">
                    <label>ملاحظات</label>
                    <input type="text" name="contrib_description" id="edit_contrib_description">
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn" type="submit">حفظ التعديل</button>
                <button class="btn btn-cancel" type="button" onclick="closeModal('editContribModal')">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditTx(data) {
    document.getElementById('edit_tx_id').value = data.id;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_period_label').value = data.period_label || '';
    document.getElementById('edit_description').value = data.description || '';
    document.getElementById('edit_tx_date').value = data.tx_date;
    document.getElementById('edit_paid_from_bank_select').value = data.paid_from_bank || '';
    document.getElementById('edit_no_bank_reason_input').value = data.no_bank_reason || '';
    document.getElementById('edit_paid_from_bank_select').dispatchEvent(new Event('change'));
    openModal('editModal');
}

function openEditContrib(data) {
    document.getElementById('edit_contrib_id').value = data.id;
    document.getElementById('edit_contrib_name').value = data.name;
    document.getElementById('edit_contrib_amount').value = data.amount;
    document.getElementById('edit_contrib_description').value = data.description || '';
    document.getElementById('edit_contrib_tx_date').value = data.tx_date;
    document.getElementById('edit_contrib_paid_from_bank_select').value = data.paid_from_bank || '';
    document.getElementById('edit_contrib_no_bank_reason_input').value = data.no_bank_reason || '';
    document.getElementById('edit_contrib_paid_from_bank_select').dispatchEvent(new Event('change'));
    openModal('editContribModal');
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
setupBankReasonToggle('contrib_paid_from_bank_select', 'contrib_no_bank_reason_wrap', 'contrib_no_bank_reason_input');
setupBankReasonToggle('edit_contrib_paid_from_bank_select', 'edit_contrib_no_bank_reason_wrap', 'edit_contrib_no_bank_reason_input');
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
