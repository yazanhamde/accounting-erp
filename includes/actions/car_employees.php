<?php
// car_employees.php — car employee transactions & profile management
require_once __DIR__ . '/helpers.php';
function update_car_employee_profile($car_employee_id, $name, $phone, $car_info, $base_salary) {
    $db = get_db();
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'اسم موظف السيارة مطلوب'];
    }
    $stmt = $db->prepare('SELECT id FROM car_employees WHERE name = ? AND id != ? LIMIT 1');
    $stmt->execute([$name, $car_employee_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => "في موظف سيارة تاني مسجّل بنفس الاسم \"$name\" أصلاً - اختار اسم مختلف أو راجع القائمة."];
    }
    $db->prepare('UPDATE car_employees SET name = ?, phone = ?, car_info = ?, base_salary = ? WHERE id = ?')
       ->execute([$name, $phone ?: null, $car_info ?: null, $base_salary !== null && $base_salary !== '' ? (float)$base_salary : 0, $car_employee_id]);
    return ['success' => true, 'message' => "تم تحديث بيانات \"$name\""];
}
function delete_car_employee($car_employee_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM car_employees WHERE id = ?');
    $stmt->execute([$car_employee_id]);
    $c = $stmt->fetch();
    if (!$c) return ['success' => false, 'message' => 'موظف السيارة غير موجود'];

    $stmt = $db->prepare('SELECT COUNT(*) FROM car_employee_transactions WHERE car_employee_id = ?');
    $stmt->execute([$car_employee_id]);
    $tx_count = (int) $stmt->fetchColumn();
    if ($tx_count > 0) {
        return [
            'success' => false,
            'message' => "ما فيك تحذف \"{$c['name']}\" لأنه عنده $tx_count حركة مسجّلة (إيرادات/مصاريف/سلف) - حذفه بيمسح كل تاريخه المالي نهائياً. لو بدك توقف التعامل معه بس تحتفظ بسجله، خليه برصيده الحالي بدل الحذف."
        ];
    }
    $db->prepare('DELETE FROM car_employees WHERE id = ?')->execute([$car_employee_id]);
    return ['success' => true, 'message' => "تم حذف \"{$c['name']}\" (ما كان عنده أي حركات مسجّلة)"];
}
function add_car_employee_transaction($car_employee_name, $type, $amount, $description, $tx_date, $user_id, $phone = null, $car_info = null, $customer_count = null, $paid_from_bank = null) {
    $db = get_db();

    // "advance"/"ads"/"bonus"/"fuel"/"parts"/"vehicle_maintenance"/"secretary_salary" فيهم فلوس فعلية بتنصرف من بنك/كاش - لازم تتحدد.
    // "revenue" و"expense" و"deduction" مجرد قيود مستحقات على حساب السائق، ما فيهم حركة بنك مباشرة.
    // ملاحظة: ما في نوع "راتب" هون - راتب السائق كموظف بينسجل من صفحة الموظفين (add_employee_transaction)، مش من هون،
    // لأن الإيراد هون حق صاحب المحل مش السائق، والسائق بس إله راتب + المصاريف التشغيلية (محروقات/قطع/صيانة/إعلانات/سكرتارية) عليه.
    if (in_array($type, ['advance', 'ads', 'bonus', 'fuel', 'parts', 'vehicle_maintenance', 'secretary_salary'], true) && empty($paid_from_bank)) {
        return ['success' => false, 'message' => 'لازم تحدد من أي حساب بنك أو كاش انصرفت الفلوس، عشان ما تروح لحساب معلّق.'];
    }

    $similar = find_similar_entities('car_employees', $car_employee_name);
    if (!empty($similar)) {
        $names = implode('، ', array_map(fn($r) => '"' . $r['name'] . '"', $similar));
        return ['success' => false, 'ambiguous' => true, 'message' => "في أكتر من موظف سيارة بيتشابه اسمهم مع \"$car_employee_name\": $names. حدد أي واحد بالضبط أو اكتب الاسم الكامل."];
    }

    $car_employee_id = find_or_create_entity('car_employees', $car_employee_name);
    if ($phone) {
        $db->prepare('UPDATE car_employees SET phone = ? WHERE id = ?')->execute([$phone, $car_employee_id]);
    }
    if ($car_info) {
        $db->prepare('UPDATE car_employees SET car_info = ? WHERE id = ?')->execute([$car_info, $car_employee_id]);
    }

    $stmt = $db->prepare('INSERT INTO car_employee_transactions (car_employee_id, type, amount, customer_count, description, tx_date, created_by, paid_from_bank) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$car_employee_id, $type, $amount, $customer_count !== null && $customer_count !== '' ? (int)$customer_count : null, $description, $tx_date, $user_id, $paid_from_bank ?: null]);
    $new_car_tx_id = $db->lastInsertId();

    // expense/advance/ads/fuel/parts/vehicle_maintenance/secretary_salary فيها فلوس فعلية طلعت من حساب (withdraw)
    // revenue لو حددت لها بنك معيّن، فهاي فلوس فعلية دخلت البنك (deposit) - لو ما حددت شي بتحسب كاش (بدون حركة بجدول بنك)
    if ($paid_from_bank && $type === 'revenue') {
        mirror_bank_movement($paid_from_bank, 'deposit', $amount, "سائق: $car_employee_name", $tx_date, $user_id, 'car_employee_transactions', $new_car_tx_id);
    } elseif ($paid_from_bank && in_array($type, ['expense', 'advance', 'ads', 'fuel', 'parts', 'vehicle_maintenance', 'secretary_salary'], true)) {
        mirror_bank_movement($paid_from_bank, 'withdraw', $amount, "سائق: $car_employee_name", $tx_date, $user_id, 'car_employee_transactions', $new_car_tx_id);
    }

    // إيرادات تزيد الصافي، وأي شيء ثاني (مصاريف/سلف/خصومات/إعلانات/راتب) بينقص الصافي
    $sign = $type === 'revenue' ? 1 : -1;
    $db->prepare('UPDATE car_employees SET balance = balance + ? WHERE id = ?')
       ->execute([$sign * $amount, $car_employee_id]);

    return [
        'success' => true,
        'message' => "تم تسجيل الحركة لموظف السيارة \"$car_employee_name\" بمبلغ $amount",
        'entity_table' => 'car_employee_transactions',
        'entity_id' => $new_car_tx_id,
    ];
}
function get_car_employee_ads_stats() {
    $db = get_db();
    $sql = "SELECT c.id, c.name,
                COALESCE((SELECT SUM(customer_count) FROM car_employee_transactions WHERE car_employee_id = c.id AND type = 'revenue'), 0) AS total_customers,
                COALESCE((SELECT SUM(amount) FROM car_employee_transactions WHERE car_employee_id = c.id AND type = 'ads'), 0) AS total_ads
            FROM car_employees c
            ORDER BY c.name ASC";
    $rows = $db->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['cost_per_customer'] = $r['total_customers'] > 0 ? $r['total_ads'] / $r['total_customers'] : null;
    }
    return $rows;
}
function distribute_ads_by_customers($total_ads_amount, $driver_customer_counts, $tx_date, $user_id, $description = null) {
    // $driver_customer_counts: array مثل [car_employee_id => عدد_الزبائن]
    $total_customers = array_sum($driver_customer_counts);
    if ($total_customers <= 0) {
        return ['success' => false, 'message' => 'لازم تدخل عدد زبائن أكبر من صفر لواحد سائق على الأقل'];
    }
    $db = get_db();
    $results = [];
    foreach ($driver_customer_counts as $car_employee_id => $count) {
        if ($count <= 0) continue;
        $share = round($total_ads_amount * ($count / $total_customers), 2);
        $stmt = $db->prepare('INSERT INTO car_employee_transactions (car_employee_id, type, amount, customer_count, description, tx_date, created_by) VALUES (?,?,?,?,?,?,?)');
        $desc = $description ?: 'حصة من إعلانات مشتركة موزعة حسب عدد الزبائن';
        $stmt->execute([$car_employee_id, 'ads', $share, $count, $desc, $tx_date, $user_id]);
        $db->prepare('UPDATE car_employees SET balance = balance - ? WHERE id = ?')->execute([$share, $car_employee_id]);
        $results[] = ['car_employee_id' => $car_employee_id, 'share' => $share];
    }
    return ['success' => true, 'message' => 'تم توزيع الإعلانات على السائقين حسب عدد الزبائن', 'results' => $results];
}
function distribute_secretary_salary_equally($total_amount, $tx_date, $user_id, $paid_from_bank, $description = null, $selected_ids = null) {
    $db = get_db();
    if (empty($paid_from_bank)) {
        return ['success' => false, 'message' => 'لازم تحدد من أي حساب بنك أو كاش انصرف راتب السكرتارية.'];
    }
    if ($selected_ids !== null && count($selected_ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $stmt = $db->prepare("SELECT id, name FROM car_employees WHERE id IN ($placeholders) ORDER BY id ASC");
        $stmt->execute($selected_ids);
        $car_employees = $stmt->fetchAll();
    } else {
        $car_employees = $db->query('SELECT id, name FROM car_employees ORDER BY id ASC')->fetchAll();
    }
    $count = count($car_employees);
    if ($count === 0) {
        return ['success' => false, 'message' => 'ما حددت أي موظف سيارة لتوزيع راتب السكرتارية عليه'];
    }
    $share = round($total_amount / $count, 2);
    $desc = $description ?: 'حصة من راتب السكرتارية (موزّع بالتساوي)';
    $results = [];
    foreach ($car_employees as $c) {
        $stmt = $db->prepare('INSERT INTO car_employee_transactions (car_employee_id, type, amount, description, tx_date, created_by, paid_from_bank) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$c['id'], 'secretary_salary', $share, $desc, $tx_date, $user_id, $paid_from_bank]);
        $new_id = $db->lastInsertId();
        mirror_bank_movement($paid_from_bank, 'withdraw', $share, "سائق: {$c['name']} - حصة راتب سكرتارية", $tx_date, $user_id, 'car_employee_transactions', $new_id);
        $db->prepare('UPDATE car_employees SET balance = balance - ? WHERE id = ?')->execute([$share, $c['id']]);
        $results[] = ['car_employee_id' => $c['id'], 'share' => $share];
    }
    return ['success' => true, 'message' => "تم توزيع راتب السكرتارية بالتساوي على $count سائق (حصة كل واحد: $share)", 'results' => $results];
}
function get_car_employee_pl_report() {
    $db = get_db();
    $car_employees = $db->query('SELECT * FROM car_employees ORDER BY name ASC')->fetchAll();
    $sql = "SELECT car_employee_id, type, SUM(amount) AS total
            FROM car_employee_transactions
            GROUP BY car_employee_id, type";
    $rows = $db->query($sql)->fetchAll();
    $by_employee = [];
    foreach ($rows as $r) {
        $by_employee[$r['car_employee_id']][$r['type']] = (float)$r['total'];
    }

    // راتب فعلي استلمه من صفحة الموظفين (نوع "دفع راتب مستحق" - فلوس فعلية طلعت)، مربوط بالاسم
    $salary_rows = $db->query("SELECT e.name, SUM(et.amount) AS total
                                FROM employee_transactions et
                                JOIN employees e ON e.id = et.employee_id
                                WHERE et.type = 'payment'
                                GROUP BY e.name")->fetchAll();
    $salary_by_name = [];
    foreach ($salary_rows as $sr) {
        $salary_by_name[normalize_name_for_match($sr['name'])] = (float)$sr['total'];
    }

    $expense_types = ['fuel', 'parts', 'vehicle_maintenance', 'ads', 'secretary_salary', 'other'];
    $report = [];
    foreach ($car_employees as $c) {
        $t = $by_employee[$c['id']] ?? [];
        $revenue = $t['revenue'] ?? 0;
        $expenses = [];
        $total_expenses = 0;
        foreach ($expense_types as $et) {
            // "other" = مصروف عام (expense) غير مصنّف - ما فيه نوع خاص فيه بجدول car_employee_transactions
            $val = $et === 'other' ? ($t['expense'] ?? 0) : ($t[$et] ?? 0);
            $expenses[$et] = $val;
            $total_expenses += $val;
        }
        // راتب الموظف - مسحوب من صفحة الموظفين (مش من car_employee_transactions)، بس بينضاف بنفس التقرير هون
        $employee_salary = $salary_by_name[normalize_name_for_match($c['name'])] ?? 0;
        $expenses['employee_salary'] = $employee_salary;
        $total_expenses += $employee_salary;

        $report[] = [
            'id' => $c['id'],
            'name' => $c['name'],
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_expenses' => $total_expenses,
            'net' => $revenue - $total_expenses,
        ];
    }
    return $report;
}
function update_car_employee_transaction($tx_id, $car_employee_name, $type, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM car_employee_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    if (in_array($type, ['advance', 'ads', 'bonus'], true) && empty($paid_from_bank) && trim((string)$no_bank_reason) === '') {
        return ['success' => false, 'message' => 'لازم تحدد من أي حساب بنك أو كاش انصرفت الفلوس، أو تكتب سبب واضح.'];
    }

    $old_sign = $old['type'] === 'revenue' ? 1 : -1;
    $db->prepare('UPDATE car_employees SET balance = balance - ? WHERE id = ?')
       ->execute([$old_sign * $old['amount'], $old['car_employee_id']]);

    $car_employee_id = find_or_create_entity('car_employees', $car_employee_name);
    $new_sign = $type === 'revenue' ? 1 : -1;
    $db->prepare('UPDATE car_employees SET balance = balance + ? WHERE id = ?')
       ->execute([$new_sign * $amount, $car_employee_id]);

    $stmt = $db->prepare('UPDATE car_employee_transactions SET car_employee_id=?, type=?, amount=?, description=?, tx_date=?, paid_from_bank=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$car_employee_id, $type, $amount, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason, $tx_id]);

    reverse_mirrored_bank_movement('car_employee_transactions', $tx_id);
    if (!empty($paid_from_bank)) {
        $tx_type = $type === 'revenue' ? 'deposit' : 'withdraw';
        mirror_bank_movement($paid_from_bank, $tx_type, $amount, "سائق: $car_employee_name", $tx_date, $old['created_by'], 'car_employee_transactions', $tx_id);
    }

    return ['success' => true, 'message' => 'تم تعديل الحركة'];
}
function delete_car_employee_transaction($tx_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM car_employee_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $sign = $old['type'] === 'revenue' ? 1 : -1;
    $db->prepare('UPDATE car_employees SET balance = balance - ? WHERE id = ?')
       ->execute([$sign * $old['amount'], $old['car_employee_id']]);

    reverse_mirrored_bank_movement('car_employee_transactions', $tx_id);
    $db->prepare('DELETE FROM car_employee_transactions WHERE id = ?')->execute([$tx_id]);
    return ['success' => true, 'message' => 'تم حذف الحركة'];
}
function update_car_employee_phone($car_employee_id, $phone) {
    $db = get_db();
    $db->prepare('UPDATE car_employees SET phone = ? WHERE id = ?')->execute([$phone ?: null, $car_employee_id]);
    return ['success' => true, 'message' => 'تم تحديث رقم الهاتف'];
}
function update_car_employee_car_info($car_employee_id, $car_info) {
    $db = get_db();
    $db->prepare('UPDATE car_employees SET car_info = ? WHERE id = ?')->execute([$car_info ?: null, $car_employee_id]);
    return ['success' => true, 'message' => 'تم تحديث بيانات السيارة'];
}
