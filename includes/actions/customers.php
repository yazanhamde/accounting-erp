<?php
// customers.php — customer transactions & profile management
require_once __DIR__ . '/helpers.php';
function update_customer_phone($customer_id, $phone) {
    $db = get_db();
    $db->prepare('UPDATE customers SET phone = ? WHERE id = ?')->execute([$phone ?: null, $customer_id]);
    return ['success' => true, 'message' => 'تم تحديث رقم الهاتف'];
}

// ---------------- عمولة مكينة الفيزا (بنك الاتحاد) - تلقائية ----------------
// بنك الاتحاد بالنظام مخصص فقط لمكينة الفيزا اللي بتاخد دفعات العملاء، وعليها
// عمولة ثابتة 8 بالألف (0.8%) بتقتطعها المكينة/البنك من كل عملية. بدل ما ندخلها
// يدوياً كل مرة، بنحسبها ونسجلها تلقائياً كمصروف مقتطع من رصيد بنك الاتحاد
// كل ما تنسجل دفعة عميل (credit) مسددة عبر بنك الاتحاد.
// لو تغيرت نسبة العمولة مستقبلاً، بس عدّل هاد الرقم.
define('ETIHAD_POS_COMMISSION_RATE', 0.008); // 8 بالألف = 0.008

// بيدور على مصروف العمولة التلقائي المرتبط بحركة عميل معينة (لو موجود) وبيحذفه
function reverse_etihad_pos_commission($customer_tx_id) {
    $db = get_db();
    $tag = "#{$customer_tx_id})";
    $stmt = $db->prepare("SELECT id FROM expenses WHERE description LIKE ?");
    $stmt->execute(['%' . $tag]);
    foreach ($stmt->fetchAll() as $row) {
        delete_expense($row['id']);
    }
}
function apply_etihad_pos_commission($customer_tx_id, $customer_name, $amount, $tx_date, $user_id) {
    $commission = round($amount * ETIHAD_POS_COMMISSION_RATE, 2);
    if ($commission <= 0) return;
    $desc = "عمولة مكينة فيزا - بنك الاتحاد (دفعة عميل: $customer_name #{$customer_tx_id})";
    add_expense('expense', $commission, $desc, 'etihad', $tx_date, $user_id);
}
function add_customer_transaction($customer_name, $type, $amount, $description, $tx_date, $user_id, $phone = null, $paid_from_bank = null, $no_bank_reason = null, $invoice_no = null, $tax_rate = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();

    // كل حركة (credit أو debit) لازم يتحدد فيها بنك/كاش، أو سبب واضح ليش ما تحدد
    // (مثلاً "بيع آجل - ما في فلوس تحركت فعلياً"، أو "سلفة نقدية من الصندوق").
    // هيك ولا حركة بتروح suspense بالغلط بدون ما المستخدم ياخد قرار واعي.
    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $similar = find_similar_entities('customers', $customer_name);
    if (!empty($similar)) {
        $names = implode('، ', array_map(fn($r) => '"' . $r['name'] . '"', $similar));
        return ['success' => false, 'ambiguous' => true, 'message' => "في أكتر من عميل بيتشابه اسمهم مع \"$customer_name\": $names. حدد أي واحد بالضبط أو اكتب الاسم الكامل."];
    }

    $customer_id = find_or_create_entity('customers', $customer_name);
    if ($phone) {
        $db->prepare('UPDATE customers SET phone = ? WHERE id = ?')->execute([$phone, $customer_id]);
    }

    // لو انحددت نسبة ضريبة، نعتبر $amount هو المبلغ شامل الضريبة ونفصلها حسابياً
    $amount_excl_tax = null;
    $tax_amount = null;
    if ($tax_rate !== null && $tax_rate !== '' && (float)$tax_rate > 0) {
        $tax_rate = (float)$tax_rate;
        $amount_excl_tax = round($amount / (1 + $tax_rate / 100), 2);
        $tax_amount = round($amount - $amount_excl_tax, 2);
    } else {
        $tax_rate = null;
    }

    $stmt = $db->prepare('INSERT INTO customer_transactions (customer_id, type, amount, description, tx_date, created_by, paid_from_bank, no_bank_reason, invoice_no, amount_excl_tax, tax_rate, tax_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$customer_id, $type, $amount, $description, $tx_date, $user_id, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason, $invoice_no ?: null, $amount_excl_tax, $tax_rate, $tax_amount]);
    $new_id = $db->lastInsertId();

    // لو انحدد حساب/بنك، نسجل حركة حقيقية فيه (يخلي الداشبورد ينعكس فوراً)
    if ($paid_from_bank) {
        mirror_bank_movement($paid_from_bank, $type === 'credit' ? 'deposit' : 'withdraw', $amount, "دفعة من عميل: $customer_name", $tx_date, $user_id, 'customer_transactions', $new_id);
    }

    // دفعة عميل (credit) عبر مكينة الفيزا (بنك الاتحاد) → اقتطاع عمولة تلقائياً
    if ($paid_from_bank === 'etihad' && $type === 'credit') {
        apply_etihad_pos_commission($new_id, $customer_name, $amount, $tx_date, $user_id);
    }

    // balance يتحدث تلقائياً عبر trigger trg_ct_balance_after_insert

    return [
        'success' => true,
        'message' => "تم تسجيل الحركة للعميل \"$customer_name\" بمبلغ $amount",
        'entity_table' => 'customer_transactions',
        'entity_id' => $new_id,
    ];
}
function update_customer_transaction($tx_id, $customer_name, $type, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $paid_from_bank = normalize_bank_key($paid_from_bank);
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM customer_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    // balance يتحدث تلقائياً عبر trigger trg_ct_balance_after_update
    $customer_id = find_or_create_entity('customers', $customer_name);

    $stmt = $db->prepare('UPDATE customer_transactions SET customer_id=?, type=?, amount=?, description=?, tx_date=?, paid_from_bank=?, no_bank_reason=? WHERE id=?');
    $stmt->execute([$customer_id, $type, $amount, $description, $tx_date, $paid_from_bank ?: null, $paid_from_bank ? null : $no_bank_reason, $tx_id]);

    // نمسح الحركة البنكية المرآة القديمة (كانت رح تضل فيها المبلغ/التاريخ القديم) ونعيد إنشاءها بالقيم الجديدة
    reverse_mirrored_bank_movement('customer_transactions', $tx_id);
    if (!empty($paid_from_bank)) {
        mirror_bank_movement($paid_from_bank, $type === 'credit' ? 'deposit' : 'withdraw', $amount, "دفعة من عميل: $customer_name", $tx_date, $old['created_by'], 'customer_transactions', $tx_id);
    }

    // نلغي عمولة الفيزا القديمة المرتبطة بهاي الحركة (لو كانت موجودة) ونعيد حسابها
    // بالقيم الجديدة لو لسا مستوفية الشرط (دفعة عميل عبر بنك الاتحاد)
    reverse_etihad_pos_commission($tx_id);
    if ($paid_from_bank === 'etihad' && $type === 'credit') {
        apply_etihad_pos_commission($tx_id, $customer_name, $amount, $tx_date, $old['created_by']);
    }

    return ['success' => true, 'message' => "تم تعديل الحركة"];
}
function delete_customer_transaction($tx_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM customer_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    // balance يتحدث تلقائياً عبر trigger trg_ct_balance_after_delete
    reverse_mirrored_bank_movement('customer_transactions', $tx_id);
    reverse_etihad_pos_commission($tx_id);
    $db->prepare('DELETE FROM customer_transactions WHERE id = ?')->execute([$tx_id]);

    return ['success' => true, 'message' => 'تم حذف الحركة'];
}
function update_customer_profile($customer_id, $name, $phone) {
    $db = get_db();
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'اسم العميل مطلوب'];
    }
    $stmt = $db->prepare('SELECT id FROM customers WHERE name = ? AND id != ? LIMIT 1');
    $stmt->execute([$name, $customer_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => "في عميل تاني مسجّل بنفس الاسم \"$name\" أصلاً - اختار اسم مختلف أو راجع القائمة."];
    }
    $db->prepare('UPDATE customers SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $customer_id]);
    return ['success' => true, 'message' => "تم تحديث بيانات \"$name\""];
}
function delete_customer($customer_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customer_id]);
    $c = $stmt->fetch();
    if (!$c) return ['success' => false, 'message' => 'العميل غير موجود'];

    $stmt = $db->prepare('SELECT COUNT(*) FROM customer_transactions WHERE customer_id = ?');
    $stmt->execute([$customer_id]);
    $tx_count = (int) $stmt->fetchColumn();
    if ($tx_count > 0) {
        return [
            'success' => false,
            'message' => "ما فيك تحذف \"{$c['name']}\" لأنه عنده $tx_count حركة مسجّلة - حذفه بيمسح كل تاريخه المالي نهائياً. لو بدك توقف التعامل معه بس تحتفظ بسجله، خليه برصيده الحالي بدل الحذف."
        ];
    }
    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$customer_id]);
    return ['success' => true, 'message' => "تم حذف \"{$c['name']}\" (ما كان عنده أي حركات مسجّلة)"];
}
