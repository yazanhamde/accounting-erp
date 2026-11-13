<?php
// checks.php — check management (incoming/outgoing)
require_once __DIR__ . '/helpers.php';
function check_entity_tx_type($direction, $entity_type) {
    if ($entity_type === 'customer') {
        // وارد من عميل = دفعة منه (تقل ذمته) = credit. صادر لعميل = حالة نادرة (استرجاع/سلفة) = debit.
        return $direction === 'incoming' ? 'credit' : 'debit';
    }
    if ($entity_type === 'supplier') {
        // صادر لمورد = دفعنا له (تقل ذمتنا) = credit. وارد من مورد = حالة نادرة (استرجاع منه) = debit.
        return $direction === 'incoming' ? 'debit' : 'credit';
    }
    // person: وارد = هو يسدد لنا (repay). صادر = نحن أعطيناه (lend).
    return $direction === 'incoming' ? 'repay' : 'lend';
}
function create_check_entity_link($direction, $entity_type, $entity_name, $amount, $tx_date, $user_id, $check_number) {
    if (empty($entity_type) || trim((string)$entity_name) === '') return null;

    $type = check_entity_tx_type($direction, $entity_type);
    $label = $direction === 'incoming' ? 'شيك برسم التحصيل' : 'شيك آجل';
    $reason = $label . ($check_number ? " رقم $check_number" : '') . ' - بدون حركة بنكية حتى الصرف';
    $desc = "$label - $entity_name" . ($check_number ? " (شيك #$check_number)" : '');

    if ($entity_type === 'customer') {
        $r = add_customer_transaction($entity_name, $type, $amount, $desc, $tx_date, $user_id, null, null, $reason);
    } elseif ($entity_type === 'supplier') {
        $r = add_supplier_transaction($entity_name, $type, $amount, $desc, $tx_date, $user_id, null, null, $reason);
    } else {
        $r = add_personal_transaction($entity_name, $type, $amount, $desc, $tx_date, $user_id, null, $reason);
    }
    if (empty($r['success'])) return ['error' => $r['message'] ?? 'تعذر ربط الشيك بحساب الجهة'];
    return ['entity_table' => $r['entity_table'], 'entity_id' => $r['entity_id']];
}
function remove_check_entity_link($linked_tx_table, $linked_tx_id) {
    if (empty($linked_tx_table) || empty($linked_tx_id)) return;
    undo_entity($linked_tx_table, $linked_tx_id);
}
function add_check($direction, $party_name, $amount, $due_date, $bank_name, $check_number, $notes, $user_id, $entity_type = null, $entity_name = null) {
    $db = get_db();
    $entity_type = in_array($entity_type, ['customer', 'supplier', 'person'], true) ? $entity_type : null;

    $link = null;
    if ($entity_type) {
        $link = create_check_entity_link($direction, $entity_type, $entity_name, $amount, $due_date, $user_id, $check_number);
        if ($link && isset($link['error'])) {
            return ['success' => false, 'message' => $link['error']];
        }
    }

    $entity_id = $entity_type ? find_or_create_entity(
        $entity_type === 'customer' ? 'customers' : ($entity_type === 'supplier' ? 'suppliers' : 'personal_accounts'),
        $entity_name
    ) : null;

    $stmt = $db->prepare('INSERT INTO checks (check_number, direction, party_name, entity_type, entity_id, linked_tx_table, linked_tx_id, amount, bank_name, due_date, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $check_number, $direction, $party_name ?: ($entity_name ?? ''), $entity_type, $entity_id,
        $link['entity_table'] ?? null, $link['entity_id'] ?? null,
        $amount, $bank_name, $due_date, $notes, $user_id,
    ]);

    return [
        'success' => true,
        'message' => "تم تسجيل شيك بمبلغ $amount بتاريخ استحقاق $due_date",
        'entity_table' => 'checks',
        'entity_id' => $db->lastInsertId(),
    ];
}
function update_check($id, $direction, $party_name, $amount, $due_date, $bank_name, $check_number, $notes, $entity_type = null, $entity_name = null) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM checks WHERE id = ?');
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الشيك غير موجود'];

    $entity_type = in_array($entity_type, ['customer', 'supplier', 'person'], true) ? $entity_type : null;

    // نلغي الحركة القديمة المرتبطة بجهة (لو كانت موجودة) قبل ما ننشئ الجديدة
    remove_check_entity_link($old['linked_tx_table'], $old['linked_tx_id']);

    $link = null;
    // إذا الشيك حالته "مرتجع" حالياً، ما لازم ننشئ حركة جهة جديدة (الدين أصلاً معاد لصاحبه)
    if ($entity_type && $old['status'] !== 'bounced') {
        $link = create_check_entity_link($direction, $entity_type, $entity_name, $amount, $due_date, $old['created_by'], $check_number);
        if ($link && isset($link['error'])) {
            return ['success' => false, 'message' => $link['error']];
        }
    }

    $entity_id = $entity_type ? find_or_create_entity(
        $entity_type === 'customer' ? 'customers' : ($entity_type === 'supplier' ? 'suppliers' : 'personal_accounts'),
        $entity_name
    ) : null;

    $stmt = $db->prepare('UPDATE checks SET check_number=?, direction=?, party_name=?, entity_type=?, entity_id=?, linked_tx_table=?, linked_tx_id=?, amount=?, bank_name=?, due_date=?, notes=? WHERE id=?');
    $stmt->execute([
        $check_number, $direction, $party_name ?: ($entity_name ?? ''), $entity_type, $entity_id,
        $link['entity_table'] ?? null, $link['entity_id'] ?? null,
        $amount, $bank_name, $due_date, $notes, $id,
    ]);

    // لو الشيك "تم الصرف" فعلياً وكانت له حركة بنك حقيقية مرآة، وتغيّر المبلغ بالتعديل،
    // لازم نلغي المرآة القديمة (بمبلغها القديم) ونسجل مرآة جديدة بالمبلغ الجديد، وإلا
    // بيضل رصيد البنك محسوب على المبلغ القديم رغم إن الشيك انعدّل.
    if ($old['status'] === 'cleared' && !empty($old['cleared_via_bank']) && (float)$old['amount'] !== (float)$amount) {
        reverse_mirrored_bank_movement('checks', $id);
        $direction_deposit = $direction === 'incoming';
        $tx_type = $direction_deposit ? 'deposit' : 'withdraw';
        mirror_bank_movement($old['cleared_via_bank'], $tx_type, $amount, "تعديل شيك محصّل: " . ($party_name ?: ($entity_name ?? '')), date('Y-m-d'), $old['created_by'], 'checks', $id);
    }

    return ['success' => true, 'message' => 'تم تعديل الشيك'];
}
function delete_check($id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM checks WHERE id = ?');
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if ($old) {
        remove_check_entity_link($old['linked_tx_table'], $old['linked_tx_id']);
        // لو كان للشيك حركة بنك حقيقية مرآة (تم الصرف عبر حساب فعلي)، لازم نلغيها وإلا
        // بيضل رصيد البنك متأثر بشيك محذوف أصلاً.
        reverse_mirrored_bank_movement('checks', $id);
    }
    $db->prepare('DELETE FROM checks WHERE id = ?')->execute([$id]);
    return ['success' => true, 'message' => 'تم حذف الشيك'];
}
function update_check_entity_link_on_status_change($check_id, $old_status, $new_status) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM checks WHERE id = ?');
    $stmt->execute([$check_id]);
    $check = $stmt->fetch();
    if (!$check || empty($check['entity_type'])) return;

    if ($new_status === 'bounced' && $old_status !== 'bounced') {
        // الشيك ارتد: نلغي حركة الجهة (الدين يرجع كما كان) ونصفر الربط
        remove_check_entity_link($check['linked_tx_table'], $check['linked_tx_id']);
        $db->prepare('UPDATE checks SET linked_tx_table=NULL, linked_tx_id=NULL WHERE id=?')->execute([$check_id]);
    } elseif ($old_status === 'bounced' && $new_status !== 'bounced' && empty($check['linked_tx_id'])) {
        // الشيك كان مرتجع ورجع لحالة تانية (قيد الانتظار/تم الصرف): نعيد إنشاء حركة الجهة
        $entity_table = $check['entity_type'] === 'customer' ? 'customers' : ($check['entity_type'] === 'supplier' ? 'suppliers' : 'personal_accounts');
        $stmt2 = $db->prepare("SELECT name FROM `$entity_table` WHERE id = ?");
        $stmt2->execute([$check['entity_id']]);
        $name = $stmt2->fetch()['name'] ?? null;
        if ($name) {
            $link = create_check_entity_link($check['direction'], $check['entity_type'], $name, $check['amount'], date('Y-m-d'), $check['created_by'], $check['check_number']);
            if ($link && !isset($link['error'])) {
                $db->prepare('UPDATE checks SET linked_tx_table=?, linked_tx_id=? WHERE id=?')
                   ->execute([$link['entity_table'], $link['entity_id'], $check_id]);
            }
        }
    }
}
function get_upcoming_checks($days = 7) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM checks WHERE status = 'pending' AND due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY due_date ASC");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}
