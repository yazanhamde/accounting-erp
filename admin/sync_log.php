<?php
require_once __DIR__ . '/../includes/pos_sync.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'مزامنة POS';
$db = get_db();

// أسماء المزامنتين التلقائيتين - الأعداد بتجي من .env (نفس قيم الكرون)
$sup_batch  = (int) env('SUPPLIER_LEDGER_BATCH_SIZE', 28);
$cust_batch = (int) env('CUSTOMER_LEDGER_BATCH_SIZE', 30);

$runs = $db->query('SELECT * FROM pos_sync_runs ORDER BY id DESC LIMIT 20')->fetchAll();
$selected_run_id = isset($_GET['run_id']) ? (int)$_GET['run_id'] : ($runs[0]['id'] ?? 0);

$items = [];
if ($selected_run_id) {
    $stmt = $db->prepare('SELECT * FROM pos_sync_items WHERE run_id = ? ORDER BY id ASC');
    $stmt->execute([$selected_run_id]);
    $items = $stmt->fetchAll();
}

require __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <h2>🔄 مزامنة العملاء / الموردين مع نظام POS</h2>
    <p>المزامنة تلقائية بالكامل (Cron Job) ومافيش تشغيل يدوي ولا مزامنة من تاريخ لتاريخ. العملاء والموردين منفصلين تماماً عن بعض، وهون بتراقب نتيجة كل تشغيلة.</p>
    <ul style="margin:10px 0 0;padding-inline-start:20px;font-size:14px;line-height:1.9">
        <li>🚚 <b>مزامنتين موردين</b> - كل مزامنة <?= $sup_batch ?> مورد</li>
        <li>👤 <b>مزامنات عملاء</b> - كل مزامنة <?= $cust_batch ?> عميل</li>
    </ul>
</div>

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
                        'daily_cron_suppliers' => "🚚 مزامنتين موردين - كل مزامنة {$sup_batch} مورد",
                        'daily_cron_customers' => "👤 مزامنات عملاء - كل مزامنة {$cust_batch} عميل",
                        // أنواع قديمة (سجلات تاريخية فقط - الميزة انحذفت)
                        'full_history' => '🕰️ تاريخية كاملة (قديمة)',
                        'scheduled' => '⏱️ تلقائية (قديمة)',
                        'manual' => '▶️ يدوية (قديمة)',
                        'manual_suppliers' => '🚚 يدوية للموردين (قديمة)',
                        'manual_customers' => '👤 يدوية للعملاء (قديمة)',
                        'manual_customer_ledger' => '👤 يدوية للعملاء (قديمة)',
                        'from_date' => '📅 من تاريخ محدد (قديمة)',
                        'from_date_cli' => '📅 من تاريخ محدد (قديمة)',
                        'from_date_customers' => '📅 من تاريخ - عملاء (قديمة)',
                        'from_date_suppliers' => '📅 من تاريخ - موردين (قديمة)',
                        'historical_balances_only' => '🧮 نقل رصيد فقط (قديمة)',
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
                <td><a href="/accounting-system/admin/sync_log.php?run_id=<?= $r['id'] ?>">🔍 التفاصيل</a></td>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
