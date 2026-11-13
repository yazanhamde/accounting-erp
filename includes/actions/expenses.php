<?php
// expenses.php — expenses & external revenues
require_once __DIR__ . '/helpers.php';
function add_expense($category, $amount, $description, $paid_from_bank, $tx_date, $user_id, $no_bank_reason = null, $invoice_no = null, $tax_rate = null) {
    $db = get_db();
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason ?? null);
    if ($err) return ['success' => false, 'message' => $err];

    $amount_excl_tax = null;
    $tax_amount = null;
    if ($tax_rate !== null && $tax_rate !== '' && (float)$tax_rate > 0) {
        $tax_rate = (float)$tax_rate;
        $amount_excl_tax = round($amount / (1 + $tax_rate / 100), 2);
        $tax_amount = round($amount - $amount_excl_tax, 2);
    } else {
        $tax_rate = null;
    }

    $stmt = $db->prepare('INSERT INTO expenses (category, amount, description, paid_from_bank, tx_date, created_by, no_bank_reason, invoice_no, amount_excl_tax, tax_rate, tax_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$category, $amount, $description, $paid_from_bank, $tx_date, $user_id, $paid_from_bank ? null : ($no_bank_reason ?? null), $invoice_no ?: null, $amount_excl_tax, $tax_rate, $tax_amount]);
    $new_exp_id = $db->lastInsertId();

    if ($paid_from_bank) {
        mirror_bank_movement($paid_from_bank, 'withdraw', $amount, "مصروف: $description", $tx_date, $user_id, 'expenses', $new_exp_id);
    }

    $labels = ['expense' => 'مصروف', 'personal_withdrawal' => 'سحب شخصي', 'donation' => 'تبرع'];
    return [
        'success' => true,
        'message' => "تم تسجيل " . ($labels[$category] ?? $category) . " بمبلغ $amount",
        'entity_table' => 'expenses',
        'entity_id' => $new_exp_id,
    ];
}
function update_expense($id, $category, $amount, $description, $paid_from_bank, $tx_date, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $stmt = $db->prepare('SELECT created_by FROM expenses WHERE id = ?');
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $stmt = $db->prepare('UPDATE expenses SET category=?, amount=?, description=?, paid_from_bank=?, tx_date=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$category, $amount, $description, $paid_from_bank ?: null, $tx_date, $paid_from_bank ? null : $no_bank_reason, $id]);

    reverse_mirrored_bank_movement('expenses', $id);
    if (!empty($paid_from_bank)) {
        mirror_bank_movement($paid_from_bank, 'withdraw', $amount, "مصروف: $description", $tx_date, $old['created_by'], 'expenses', $id);
    }

    return ['success' => true, 'message' => 'تم تعديل الحركة'];
}
function delete_expense($id) {
    $db = get_db();
    reverse_mirrored_bank_movement('expenses', $id);
    $db->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
    return ['success' => true, 'message' => 'تم حذف الحركة'];
}
function add_external_revenue($source_person, $amount, $description, $paid_from_bank, $tx_date, $user_id, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $source_person = trim((string)$source_person) !== '' ? trim($source_person) : 'رمزي';

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason ?? null);
    if ($err) return ['success' => false, 'message' => $err];

    $stmt = $db->prepare('INSERT INTO external_revenues (source_person, amount, description, tx_date, paid_from_bank, no_bank_reason, created_by) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$source_person, $amount, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : ($no_bank_reason ?? null), $user_id]);
    $new_id = $db->lastInsertId();

    if ($paid_from_bank) {
        mirror_bank_movement($paid_from_bank, 'deposit', $amount, "إيراد خارجي: $source_person" . ($description ? " - $description" : ''), $tx_date, $user_id, 'external_revenues', $new_id);
    }

    return [
        'success' => true,
        'message' => "تم تسجيل إيراد خارجي لـ \"$source_person\" بمبلغ $amount",
        'entity_table' => 'external_revenues',
        'entity_id' => $new_id,
    ];
}
function update_external_revenue($id, $source_person, $amount, $description, $paid_from_bank, $tx_date, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $stmt = $db->prepare('SELECT created_by FROM external_revenues WHERE id = ?');
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $source_person = trim((string)$source_person) !== '' ? trim($source_person) : 'رمزي';
    $stmt = $db->prepare('UPDATE external_revenues SET source_person=?, amount=?, description=?, paid_from_bank=?, tx_date=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$source_person, $amount, $description, $paid_from_bank ?: null, $tx_date, $paid_from_bank ? null : $no_bank_reason, $id]);

    reverse_mirrored_bank_movement('external_revenues', $id);
    if (!empty($paid_from_bank)) {
        mirror_bank_movement($paid_from_bank, 'deposit', $amount, "إيراد خارجي: $source_person" . ($description ? " - $description" : ''), $tx_date, $old['created_by'], 'external_revenues', $id);
    }

    return ['success' => true, 'message' => 'تم تعديل الحركة'];
}
function delete_external_revenue($id) {
    $db = get_db();
    reverse_mirrored_bank_movement('external_revenues', $id);
    $db->prepare('DELETE FROM external_revenues WHERE id = ?')->execute([$id]);
    return ['success' => true, 'message' => 'تم حذف الحركة'];
}
function get_external_revenues_total() {
    $db = get_db();
    return $db->query('SELECT COALESCE(SUM(amount),0) t FROM external_revenues')->fetch()['t'];
}
