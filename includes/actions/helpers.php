<?php
// helpers.php — shared utilities (normalize, find/create entity, etc.)
/**
 * =====================================================================
 * كل دالة هون بتمثل "إجراء محاسبي" ممكن يستدعيه:
 * 1) الشات بوت (بعد ما يفهم OpenAI قصد المستخدم)
 * 2) النماذج اليدوية بالداشبورد
 * كل دالة بترجع array فيها success و message عشان نعرضها للمستخدم
 * =====================================================================
 */
require_once __DIR__ . '/../db.php';

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
function require_bank_or_reason($paid_from_bank, $no_bank_reason) {
    if (empty($paid_from_bank) && trim((string)$no_bank_reason) === '') {
        return 'لازم تحدد "من/إلى أي بنك أو كاش" هاي الحركة، أو تكتب سبب واضح ليش ما فيه بنك محدد (مثلاً: سلفة مفتوحة لسا ما تحدد مصدرها). هاد الشرط موجود عشان حساب suspense ما يصير سلة مهملات.';
    }
    return null;
}
function normalize_bank_key(?string $bank): ?string {
    if (empty($bank)) return null;
    $map = [
        'كاش' => 'cash', 'cash' => 'cash', 'الكاش' => 'cash', 'صندوق' => 'cash', 'الصندوق' => 'cash',
        'islami' => 'islami', 'الإسلامي' => 'islami', 'الاسلامي' => 'islami', 'اسلامي' => 'islami', 'إسلامي' => 'islami',
        'etihad' => 'etihad', 'الاتحاد' => 'etihad', 'اتحاد' => 'etihad',
        'dinarak' => 'dinarak', 'دينارك' => 'dinarak',
        'investment' => 'investment', 'الاستثماري' => 'investment', 'استثماري' => 'investment',
        'jordan_personal' => 'jordan_personal', 'الأردني شخصي' => 'jordan_personal', 'الاردني شخصي' => 'jordan_personal',
        'jordan_business' => 'jordan_business', 'الأردني مؤسسة' => 'jordan_business', 'الاردني مؤسسة' => 'jordan_business',
    ];
    return $map[trim($bank)] ?? $bank;
}
function normalize_name_for_match($name) {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return str_replace('ة', 'ه', $name);
}
function find_or_create_entity($table, $name) {
    $db = get_db();
    $normalized = normalize_name_for_match($name);

    // SELECT أول — الحالة الشائعة (entity موجودة)
    $stmt = $db->prepare("SELECT id FROM $table WHERE REPLACE(name, 'ة', 'ه') = ? LIMIT 1");
    $stmt->execute([$normalized]);
    $row = $stmt->fetch();
    if ($row) return $row['id'];

    // INSERT IGNORE — لو جاء طلبان بنفس الوقت (race condition)، الثاني بيتجاهل بدون error
    // يشتغل فقط لو في UNIQUE KEY على name (uq_*_name)
    $stmt = $db->prepare("INSERT IGNORE INTO $table (name) VALUES (?)");
    $stmt->execute([trim($name)]);

    // SELECT ثاني — يرجع الـ id سواء أدخلنا هنا أو كان موجود من request ثاني
    $stmt = $db->prepare("SELECT id FROM $table WHERE REPLACE(name, 'ة', 'ه') = ? LIMIT 1");
    $stmt->execute([$normalized]);
    return $stmt->fetchColumn();
}
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
