<?php
// banks.php — bank transactions, balances, opening balances, mirror movements
require_once __DIR__ . '/helpers.php';
function add_bank_transaction($bank, $tx_type, $amount, $usage_type, $description, $tx_date, $user_id, $related_customer = null) {
    $db = get_db();
    $tables = [
        'jordan' => 'bank_jordan',
        'jordan_personal' => 'bank_jordan',
        'jordan_business' => 'bank_jordan',
        'etihad' => 'bank_etihad',
        'islami' => 'bank_islami',
        'dinarak' => 'bank_dinarak',
        'investment' => 'bank_investment',
    ];
    if (!isset($tables[$bank])) {
        return ['success' => false, 'message' => 'اسم البنك غير معروف'];
    }
    $table = $tables[$bank];

    // بنك الأردن شخصي/مؤسسة: نوع الاستخدام محدد تلقائياً من اسم الحساب نفسه، ما في داعي نعتمد
    // على أي قيمة مدخلة من النموذج/البوت
    if ($bank === 'jordan_personal') $usage_type = 'personal';
    if ($bank === 'jordan_business') $usage_type = 'business';

    // القيم المسموحة فعلياً بقاعدة البيانات (ENUM) لكل بنك + قيمة افتراضية أمينة
    // هذا يحمي من أي قيمة غريبة يرجعها الذكاء الاصطناعي (أو null) قبل ما توصل لقاعدة البيانات
    $allowed_usage = [
        'jordan' => ['allowed' => ['personal', 'business'], 'default' => 'business'],
        'etihad' => ['allowed' => ['transfer', 'purchase'], 'default' => 'transfer'],
        'islami' => ['allowed' => ['pos_machine', 'check', 'other'], 'default' => 'other'],
        'investment' => ['allowed' => ['capital', 'profit', 'other'], 'default' => 'other'],
    ];
    if ($bank !== 'dinarak' && isset($allowed_usage[$bank])) {
        $rules = $allowed_usage[$bank];
        if (!in_array($usage_type, $rules['allowed'], true)) {
            $usage_type = $rules['default'];
        }
    }

    try {
        if ($bank === 'dinarak') {
            $stmt = $db->prepare("INSERT INTO $table (tx_type, amount, related_customer, description, tx_date, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$tx_type, $amount, $related_customer, $description, $tx_date, $user_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO $table (tx_type, amount, usage_type, description, tx_date, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$tx_type, $amount, $usage_type, $description, $tx_date, $user_id]);
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'تعذر تسجيل الحركة البنكية، جرب توضيح التفاصيل أكتر (مثلاً نوع الاستخدام)'];
    }

    return [
        'success' => true,
        'message' => "تم تسجيل الحركة البنكية ($bank) بمبلغ $amount",
        'entity_table' => $table,
        'entity_id' => $db->lastInsertId(),
    ];
}
function source_table_label($table) {
    $labels = [
        'customer_transactions' => ['name' => 'عميل', 'page' => 'customers.php'],
        'supplier_transactions' => ['name' => 'مورد', 'page' => 'suppliers.php'],
        'personal_transactions' => ['name' => 'دائن/مدين شخصي', 'page' => 'personal.php'],
        'employee_transactions' => ['name' => 'موظف', 'page' => 'employees.php'],
        'car_employee_transactions' => ['name' => 'موظف سيارة', 'page' => 'car_employees.php'],
        'expenses' => ['name' => 'مصروف', 'page' => 'expenses.php'],
        'profit_distributions' => ['name' => 'توزيع أرباح', 'page' => 'profits.php'],
        'checks_settlement' => ['name' => 'تسوية شيك', 'page' => 'checks.php'],
    ];
    return $labels[$table] ?? ['name' => $table, 'page' => null];
}
function update_bank_transaction($bank, $id, $tx_type, $amount, $usage_type, $description, $tx_date, $related_customer = null) {
    $db = get_db();
    $tables = ['jordan' => 'bank_jordan', 'jordan_personal' => 'bank_jordan', 'jordan_business' => 'bank_jordan', 'etihad' => 'bank_etihad', 'islami' => 'bank_islami', 'dinarak' => 'bank_dinarak', 'investment' => 'bank_investment'];
    if (!isset($tables[$bank])) {
        return ['success' => false, 'message' => 'اسم البنك غير معروف'];
    }
    $table = $tables[$bank];

    // حماية: هاي حركة "مرآة" تلقائية مرتبطة بمصدر تاني (عميل/مورد/مصروف...) -
    // القيد الحقيقي بدفتر الأستاذ مسجّل هناك مش هون، فتعديلها من هون رح يسبب
    // عدم تطابق بين رصيد البنك والقيد الفعلي. لازم تتعدّل من صفحتها الأصلية.
    $stmt = $db->prepare("SELECT linked_source_table FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && !empty($row['linked_source_table'])) {
        $src = source_table_label($row['linked_source_table']);
        $where = $src['page'] ? " من صفحة {$src['page']}" : '';
        return ['success' => false, 'message' => "هاي الحركة مرتبطة تلقائياً بـ\"{$src['name']}\" - عدّلها{$where} مش من صفحة البنوك، حتى تنعدّل الحركة والقيد مع بعض بشكل صحيح."];
    }

    if ($bank === 'jordan_personal') $usage_type = 'personal';
    if ($bank === 'jordan_business') $usage_type = 'business';

    if ($bank === 'dinarak') {
        $stmt = $db->prepare("UPDATE $table SET tx_type=?, amount=?, related_customer=?, description=?, tx_date=? WHERE id=?");
        $stmt->execute([$tx_type, $amount, $related_customer, $description, $tx_date, $id]);
    } else {
        $stmt = $db->prepare("UPDATE $table SET tx_type=?, amount=?, usage_type=?, description=?, tx_date=? WHERE id=?");
        $stmt->execute([$tx_type, $amount, $usage_type, $description, $tx_date, $id]);
    }

    return ['success' => true, 'message' => 'تم تعديل الحركة البنكية'];
}
function delete_bank_transaction($bank, $id) {
    $db = get_db();
    $tables = ['jordan' => 'bank_jordan', 'jordan_personal' => 'bank_jordan', 'jordan_business' => 'bank_jordan', 'etihad' => 'bank_etihad', 'islami' => 'bank_islami', 'dinarak' => 'bank_dinarak', 'investment' => 'bank_investment'];
    if (!isset($tables[$bank])) {
        return ['success' => false, 'message' => 'اسم البنك غير معروف'];
    }
    $table = $tables[$bank];

    $stmt = $db->prepare("SELECT linked_source_table FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && !empty($row['linked_source_table'])) {
        $src = source_table_label($row['linked_source_table']);
        $where = $src['page'] ? " من صفحة {$src['page']}" : '';
        return ['success' => false, 'message' => "هاي الحركة مرتبطة تلقائياً بـ\"{$src['name']}\" - احذفها{$where} مش من صفحة البنوك، حتى ينحذف رصيد البنك والقيد مع بعض بشكل صحيح."];
    }

    $db->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
    return ['success' => true, 'message' => 'تم حذف الحركة البنكية'];
}
function get_opening_balance($account_key) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM opening_balances WHERE account_key = ?');
    $stmt->execute([$account_key]);
    return $stmt->fetch() ?: null;
}
function set_opening_balance($account_key, $amount, $balance_date, $notes, $user_id) {
    $valid = ['bank_jordan_personal','bank_jordan_business','bank_etihad','bank_islami','bank_dinarak','bank_investment','cash','cash_diff'];
    if (!in_array($account_key, $valid, true)) {
        return ['success' => false, 'message' => 'حساب غير معروف للرصيد الافتتاحي'];
    }
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO opening_balances (account_key, amount, balance_date, notes, updated_by)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), balance_date = VALUES(balance_date),
            notes = VALUES(notes), updated_by = VALUES(updated_by)');
    $stmt->execute([$account_key, $amount, $balance_date, $notes, $user_id]);
    return ['success' => true, 'message' => 'تم حفظ الرصيد الافتتاحي بمبلغ ' . number_format($amount, 2)];
}
function get_bank_balance($bank) {
    $db = get_db();
    $tables = ['jordan' => 'bank_jordan', 'etihad' => 'bank_etihad', 'islami' => 'bank_islami', 'dinarak' => 'bank_dinarak', 'investment' => 'bank_investment'];

    // بنك الأردن مقسوم لحسابين مستقلين تماماً (شخصي / مؤسسة) - كل واحد إله جدول
    // حركاته المفلترة، ورصيده الافتتاحي الخاص فيه لحاله
    if ($bank === 'jordan_personal' || $bank === 'jordan_business') {
        $usage = $bank === 'jordan_personal' ? 'personal' : 'business';
        $stmt = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN tx_type='deposit' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN tx_type='withdraw' THEN amount ELSE 0 END),0) AS balance
            FROM bank_jordan WHERE usage_type = ?");
        $stmt->execute([$usage]);
        $row = $stmt->fetch();
        $opening = get_opening_balance("bank_$bank");
        return (float)$row['balance'] + ($opening ? (float)$opening['amount'] : 0);
    }

    if (!isset($tables[$bank])) return 0;
    $table = $tables[$bank];
    $row = $db->query("SELECT
        COALESCE(SUM(CASE WHEN tx_type='deposit' THEN amount ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN tx_type='withdraw' THEN amount ELSE 0 END),0) AS balance
        FROM $table")->fetch();
    $opening = get_opening_balance('bank_' . $bank);
    return (float)$row['balance'] + ($opening ? (float)$opening['amount'] : 0);
}
function adjust_bank_balance_to_actual($bank, $actual_amount, $user_id, $usage_type = null, $tx_date = null) {
    $current = get_bank_balance($bank);
    $diff = round($actual_amount - $current, 2);
    $tx_date = $tx_date ?: date('Y-m-d');

    if ($diff == 0) {
        return ['success' => true, 'message' => 'الرصيد الفعلي مطابق للرصيد المحسوب أصلاً، ما في داعي لتعديل'];
    }

    $tx_type = $diff > 0 ? 'deposit' : 'withdraw';
    $amount = abs($diff);
    $description = 'تعديل لمطابقة الرصيد الفعلي';

    return add_bank_transaction($bank, $tx_type, $amount, $usage_type, $description, $tx_date, $user_id);
}
function reverse_mirrored_bank_movement($source_table, $source_id) {
    $db = get_db();
    $tables = ['bank_jordan', 'bank_etihad', 'bank_islami', 'bank_dinarak', 'bank_investment'];
    foreach ($tables as $table) {
        $stmt = $db->prepare("DELETE FROM `$table` WHERE linked_source_table = ? AND linked_source_id = ?");
        $stmt->execute([$source_table, $source_id]);
    }
}
function mirror_bank_movement($bank, $tx_type, $amount, $description, $tx_date, $user_id, $source_table, $source_id) {
    if (empty($bank) || $bank === 'cash') return; // ما في جدول بنك لـ"كاش"
    $tables = [
        'jordan' => 'bank_jordan',
        'jordan_personal' => 'bank_jordan',
        'jordan_business' => 'bank_jordan',
        'etihad' => 'bank_etihad',
        'islami' => 'bank_islami',
        'dinarak' => 'bank_dinarak',
        'investment' => 'bank_investment',
    ];
    if (!isset($tables[$bank])) return;

    $db = get_db();
    $table = $tables[$bank];

    // بنك الأردن شخصي/مؤسسة: نوع الاستخدام محدد تلقائياً من اسم الحساب نفسه
    if ($bank === 'jordan_personal') $usage_col = 'personal';
    elseif ($bank === 'jordan_business' || $bank === 'jordan') $usage_col = 'business';
    elseif ($bank === 'etihad') $usage_col = 'transfer';
    elseif ($bank === 'islami') $usage_col = 'other';
    elseif ($bank === 'investment') $usage_col = 'other';
    else $usage_col = null;

    if ($bank === 'dinarak') {
        $stmt = $db->prepare("INSERT INTO $table (tx_type, amount, related_customer, description, tx_date, created_by, linked_source_table, linked_source_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$tx_type, $amount, $description, $description, $tx_date, $user_id, $source_table, $source_id]);
    } else {
        $stmt = $db->prepare("INSERT INTO $table (tx_type, amount, usage_type, description, tx_date, created_by, linked_source_table, linked_source_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$tx_type, $amount, $usage_col, $description, $tx_date, $user_id, $source_table, $source_id]);
    }
}
