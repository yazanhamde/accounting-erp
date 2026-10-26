<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/report_data.php';
require_once __DIR__ . '/includes/xlsx_writer.php';

$month = $_GET['month'] ?? date('Y-m');
$report = get_monthly_report_data($month);

$xlsx = new SimpleXlsxWriter();

// ---- شيت الملخص ----
$t = $report['totals'];
$summaryRows = [
    ['تحصيل من العملاء', $t['customers_credit']],
    ['مبيعات/ديون جديدة على العملاء', $t['customers_debit']],
    ['مدفوع للموردين', $t['suppliers_credit']],
    ['ديون جديدة من الموردين', $t['suppliers_debit']],
    ['سلف شخصية معطاة', $t['personal_lend']],
    ['تسديدات شخصية مستلمة', $t['personal_repay']],
    ['أرباح موزّعة', $t['profits_paid']],
    ['مصاريف الشهر', $t['expenses_total']],
    ['إيرادات خارجية', $t['external_revenues_total']],
    ['إجمالي إيداعات البنوك', $t['banks_deposit']],
    ['إجمالي سحوبات البنوك', $t['banks_withdraw']],
];
$xlsx->addSheet('الملخص', ['البند', 'المبلغ'], $summaryRows);

// ---- شيت ذمم العملاء ----
$rows = array_map(fn($r) => [$r['tx_date'], $r['party'], $r['type'] === 'credit' ? 'تسديد' : 'دين جديد', $r['amount'], $r['description'] ?? ''], $report['customers']);
$xlsx->addSheet('ذمم العملاء', ['التاريخ', 'الاسم', 'النوع', 'المبلغ', 'الوصف'], $rows);

// ---- شيت ذمم الموردين ----
$rows = array_map(fn($r) => [$r['tx_date'], $r['party'], $r['type'] === 'credit' ? 'تسديد لنا' : 'دين جديد', $r['amount'], $r['description'] ?? ''], $report['suppliers']);
$xlsx->addSheet('ذمم الموردين', ['التاريخ', 'الاسم', 'النوع', 'المبلغ', 'الوصف'], $rows);

// ---- شيت دائن ومدين شخصي ----
$rows = array_map(fn($r) => [$r['tx_date'], $r['party'], $r['type'] === 'lend' ? 'سلفة' : 'تسديد', $r['amount'], $r['description'] ?? ''], $report['personal']);
$xlsx->addSheet('دائن ومدين شخصي', ['التاريخ', 'الاسم', 'النوع', 'المبلغ', 'الوصف'], $rows);

// ---- شيت الأرباح ----
$rows = array_map(fn($r) => [$r['tx_date'], $r['party'], $r['amount'], $r['period_label'] ?? '', $r['description'] ?? ''], $report['profits']);
$xlsx->addSheet('توزيع الأرباح', ['التاريخ', 'الشريك', 'المبلغ', 'الفترة', 'الوصف'], $rows);

// ---- شيت المصاريف ----
$rows = array_map(fn($r) => [$r['tx_date'], $r['category'], $r['amount'], $r['paid_from_bank'] ?? '', $r['description'] ?? ''], $report['expenses']);
$xlsx->addSheet('المصاريف', ['التاريخ', 'النوع', 'المبلغ', 'مدفوع من', 'الوصف'], $rows);

// ---- شيت الإيرادات الخارجية ----
$rows = array_map(fn($r) => [$r['tx_date'], $r['source_person'], $r['amount'], $r['paid_from_bank'] ?? '', $r['description'] ?? ''], $report['external_revenues']);
$xlsx->addSheet('إيرادات خارجية', ['التاريخ', 'المصدر', 'المبلغ', 'دخلت لـ', 'الوصف'], $rows);

// ---- شيت البنوك (كل بنك بصفوف بعد بعض بعمود يوضح اسم البنك) ----
$bankRows = [];
foreach ($report['banks'] as $bankLabel => $txs) {
    foreach ($txs as $r) {
        $bankRows[] = [$bankLabel, $r['tx_date'], $r['tx_type'] === 'deposit' ? 'إيداع' : 'سحب', $r['amount'], $r['description'] ?? ''];
    }
}
$xlsx->addSheet('الحسابات البنكية', ['البنك', 'التاريخ', 'النوع', 'المبلغ', 'الوصف'], $bankRows);

// ---- شيت الشيكات ----
$rows = array_map(fn($r) => [$r['due_date'], $r['party_name'], $r['direction'] === 'incoming' ? 'مستلم' : 'مدفوع', $r['amount'], $r['bank_name'] ?? '', $r['status']], $report['checks']);
$xlsx->addSheet('الشيكات', ['التاريخ', 'الجهة', 'الاتجاه', 'المبلغ', 'البنك', 'الحالة'], $rows);

// ---- شيت صندوق الكاش ----
$rows = array_map(fn($r) => [$r['count_date'], $r['expected_amount'], $r['actual_amount'], $r['difference'] >= 0 ? 'زيادة' : 'عجز', abs($r['difference']), $r['notes'] ?? ''], $report['cash_box']);
$xlsx->addSheet('صندوق الكاش', ['التاريخ', 'المفروض', 'الفعلي', 'الحالة', 'الفرق', 'ملاحظات'], $rows);

$xlsx->output('تقرير-' . $month . '.xlsx');
