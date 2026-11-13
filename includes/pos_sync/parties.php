<?php
/**
 * مزامنة POS - مزامنة العملاء والموردين: إيجاد/إنشاء بالاسم أو بالـ pos_id (النسخة القديمة و v2)
 * (جزء من includes/pos_sync.php سابقاً - انقسم لملفات، المنطق ما تغيّر)
 */

// ==================== عملاء وموردين (مطابقة بالاسم - الـ API ما بيرجع id) ====================

/** يدور على عميل موجود بنفس الاسم (بدون حساسية لحالة الأحرف/مسافات)، وإلا يعمله جديد */
function pos_find_or_create_customer_by_name($name, $phone, $run_id, array &$counts) {
    $db = get_db();
    $name = trim($name);
    if ($name === '') return null;

    $stmt = $db->prepare('SELECT id, phone FROM customers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($phone && !$existing['phone']) {
            $db->prepare('UPDATE customers SET phone = ?, updated_at = NOW() WHERE id = ?')->execute([$phone, $existing['id']]);
            $counts['customers_updated']++;
            pos_log_item($run_id, 'customer', 'update', $existing['id'], "تحديث رقم هاتف العميل: $name");
        }
        return $existing['id'];
    }

    $db->prepare("INSERT INTO customers (source, name, phone, balance, created_at, updated_at) VALUES ('pos', ?, ?, 0, NOW(), NOW())")
       ->execute([$name, $phone]);
    $new_id = $db->lastInsertId();
    $counts['customers_new']++;
    pos_log_item($run_id, 'customer', 'insert', $new_id, "عميل جديد من POS: $name");
    return $new_id;
}

function pos_find_or_create_supplier_by_name($name, $phone, $run_id, array &$counts) {
    $db = get_db();
    $name = trim($name);
    if ($name === '') return null;

    $stmt = $db->prepare('SELECT id, phone FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($phone && !$existing['phone']) {
            $db->prepare('UPDATE suppliers SET phone = ?, updated_at = NOW() WHERE id = ?')->execute([$phone, $existing['id']]);
            $counts['suppliers_updated']++;
            pos_log_item($run_id, 'supplier', 'update', $existing['id'], "تحديث رقم هاتف المورد: $name");
        }
        return $existing['id'];
    }

    $db->prepare("INSERT INTO suppliers (source, name, phone, balance, created_at, updated_at) VALUES ('pos', ?, ?, 0, NOW(), NOW())")
       ->execute([$name, $phone]);
    $new_id = $db->lastInsertId();
    $counts['suppliers_new']++;
    pos_log_item($run_id, 'supplier', 'insert', $new_id, "مورد جديد من POS: $name");
    return $new_id;
}

/**
 * زي pos_find_or_create_supplier_by_name بس بتعتمد على pos_id الحقيقي الراجع من
 * customer_supplier_reports/suppliers (بدل المطابقة بالاسم بس، اللي كانت بتسبب مشاكل
 * لو صار تكرار بالاسم أو تغيير بسيط بالكتابة). لو المورد موجود أصلاً بالنظام (اتضاف قبل
 * بالطريقة القديمة بدون pos_id) بتربطه تلقائياً بالـ pos_id أول مرة بتشوفه فيها.
 */
function pos_find_or_create_supplier_by_pos_id($pos_id, $name, $phone, $run_id, array &$counts) {
    $db = get_db();
    $pos_id = trim((string) $pos_id);
    $name = trim((string) $name);
    if ($pos_id === '' || $name === '') return null;

    // 1) دور بالـ pos_id (الحالة العادية بعد أول مزامنة)
    $stmt = $db->prepare('SELECT id, phone, name FROM suppliers WHERE pos_id = ? LIMIT 1');
    $stmt->execute([$pos_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updates = [];
        $params = [];
        if ($phone && !$existing['phone']) { $updates[] = 'phone = ?'; $params[] = $phone; }
        // ما منغيّر الاسم لو اسم تاني موجود فعلاً بنفس القيمة (الـ unique key uq_suppliers_name)
        if ($name !== '' && $name !== $existing['name'] && !pos_party_name_taken('suppliers', $name, $existing['id'])) { $updates[] = 'name = ?'; $params[] = $name; }
        if ($updates) {
            $params[] = $existing['id'];
            $db->prepare('UPDATE suppliers SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?')->execute($params);
            $counts['suppliers_updated']++;
            pos_log_item($run_id, 'supplier', 'update', $existing['id'], "تحديث بيانات المورد: $name");
        }
        return $existing['id'];
    }

    // 2) مورد قديم مضاف بالاسم بس (بدون pos_id لسا) - اربطه بدل ما تعمل تكرار
    $stmt2 = $db->prepare('SELECT id, phone FROM suppliers WHERE pos_id IS NULL AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt2->execute([$name]);
    $legacy = $stmt2->fetch();
    if ($legacy) {
        $db->prepare('UPDATE suppliers SET pos_id = ?, phone = COALESCE(NULLIF(phone,\'\'), ?), source = \'pos\', updated_at = NOW() WHERE id = ?')
           ->execute([$pos_id, $phone, $legacy['id']]);
        $counts['suppliers_updated']++;
        pos_log_item($run_id, 'supplier', 'update', $legacy['id'], "ربط المورد \"$name\" بمعرّف POS #$pos_id");
        return $legacy['id'];
    }

    // 3) مورد جديد بالكامل - لو الاسم مستخدم لمورد تاني (اسمين متشابهين بالـ POS) بنضيف رقم الـ POS للتمييز
    if (pos_party_name_taken('suppliers', $name)) { $name = $name . ' (' . $pos_id . ')'; }
    $db->prepare("INSERT INTO suppliers (pos_id, source, name, phone, balance, created_at, updated_at) VALUES (?, 'pos', ?, ?, 0, NOW(), NOW())")
       ->execute([$pos_id, $name, $phone]);
    $new_id = $db->lastInsertId();
    $counts['suppliers_new']++;
    pos_log_item($run_id, 'supplier', 'insert', $new_id, "مورد جديد من POS: $name (#$pos_id)");
    return $new_id;
}

/**
 * هل الاسم مستخدم لطرف تاني؟ (نفس منطق الـ unique key uq_*_name - بنفس الـ collation)
 * $table: اسم جدول من الكود نفسه (customers/suppliers) مش من مدخلات.
 */
function pos_party_name_taken($table, $name, $exclude_id = null) {
    $db = get_db();
    $sql = "SELECT id FROM {$table} WHERE name = ?" . ($exclude_id !== null ? ' AND id <> ?' : '') . ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($exclude_id !== null ? [$name, $exclude_id] : [$name]);
    return $stmt->fetchColumn() !== false;
}

/** استيراد دفتر عناوين العملاء/الموردين (شركة، هاتف...) - مفيد حتى لو ما عندهم فواتير لسا */
function pos_sync_customers($run_id, array &$counts) {
    $rows = pos_fetch_all_pages('companies', ['group' => 'customer']);
    foreach ($rows as $row) {
        $name = pos_field($row, ['company', 'name'], '');
        if (!$name || in_array(trim($name), POS_IGNORE_CUSTOMER_NAMES, true)) continue;
        $phone = pos_field($row, ['phone', 'mobile']);
        pos_find_or_create_customer_by_name($name, $phone, $run_id, $counts);
    }
}

function pos_sync_suppliers($run_id, array &$counts) {
    $rows = pos_fetch_all_pages('companies', ['group' => 'supplier']);
    foreach ($rows as $row) {
        $name = pos_field($row, ['company', 'name'], '');
        if (!$name) continue;
        $phone = pos_field($row, ['phone', 'mobile']);
        pos_find_or_create_supplier_by_name($name, $phone, $run_id, $counts);
    }
}

// ==================== العملاء (نسخة جديدة عبر customer_supplier_reports - فيها pos_id حقيقي) ====================

/**
 * زي pos_find_or_create_supplier_by_pos_id بس للعملاء - بتعتمد على customer_id
 * الحقيقي الراجع من customer_supplier_reports/customers بدل المطابقة بالاسم بس.
 * لو العميل موجود أصلاً بالنظام (اتضاف قبل بالطريقة القديمة بدون pos_id) بتربطه
 * تلقائياً بالـ pos_id أول مرة بتشوفه فيها.
 */
function pos_find_or_create_customer_by_pos_id($pos_id, $name, $phone, $run_id, array &$counts) {
    $db = get_db();
    $pos_id = trim((string) $pos_id);
    $name = trim((string) $name);
    if ($pos_id === '' || $name === '') return null;
    if (in_array($name, POS_IGNORE_CUSTOMER_NAMES, true)) return null;

    // 1) دور بالـ pos_id (الحالة العادية بعد أول مزامنة)
    $stmt = $db->prepare('SELECT id, phone, name FROM customers WHERE pos_id = ? LIMIT 1');
    $stmt->execute([$pos_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updates = [];
        $params = [];
        if ($phone && !$existing['phone']) { $updates[] = 'phone = ?'; $params[] = $phone; }
        // ما منغيّر الاسم لو اسم تاني موجود فعلاً بنفس القيمة (الـ unique key uq_customers_name)
        if ($name !== '' && $name !== $existing['name'] && !pos_party_name_taken('customers', $name, $existing['id'])) { $updates[] = 'name = ?'; $params[] = $name; }
        if ($updates) {
            $params[] = $existing['id'];
            $db->prepare('UPDATE customers SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?')->execute($params);
            $counts['customers_updated']++;
            pos_log_item($run_id, 'customer', 'update', $existing['id'], "تحديث بيانات العميل: $name");
        }
        return $existing['id'];
    }

    // 2) عميل قديم مضاف بالاسم بس (بدون pos_id لسا) - اربطه بدل ما تعمل تكرار
    $stmt2 = $db->prepare('SELECT id, phone FROM customers WHERE pos_id IS NULL AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt2->execute([$name]);
    $legacy = $stmt2->fetch();
    if ($legacy) {
        $db->prepare('UPDATE customers SET pos_id = ?, phone = COALESCE(NULLIF(phone,\'\'), ?), source = \'pos\', updated_at = NOW() WHERE id = ?')
           ->execute([$pos_id, $phone, $legacy['id']]);
        $counts['customers_updated']++;
        pos_log_item($run_id, 'customer', 'update', $legacy['id'], "ربط العميل \"$name\" بمعرّف POS #$pos_id");
        return $legacy['id'];
    }

    // 3) عميل جديد بالكامل - لو الاسم مستخدم لعميل تاني (اسمين متشابهين بالـ POS) بنضيف رقم الـ POS للتمييز
    if (pos_party_name_taken('customers', $name)) { $name = $name . ' (' . $pos_id . ')'; }
    $db->prepare("INSERT INTO customers (pos_id, source, name, phone, balance, created_at, updated_at) VALUES (?, 'pos', ?, ?, 0, NOW(), NOW())")
       ->execute([$pos_id, $name, $phone]);
    $new_id = $db->lastInsertId();
    $counts['customers_new']++;
    pos_log_item($run_id, 'customer', 'insert', $new_id, "عميل جديد من POS: $name (#$pos_id)");
    return $new_id;
}

/** استيراد دفتر عناوين العملاء من الـ endpoint الجديد - فيه customer_id حقيقي (customer_supplier_reports/customers) */
function pos_sync_customers_v2($run_id, array &$counts) {
    $rows = pos_fetch_all_pages('customer_supplier_reports/customers');
    foreach ($rows as $row) {
        $pos_id = pos_field($row, ['customer_id', 'id']);
        $name = pos_field($row, ['name', 'company'], '');
        if (!$pos_id || !$name) continue;
        $phone = pos_field($row, ['phone', 'mobile']);
        // فشل عميل واحد ما لازم يوقف باقي العملاء
        try {
            pos_find_or_create_customer_by_pos_id($pos_id, $name, $phone, $run_id, $counts);
        } catch (Throwable $e) {
            $counts['party_failed'] = ($counts['party_failed'] ?? 0) + 1;
            $counts['party_failed_sample'] = $counts['party_failed_sample'] ?? ($name . ': ' . $e->getMessage());
        }
    }
}

// ==================== الموردين (نسخة جديدة عبر customer_supplier_reports - فيها pos_id حقيقي) ====================


/** استيراد دفتر عناوين الموردين من الـ endpoint الجديد - فيه supplier_id حقيقي */
function pos_sync_suppliers_v2($run_id, array &$counts) {
    $rows = pos_fetch_all_pages('customer_supplier_reports/suppliers');
    foreach ($rows as $row) {
        $pos_id = pos_field($row, ['supplier_id', 'id']);
        $name = pos_field($row, ['name', 'company'], '');
        if (!$pos_id || !$name) continue;
        $phone = pos_field($row, ['phone', 'mobile']);
        // فشل مورد واحد ما لازم يوقف باقي الموردين
        try {
            pos_find_or_create_supplier_by_pos_id($pos_id, $name, $phone, $run_id, $counts);
        } catch (Throwable $e) {
            $counts['party_failed'] = ($counts['party_failed'] ?? 0) + 1;
            $counts['party_failed_sample'] = $counts['party_failed_sample'] ?? ($name . ': ' . $e->getMessage());
        }
    }
}
