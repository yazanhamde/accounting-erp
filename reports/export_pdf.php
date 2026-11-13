<?php
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/report_data.php';
require_once __DIR__ . '/../vendor/tcpdf/tcpdf.php';

$month = $_GET['month'] ?? date('Y-m');
$report = get_monthly_report_data($month);
$monthLabel = date('F Y', strtotime($report['start']));

class ReportPDF extends TCPDF {
    public $reportTitle = '';
    public function Header() {
        $this->SetFont('aealarabiya', 'B', 14);
        $this->Cell(0, 10, $this->reportTitle, 0, 1, 'C');
        $this->SetLineWidth(0.3);
        $this->Line(10, 22, $this->getPageWidth() - 10, 22);
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('aealarabiya', '', 9);
        $this->Cell(0, 10, 'صفحة ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new ReportPDF('P', 'mm', 'A4', true, 'UTF-8');
$pdf->reportTitle = APP_NAME . ' - تقرير شهر ' . $monthLabel;
$pdf->SetCreator('Accounting System');
$pdf->SetTitle('تقرير ' . $monthLabel);
$pdf->setRTL(true);
$pdf->SetMargins(10, 26, 10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();
$pdf->SetFont('aealarabiya', '', 11);

function pdf_section_title($pdf, $title) {
    $pdf->Ln(3);
    $pdf->SetFillColor(30, 60, 114);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('aealarabiya', 'B', 12);
    $pdf->Cell(0, 9, $title, 0, 1, 'R', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('aealarabiya', '', 10);
}

function pdf_table($pdf, $headers, $rows, $widths) {
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('aealarabiya', 'B', 9);
    foreach (array_reverse($headers) as $i => $h) {
        // نعبي بالترتيب العادي لأنه RTL بيعكس تلقائياً حسب الخانة، فبنحافظ على ترتيب المصفوفة الأصلي
    }
    // رأس الجدول (بترتيب المصفوفة الأصلي، TCPDF بيتعامل مع الاتجاه تلقائياً بفضل setRTL)
    foreach ($headers as $i => $h) {
        $pdf->Cell($widths[$i], 8, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetFont('aealarabiya', '', 9);
    if (empty($rows)) {
        $pdf->Cell(array_sum($widths), 8, 'لا يوجد بيانات', 1, 1, 'C');
        return;
    }
    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $pdf->Cell($widths[$i], 7, $cell, 1, 0, 'C');
        }
        $pdf->Ln();
    }
}

// ---- ملخص الأرقام ----
pdf_section_title($pdf, 'ملخص الشهر');
$t = $report['totals'];
$summaryRows = [
    ['تحصيل من العملاء', number_format($t['customers_credit'], 2)],
    ['مبيعات/ديون جديدة على العملاء', number_format($t['customers_debit'], 2)],
    ['مدفوع للموردين', number_format($t['suppliers_credit'], 2)],
    ['ديون جديدة من الموردين', number_format($t['suppliers_debit'], 2)],
    ['سلف شخصية معطاة', number_format($t['personal_lend'], 2)],
    ['تسديدات شخصية مستلمة', number_format($t['personal_repay'], 2)],
    ['أرباح موزّعة', number_format($t['profits_paid'], 2)],
    ['مصاريف الشهر', number_format($t['expenses_total'], 2)],
    ['إيرادات خارجية', number_format($t['external_revenues_total'], 2)],
    ['إجمالي إيداعات البنوك', number_format($t['banks_deposit'], 2)],
    ['إجمالي سحوبات البنوك', number_format($t['banks_withdraw'], 2)],
];
pdf_table($pdf, ['البند', 'المبلغ (' . APP_CURRENCY . ')'], $summaryRows, [130, 50]);

// ---- ذمم العملاء ----
pdf_section_title($pdf, 'ذمم العملاء');
$rows = array_map(fn($r) => [$r['tx_date'], $r['party'], $r['type'] === 'credit' ? 'تسديد' : 'دين جديد', number_format($r['amount'], 2), $r['description'] ?? ''], $report['customers']);
pdf_table($pdf, ['التاريخ', 'الاسم', 'النوع', 'المبلغ', 'الوصف'], $rows, [25, 35, 25, 25, 70]);

// ---- ذمم الموردين ----
pdf_section_title($pdf, 'ذمم الموردين');
$rows = array_map(fn($r) => [$r['tx_date'], $r['party'], $r['type'] === 'credit' ? 'تسديد لنا' : 'دين جديد', number_format($r['amount'], 2), $r['description'] ?? ''], $report['suppliers']);
pdf_table($pdf, ['التاريخ', 'الاسم', 'النوع', 'المبلغ', 'الوصف'], $rows, [25, 35, 25, 25, 70]);

// ---- دائن ومدين شخصي ----
pdf_section_title($pdf, 'دائن ومدين شخصي');
$rows = array_map(fn($r) => [$r['tx_date'], $r['party'], $r['type'] === 'lend' ? 'سلفة' : 'تسديد', number_format($r['amount'], 2), $r['description'] ?? ''], $report['personal']);
pdf_table($pdf, ['التاريخ', 'الاسم', 'النوع', 'المبلغ', 'الوصف'], $rows, [25, 35, 25, 25, 70]);

// ---- الأرباح ----
pdf_section_title($pdf, 'توزيع الأرباح');
$rows = array_map(fn($r) => [$r['tx_date'], $r['party'], number_format($r['amount'], 2), $r['period_label'] ?? '', $r['description'] ?? ''], $report['profits']);
pdf_table($pdf, ['التاريخ', 'الشريك', 'المبلغ', 'الفترة', 'الوصف'], $rows, [25, 35, 25, 30, 65]);

// ---- المصاريف ----
pdf_section_title($pdf, 'المصاريف والسحوبات');
$rows = array_map(fn($r) => [$r['tx_date'], $r['category'], number_format($r['amount'], 2), $r['paid_from_bank'] ?? '', $r['description'] ?? ''], $report['expenses']);
pdf_table($pdf, ['التاريخ', 'النوع', 'المبلغ', 'مدفوع من', 'الوصف'], $rows, [25, 30, 25, 30, 70]);

// ---- الإيرادات الخارجية ----
pdf_section_title($pdf, 'الإيرادات الخارجية');
$rows = array_map(fn($r) => [$r['tx_date'], $r['source_person'], number_format($r['amount'], 2), $r['paid_from_bank'] ?? '', $r['description'] ?? ''], $report['external_revenues']);
pdf_table($pdf, ['التاريخ', 'المصدر', 'المبلغ', 'دخلت لـ', 'الوصف'], $rows, [25, 30, 25, 30, 70]);

// ---- الشيكات ----
pdf_section_title($pdf, 'الشيكات المستحقة هالشهر');
$rows = array_map(fn($r) => [$r['due_date'], $r['party_name'], $r['direction'] === 'incoming' ? 'مستلم' : 'مدفوع', number_format($r['amount'], 2), $r['bank_name'] ?? '', $r['status']], $report['checks']);
pdf_table($pdf, ['التاريخ', 'الجهة', 'الاتجاه', 'المبلغ', 'البنك', 'الحالة'], $rows, [22, 35, 20, 25, 25, 25]);

// ---- صندوق الكاش ----
pdf_section_title($pdf, 'صندوق الكاش (عجز/زيادة)');
$rows = array_map(fn($r) => [$r['count_date'], number_format($r['expected_amount'], 2), number_format($r['actual_amount'], 2), $r['difference'] >= 0 ? 'زيادة' : 'عجز', number_format(abs($r['difference']), 2), $r['notes'] ?? ''], $report['cash_box']);
pdf_table($pdf, ['التاريخ', 'المفروض', 'الفعلي', 'الحالة', 'الفرق', 'ملاحظات'], $rows, [22, 28, 28, 22, 25, 47]);

$filename = 'تقرير-' . $month . '.pdf';
$pdf->Output($filename, 'I');
