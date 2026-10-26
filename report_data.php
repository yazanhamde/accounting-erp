<?php
/**
 * يجمع بيانات كل أقسام النظام لشهر معيّن (لاستخدامها بتقرير PDF/Excel)
 * $month بصيغة YYYY-MM
 */
function get_monthly_report_data($month) {
    $pdo = get_db();
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));

    $data = [];

    // ذمم العملاء
    $stmt = $pdo->prepare("
        SELECT ct.tx_date, c.name AS party, ct.type, ct.amount, ct.description
        FROM customer_transactions ct
        JOIN customers c ON c.id = ct.customer_id
        WHERE ct.tx_date BETWEEN ? AND ?
        ORDER BY ct.tx_date
    ");
    $stmt->execute([$start, $end]);
    $data['customers'] = $stmt->fetchAll();

    // ذمم الموردين
    $stmt = $pdo->prepare("
        SELECT st.tx_date, s.name AS party, st.type, st.amount, st.description
        FROM supplier_transactions st
        JOIN suppliers s ON s.id = st.supplier_id
        WHERE st.tx_date BETWEEN ? AND ?
        ORDER BY st.tx_date
    ");
    $stmt->execute([$start, $end]);
    $data['suppliers'] = $stmt->fetchAll();

    // دائن/مدين شخصي
    $stmt = $pdo->prepare("
        SELECT pt.tx_date, p.name AS party, pt.type, pt.amount, pt.description
        FROM personal_transactions pt
        JOIN personal_accounts p ON p.id = pt.personal_account_id
        WHERE pt.tx_date BETWEEN ? AND ?
        ORDER BY pt.tx_date
    ");
    $stmt->execute([$start, $end]);
    $data['personal'] = $stmt->fetchAll();

    // توزيع الأرباح
    $stmt = $pdo->prepare("
        SELECT pd.tx_date, sh.name AS party, pd.amount, pd.period_label, pd.description
        FROM profit_distributions pd
        JOIN shareholders sh ON sh.id = pd.shareholder_id
        WHERE pd.tx_date BETWEEN ? AND ?
        ORDER BY pd.tx_date
    ");
    $stmt->execute([$start, $end]);
    $data['profits'] = $stmt->fetchAll();

    // مصاريف
    $stmt = $pdo->prepare("
        SELECT tx_date, category, amount, description, paid_from_bank
        FROM expenses
        WHERE tx_date BETWEEN ? AND ?
        ORDER BY tx_date
    ");
    $stmt->execute([$start, $end]);
    $data['expenses'] = $stmt->fetchAll();

    // إيرادات خارجية
    $stmt = $pdo->prepare("
        SELECT tx_date, source_person, amount, description, paid_from_bank
        FROM external_revenues
        WHERE tx_date BETWEEN ? AND ?
        ORDER BY tx_date
    ");
    $stmt->execute([$start, $end]);
    $data['external_revenues'] = $stmt->fetchAll();

    // البنوك: الأردني (شخصي/مؤسسة) + الثلاثة الباقيين
    $data['banks'] = [];

    $stmt = $pdo->prepare("SELECT tx_date, tx_type, amount, description FROM bank_jordan WHERE usage_type = 'personal' AND tx_date BETWEEN ? AND ? ORDER BY tx_date");
    $stmt->execute([$start, $end]);
    $data['banks']['البنك الأردني - شخصي'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT tx_date, tx_type, amount, description FROM bank_jordan WHERE usage_type = 'business' AND tx_date BETWEEN ? AND ? ORDER BY tx_date");
    $stmt->execute([$start, $end]);
    $data['banks']['البنك الأردني - مؤسسة'] = $stmt->fetchAll();

    $other_banks = ['bank_etihad' => 'بنك الاتحاد', 'bank_islami' => 'البنك الإسلامي', 'bank_dinarak' => 'دينارك', 'bank_investment' => 'البنك الاستثماري'];
    foreach ($other_banks as $table => $label) {
        $stmt = $pdo->prepare("SELECT tx_date, tx_type, amount, description FROM `$table` WHERE tx_date BETWEEN ? AND ? ORDER BY tx_date");
        $stmt->execute([$start, $end]);
        $data['banks'][$label] = $stmt->fetchAll();
    }

    // صندوق الكاش (عجز/زيادة) لنفس الشهر
    $stmt = $pdo->prepare("SELECT count_date, expected_amount, actual_amount, difference, notes FROM cash_box WHERE count_date BETWEEN ? AND ? ORDER BY count_date");
    $stmt->execute([$start, $end]);
    $data['cash_box'] = $stmt->fetchAll();

    // الشيكات المستحقة بنفس الشهر
    $stmt = $pdo->prepare("
        SELECT due_date, check_number, direction, party_name, amount, bank_name, status
        FROM checks
        WHERE due_date BETWEEN ? AND ?
        ORDER BY due_date
    ");
    $stmt->execute([$start, $end]);
    $data['checks'] = $stmt->fetchAll();

    // ---- إجماليات سريعة ----
    $totals = [
        'customers_debit' => 0, 'customers_credit' => 0,
        'suppliers_debit' => 0, 'suppliers_credit' => 0,
        'personal_lend' => 0, 'personal_repay' => 0,
        'profits_paid' => 0,
        'expenses_total' => 0,
        'external_revenues_total' => 0,
        'banks_deposit' => 0, 'banks_withdraw' => 0,
    ];
    foreach ($data['customers'] as $r) { $totals['customers_' . $r['type']] += $r['amount']; }
    foreach ($data['suppliers'] as $r) { $totals['suppliers_' . $r['type']] += $r['amount']; }
    foreach ($data['personal'] as $r) { $totals['personal_' . $r['type']] += $r['amount']; }
    foreach ($data['profits'] as $r) { $totals['profits_paid'] += $r['amount']; }
    foreach ($data['expenses'] as $r) { $totals['expenses_total'] += $r['amount']; }
    foreach ($data['external_revenues'] as $r) { $totals['external_revenues_total'] += $r['amount']; }
    foreach ($data['banks'] as $rows) {
        foreach ($rows as $r) {
            $totals['banks_' . $r['tx_type']] += $r['amount'];
        }
    }
    $data['totals'] = $totals;
    $data['month'] = $month;
    $data['start'] = $start;
    $data['end'] = $end;

    return $data;
}
