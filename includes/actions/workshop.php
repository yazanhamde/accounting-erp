<?php
// workshop.php — workshop transactions & cash counting
require_once __DIR__ . '/helpers.php';
function add_workshop_transaction($type, $amount, $description, $tx_date, $user_id, $paid_from_bank = null, $no_bank_reason = null) {
    $db = get_db();

    // مصاريف المشغل فلوس فعلية طالعة - لازم تتحدد من أي حساب أو يتكتب سبب واضح.
    // إيراد المشغل: لو ما تحدد حساب بيحسب كاش تلقائياً (زي صيانة خارجية بالضبط).
    if ($type === 'expense' && empty($paid_from_bank) && trim((string)$no_bank_reason) === '') {
        return ['success' => false, 'message' => 'لازم تحدد من أي حساب بنك أو كاش انصرفت مصاريف المشغل، أو تكتب سبب واضح.'];
    }

    $stmt = $db->prepare('INSERT INTO workshop_transactions (type, amount, description, tx_date, created_by, paid_from_bank, no_bank_reason) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$type, $amount, $description, $tx_date, $user_id, $paid_from_bank ?: null, $paid_from_bank ? null : ($no_bank_reason ?: null)]);
    $new_id = $db->lastInsertId();

    if ($paid_from_bank) {
        $tx_type = $type === 'revenue' ? 'deposit' : 'withdraw';
        mirror_bank_movement($paid_from_bank, $tx_type, $amount, 'مشغل', $tx_date, $user_id, 'workshop_transactions', $new_id);
    }

    return [
        'success' => true,
        'message' => 'تم تسجيل حركة المشغل بمبلغ ' . $amount,
        'entity_table' => 'workshop_transactions',
        'entity_id' => $new_id,
    ];
}
function update_workshop_transaction($tx_id, $type, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM workshop_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    if ($type === 'expense' && empty($paid_from_bank) && trim((string)$no_bank_reason) === '') {
        return ['success' => false, 'message' => 'لازم تحدد من أي حساب بنك أو كاش انصرفت مصاريف المشغل، أو تكتب سبب واضح.'];
    }

    $stmt = $db->prepare('UPDATE workshop_transactions SET type=?, amount=?, description=?, tx_date=?, paid_from_bank=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$type, $amount, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : ($no_bank_reason ?: null), $tx_id]);

    reverse_mirrored_bank_movement('workshop_transactions', $tx_id);
    if (!empty($paid_from_bank)) {
        $tx_type = $type === 'revenue' ? 'deposit' : 'withdraw';
        mirror_bank_movement($paid_from_bank, $tx_type, $amount, 'مشغل', $tx_date, $old['created_by'], 'workshop_transactions', $tx_id);
    }

    return ['success' => true, 'message' => 'تم تعديل حركة المشغل'];
}
function delete_workshop_transaction($tx_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM workshop_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    reverse_mirrored_bank_movement('workshop_transactions', $tx_id);
    $db->prepare('DELETE FROM workshop_transactions WHERE id = ?')->execute([$tx_id]);
    return ['success' => true, 'message' => 'تم حذف حركة المشغل'];
}
function get_workshop_transactions($limit = 200) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM workshop_transactions ORDER BY tx_date DESC, id DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
function get_workshop_totals() {
    $db = get_db();
    $row = $db->query("SELECT
        COALESCE(SUM(CASE WHEN type='revenue' THEN amount ELSE 0 END),0) AS revenue,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense
        FROM workshop_transactions")->fetch();
    $revenue = (float)$row['revenue'];
    $expense = (float)$row['expense'];
    return ['revenue' => $revenue, 'expense' => $expense, 'net' => $revenue - $expense];
}
function add_cash_count($count_date, $expected_amount, $actual_amount, $notes, $user_id) {
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO cash_box (count_date, expected_amount, actual_amount, notes, created_by) VALUES (?,?,?,?,?)');
    $stmt->execute([$count_date, $expected_amount, $actual_amount, $notes, $user_id]);
    $diff = $actual_amount - $expected_amount;
    $label = $diff >= 0 ? 'زيادة' : 'عجز';
    return [
        'success' => true,
        'message' => "تم تسجيل عد الكاش ليوم $count_date - $label بمقدار " . number_format(abs($diff), 2),
        'entity_table' => 'cash_box',
        'entity_id' => $db->lastInsertId(),
    ];
}
function update_cash_count($id, $count_date, $expected_amount, $actual_amount, $notes) {
    $db = get_db();
    $stmt = $db->prepare('UPDATE cash_box SET count_date=?, expected_amount=?, actual_amount=?, notes=? WHERE id=?');
    $stmt->execute([$count_date, $expected_amount, $actual_amount, $notes, $id]);
    return ['success' => true, 'message' => 'تم تعديل عد الكاش'];
}
function delete_cash_count($id) {
    $db = get_db();
    $db->prepare('DELETE FROM cash_box WHERE id = ?')->execute([$id]);
    return ['success' => true, 'message' => 'تم حذف عد الكاش'];
}
function get_cash_box_total() {
    $db = get_db();
    $sum = (float)$db->query('SELECT COALESCE(SUM(difference),0) t FROM cash_box')->fetch()['t'];
    $opening = get_opening_balance('cash_diff');
    return $sum + ($opening ? (float)$opening['amount'] : 0);
}
function get_real_cash_balance() {
    $db = get_db();
    $lastCount = $db->query("SELECT * FROM cash_box ORDER BY count_date DESC, id DESC LIMIT 1")->fetch();
    $cashOpening = get_opening_balance('cash');
    $openingAmt = $cashOpening ? (float)$cashOpening['amount'] : 0;

    if ($lastCount) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN jl.side='debit' THEN jl.amount ELSE -jl.amount END),0) t
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.entry_id
            WHERE jl.account_type = 'cash' AND je.entry_date > ?
              AND je.source_table NOT IN ('cash_box','opening_balances')");
        $stmt->execute([$lastCount['count_date']]);
        $after = (float)$stmt->fetch()['t'];
        return $openingAmt + (float)$lastCount['actual_amount'] + $after;
    }

    $row = $db->query("SELECT COALESCE(SUM(CASE WHEN side='debit' THEN amount ELSE -amount END),0) t FROM journal_lines WHERE account_type = 'cash'")->fetch();
    return (float)$row['t'];
}
