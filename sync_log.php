<?php
require_once __DIR__ . '/includes/pos_sync.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'مزامنة POS';
$db = get_db();

$manual_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_now_suppliers') {
    // مزامنة سريعة يدوية للموردين لحالهم (آخر يوم بس)
    try {
        $manual_result = pos_run_suppliers_sync(null, 'manual_suppliers');
    } catch (Throwable $e) {
        $manual_result = ['status' => 'failed', 'errors' => [$e->getMessage()], 'counts' => []];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_now_customers') {
    // مزامنة سريعة يدوية للعملاء لحالهم (آخر يوم بس) - عن طريق كشف الحساب الدقيق (customer_statement)، نفس منطق الموردين بالضبط
    try {
        $manual_result = pos_run_customer_ledgers_sync(null, 'manual_customers');
    } catch (Throwable $e) {
        $manual_result = ['status' => 'failed', 'errors' => [$e->getMessage()], 'counts' => []];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_from_date_suppliers') {
    // مزامنة تاريخية للموردين لحالهم فقط (بدون عملاء)
    set_time_limit(0);
    ignore_user_abort(true);
    $from_date = trim($_POST['from_date_suppliers'] ?? '');
    $cutoff_ts = $from_date !== '' ? strtotime($from_date . ' 00:00:00') : false;
    $opening_cutoff_ts = strtotime(OPENING_BALANCE_CUTOFF_DATE . ' 00:00:00');
    if ($cutoff_ts === false) {
        $manual_result = ['status' => 'failed', 'errors' => ['تاريخ غير صالح'], 'counts' => []];
    } elseif ($cutoff_ts < $opening_cutoff_ts) {
        // حماية من تكرار الفواتير القديمة يلي ملخّصة أصلاً بحركة "رصيد افتتاحي"
        $manual_result = ['status' => 'failed', 'errors' => ['التاريخ يلي حددته قبل تاريخ الرصيد الافتتاحي المجمّع (' . OPENING_BALANCE_CUTOFF_DATE . '). سحب فواتير من قبل هاد التاريخ رح يكرر المبلغ (مرة بالرصيد الافتتاحي، ومرة بالفواتير التفصيلية). استخدم تاريخ ' . OPENING_BALANCE_CUTOFF_DATE . ' أو بعده.'], 'counts' => []];
    } else {
        try {
            $manual_result = pos_run_suppliers_sync($cutoff_ts, 'from_date_suppliers');
        } catch (Throwable $e) {
            $manual_result = ['status' => 'failed', 'errors' => [$e->getMessage()], 'counts' => []];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_range_customers') {
    // مزامنة تاريخية للعملاء لحالهم فقط، بمدى تاريخين (من - لغاية) بدل "لغاية اليوم" دايماً
    // - مفيدة لتقسيم مزامنة طويلة (مثلاً من 2024) لدفعات أصغر بتخلص أسرع وبتقلل خطر timeout
    set_time_limit(0);
    ignore_user_abort(true);
    $from_date = trim($_POST['range_from_date_customers'] ?? '');
    $to_date = trim($_POST['range_to_date_customers'] ?? '');
    $cutoff_ts = $from_date !== '' ? strtotime($from_date . ' 00:00:00') : false;
    $end_ts = $to_date !== '' ? strtotime($to_date . ' 23:59:59') : false;
    $opening_cutoff_ts = strtotime(OPENING_BALANCE_CUTOFF_DATE . ' 00:00:00');
    if ($cutoff_ts === false || $end_ts === false) {
        $manual_result = ['status' => 'failed', 'errors' => ['لازم تحدد تاريخ بداية ونهاية صحيحين'], 'counts' => []];
    } elseif ($end_ts < $cutoff_ts) {
        $manual_result = ['status' => 'failed', 'errors' => ['تاريخ النهاية لازم يكون بعد تاريخ البداية'], 'counts' => []];
    } elseif ($cutoff_ts < $opening_cutoff_ts) {
        // حماية من تكرار الفواتير القديمة يلي ملخّصة أصلاً بحركة "رصيد افتتاحي"
        $manual_result = ['status' => 'failed', 'errors' => ['تاريخ البداية يلي حددته قبل تاريخ الرصيد الافتتاحي المجمّع (' . OPENING_BALANCE_CUTOFF_DATE . '). سحب فواتير من قبل هاد التاريخ رح يكرر المبلغ. استخدم تاريخ ' . OPENING_BALANCE_CUTOFF_DATE . ' أو بعده.'], 'counts' => []];
    } else {
        try {
            $manual_result = pos_run_customer_ledgers_sync($cutoff_ts, 'from_date_customers', $end_ts);
        } catch (Throwable $e) {
            $manual_result = ['status' => 'failed', 'errors' => [$e->getMessage()], 'counts' => []];
        }
    }
}

$runs = $db->query('SELECT * FROM pos_sync_runs ORDER BY id DESC LIMIT 20')->fetchAll();
$selected_run_id = isset($_GET['run_id']) ? (int)$_GET['run_id'] : ($runs[0]['id'] ?? 0);

$items = [];
if ($selected_run_id) {
    $stmt = $db->prepare('SELECT * FROM pos_sync_items WHERE run_id = ? ORDER BY id ASC');
    $stmt->execute([$selected_run_id]);
    $items = $stmt->fetchAll();
}

require __DIR__ . '/includes/header.php';
?>

<div class="panel">
    <h2>🔄 مزامنة العملاء / الموردين مع نظام POS</h2>
    <p>مزامنة العملاء والموردين منفصلة تماماً عن بعض، ومافيش مزامنة تلقائية إطلاقاً - كل شي يدوي بالكامل من هون. تقدر تشغّل مزامنة يدوية سريعة (آخر يوم) أو مزامنة تاريخية من تاريخ محدد، وتراقب كل تحديث.</p>

    <h3 style="margin-top:18px;font-size:15px">▶️ مزامنة يدوية سريعة (آخر يوم بس)</h3>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <form method="post" onsubmit="return posSyncGuard(this, '🚚 جاري المزامنة...')">
            <input type="hidden" name="action" value="run_now_suppliers">
            <button type="submit" class="btn" style="background:#7c3aed">🚚 مزامنة يدوية للموردين</button>
        </form>
        <form method="post" onsubmit="return posSyncGuard(this, '👤 جاري المزامنة...')">
            <input type="hidden" name="action" value="run_now_customers">
            <button type="submit" class="btn" style="background:#2563eb">👤 مزامنة يدوية للعملاء (كشف حساب دقيق)</button>
        </form>
    </div>
    <p style="font-size:13px;color:#666;margin:6px 0 0">(كل زر مستقل عن التاني تماماً - سريعة، بتجيب آخر يوم بس)</p>

    <hr style="margin:16px 0;border:none;border-top:1px solid #eee">

    <h3 style="font-size:15px">📅 مزامنة تاريخية (من تاريخ محدد لليوم)</h3>
    <p style="font-size:13px;color:#b00;background:#fff5f5;padding:8px 10px;border-radius:6px;margin-top:8px">🔒 ما تقدر تسحب من قبل تاريخ <?= OPENING_BALANCE_CUTOFF_DATE ?> (تاريخ الرصيد الافتتاحي المجمّع) - سحب فواتير أقدم رح يكررها فوق الرصيد الافتتاحي.</p>
    <form method="post" onsubmit="return confirm('مزامنة تاريخية للعملاء فقط بالمدى المحدد. متأكد بدك تكمل؟') &amp;&amp; posSyncGuard(this, '👤 جاري المزامنة... قد تاخد وقت، لا تسكّر الصفحة')" style="margin-top:16px;display:flex;gap:10px;align-items:end;flex-wrap:wrap;background:#f8fafc;padding:12px;border-radius:8px">
        <input type="hidden" name="action" value="run_range_customers">
        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px">من تاريخ</label>
            <input type="date" name="range_from_date_customers" min="<?= OPENING_BALANCE_CUTOFF_DATE ?>" value="<?= OPENING_BALANCE_CUTOFF_DATE ?>" required>
        </div>
        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px">لغاية تاريخ</label>
            <input type="date" name="range_to_date_customers" min="<?= OPENING_BALANCE_CUTOFF_DATE ?>" required>
        </div>
        <button type="submit" class="btn" style="background:#0891b2">👤 مزامنة العملاء بمدى محدد (دفعة تاريخية)</button>
    </form>
    <p style="font-size:13px;color:#666;margin:6px 0 0">(بتسحب المدى المحدد بس، مش لغاية اليوم - مفيدة لتقسيم مزامنة طويلة لدفعات صغيرة، مثلاً كل 6 شهور لحالها، حتى تتفادى أي بطء أو timeout)</p>

    <form method="post" onsubmit="return confirm('مزامنة تاريخية للموردين فقط (بدون عملاء) من التاريخ المحدد لليوم. متأكد بدك تكمل؟') &amp;&amp; posSyncGuard(this, '🚚 جاري المزامنة التاريخية... قد تاخد وقت، لا تسكّر الصفحة')" style="margin-top:16px;display:flex;gap:10px;align-items:end;flex-wrap:wrap">
        <input type="hidden" name="action" value="run_from_date_suppliers">
        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px">من تاريخ (موردين فقط)</label>
            <input type="date" name="from_date_suppliers" min="<?= OPENING_BALANCE_CUTOFF_DATE ?>" value="<?= OPENING_BALANCE_CUTOFF_DATE ?>" required>
        </div>
        <button type="submit" class="btn" style="background:#7c3aed">🚚 مزامنة تاريخية للموردين لحالهم</button>
    </form>
    <p style="font-size:13px;color:#666;margin:6px 0 0">(بتجيب الموردين وحركات مشترياتهم بس - ما بتلمس العملاء ولا المبيعات إطلاقاً)</p>

    <?php if ($manual_result): ?>
        <div style="margin-top:12px;padding:10px;border-radius:8px;background:<?= $manual_result['status'] === 'skipped' ? '#fff9e6' : ($manual_result['status'] === 'success' ? '#eafff0' : '#fff5f5') ?>">
            <?php if ($manual_result['status'] === 'skipped'): ?>
                ⏳ <?= htmlspecialchars(implode(' | ', $manual_result['errors'])) ?>
            <?php elseif (!empty($manual_result['errors'])): ?>
                ⚠️ صار خطأ: <?= htmlspecialchars(implode(' | ', $manual_result['errors'])) ?>
            <?php else: ?>
                ✅ تمت المزامنة بنجاح.
                <?php if (isset($manual_result['counts']['balances_set'])): ?>
                    (أرصدة اتضبطت: <?= (int) $manual_result['counts']['balances_set'] ?>)
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// حماية بالواجهة: بمجرد ما يضغط المستخدم زر مزامنة، بنعطّله فوراً حتى ما
// يقدر يكبسه مرتين وراء بعض بالغلط. هاي طبقة إضافية بس - الحماية الحقيقية
// من التكرار موجودة بقاعدة البيانات (قفل GET_LOCK) حتى لو حدا فتح تبويبين.
function posSyncGuard(form, loadingText) {
    var btn = form.querySelector('button[type="submit"]');
    if (btn) {
        if (btn.disabled) return false; // ضغط مرتين بسرعة - تجاهل الضغطة التانية
        btn.disabled = true;
        btn.dataset.originalText = btn.textContent;
        btn.textContent = loadingText;
    }
    return true;
}
</script>

<div class="panel">
    <h3>📋 آخر 20 عملية مزامنة</h3>
    <table class="table">
        <thead>
            <tr>
                <th>الوقت</th>
                <th>النوع</th>
                <th>الحالة</th>
                <th>عملاء (جديد/تحديث)</th>
                <th>موردين (جديد/تحديث)</th>
                <th>حركات مبيعات</th>
                <th>حركات مشتريات</th>
                <th>أرصدة مضبوطة</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($runs as $r): ?>
            <tr style="<?= $r['id'] == $selected_run_id ? 'background:#f0f7ff' : '' ?>">
                <td><?= htmlspecialchars($r['started_at']) ?></td>
                <td>
                    <?php
                    $type_badge = [
                        'full_history' => '🕰️ تاريخية كاملة (قديمة)',
                        'scheduled' => '⏱️ تلقائية (قديمة)',
                        'manual' => '▶️ يدوية مشتركة (قديمة)',
                        'manual_suppliers' => '🚚 يدوية للموردين',
                        'manual_customers' => '👤 يدوية للعملاء',
                        'from_date' => '📅 من تاريخ محدد (قديمة)',
                        'from_date_cli' => '📅 من تاريخ محدد (CLI)',
                        'from_date_customers' => '👤 من تاريخ محدد (عملاء فقط)',
                        'from_date_customers_range' => '👤 دفعة تاريخية بمدى محدد (عملاء فقط)',
                        'from_date_suppliers' => '🚚 من تاريخ محدد (موردين فقط)',
                        'daily_cron_suppliers' => '🌙 يومية تلقائية للموردين (12 بالليل)',
                        'daily_cron_customers' => '🌅 يومية تلقائية للعملاء (5 الفجر)',
                        'historical_balances_only' => '🧮 نقل رصيد فقط (بدون حركات)',
                    ];
                    echo $type_badge[$r['sync_type'] ?? 'manual'] ?? ($r['sync_type'] ?? '-');
                    ?>
                </td>
                <td>
                    <?php
                    $badge = ['success' => '✅ نجحت', 'partial' => '⚠️ جزئية', 'failed' => '❌ فشلت', 'running' => '⏳ شغّالة'];
                    echo $badge[$r['status']] ?? $r['status'];
                    ?>
                </td>
                <td><?= (int)$r['customers_new'] ?> / <?= (int)$r['customers_updated'] ?></td>
                <td><?= (int)$r['suppliers_new'] ?> / <?= (int)$r['suppliers_updated'] ?></td>
                <td><?= (int)$r['sales_tx_new'] ?></td>
                <td><?= (int)$r['purchases_tx_new'] ?></td>
                <td><?= isset($r['balances_set']) ? (int)$r['balances_set'] : '-' ?></td>
                <td><a href="sync_log.php?run_id=<?= $r['id'] ?>">🔍 التفاصيل</a></td>
            </tr>
            <?php if ($r['error_message']): ?>
            <tr><td colspan="8" style="color:#b00;font-size:13px">⚠️ <?= htmlspecialchars($r['error_message']) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($selected_run_id): ?>
<div class="panel">
    <h3>🧾 تفاصيل عملية المزامنة #<?= $selected_run_id ?></h3>
    <?php if (empty($items)): ?>
        <p>ما انسجل أي شي جديد بهاي المزامنة (كل شي كان محدّث من قبل).</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>النوع</th><th>العملية</th><th>الوصف</th><th>الوقت</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <?php
                $type_labels = [
                    'customer' => '👤 عميل', 'supplier' => '🚚 مورد',
                    'customer_transaction' => '🧾 حركة عميل', 'supplier_transaction' => '📦 حركة مورد',
                    'customer_balance' => '🧮 ضبط رصيد عميل',
                ];
                ?>
                <tr>
                    <td><?= $type_labels[$it['entity_type']] ?? $it['entity_type'] ?></td>
                    <td><?= $it['action'] === 'insert' ? '➕ جديد' : ($it['action'] === 'set' ? '🧮 ضبط رصيد' : '✏️ تحديث') ?></td>
                    <td><?= htmlspecialchars($it['label']) ?></td>
                    <td><?= htmlspecialchars($it['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
