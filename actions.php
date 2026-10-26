<?php
/**
 * =====================================================================
 * كل دالة هون بتمثل "إجراء محاسبي" ممكن يستدعيه:
 * 1) الشات بوت (بعد ما يفهم OpenAI قصد المستخدم)
 * 2) النماذج اليدوية بالداشبورد
 * كل دالة بترجع array فيها success و message عشان نعرضها للمستخدم
 * =====================================================================
 */
require_once __DIR__ . '/db.php';

// =====================================================================
// التراجع عن آخر عملية سجّلها البوت لهذا المستخدم (Undo)
// بيستخدم دوال الحذف الموجودة أصلاً لكل نوع جدول
// =====================================================================
function undo_entity($entity_table, $entity_id) {
    switch ($entity_table) {
        case 'customer_transactions':
            return delete_customer_transaction($entity_id);
        case 'supplier_transactions':
            return delete_supplier_transaction($entity_id);
        case 'personal_transactions':
            return delete_personal_transaction($entity_id);
        case 'employee_transactions':
            return delete_employee_transaction($entity_id);
        case 'car_employee_transactions':
            return delete_car_employee_transaction($entity_id);
        case 'profit_distributions':
            return delete_profit_distribution($entity_id);
        case 'expenses':
            return delete_expense($entity_id);
        case 'external_revenues':
            return delete_external_revenue($entity_id);
        case 'checks':
            return delete_check($entity_id);
        case 'bank_jordan':
            return delete_bank_transaction('jordan', $entity_id);
        case 'bank_etihad':
            return delete_bank_transaction('etihad', $entity_id);
        case 'bank_islami':
            return delete_bank_transaction('islami', $entity_id);
        case 'bank_dinarak':
            return delete_bank_transaction('dinarak', $entity_id);
        case 'bank_investment':
            return delete_bank_transaction('investment', $entity_id);
        case 'cash_box':
            return delete_cash_count($entity_id);
        default:
            return ['success' => false, 'message' => 'ما قدرت أعرف نوع العملية المطلوب التراجع عنها'];
    }
}

// ---------------- ضبط استخدام حساب "suspense" (معلّق) ----------------
// suspense لازم يبقى استثناء موثّق، مش خيار افتراضي بينسى حاله. أي حركة
// ما فيها paid_from_bank لازم يكون فيها no_bank_reason مكتوب بالإيد، وإلا نرفض الحفظ.
function require_bank_or_reason($paid_from_bank, $no_bank_reason) {
    if (empty($paid_from_bank) && trim((string)$no_bank_reason) === '') {
        return 'لازم تحدد "من/إلى أي بنك أو كاش" هاي الحركة، أو تكتب سبب واضح ليش ما فيه بنك محدد (مثلاً: سلفة مفتوحة لسا ما تحدد مصدرها). هاد الشرط موجود عشان حساب suspense ما يصير سلة مهملات.';
    }
    return null;
}

// بتوحّد الاسم للمقارنة بس (مش للعرض): بتعتبر "ة" و"ه" نفس الحرف، وبتشيل الفراغات الزايدة،
// حتى "شركة الأمل" و"شركه الأمل" ما ينسجلوا كحسابين مختلفين بالغلط بسبب فرق إملائي.
function normalize_name_for_match($name) {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return str_replace('ة', 'ه', $name);
}

function find_or_create_entity($table, $name) {
    $db = get_db();
    $normalized = normalize_name_for_match($name);
    $stmt = $db->prepare("SELECT id, name FROM $table WHERE REPLACE(name, 'ة', 'ه') = ? LIMIT 1");
    $stmt->execute([$normalized]);
    $row = $stmt->fetch();
    if ($row) return $row['id'];

    $stmt = $db->prepare("INSERT INTO $table (name) VALUES (?)");
    $stmt->execute([trim($name)]);
    return $db->lastInsertId();
}

// بيرجع لستة الأسماء المشابهة الموجودة أصلاً بالجدول (لتفادي إنشاء زبون/مورد مكرر بالغلط
// أو التسجيل على شخص غلط لما الاسم يكون قصير/غامض زي "أحمد" وفي أكتر من "أحمد" بالنظام)
function find_similar_entities($table, $name) {
    $db = get_db();
    $name = trim($name);
    if ($name === '') return [];

    // تطابق جزئي: أي اسم موجود يحتوي هذا الاسم أو محتوى فيه (يغطي "أحمد" مقابل "أحمد الخطيب")
    $stmt = $db->prepare("SELECT id, name FROM $table WHERE name LIKE ? LIMIT 5");
    $stmt->execute(['%' . $name . '%']);
    $matches = $stmt->fetchAll();

    // تطابق تام باسم واحد فقط = ما فيه لبس، رجّع مصفوفة فاضية
    if (count($matches) === 1 && strcasecmp($matches[0]['name'], $name) === 0) {
        return [];
    }
    // لو ما فيه إلا تطابق واحد بس بالـ LIKE، وما فيه غموض فعلي، خليه يعدي عادي
    if (count($matches) <= 1) {
        return [];
    }
    return $matches;
}

function update_customer_phone($customer_id, $phone) {
    $db = get_db();
    $db->prepare('UPDATE customers SET phone = ? WHERE id = ?')->execute([$phone ?: null, $customer_id]);
    return ['success' => true, 'message' => 'تم تحديث رقم الهاتف'];
}

function update_supplier_phone($supplier_id, $phone) {
    $db = get_db();
    $db->prepare('UPDATE suppliers SET phone = ? WHERE id = ?')->execute([$phone ?: null, $supplier_id]);
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
// مع الحركة البنكية المرآة تبعه، حتى نقدر نعيد حسابها بشكل صحيح عند التعديل/الحذف
function reverse_etihad_pos_commission($customer_tx_id) {
    $db = get_db();
    $tag = "#{$customer_tx_id})";
    $stmt = $db->prepare("SELECT id FROM expenses WHERE description LIKE ?");
    $stmt->execute(['%' . $tag]);
    foreach ($stmt->fetchAll() as $row) {
        delete_expense($row['id']);
    }
}

// بينشئ مصروف "عمولة مكينة فيزا" تلقائي بمبلغ = amount * ETIHAD_POS_COMMISSION_RATE
// ومرتبط (عن طريق وسم بالوصف) بحركة العميل $customer_tx_id، حتى نقدر نرجعه/نلغيه لاحقاً
function apply_etihad_pos_commission($customer_tx_id, $customer_name, $amount, $tx_date, $user_id) {
    $commission = round($amount * ETIHAD_POS_COMMISSION_RATE, 2);
    if ($commission <= 0) return;
    $desc = "عمولة مكينة فيزا - بنك الاتحاد (دفعة عميل: $customer_name #{$customer_tx_id})";
    add_expense('expense', $commission, $desc, 'etihad', $tx_date, $user_id);
}

// ---------------- ذمم العملاء ----------------
function add_customer_transaction($customer_name, $type, $amount, $description, $tx_date, $user_id, $phone = null, $paid_from_bank = null, $no_bank_reason = null, $invoice_no = null, $tax_rate = null) {
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

    $sign = $type === 'debit' ? 1 : -1;
    $db->prepare('UPDATE customers SET balance = balance + ? WHERE id = ?')
       ->execute([$sign * $amount, $customer_id]);

    return [
        'success' => true,
        'message' => "تم تسجيل الحركة للعميل \"$customer_name\" بمبلغ $amount",
        'entity_table' => 'customer_transactions',
        'entity_id' => $new_id,
    ];
}

// ---------------- ذمم الموردين ----------------
function add_supplier_transaction($supplier_name, $type, $amount, $description, $tx_date, $user_id, $phone = null, $paid_from_bank = null, $no_bank_reason = null) {
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

    $sign = $type === 'debit' ? 1 : -1;
    $db->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')
       ->execute([$sign * $amount, $supplier_id]);

    return [
        'success' => true,
        'message' => "تم تسجيل الحركة للمورد \"$supplier_name\" بمبلغ $amount",
        'entity_table' => 'supplier_transactions',
        'entity_id' => $new_sup_id,
    ];
}

function update_customer_transaction($tx_id, $customer_name, $type, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM customer_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    // نرجّع أثر الحركة القديمة عن صاحبها القديم
    $old_sign = $old['type'] === 'debit' ? 1 : -1;
    $db->prepare('UPDATE customers SET balance = balance - ? WHERE id = ?')
       ->execute([$old_sign * $old['amount'], $old['customer_id']]);

    // ونطبّق أثر الحركة الجديدة (ممكن يكون العميل نفسه أو تغيّر اسمه)
    $customer_id = find_or_create_entity('customers', $customer_name);
    $new_sign = $type === 'debit' ? 1 : -1;
    $db->prepare('UPDATE customers SET balance = balance + ? WHERE id = ?')
       ->execute([$new_sign * $amount, $customer_id]);

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

    $sign = $old['type'] === 'debit' ? 1 : -1;
    $db->prepare('UPDATE customers SET balance = balance - ? WHERE id = ?')
       ->execute([$sign * $old['amount'], $old['customer_id']]);
    reverse_mirrored_bank_movement('customer_transactions', $tx_id);
    reverse_etihad_pos_commission($tx_id);
    $db->prepare('DELETE FROM customer_transactions WHERE id = ?')->execute([$tx_id]);

    return ['success' => true, 'message' => 'تم حذف الحركة'];
}

function update_supplier_transaction($tx_id, $supplier_name, $type, $amount, $description, $tx_date, $paid_from_bank = null, $no_bank_reason = null) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM supplier_transactions WHERE id = ?');
    $stmt->execute([$tx_id]);
    $old = $stmt->fetch();
    if (!$old) return ['success' => false, 'message' => 'الحركة غير موجودة'];

    $err = require_bank_or_reason($paid_from_bank, $no_bank_reason);
    if ($err) return ['success' => false, 'message' => $err];

    $old_sign = $old['type'] === 'debit' ? 1 : -1;
    $db->prepare('UPDATE suppliers SET balance = balance - ? WHERE id = ?')
       ->execute([$old_sign * $old['amount'], $old['supplier_id']]);

    $supplier_id = find_or_create_entity('suppliers', $supplier_name);
    $new_sign = $type === 'debit' ? 1 : -1;
    $db->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')
       ->execute([$new_sign * $amount, $supplier_id]);

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

    $sign = $old['type'] === 'debit' ? 1 : -1;
    $db->prepare('UPDATE suppliers SET balance = balance - ? WHERE id = ?')
       ->execute([$sign * $old['amount'], $old['supplier_id']]);
    reverse_mirrored_bank_movement('supplier_transactions', $tx_id);
    $db->prepare('DELETE FROM supplier_transactions WHERE id = ?')->execute([$tx_id]);

    return ['success' => true, 'message' => 'تم حذف الحركة'];
}

// ---------------- دائن ومدين شخصي (سلف حسنة) ----------------
function add_personal_transaction($person_name, $type, $amount, $description, $tx_date, $user_id, $paid_from_bank = null, $no_bank_reason = null) {
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

// ---------------- توزيع أرباح على المساهمين ----------------
function add_profit_distribution($shareholder_name, $amount, $period_label, $description, $tx_date, $user_id, $paid_from_bank = null, $no_bank_reason = null) {
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

// ---------------- مساهمات/إضافات فلوس من الشريك للشركة (عكس توزيع الأرباح تماماً) ----------------
function add_shareholder_contribution($shareholder_name, $amount, $description, $tx_date, $user_id, $paid_from_bank = null, $no_bank_reason = null) {
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

// ---------------- مصاريف / سحوبات / تبرعات ----------------
function add_expense($category, $amount, $description, $paid_from_bank, $tx_date, $user_id, $no_bank_reason = null, $invoice_no = null, $tax_rate = null) {
    $db = get_db();
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

// ---------------- إيرادات خارجية (دخل برّاني عن الشغل الأساسي، افتراضياً لـ"رمزي") ----------------
function add_external_revenue($source_person, $amount, $description, $paid_from_bank, $tx_date, $user_id, $no_bank_reason = null) {
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

// ---------------- حركات البنوك (جدول منفصل لكل بنك) ----------------
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

// أسماء عربية للجدول المصدر - تُستخدم برسالة المنع لما حد يحاول يعدّل/يحذف
// حركة "مرآة" مباشرة من صفحة البنوك
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

// ---------------- الموظفين (رواتب / سلف / خصومات) ----------------
function add_employee_transaction($employee_name, $type, $amount, $description, $tx_date, $user_id, $phone = null, $paid_from_bank = null) {
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

// تعديل بيانات الموظف نفسه (الاسم / الراتب الأساسي / الهاتف) - مش حركة مالية
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

// حذف موظف بالكامل - مسموح فقط لو ما إله أي حركات مسجّلة (حماية للتاريخ المالي)
// لو عنده حركات، بيرفض الحذف ويقترح تصفير الرصيد بدل الحذف
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

// =====================================================================
// تعديل/حذف الجهة نفسها (العميل/المورد/الشخص/موظف السيارة) - مش حركة مالية
// نفس منطق الموظفين بالضبط: تعديل الاسم/الهاتف مسموح لأي مستخدم، الحذف
// مرفوض تلقائياً لو عندها أي حركة مسجّلة (حماية للتاريخ المالي)
// =====================================================================

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


// ---------------- صيانة خارجية (إيرادات / مصاريف / سلف / خصومات / إعلانات) ----------------
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

// إحصائية: إجمالي الزبائن وإجمالي الإعلانات وتكلفة الإعلان لكل زبون، لكل موظف سيارة
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

// توزيع مبلغ إعلانات إجمالي على مجموعة سائقين بالتناسب مع عدد الزبائن اللي جابوه
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

// توزيع راتب السكرتارية (مصروف عام واحد) بالتساوي على كل السائقين المسجلين
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

// تقرير صافي إيراد كل سائق: الإيراد الكامل مقابل كل بند مصروف لحاله (محروقات/قطع/صيانة مركبة/راتب/إعلانات/سكرتارية)
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

// ---------------- الرصيد الافتتاحي (بنوك + كاش) ----------------
// account_key: 'bank_jordan'/'bank_etihad'/'bank_islami'/'bank_dinarak'/'bank_investment'/'cash'
function get_opening_balance($account_key) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM opening_balances WHERE account_key = ?');
    $stmt->execute([$account_key]);
    return $stmt->fetch() ?: null;
}

// upsert حقيقي: تحديث مباشر لو الحساب موجود، أو إضافة لو أول مرة - بدون ما نمر
// بخطوة DELETE (يلي محمية بصلاحية "المالك فقط" لأنها نفس صلاحية حذف حركة مالية
// حقيقية). تعديل رصيد افتتاحي مش لازم يحتاج هالصلاحية، فهيك منضمن انه يشتغل
// لأي مستخدم يقدر يدخل ع الصفحة.
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

// ---------------- ضبط الرصيد الفعلي للبنوك ----------------
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

// بيقارن الرصيد المحسوب بالرصيد الفعلي (اللي عده المستخدم يدوياً بالبنك) وبيسجل حركة تسوية بالفرق
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

// ---------------- مشغل (إيراد المشغل / مصاريف المشغل - كيان واحد إجمالي) ----------------
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

// ---------------- صندوق الكاش (عجز / زيادة كاش المحل) ----------------
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

// إجمالي العجز/الزيادة التراكمي = مجموع فروقات العد اليومي + رصيد افتتاحي منفصل
// (لو كان معك عجز/زيادة متراكم من قبل ما تبلش تسجّل بالنظام)
function get_cash_box_total() {
    $db = get_db();
    $sum = (float)$db->query('SELECT COALESCE(SUM(difference),0) t FROM cash_box')->fetch()['t'];
    $opening = get_opening_balance('cash_diff');
    return $sum + ($opening ? (float)$opening['amount'] : 0);
}

// الرصيد الفعلي الحقيقي للكاش (قديش معك فلوس فعلياً بالصندوق).
// = الرصيد الافتتاحي (فلوس كانت معك قبل النظام، منفصلة عن الدرج) + آخر عد فعلي مسجّل
// (لأنه آخر رقم حقيقي انعدّ فعلياً بالدرج) + أي حركة كاش حقيقية صارت بعد تاريخ هداك العد
// (تسديدات، مصاريف، سلف، تحصيل شيكات...). بنستثني من "الحركات اللي بعده" فروقات عدّ
// الكاش القديمة وقيود الرصيد الافتتاحي نفسها، لأنهم مضمنين أصلاً بالرصيد الافتتاحي +
// آخر عد. لو لسا ما في ولا عد مسجّل، بنرجع للطريقة القديمة: مجموع كل قيود الكاش من
// البداية (شامل الرصيد الافتتاحي، لأنه هو نفسه قيد بنفس الحساب).
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


// ---------------- تسجيل حركة حقيقية بجدول البنك نفسه (يخلي الداشبورد ينعكس) ----------------
// تُستدعى من أي دالة فيها paid_from_bank (عميل، مورد، مصروف، موظف، سائق، أرباح، شيك)
// $tx_type: 'deposit' (فلوس دخلت البنك) أو 'withdraw' (فلوس طلعت من البنك)
// ملاحظة: "cash" مش إله جدول بنك مخصص (الكاش يتتبع بعدّ الصندوق لا بجدول حركات)، فبتم تجاهله هون
// ---------------- عكس حركة البنك المرآة (تُستدعى قبل حذف أي حركة مصدر فيها paid_from_bank) ----------------
// لما تنحذف حركة (مصروف، تسديد، سلفة...) وكانت مرتبطة ببنك حقيقي (paid_from_bank)،
// لازم نمسح الصف المرآة يلي كان mirror_bank_movement سجله بجدول البنك نفسه (bank_jordan...الخ)،
// وإلا يضل رصيد البنك بالداشبورد فيه أثر حركة انحذفت أصلاً من مصدرها.
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


// ---------------- ربط الشيك بحساب عميل/مورد/شخص ----------------
// بيحدد نوع الحركة (debit/credit أو lend/repay) المناسب حسب اتجاه الشيك
// (وارد = تحصيل، صادر = آجل) ونوع الجهة. راجع الشرح بأعلى ملف الترحيل
// database/migration_v4_checks_entity_link.sql لتفاصيل لماذا هذا صحيح محاسبياً.
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

// بينشئ (أو يعيد إنشاء) الحركة المرتبطة بحساب الجهة (عميل/مورد/شخص) لشيك معيّن،
// ويرجّع entity_table/entity_id تبعها حتى نخزنها بجدول checks (linked_tx_table/linked_tx_id)
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

// بيحذف الحركة المرتبطة بجهة (لو موجودة) - تُستدعى عند حذف/تعديل/ارتداد الشيك
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

// ---------------- تحديث حالة الشيك (قيد الانتظار / تم الصرف / مرتجع) ----------------
// هون منطق البنك (checks_in_hand/checks_payable <-> بنك حقيقي) موجود أصلاً بـ checks.php
// (بيستدعي mirror_bank_movement مباشرة زي ما هو). الإضافة هون فقط: لو الشيك "ارتد" (bounced)
// لازم نلغي حركة حساب الجهة (لأنه الدين يرجع كما كان - الشيك ما انصرف فعلياً)، ولو رجع من
// "مرتجع" لحالة تانية، لازم نعيد إنشاء حركة الجهة من جديد.
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

// ---------------- استعلام عن رصيد/وضع جهة محددة بالاسم (يستخدمه الشات بوت) ----------------
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

// ---------------- حفظ صورة مؤقتاً على القرص بس (بدون ربطها بأي جدول لسا) ----------------
// تُستخدم لما الحركة لسا "معلّقة" بانتظار تأكيد المستخدم - نحفظ الصورة ونرجع مسارها
// حتى نربطها بالحركة الحقيقية بنفس لحظة التأكيد مباشرة (بدون خطوة "ربط لاحق" عرضة للفشل)
function save_temp_image($image_data_url) {
    if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $image_data_url, $m)) {
        return null;
    }
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : preg_replace('/[^a-z0-9]/', '', strtolower($m[1]));
    $binary = base64_decode($m[2]);
    if ($binary === false) return null;

    $dir = __DIR__ . '/../uploads/attachments/' . date('Y-m');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'pending_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $full_path = $dir . '/' . $filename;
    if (file_put_contents($full_path, $binary) === false) return null;

    return date('Y-m') . '/' . $filename;
}

// ---------------- حفظ صورة (شيك/فاتورة/حوالة) مرتبطة بحركة معينة ----------------
function save_attachment($entity_table, $entity_id, $image_data_url, $caption, $user_id) {
    if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $image_data_url, $m)) {
        return null;
    }
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : preg_replace('/[^a-z0-9]/', '', strtolower($m[1]));
    $binary = base64_decode($m[2]);
    if ($binary === false) return null;

    $dir = __DIR__ . '/../uploads/attachments/' . date('Y-m');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = $entity_table . '_' . $entity_id . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $full_path = $dir . '/' . $filename;
    if (file_put_contents($full_path, $binary) === false) return null;

    $relative_path = date('Y-m') . '/' . $filename;

    $db = get_db();
    $stmt = $db->prepare('INSERT INTO attachments (entity_table, entity_id, file_path, caption, uploaded_by) VALUES (?,?,?,?,?)');
    $stmt->execute([$entity_table, $entity_id, $relative_path, $caption, $user_id]);

    return $relative_path;
}

// ---------------- جلب مرفقات حركة معينة ----------------
function get_attachments($entity_table, $entity_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM attachments WHERE entity_table = ? AND entity_id = ? ORDER BY uploaded_at DESC');
    $stmt->execute([$entity_table, $entity_id]);
    return $stmt->fetchAll();
}

// ---------------- بحث عام بالمرفقات حسب اسم جهة (يستخدمه الشات بوت: "وريني شيك خالد") ----------------
function search_attachments_by_entity_name($entity_type, $name) {
    // الشيكات جدول مستقل (مالوش مكان بـ get_entity_details) - نعالجه لحاله
    if ($entity_type === 'check') {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM checks WHERE party_name LIKE ? ORDER BY due_date DESC LIMIT 5");
        $stmt->execute(['%' . $name . '%']);
        $checks = $stmt->fetchAll();
        if (empty($checks)) return null;
        $ids = array_column($checks, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt2 = $db->prepare("SELECT * FROM attachments WHERE entity_table = 'checks' AND entity_id IN ($placeholders) ORDER BY uploaded_at DESC");
        $stmt2->execute($ids);
        return ['name' => $name, 'attachments' => $stmt2->fetchAll()];
    }

    $info = get_entity_details($entity_type, $name);
    if (!$info || isset($info['ambiguous'])) return $info;

    $map2 = [
        'customer' => 'customer_transactions', 'supplier' => 'supplier_transactions',
        'personal' => 'personal_transactions', 'employee' => 'employee_transactions',
        'car_employee' => 'car_employee_transactions', 'shareholder' => 'profit_distributions',
    ];
    $tx_table = $map2[$entity_type] ?? null;
    if (!$tx_table) return null;

    $ids = array_column($info['recent_transactions'], 'id');
    if (empty($ids)) return ['name' => $info['name'], 'attachments' => []];

    $db = get_db();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT * FROM attachments WHERE entity_table = ? AND entity_id IN ($placeholders) ORDER BY uploaded_at DESC");
    $stmt->execute(array_merge([$tx_table], $ids));

    return ['name' => $info['name'], 'attachments' => $stmt->fetchAll()];
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
