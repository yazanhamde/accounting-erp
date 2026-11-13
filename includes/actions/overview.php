<?php
// overview.php — dashboard overview + entity details
require_once __DIR__ . '/helpers.php';
function get_entity_details($entity_type, $name) {
    $db = get_db();
    $map = [
        'customer'     => ['table' => 'customers', 'tx_table' => 'customer_transactions', 'fk' => 'customer_id'],
        'supplier'     => ['table' => 'suppliers', 'tx_table' => 'supplier_transactions', 'fk' => 'supplier_id'],
        'personal'     => ['table' => 'personal_accounts', 'tx_table' => 'personal_transactions', 'fk' => 'personal_account_id'],
        'employee'     => ['table' => 'employees', 'tx_table' => 'employee_transactions', 'fk' => 'employee_id'],
        'car_employee' => ['table' => 'car_employees', 'tx_table' => 'car_employee_transactions', 'fk' => 'car_employee_id'],
        'shareholder'  => ['table' => 'shareholders', 'tx_table' => 'profit_distributions', 'fk' => 'shareholder_id'],
    ];
    if (!isset($map[$entity_type])) return null;
    $t = $map[$entity_type];

    // بحث بالاسم (يقبل تطابق جزئي) - لو في أكتر من نتيجة، نرجع تنبيه بدل ما نخمن
    $stmt = $db->prepare("SELECT * FROM {$t['table']} WHERE name LIKE ? ORDER BY name LIMIT 5");
    $stmt->execute(['%' . $name . '%']);
    $matches = $stmt->fetchAll();

    if (empty($matches)) return null;
    if (count($matches) > 1) {
        $names = implode('، ', array_map(fn($r) => $r['name'], $matches));
        return ['ambiguous' => true, 'message' => "في أكتر من نتيجة تتشابه مع \"$name\": $names. حدد الاسم بالضبط."];
    }

    $row = $matches[0];
    $tx_stmt = $db->prepare("SELECT * FROM {$t['tx_table']} WHERE {$t['fk']} = ? ORDER BY tx_date DESC, id DESC LIMIT 5");
    $tx_stmt->execute([$row['id']]);
    $recent = $tx_stmt->fetchAll();

    return [
        'entity_type' => $entity_type,
        'name' => $row['name'],
        'balance' => $row['balance'] ?? null,
        'recent_transactions' => $recent,
    ];
}
function get_overview() {
    $db = get_db();
    $overview = [];

    $overview['customers_total'] = $db->query('SELECT COALESCE(SUM(balance),0) t FROM customers')->fetch()['t'];
    $overview['suppliers_total'] = $db->query('SELECT COALESCE(SUM(balance),0) t FROM suppliers')->fetch()['t'];
    $overview['personal_total'] = $db->query('SELECT COALESCE(SUM(balance),0) t FROM personal_accounts')->fetch()['t'];
    $overview['employees_total'] = $db->query('SELECT COALESCE(SUM(balance),0) t FROM employees')->fetch()['t'];
    $overview['car_employees_total'] = $db->query('SELECT COALESCE(SUM(balance),0) t FROM car_employees')->fetch()['t'];

    foreach (['etihad','islami','dinarak','investment'] as $bank) {
        $overview["bank_$bank"] = get_bank_balance($bank);
    }

    $overview['cash_box_total'] = get_cash_box_total();
    $overview['cash_real_balance'] = get_real_cash_balance();
    $cash_opening = get_opening_balance('cash');
    $overview['cash_opening_balance'] = $cash_opening ? (float)$cash_opening['amount'] : 0;

    // بنك الأردن: حسابين مستقلين تماماً (شخصي / مؤسسة)، كل واحد إله رصيده الافتتاحي الخاص
    $overview['bank_jordan_personal'] = get_bank_balance('jordan_personal');
    $overview['bank_jordan_business'] = get_bank_balance('jordan_business');
    // مجموع تقريبي للتوافق مع أي مكان قديم لسا بيسأل عن "بنك الأردن" ككل (البوت مثلاً)
    $overview['bank_jordan'] = $overview['bank_jordan_personal'] + $overview['bank_jordan_business'];

    $overview['upcoming_checks'] = get_upcoming_checks(7);
    $overview['external_revenues_total'] = get_external_revenues_total();
    $overview['shareholders_total'] = $db->query('SELECT COALESCE(SUM(capital_amount),0) t FROM shareholders')->fetch()['t'];

    return $overview;
}
