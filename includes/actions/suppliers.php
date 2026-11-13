<?php
// suppliers.php — supplier transactions & profile management
require_once __DIR__ . '/helpers.php';
function update_supplier_phone($supplier_id, $phone) {
    $db = get_db();
    $db->prepare('UPDATE suppliers SET phone = ? WHERE id = ?')->execute([$phone ?: null, $supplier_id]);
    return ['success' => true, 'message' => 'تم تحديث رقم الهاتف'];
}
function add_supplier_transaction($supplier_name, $type, $amount, $description, $tx_date, $user_id, $phone = null, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();

    // كل حركة (استلام بضاعة أو دفع فعلي) لازم يتحدد فيها بنك/كاش، أو سبب واضح.
    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $similar = find_similar_entities('suppliers', $supplier_name);
    if (!empty($similar)) {
        $names = implode('، ', array_map(fn($r) => '"' . $r['name'] . '"', $similar));
        return ['success' => false, 'ambiguous' => true, 'message' => "في أكتر من مورد بيتشابه اسمهم مع \"$supplier_name\": $names. حدد أي واحد بالضبط أو اكتب الاسم الكامل."];
    }

    $supplier_id = find_or_create_entity('suppliers', $supplier_name);
    if ($phone) {
        $db->prepare('UPDATE suppliers SET phone = ? WHERE id = ?')->execute([$phone, $supplier_id]);
    }

    $stmt = $db->prepare('INSERT INTO supplier_transactions (supplier_id, type, amount, description, tx_date, created_by, paid_from_bank, no_bank_reason) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$supplier_id, $type, $amount, $description, $tx_date, $user_id, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason]);
    $new_sup_id = $db->lastInsertId();

    if ($paid_from_bank) {
        mirror_bank_movement($paid_from_bank, $type === 'credit' ? 'withdraw' : 'deposit', $amount, "دفعة لمورد: $supplier_name", $tx_date, $user_id, 'supplier_transactions', $new_sup_id);
    }

    // balance يتحدث تلقائياً عبر trigger trg_st_balance_after_insert

    return [
        'success' => true,
        'message' => "تم تسجيل الحركة للمورد \"$supplier_name\" بمبلغ $amount",
        'entity_table' => 'supplier_transactions',
        'entity_id' => $new_sup_id,
    ];
}
function update_supplier_transaction($tx_id, $supplier_name, $type, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM supplier_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    // balance يتحدث تلقائياً عبر trigger trg_st_balance_after_update
    $supplier_id = find_or_create_entity('suppliers', $supplier_name);

    $stmt = $db->prepare('UPDATE supplier_transactions SET supplier_id=?, type=?, amount=?, description=?, tx_date=?, paid_from_bank=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$supplier_id, $type, $amount, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason, $tx_id]);

    reverse_mirrored_bank_movement('supplier_transactions', $tx_id);
    if (!empty($paid_from_bank)) {
        mirror_bank_movement($paid_from_bank, $type === 'credit' ? 'withdraw' : 'deposit', $amount, "دفعة لمورد: $supplier_name", $tx_date, $old['created_by'], 'supplier_transactions', $tx_id);
    }

    return ['success' => true, 'message' => "تم تعديل الحركة"];
}
function delete_supplier_transaction($tx_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM supplier_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    // balance يتحدث تلقائياً عبر trigger trg_st_balance_after_delete
    reverse_mirrored_bank_movement('supplier_transactions', $tx_id);
    $db->prepare('DELETE FROM supplier_transactions WHERE id = ?')->execute([$tx_id]);

    return ['success' => true, 'message' => 'تم حذف الحركة'];
}
function update_supplier_profile($supplier_id, $name, $phone) {
    $db = get_db();
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'اسم المورد مطلوب'];
    }
    $stmt = $db->prepare('SELECT id FROM suppliers WHERE name = ? AND id != ? LIMIT 1');
    $stmt->execute([$name, $supplier_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => "في مورد تاني مسجّل بنفس الاسم \"$name\" أصلاً - اختار اسم مختلف أو راجع القائمة."];
    }
    $db->prepare('UPDATE suppliers SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $supplier_id]);
    return ['success' => true, 'message' => "تم تحديث بيانات \"$name\""];
}
function delete_supplier($supplier_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([$supplier_id]);
    $s = $stmt->fetch();
    if (!$s) return ['success' => false, 'message' => 'المورد غير موجود'];

    $stmt = $db->prepare('SELECT COUNT(*) FROM supplier_transactions WHERE supplier_id = ?');
    $stmt->execute([$supplier_id]);
    $tx_count = (int) $stmt->fetchColumn();
    if ($tx_count > 0) {
        return [
            'success' => false,
            'message' => "ما فيك تحذف \"{$s['name']}\" لأنه عنده $tx_count حركة مسجّلة - حذفه بيمسح كل تاريخه المالي نهائياً. لو بدك توقف التعامل معه بس تحتفظ بسجله، خليه برصيده الحالي بدل الحذف."
        ];
    }
    $db->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplier_id]);
    return ['success' => true, 'message' => "تم حذف \"{$s['name']}\" (ما كان عنده أي حركات مسجّلة)"];
}
