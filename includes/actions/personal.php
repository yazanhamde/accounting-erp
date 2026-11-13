<?php
// personal.php — personal accounts, profit distribution, shareholder contributions
require_once __DIR__ . '/helpers.php';
function add_personal_transaction($person_name, $type, $amount, $description, $tx_date, $user_id, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $person_id = find_or_create_entity('personal_accounts', $person_name);

    $stmt = $db->prepare('INSERT INTO personal_transactions (personal_account_id, type, amount, description, tx_date, created_by, paid_from_bank, no_bank_reason) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$person_id, $type, $amount, $description, $tx_date, $user_id, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason]);
    $new_pt_id = $db->lastInsertId();

    // لو انحدد حساب/بنك، نسجل حركة حقيقية فيه (كانت ناقصة - يخلي الداشبورد ينعكس فوراً)
    if ($paid_from_bank) {
        mirror_bank_movement($paid_from_bank, $type === 'lend' ? 'withdraw' : 'deposit', $amount, ($type === 'lend' ? "سلفة لـ: $person_name" : "تسديد من: $person_name"), $tx_date, $user_id, 'personal_transactions', $new_pt_id);
    }

    // lend: نحن أعطينا سلفة => هو صار مدين لنا (+) / repay: سدد => (-)
    $sign = $type === 'lend' ? 1 : -1;
    $db->prepare('UPDATE personal_accounts SET balance = balance + ? WHERE id = ?')
       ->execute([$sign * $amount, $person_id]);

    $label = $type === 'lend' ? 'سلفة' : 'تسديد';
    return [
        'success' => true,
        'message' => "تم تسجيل $label لـ \"$person_name\" بمبلغ $amount",
        'entity_table' => 'personal_transactions',
        'entity_id' => $new_pt_id,
    ];
}
function update_personal_transaction($tx_id, $person_name, $type, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM personal_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $old_sign = $old['type'] === 'lend' ? 1 : -1;
    $db->prepare('UPDATE personal_accounts SET balance = balance - ? WHERE id = ?')
       ->execute([$old_sign * $old['amount'], $old['personal_account_id']]);

    $person_id = find_or_create_entity('personal_accounts', $person_name);
    $new_sign = $type === 'lend' ? 1 : -1;
    $db->prepare('UPDATE personal_accounts SET balance = balance + ? WHERE id = ?')
       ->execute([$new_sign * $amount, $person_id]);

    $stmt = $db->prepare('UPDATE personal_transactions SET personal_account_id=?, type=?, amount=?, description=?, tx_date=?, paid_from_bank=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$person_id, $type, $amount, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason, $tx_id]);

    reverse_mirrored_bank_movement('personal_transactions', $tx_id);
    if (!empty($paid_from_bank)) {
        mirror_bank_movement($paid_from_bank, $type === 'lend' ? 'withdraw' : 'deposit', $amount, ($type === 'lend' ? "سلفة لـ: $person_name" : "تسديد من: $person_name"), $tx_date, $old['created_by'], 'personal_transactions', $tx_id);
    }

    return ['success' => true, 'message' => 'تم تعديل الحركة'];
}
function delete_personal_transaction($tx_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM personal_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $sign = $old['type'] === 'lend' ? 1 : -1;
    $db->prepare('UPDATE personal_accounts SET balance = balance - ? WHERE id = ?')
       ->execute([$sign * $old['amount'], $old['personal_account_id']]);
    reverse_mirrored_bank_movement('personal_transactions', $tx_id);
    $db->prepare('DELETE FROM personal_transactions WHERE id = ?')->execute([$tx_id]);

    return ['success' => true, 'message' => 'تم حذف الحركة'];
}
function add_profit_distribution($shareholder_name, $amount, $period_label, $description, $tx_date, $user_id, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $shareholder_id = find_or_create_entity('shareholders', $shareholder_name);

    $stmt = $db->prepare('INSERT INTO profit_distributions (shareholder_id, amount, period_label, description, tx_date, created_by, paid_from_bank, no_bank_reason) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$shareholder_id, $amount, $period_label, $description, $tx_date, $user_id, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason]);
    $new_dist_id = $db->lastInsertId();

    if ($paid_from_bank) {
        mirror_bank_movement($paid_from_bank, 'withdraw', $amount, "توزيع أرباح: $shareholder_name", $tx_date, $user_id, 'profit_distributions', $new_dist_id);
    }

    return [
        'success' => true,
        'message' => "تم تسجيل توزيع أرباح لـ \"$shareholder_name\" بمبلغ $amount",
        'entity_table' => 'profit_distributions',
        'entity_id' => $new_dist_id,
    ];
}
function update_profit_distribution($dist_id, $shareholder_name, $amount, $period_label, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM profit_distributions WHERE id = ?');
    $stmt->execute([$dist_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'التوزيع غير موجود'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $shareholder_id = find_or_create_entity('shareholders', $shareholder_name);
    $stmt = $db->prepare('UPDATE profit_distributions SET shareholder_id=?, amount=?, period_label=?, description=?, tx_date=?, paid_from_bank=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$shareholder_id, $amount, $period_label, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason, $dist_id]);

    reverse_mirrored_bank_movement('profit_distributions', $dist_id);
    if (!empty($paid_from_bank)) {
        mirror_bank_movement($paid_from_bank, 'withdraw', $amount, "توزيع أرباح: $shareholder_name", $tx_date, $old['created_by'], 'profit_distributions', $dist_id);
    }

    return ['success' => true, 'message' => 'تم تعديل التوزيع'];
}
function delete_profit_distribution($dist_id) {
    $db = get_db();
    reverse_mirrored_bank_movement('profit_distributions', $dist_id);
    $db->prepare('DELETE FROM profit_distributions WHERE id = ?')->execute([$dist_id]);
    return ['success' => true, 'message' => 'تم حذف التوزيع'];
}
function add_shareholder_contribution($shareholder_name, $amount, $description, $tx_date, $user_id, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $shareholder_id = find_or_create_entity('shareholders', $shareholder_name);

    $stmt = $db->prepare('INSERT INTO shareholder_contributions (shareholder_id, amount, description, tx_date, created_by, paid_from_bank, no_bank_reason) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$shareholder_id, $amount, $description, $tx_date, $user_id, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason]);
    $new_id = $db->lastInsertId();

    if ($paid_from_bank) {
        mirror_bank_movement($paid_from_bank, 'deposit', $amount, "مساهمة/إضافة فلوس من: $shareholder_name", $tx_date, $user_id, 'shareholder_contributions', $new_id);
    }

    return [
        'success' => true,
        'message' => "تم تسجيل مساهمة \"$shareholder_name\" بمبلغ $amount، ورصيده صار أعلى بنفس المبلغ",
        'entity_table' => 'shareholder_contributions',
        'entity_id' => $new_id,
    ];
}
function update_shareholder_contribution($id, $shareholder_name, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM shareholder_contributions WHERE id = ?');
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'المساهمة غير موجودة'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $shareholder_id = find_or_create_entity('shareholders', $shareholder_name);
    $stmt = $db->prepare('UPDATE shareholder_contributions SET shareholder_id=?, amount=?, description=?, tx_date=?, paid_from_bank=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$shareholder_id, $amount, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason, $id]);

    reverse_mirrored_bank_movement('shareholder_contributions', $id);
    if (!empty($paid_from_bank)) {
        mirror_bank_movement($paid_from_bank, 'deposit', $amount, "مساهمة/إضافة فلوس من: $shareholder_name", $tx_date, $old['created_by'], 'shareholder_contributions', $id);
    }

    return ['success' => true, 'message' => 'تم تعديل المساهمة'];
}
function delete_shareholder_contribution($id) {
    $db = get_db();
    reverse_mirrored_bank_movement('shareholder_contributions', $id);
    $db->prepare('DELETE FROM shareholder_contributions WHERE id = ?')->execute([$id]);
    return ['success' => true, 'message' => 'تم حذف المساهمة'];
}
function update_personal_profile($person_id, $name, $phone) {
    $db = get_db();
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'الاسم مطلوب'];
    }
    $stmt = $db->prepare('SELECT id FROM personal_accounts WHERE name = ? AND id != ? LIMIT 1');
    $stmt->execute([$name, $person_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => "في شخص تاني مسجّل بنفس الاسم \"$name\" أصلاً - اختار اسم مختلف أو راجع القائمة."];
    }
    $db->prepare('UPDATE personal_accounts SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $person_id]);
    return ['success' => true, 'message' => "تم تحديث بيانات \"$name\""];
}
function delete_personal($person_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM personal_accounts WHERE id = ?');
    $stmt->execute([$person_id]);
    $p = $stmt->fetch();
    if (!$p) return ['success' => false, 'message' => 'الشخص غير موجود'];

    $stmt = $db->prepare('SELECT COUNT(*) FROM personal_transactions WHERE personal_account_id = ?');
    $stmt->execute([$person_id]);
    $tx_count = (int) $stmt->fetchColumn();
    if ($tx_count > 0) {
        return [
            'success' => false,
            'message' => "ما فيك تحذف \"{$p['name']}\" لأنه عنده $tx_count حركة مسجّلة - حذفه بيمسح كل تاريخه المالي نهائياً. لو بدك توقف التعامل معه بس تحتفظ بسجله، خليه برصيده الحالي بدل الحذف."
        ];
    }
    $db->prepare('DELETE FROM personal_accounts WHERE id = ?')->execute([$person_id]);
    return ['success' => true, 'message' => "تم حذف \"{$p['name']}\" (ما كان عنده أي حركات مسجّلة)"];
}
