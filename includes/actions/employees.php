<?php
// employees.php — employee transactions & profile management
require_once __DIR__ . '/helpers.php';
function add_employee_transaction($employee_name, $type, $amount, $description, $tx_date, $user_id, $phone = null, $paid_from_bank = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();

    // "advance" (سلفة) و"payment" (دفع راتب مستحق) هما الحالتين يلي فلوس فعلية بتطلع من حساب - لازم تتحدد.
    // "salary" و"deduction" و"bonus" ما فيهم حركة كاش فعلية (مجرد قيد مستحقات).
    if (in_array($type, ['advance', 'payment'], true) && empty($paid_from_bank)) {
        return ['success' => false, 'message' => 'لازم تحدد من أي حساب/كاش انصرفت الفلوس، عشان ما تروح لحساب معلّق.'];
    }

    $similar = find_similar_entities('employees', $employee_name);
    if (!empty($similar)) {
        $names = implode('، ', array_map(fn($r) => '"' . $r['name'] . '"', $similar));
        return ['success' => false, 'ambiguous' => true, 'message' => "في أكتر من موظف بيتشابه اسمهم مع \"$employee_name\": $names. حدد أي واحد بالضبط أو اكتب الاسم الكامل."];
    }

    $employee_id = find_or_create_entity('employees', $employee_name);
    if ($phone) {
        $db->prepare('UPDATE employees SET phone = ? WHERE id = ?')->execute([$phone, $employee_id]);
    }

    $stmt = $db->prepare('INSERT INTO employee_transactions (employee_id, type, amount, description, tx_date, created_by, paid_from_bank) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$employee_id, $type, $amount, $description, $tx_date, $user_id, $paid_from_bank ?: null]);
    $new_emp_tx_id = $db->lastInsertId();

    // سلفة أو دفع راتب مستحق هما يلي فيهم فلوس فعلية طلعت من حساب (راتب/خصم/مكافأة ما فيهم حركة كاش فعلية)
    if ($paid_from_bank && in_array($type, ['advance', 'payment'], true)) {
        $label = $type === 'payment' ? 'دفع راتب موظف' : 'سلفة موظف';
        mirror_bank_movement($paid_from_bank, 'withdraw', $amount, "$label: $employee_name", $tx_date, $user_id, 'employee_transactions', $new_emp_tx_id);
    }

    $sign = in_array($type, ['salary', 'bonus'], true) ? 1 : -1; // راتب/مكافأة يزيدوا المستحق / سلفة أو خصم بينقصوا المستحق
    $db->prepare('UPDATE employees SET balance = balance + ? WHERE id = ?')
       ->execute([$sign * $amount, $employee_id]);

    return [
        'success' => true,
        'message' => "تم تسجيل الحركة للموظف \"$employee_name\" بمبلغ $amount",
        'entity_table' => 'employee_transactions',
        'entity_id' => $new_emp_tx_id,
    ];
}
function update_employee_transaction($tx_id, $employee_name, $type, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM employee_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    if (in_array($type, ['advance', 'payment'], true) && empty($paid_from_bank) && trim((string)$no_bank_reason) === '') {
        return ['success' => false, 'message' => 'لازم تحدد من أي حساب/كاش انصرفت الفلوس، أو تكتب سبب واضح.'];
    }

    $old_sign = in_array($old['type'], ['salary', 'bonus'], true) ? 1 : -1;
    $db->prepare('UPDATE employees SET balance = balance - ? WHERE id = ?')
       ->execute([$old_sign * $old['amount'], $old['employee_id']]);

    $employee_id = find_or_create_entity('employees', $employee_name);
    $new_sign = in_array($type, ['salary', 'bonus'], true) ? 1 : -1;
    $db->prepare('UPDATE employees SET balance = balance + ? WHERE id = ?')
       ->execute([$new_sign * $amount, $employee_id]);

    $stmt = $db->prepare('UPDATE employee_transactions SET employee_id=?, type=?, amount=?, description=?, tx_date=?, paid_from_bank=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$employee_id, $type, $amount, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason, $tx_id]);

    reverse_mirrored_bank_movement('employee_transactions', $tx_id);
    if (!empty($paid_from_bank)) {
        $label = $type === 'payment' ? 'دفع راتب موظف' : 'سلفة موظف';
        mirror_bank_movement($paid_from_bank, 'withdraw', $amount, "$label: $employee_name", $tx_date, $old['created_by'], 'employee_transactions', $tx_id);
    }

    return ['success' => true, 'message' => 'تم تعديل الحركة'];
}
function delete_employee_transaction($tx_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM employee_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $sign = in_array($old['type'], ['salary', 'bonus'], true) ? 1 : -1;
    $db->prepare('UPDATE employees SET balance = balance - ? WHERE id = ?')
       ->execute([$sign * $old['amount'], $old['employee_id']]);

    reverse_mirrored_bank_movement('employee_transactions', $tx_id);
    $db->prepare('DELETE FROM employee_transactions WHERE id = ?')->execute([$tx_id]);
    return ['success' => true, 'message' => 'تم حذف الحركة'];
}
function update_employee_phone($employee_id, $phone) {
    $db = get_db();
    $db->prepare('UPDATE employees SET phone = ? WHERE id = ?')->execute([$phone ?: null, $employee_id]);
    return ['success' => true, 'message' => 'تم تحديث رقم الهاتف'];
}
function update_employee_profile($employee_id, $name, $base_salary, $phone, $employee_type = 'regular') {
    $db = get_db();
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'اسم الموظف مطلوب'];
    }
    if (!in_array($employee_type, ['regular', 'driver'], true)) {
        $employee_type = 'regular';
    }
    // امنع التعارض مع موظف تاني بنفس الاسم بالضبط
    $stmt = $db->prepare('SELECT id FROM employees WHERE name = ? AND id != ? LIMIT 1');
    $stmt->execute([$name, $employee_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => "في موظف تاني مسجّل بنفس الاسم \"$name\" أصلاً - اختار اسم مختلف أو راجع القائمة."];
    }
    $db->prepare('UPDATE employees SET name = ?, base_salary = ?, phone = ?, employee_type = ? WHERE id = ?')
       ->execute([$name, $base_salary !== null && $base_salary !== '' ? (float)$base_salary : 0, $phone ?: null, $employee_type, $employee_id]);

    // "موظف صيانة" لازم يبين تلقائياً بصفحة صيانة خارجية - بس ربط بالاسم، بدون ما يأثر على أي رصيد
    if ($employee_type === 'driver') {
        find_or_create_entity('car_employees', $name);
    }

    return ['success' => true, 'message' => "تم تحديث بيانات \"$name\""];
}
function delete_employee($employee_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM employees WHERE id = ?');
    $stmt->execute([$employee_id]);
    $emp = $stmt->fetch();
    if (!$emp) {
        return ['success' => false, 'message' => 'الموظف غير موجود'];
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM employee_transactions WHERE employee_id = ?');
    $stmt->execute([$employee_id]);
    $tx_count = (int) $stmt->fetchColumn();

    if ($tx_count > 0) {
        return [
            'success' => false,
            'message' => "ما فيك تحذف \"{$emp['name']}\" لأنه عنده $tx_count حركة مسجّلة (رواتب/سلف/خصومات) - حذفه بيمسح كل تاريخه المالي نهائياً. لو بدك توقف التعامل معه بس تحتفظ بسجله، ما تحذفه - خليه برصيده الحالي وسجّل ملاحظة بدل هيك."
        ];
    }

    $db->prepare('DELETE FROM employees WHERE id = ?')->execute([$employee_id]);
    return ['success' => true, 'message' => "تم حذف \"{$emp['name']}\" (ما كان عنده أي حركات مسجّلة)"];
}
