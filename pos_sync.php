<?php
require_once __DIR__ . '/pos_api_client.php';
require_once __DIR__ . '/db.php';

// آي دي المستخدم النظامي اللي بينسجل باسمه أي حركة جاية أوتوماتيك من الـ POS
// (لازم يطابق اللي حطيته بملف migration_pos_sync.sql)
define('POS_SYNC_SYSTEM_USER_ID', 1);

// أسماء بترجع من الـ POS ما بدنا نعتبرها "عميل حقيقي" إله ذمم (زبون كاش عابر)
define('POS_IGNORE_CUSTOMER_NAMES', ['Walk-in Customer', 'زبون عابر', 'Walk in Customer']);

// أقل فرق مبلغ نعتبره تغيير فعلي (تفادي مشاكل التقريب العشري)
define('POS_AMOUNT_EPSILON', 0.01);

// تاريخ "القطع" يلي عملنا فيه رصيد افتتاحي مجمّع للعملاء والموردين (حركة واحدة
// بتلخص كل تاريخهم القديم قبل هالتاريخ). أي سحب "كل التاريخ" من الـ POS لازم
// يبلّش من هالتاريخ أو بعده بس - أبداً قبله - وإلا بيصير تكرار: المبلغ محسوب
// مرة بالرصيد الافتتاحي المجمّع، ومرة تانية بالفواتير التفصيلية القديمة الفعلية.
define('OPENING_BALANCE_CUTOFF_DATE', '2026-08-22');

/** يجيب كل صفحات endpoint معيّن (الحد الأقصى للـ limit حسب توثيق SMA هو 20) */
function pos_fetch_all_pages($path, $extra_params = [], $limit = 20, $max_pages = 500) {
    $all = [];
    $start = 1;
    for ($i = 0; $i < $max_pages; $i++) {
        $params = array_merge(['start' => $start, 'limit' => $limit], $extra_params);
        $decoded = pos_api_get($path, $params);
        $rows = pos_api_extract_list($decoded);
        if (empty($rows)) break;
        $all = array_merge($all, $rows);
        if (count($rows) < $limit) break; // آخر صفحة
        $start += $limit;
        usleep(400000); // وقفة بسيطة (0.4 ثانية) بين كل صفحة وتالية لتفادي rate limit الـ POS API
    }
    return $all;
}

/**
 * زي pos_fetch_all_pages بس بتوقف لما توصل لسجلات أقدم من $cutoff_date
 * (لازم يكون الترتيب order_by=date,desc). هاي بتفرق كتير لما يكون عدد
 * السجلات بالـ endpoint كبير جداً (مثلاً 60 ألف فاتورة مبيعات) لأنه
 * بدون هالحد، كل مزامنة كانت بتجيب كل السجلات من أول يوم = آلاف طلبات
 * API متتالية وهاد اللي كان يضرب rate limit الموردة من POS مباشرة.
 */
function pos_fetch_recent_pages($path, $extra_params, $cutoff_date, $limit = 20, $max_pages = 500) {
    $all = [];
    $start = 1;
    $params_base = array_merge(['order_by' => 'date,desc'], $extra_params);
    for ($i = 0; $i < $max_pages; $i++) {
        $params = array_merge($params_base, ['start' => $start, 'limit' => $limit]);
        $decoded = pos_api_get($path, $params);
        $rows = pos_api_extract_list($decoded);
        if (empty($rows)) break;

        $reached_cutoff = false;
        foreach ($rows as $row) {
            $d = pos_field($row, ['date']);
            $ts = $d ? strtotime($d) : null;
            if ($ts !== null && $ts < $cutoff_date) {
                $reached_cutoff = true;
                continue; // هاد السجل أقدم من الحد - نتجاهله بس منكمل نفحص الباقي بنفس الصفحة
            }
            $all[] = $row;
        }
        if ($reached_cutoff) break; // وصلنا لسجلات أقدم من الحد - وقف تماماً
        if (count($rows) < $limit) break; // آخر صفحة أصلاً
        $start += $limit;
        usleep(400000);
    }
    return $all;
}

function pos_field($row, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '') return $row[$k];
    }
    return $default;
}

/**
 * تحدّد طريقة الدفع الفعلية لحركة "دفعة" (credit) قادمة من الـ POS، بالاعتماد
 * على حقل paid_by الحقيقي يلي بيرجعه الـ API - بدل الافتراض القديم إنه كل
 * دفعة = كاش تلقائياً (وهاد كان غلط: الـ API ممكن يرجّع "Cheque" مثلاً،
 * ووقتها الدفعة مش كاش أبداً).
 *
 * بترجع مصفوفة [paid_from_bank, no_bank_reason]:
 * - paid_from_bank: القيمة يلي بتتخزن بعمود paid_from_bank بجدول الحركات
 *   (cash لو دفعة كاش فعلية حسب الـ POS، أو null لو مش كاش/مش معروف)
 * - no_bank_reason: سبب واضح يفهمه المحاسب/المدقق، بيظهر بتقرير suspense
 *   لأي حالة مش "كاش مؤكد 100%" عشان تنراجع يدوياً.
 *
 * ⚠️ القيم المعروفة لحد هلق من توثيق/تجربة SMA: "cash", "Cheque".
 * أي قيمة تانية (أو null رغم إنها دفعة فعلية) بتترك paid_from_bank فاضي
 * وبتتحط بخانة الاستثناءات، عن قصد - أحسن نراجعها يدوياً من إننا نخمّن
 * ونحطها بحساب بنكي غلط.
 */
function pos_resolve_payment_method($paid_by_raw) {
    $normalized = is_string($paid_by_raw) ? trim($paid_by_raw) : $paid_by_raw;

    if ($normalized === null || $normalized === '') {
        return [null, 'دفعة POS - طريقة الدفع غير محددة من الـ API (يحتاج مراجعة يدوية)'];
    }

    $lower = strtolower($normalized);

    if ($lower === 'cash') {
        return ['cash', null];
    }

    if ($lower === 'cheque' || $lower === 'check') {
        return [null, 'دفعة POS بشيك (paid_by=' . $normalized . ') - راجع/سجّل الشيك يدوياً بقسم الشيكات، مش كاش'];
    }

    // قيمة غير متوقعة (مثلاً اسم بنك أو تحويل) - نحطها بالاستثناءات بدل ما نخمّن
    return [null, 'دفعة POS - قيمة paid_by غير معروفة بالنظام: "' . $normalized . '" (يحتاج مراجعة/إضافة دعم لها)'];
}

function pos_log_item($run_id, $entity_type, $action, $entity_id, $label) {
    $db = get_db();
    $db->prepare('INSERT INTO pos_sync_items (run_id, entity_type, action, entity_id, label) VALUES (?,?,?,?,?)')
       ->execute([$run_id, $entity_type, $action, $entity_id, $label]);
}

// ==================== قفل يمنع تشغيل مزامنتين بنفس الوقت (لو ضغط المستخدم الزر مرتين وراء بعض) ====================
// بيعتمد على GET_LOCK بـ MySQL: قفل مربوط بجلسة الاتصال نفسها، فلو انفتح طلبين بنفس
// اللحظة (double click)، الطلب التاني بياخد 0 فوراً (ما بينتظر) وبنرجعله رسالة واضحة
// بدل ما يكمل وينشئ حركات مكررة بالتوازي مع الطلب الأول.
function pos_try_acquire_lock($lock_name) {
    $db = get_db();
    $stmt = $db->query("SELECT GET_LOCK('" . $lock_name . "', 0)");
    return (int) $stmt->fetchColumn() === 1;
}

function pos_release_lock($lock_name) {
    $db = get_db();
    $db->query("SELECT RELEASE_LOCK('" . $lock_name . "')");
}

/** جمع آمن لمصفوفة counts - بيتجاهل أي قيمة نصية (زي عيّنات رسائل الخطأ) وبيجمع الأرقام بس */
function pos_sum_numeric_counts(array $counts) {
    $total = 0;
    foreach ($counts as $v) {
        if (is_int($v) || is_float($v)) $total += $v;
    }
    return $total;
}

/** هل رسالة الخطأ هاي بسبب rate limit من الـ POS؟ (مش خطأ عابر - يعني إعادة المحاولة الفورية ما بتفيد) */
function pos_is_rate_limit_error($message) {
    return (stripos($message, 'time limit') !== false)
        || (stripos($message, 'rate limit') !== false)
        || (stripos($message, 'HTTP 429') !== false);
}

/**
 * لو انقتل السكريبت من السيرفر بمنتصف الشغل (مثلاً hosting timeout) قبل ما
 * يوصل لسطر تحديث الحالة النهائية، بيضل سجل "شغّالة" عالق للأبد بصفحة
 * sync_log.php. هاي الدالة بتصحح أي سجل عالق أكتر من 20 دقيقة تلقائياً
 * لـ "فشلت (توقفت)" - بتنادى بأول أي مزامنة جديدة، فما يضل تقرير مضلّل.
 */
function pos_mark_stale_running_syncs_as_failed() {
    $db = get_db();
    $db->exec("UPDATE pos_sync_runs
        SET status = 'failed', finished_at = NOW(),
            error_message = 'توقفت العملية قبل ما تخلص (على الأغلب انتهى وقت التنفيذ المسموح بالسيرفر) - اتعلّمت تلقائياً كـ فشلت'
        WHERE status = 'running' AND started_at < (NOW() - INTERVAL 20 MINUTE)");
}

// ==================== نقطة استئناف المزامنة التاريخية (عشان ما نرجع نبلّش من الصفر كل مرة) ====================
function pos_ensure_checkpoint_table() {
    $db = get_db();
    $db->exec("CREATE TABLE IF NOT EXISTS pos_sync_checkpoints (
        name VARCHAR(50) NOT NULL PRIMARY KEY,
        value INT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pos_get_checkpoint($key) {
    pos_ensure_checkpoint_table();
    $db = get_db();
    $stmt = $db->prepare('SELECT value FROM pos_sync_checkpoints WHERE name = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int) $v : 1;
}

function pos_set_checkpoint($key, $value) {
    pos_ensure_checkpoint_table();
    $db = get_db();
    $db->prepare('INSERT INTO pos_sync_checkpoints (name, value) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE value = VALUES(value)')
       ->execute([$key, $value]);
}

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
        if ($name !== '' && $name !== $existing['name']) { $updates[] = 'name = ?'; $params[] = $name; }
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

    // 3) مورد جديد بالكامل
    $db->prepare("INSERT INTO suppliers (pos_id, source, name, phone, balance, created_at, updated_at) VALUES (?, 'pos', ?, ?, 0, NOW(), NOW())")
       ->execute([$pos_id, $name, $phone]);
    $new_id = $db->lastInsertId();
    $counts['suppliers_new']++;
    pos_log_item($run_id, 'supplier', 'insert', $new_id, "مورد جديد من POS: $name (#$pos_id)");
    return $new_id;
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
        if ($name !== '' && $name !== $existing['name']) { $updates[] = 'name = ?'; $params[] = $name; }
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

    // 3) عميل جديد بالكامل
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
        pos_find_or_create_customer_by_pos_id($pos_id, $name, $phone, $run_id, $counts);
    }
}

// ==================== الموردين (نسخة جديدة عبر customer_supplier_reports - فيها pos_id حقيقي) ====================

/** بينضف نص الملاحظة الراجعة من الـ API (فيها HTML entities مشفرة زي &lt;p&gt;) */
function pos_clean_note($note) {
    if (!$note) return '';
    $note = html_entity_decode((string) $note, ENT_QUOTES, 'UTF-8');
    $note = strip_tags($note);
    return trim(preg_replace('/\s+/u', ' ', $note));
}

/** استيراد دفتر عناوين الموردين من الـ endpoint الجديد - فيه supplier_id حقيقي */
function pos_sync_suppliers_v2($run_id, array &$counts) {
    $rows = pos_fetch_all_pages('customer_supplier_reports/suppliers');
    foreach ($rows as $row) {
        $pos_id = pos_field($row, ['supplier_id', 'id']);
        $name = pos_field($row, ['name', 'company'], '');
        if (!$pos_id || !$name) continue;
        $phone = pos_field($row, ['phone', 'mobile']);
        pos_find_or_create_supplier_by_pos_id($pos_id, $name, $phone, $run_id, $counts);
    }
}

/**
 * مزامنة حركات الموردين عن طريق كشف حساب (ledger) كامل لكل مورد
 * من customer_supplier_reports/supplier_statement - كل سطر برجع من الـ API
 * (فاتورة شراء أو دفعة) بيتسجل هون كحركة وحدة بالضبط زي ما هي، معتمدين
 * على "signed_amount" لتحديد الاتجاه (موجب = زيادة دين علينا، سالب = تسديد)
 * مش على "movement_type" لأنه في حالات (خصم مسموح به مثلاً) بتيجي payment
 * بس بأثر عكسي.
 *
 * $cutoff_override: تايم ستامب اختياري لبداية الفترة (لمزامنة تاريخية من تاريخ معيّن).
 * لو مش موجود، بستخدم آخر يوم واحد بس (POS_SYNC_DAYS_BACK) للمزامنة الدورية العادية.
 */
function pos_sync_supplier_ledgers($run_id, array &$counts, $cutoff_override = null) {
    $db = get_db();

    if ($cutoff_override !== null) {
        $start_date = date('Y-m-d', $cutoff_override);
    } else {
        $days_back = (int) env('POS_SYNC_DAYS_BACK', 1);
        $start_date = date('Y-m-d', strtotime("-{$days_back} days"));
    }
    $end_date = date('Y-m-d');

    $suppliers = $db->query("SELECT id, pos_id FROM suppliers WHERE pos_id IS NOT NULL AND pos_id <> ''")->fetchAll();

    foreach ($suppliers as $sup) {
        $local_supplier_id = $sup['id'];
        $pos_supplier_id = $sup['pos_id'];

        // ⚠️ نفس حماية العملاء - فشل مورد واحد ما لازم يسقط باقي الموردين معه.
        try {
            // سحب على دفعات (مش طلب واحد) - نفس تصحيح كشف حساب العملاء، لتفادي
            // تعبئة ذاكرة PHP لما يكون مدى التاريخ طويل (مزامنة من 2020 مثلاً).
            $rows = pos_fetch_all_pages('customer_supplier_reports/supplier_statement', [
                'supplier_id' => $pos_supplier_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
            ]);
        } catch (Throwable $e) {
            $counts['supplier_ledger_failed'] = ($counts['supplier_ledger_failed'] ?? 0) + 1;
            $counts['supplier_ledger_failed_sample'] = $counts['supplier_ledger_failed_sample'] ?? $e->getMessage();

            // 🛑 نفس circuit breaker العملاء - لو rate limit، نوقف فوراً بدل ما نضيع وقت
            if (pos_is_rate_limit_error($e->getMessage())) {
                $counts['supplier_ledger_stopped_early'] = true;
                break;
            }

            usleep(300000);
            continue;
        }

        foreach ($rows as $row) {
            $document_id = pos_field($row, ['document_id']);
            if (!$document_id) continue;

            $signed = round((float) pos_field($row, ['signed_amount'], 0), 2);
            if (abs($signed) <= POS_AMOUNT_EPSILON) continue; // ما إله أثر مالي فعلي

            $movement_type = pos_field($row, ['movement_type'], '');
            // مطابق لقناعة suppliers.php: debit = فاتورة ذمم (شراء بيرفع الدين)، credit = دفعة من الحساب (بتنزّل الدين)
            $type = $signed > 0 ? 'debit' : 'credit';
            $amount = abs($signed);
            if ($type === 'credit') {
                // دفعة فعلية للمورد - نقرأ طريقة الدفع الحقيقية من الـ POS بدل افتراض كاش
                [$paid_from_bank, $no_bank_reason] = pos_resolve_payment_method(pos_field($row, ['paid_by']));
            } else {
                $paid_from_bank = null;
                $no_bank_reason = 'مشترى POS - متبقي غير مدفوع';
            }

            $date_raw = pos_field($row, ['date']);
            $tx_date = $date_raw ? date('Y-m-d', strtotime($date_raw)) : date('Y-m-d');

            // 🔒 حماية دائمة: أي حركة تاريخها = تاريخ القطع أو قبله، معناها أصلاً
            // ملخّصة جوا حركة "رصيد افتتاحي" - سجلها بالتفصيل هون بيكرر المبلغ.
            // هاي الحماية بتشتغل حتى بالمزامنة اليومية العادية (آخر 5 أيام)، مش بس
            // بالمزامنة اليدوية الكاملة - لأنه "آخر 5 أيام" ممكن يوصل لتاريخ القطع
            // نفسه لغاية كم يوم بعده.
            if ($tx_date <= OPENING_BALANCE_CUTOFF_DATE) continue;

            $note = pos_clean_note(pos_field($row, ['note'], ''));
            $description = $label . ' #' . $document_id . ($ref_no ? " ($ref_no)" : '') . ($note !== '' ? " - $note" : '');

            // ⚠️ لازم نربط الفحص بالمورد نفسه (local_supplier_id) مش بس برقم المستند،
            // لأنه أرقام المستندات (document_id) بالـ POS مش فريدة عالمستوى الكامل بين
            // كل الموردين - كل مورد إله ترقيم مستقل بكشف حسابه. لو دورنا برقم المستند
            // بس، حركة مورد ممكن "تتصادم" برقم حركة مورد تاني وتنعالج غلط كتحديث
            // على حركة مش إلها، وتأثر على رصيد مورد مش صاحب الحركة الحقيقي.
            $stmt = $db->prepare("SELECT id, amount, type FROM supplier_transactions WHERE pos_ref_type = 'supplier_ledger' AND pos_ref_id = ? AND supplier_id = ?");
            $stmt->execute([$document_id, $local_supplier_id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                $db->prepare("INSERT INTO supplier_transactions
                        (supplier_id, type, amount, description, tx_date, created_by, source, pos_ref_type, pos_ref_id, paid_from_bank, no_bank_reason)
                     VALUES (?, ?, ?, ?, ?, ?, 'pos', 'supplier_ledger', ?, ?, ?)")
                   ->execute([$local_supplier_id, $type, $amount, $description, $tx_date, POS_SYNC_SYSTEM_USER_ID, $document_id, $paid_from_bank, $no_bank_reason]);
                $new_id = $db->lastInsertId();

                $balance_delta = $type === 'debit' ? $amount : -$amount;
                $db->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')->execute([$balance_delta, $local_supplier_id]);

                $counts['purchases_tx_new']++;
                pos_log_item($run_id, 'supplier_transaction', 'insert', $new_id, "$description - " . ($type === 'debit' ? "دين $amount" : "دفعة $amount"));
            } else {
                $old_signed = $existing['type'] === 'debit' ? (float) $existing['amount'] : -(float) $existing['amount'];
                if (abs($signed - $old_signed) > POS_AMOUNT_EPSILON) {
                    $db->prepare('UPDATE supplier_transactions SET type = ?, amount = ?, description = ?, paid_from_bank = ?, no_bank_reason = ? WHERE id = ?')
                       ->execute([$type, $amount, $description, $paid_from_bank, $no_bank_reason, $existing['id']]);

                    $delta = $signed - $old_signed; // فرق بالاتجاه الصحيح مباشرة لأنه signed مبني على نفس منطق الرصيد
                    $db->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')->execute([$delta, $local_supplier_id]);

                    $counts['purchases_tx_new']++;
                    pos_log_item($run_id, 'supplier_transaction', 'update', $existing['id'], "$description - تغيّرت من $old_signed إلى $signed");
                }
            }
        }

        usleep(300000); // وقفة بسيطة بين كل مورد وتالي لتفادي rate limit الـ POS API
    }
}

// ==================== مبيعات -> حركات عملاء (بالمتبقي الفعلي = grand_total - paid) ====================
function pos_sync_sales($run_id, array &$counts, $cutoff_override = null) {
    $db = get_db();
    $limit = 20;
    $checkpoint_key = 'sales_full_history_start';

    if ($cutoff_override !== null) {
        // وضع المزامنة التاريخية الكاملة - قابل للاستئناف (بيكمل من مكان ما وقف آخر مرة)
        $cutoff = $cutoff_override;
        $start = pos_get_checkpoint($checkpoint_key);
    } else {
        // الوضع العادي (كرون كل ربع ساعة) - بس آخر كذا يوم، ما بيحتاج استئناف
        $days_back = (int) env('POS_SYNC_DAYS_BACK', 1);
        $cutoff = strtotime("-{$days_back} days");
        $start = 1;
    }

    $params_base = ['order_by' => 'date,desc', 'include' => 'items,warehouse'];
    $max_pages = 500;

    for ($i = 0; $i < $max_pages; $i++) {
        $params = array_merge($params_base, ['start' => $start, 'limit' => $limit]);
        $decoded = pos_api_get('sales', $params); // لو صار rate limit، بيرمي Exception وآخر checkpoint محفوظ ضل زي ما هو
        $rows = pos_api_extract_list($decoded);

        if (empty($rows)) {
            if ($cutoff_override !== null) pos_set_checkpoint($checkpoint_key, 1); // خلصنا كل شي - رجّع البداية لأي مرة جاية
            break;
        }

        $reached_cutoff = false;
        foreach ($rows as $row) {
            $d = pos_field($row, ['date']);
            $ts = $d ? strtotime($d) : null;
            if ($ts !== null && $ts < $cutoff) { $reached_cutoff = true; continue; }

            $ref_id = pos_field($row, ['reference_no', 'id']);
            if (!$ref_id) continue;

            $customer_name = trim((string) pos_field($row, ['customer'], ''));
            if ($customer_name === '' || in_array($customer_name, POS_IGNORE_CUSTOMER_NAMES, true)) {
                continue; // زبون كاش عابر - ما إله ذمم
            }

            $grand_total = (float) pos_field($row, ['grand_total'], 0);
            $paid = (float) pos_field($row, ['paid'], 0);
            $due = round($grand_total - $paid, 2);
            $tx_date_raw = pos_field($row, ['date']);
            $tx_date = $tx_date_raw ? date('Y-m-d', strtotime($tx_date_raw)) : date('Y-m-d');

            // 🔒 نفس حماية كشوف الحساب - تجاهل أي فاتورة بتاريخ القطع أو قبله
            if ($tx_date <= OPENING_BALANCE_CUTOFF_DATE) continue;

            $description = 'مبيعة POS #' . $ref_id;

            $stmt = $db->prepare("SELECT id, amount FROM customer_transactions WHERE pos_ref_type = 'sale' AND pos_ref_id = ?");
            $stmt->execute([$ref_id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                if ($due <= POS_AMOUNT_EPSILON) continue; // مدفوعة بالكامل من البداية - ما بتدخل ذمم أصلاً
                $customer_id = pos_find_or_create_customer_by_name($customer_name, null, $run_id, $counts);
                if (!$customer_id) continue;

                $db->prepare("INSERT INTO customer_transactions
                        (customer_id, type, amount, description, tx_date, created_by, source, pos_ref_type, pos_ref_id)
                     VALUES (?, 'debit', ?, ?, ?, ?, 'pos', 'sale', ?)")
                   ->execute([$customer_id, $due, $description, $tx_date, POS_SYNC_SYSTEM_USER_ID, $ref_id]);
                $new_id = $db->lastInsertId();
                $db->prepare('UPDATE customers SET balance = balance + ? WHERE id = ?')->execute([$due, $customer_id]);

                $counts['sales_tx_new']++;
                pos_log_item($run_id, 'customer_transaction', 'insert', $new_id, "$description - متبقي $due على \"$customer_name\"");
            } else {
                $old_due = (float) $existing['amount'];
                if (abs($due - $old_due) > POS_AMOUNT_EPSILON) {
                    $delta = $due - $old_due;
                    $customer_id_stmt = $db->prepare('SELECT customer_id FROM customer_transactions WHERE id = ?');
                    $customer_id_stmt->execute([$existing['id']]);
                    $customer_id = $customer_id_stmt->fetchColumn();

                    $db->prepare('UPDATE customer_transactions SET amount = ? WHERE id = ?')->execute([max($due, 0), $existing['id']]);
                    $db->prepare('UPDATE customers SET balance = balance + ? WHERE id = ?')->execute([$delta, $customer_id]);

                    $counts['sales_tx_new']++;
                    pos_log_item($run_id, 'customer_transaction', 'update', $existing['id'], "$description - تغيّر المتبقي من $old_due إلى $due");
                }
            }
        }

        $start += $limit;
        if ($cutoff_override !== null) pos_set_checkpoint($checkpoint_key, $start); // حفظنا التقدم لحد هون بنجاح

        if ($reached_cutoff) {
            if ($cutoff_override !== null) pos_set_checkpoint($checkpoint_key, 1); // وصلنا للنهاية فعلياً
            break;
        }
        if (count($rows) < $limit) {
            if ($cutoff_override !== null) pos_set_checkpoint($checkpoint_key, 1);
            break;
        }
        usleep(400000);
    }
}

// ==================== مشتريات -> حركات موردين (الدين المتبقي + الدفعة الكاش الفعلية) ====================
// المستخدم أكّد إن كل المشتريات بتنّدفع كاش، فبنسجل جزئين لكل فاتورة:
// 1) "المتبقي" (due) كحركة credit عادية (دين على المحل) - زي ما كان قبل.
// 2) "المدفوع" (paid) كحركة debit بـ paid_from_bank='cash' - هاي بتخلي الـ trigger
//    الموجود أصلاً بقاعدة البيانات (trg_st_after_insert) يعمل قيد يومية تلقائي يقلل
//    صندوق الكاش الفعلي، بدون ما نحتاج نلمس جدول cash_transactions يدوياً.
function pos_sync_purchases($run_id, array &$counts, $cutoff_override = null) {
    $db = get_db();
    if ($cutoff_override !== null) {
        $cutoff = $cutoff_override;
    } else {
        $days_back = (int) env('POS_SYNC_DAYS_BACK', 1);
        $cutoff = strtotime("-{$days_back} days");
    }
    $rows = pos_fetch_recent_pages('purchases', ['include' => 'items,warehouse'], $cutoff);

    foreach ($rows as $row) {
        $ref_id = pos_field($row, ['reference_no', 'id']);
        if (!$ref_id) continue;

        $supplier_name = trim((string) pos_field($row, ['supplier'], ''));
        if ($supplier_name === '') continue;

        $grand_total = (float) pos_field($row, ['grand_total'], 0);
        $paid = (float) pos_field($row, ['paid'], 0);
        $due = round($grand_total - $paid, 2);
        $tx_date_raw = pos_field($row, ['date']);
        $tx_date = $tx_date_raw ? date('Y-m-d', strtotime($tx_date_raw)) : date('Y-m-d');

        // 🔒 نفس حماية كشوف الحساب - تجاهل أي فاتورة بتاريخ القطع أو قبله
        if ($tx_date <= OPENING_BALANCE_CUTOFF_DATE) continue;

        $supplier_id = null;

        // ---- جزء 1: المتبقي (الدين) ----
        $stmt = $db->prepare("SELECT id, amount FROM supplier_transactions WHERE pos_ref_type = 'purchase' AND pos_ref_id = ?");
        $stmt->execute([$ref_id]);
        $existing_due = $stmt->fetch();
        $description = 'مشترى POS #' . $ref_id;

        if (!$existing_due) {
            if ($due > POS_AMOUNT_EPSILON) {
                $supplier_id = pos_find_or_create_supplier_by_name($supplier_name, null, $run_id, $counts);
                if ($supplier_id) {
                    $db->prepare("INSERT INTO supplier_transactions
                            (supplier_id, type, amount, description, tx_date, created_by, source, pos_ref_type, pos_ref_id, no_bank_reason)
                         VALUES (?, 'credit', ?, ?, ?, ?, 'pos', 'purchase', ?, 'مشترى POS - متبقي غير مدفوع')")
                       ->execute([$supplier_id, $due, $description, $tx_date, POS_SYNC_SYSTEM_USER_ID, $ref_id]);
                    $new_id = $db->lastInsertId();
                    $db->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')->execute([$due, $supplier_id]);

                    $counts['purchases_tx_new']++;
                    pos_log_item($run_id, 'supplier_transaction', 'insert', $new_id, "$description - متبقي $due لـ \"$supplier_name\"");
                }
            }
        } else {
            $old_due = (float) $existing_due['amount'];
            if (abs($due - $old_due) > POS_AMOUNT_EPSILON) {
                $delta = $due - $old_due;
                $supplier_id_stmt = $db->prepare('SELECT supplier_id FROM supplier_transactions WHERE id = ?');
                $supplier_id_stmt->execute([$existing_due['id']]);
                $supplier_id = $supplier_id_stmt->fetchColumn();

                $db->prepare('UPDATE supplier_transactions SET amount = ? WHERE id = ?')->execute([max($due, 0), $existing_due['id']]);
                $db->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')->execute([$delta, $supplier_id]);

                $counts['purchases_tx_new']++;
                pos_log_item($run_id, 'supplier_transaction', 'update', $existing_due['id'], "$description - تغيّر المتبقي من $old_due إلى $due");
            }
        }

        // ---- جزء 2: المدفوع فعلياً (دفعة كاش حقيقية أثرت على الصندوق) ----
        if ($paid > POS_AMOUNT_EPSILON) {
            $stmt2 = $db->prepare("SELECT id FROM supplier_transactions WHERE pos_ref_type = 'purchase_payment' AND pos_ref_id = ?");
            $stmt2->execute([$ref_id]);
            if (!$stmt2->fetch()) {
                if (!$supplier_id) {
                    $supplier_id = pos_find_or_create_supplier_by_name($supplier_name, null, $run_id, $counts);
                }
                if ($supplier_id) {
                    $pay_description = 'دفعة كاش POS #' . $ref_id;
                    $db->prepare("INSERT INTO supplier_transactions
                            (supplier_id, type, amount, description, tx_date, created_by, source, pos_ref_type, pos_ref_id, paid_from_bank)
                         VALUES (?, 'debit', ?, ?, ?, ?, 'pos', 'purchase_payment', ?, 'cash')")
                       ->execute([$supplier_id, $paid, $pay_description, $tx_date, POS_SYNC_SYSTEM_USER_ID, $ref_id]);
                    $new_pay_id = $db->lastInsertId();
                    $db->prepare('UPDATE suppliers SET balance = balance - ? WHERE id = ?')->execute([$paid, $supplier_id]);

                    $counts['purchases_tx_new']++;
                    pos_log_item($run_id, 'supplier_transaction', 'insert', $new_pay_id, "$pay_description - كاش لـ \"$supplier_name\"");
                }
            }
        }
    }
}

/**
 * مزامنة العملاء لحالهم فقط (دفتر عناوين + حركات المبيعات) من تاريخ محدد لليوم.
 * ما بتلمس الموردين أو المشتريات إطلاقاً.
 */
function pos_run_customers_sync($cutoff_override = null, $sync_type = 'from_date_customers') {
    if (!pos_try_acquire_lock('pos_sync_customers')) {
        return [
            'run_id' => null, 'status' => 'skipped',
            'counts' => ['customers_new' => 0, 'customers_updated' => 0, 'suppliers_new' => 0, 'suppliers_updated' => 0, 'sales_tx_new' => 0, 'purchases_tx_new' => 0],
            'errors' => ['في مزامنة عملاء شغّالة حالياً (يمكن ضغطت الزر مرتين) - انتظر لين تخلص وجرب تاني.'],
        ];
    }
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO pos_sync_runs (started_at, status, sync_type) VALUES (NOW(), 'running', ?)");
        $stmt->execute([$sync_type]);
        $run_id = $db->lastInsertId();

        $counts = [
            'customers_new' => 0, 'customers_updated' => 0,
            'suppliers_new' => 0, 'suppliers_updated' => 0,
            'sales_tx_new' => 0, 'purchases_tx_new' => 0,
        ];
        $errors = [];

        try { pos_sync_customers($run_id, $counts); } catch (Throwable $e) { $errors[] = "pos_sync_customers: " . $e->getMessage(); }
        try { pos_sync_sales($run_id, $counts, $cutoff_override); } catch (Throwable $e) { $errors[] = "pos_sync_sales: " . $e->getMessage(); }

        $status = empty($errors) ? 'success' : (pos_sum_numeric_counts($counts) > 0 ? 'partial' : 'failed');
        $db->prepare('UPDATE pos_sync_runs SET finished_at = NOW(), status = ?, customers_new=?, customers_updated=?, suppliers_new=?, suppliers_updated=?, sales_tx_new=?, purchases_tx_new=?, error_message=? WHERE id = ?')
           ->execute([
               $status,
               $counts['customers_new'], $counts['customers_updated'],
               $counts['suppliers_new'], $counts['suppliers_updated'],
               $counts['sales_tx_new'], $counts['purchases_tx_new'],
               $errors ? implode(' | ', $errors) : null,
               $run_id,
           ]);

        return ['run_id' => $run_id, 'status' => $status, 'counts' => $counts, 'errors' => $errors];
    } finally {
        pos_release_lock('pos_sync_customers');
    }
}

/**
 * مزامنة الموردين لحالهم فقط (دفتر عناوين + حركات المشتريات) من تاريخ محدد لليوم.
 * ما بتلمس العملاء أو المبيعات إطلاقاً.
 */
function pos_run_suppliers_sync($cutoff_override = null, $sync_type = 'from_date_suppliers') {
    if (!pos_try_acquire_lock('pos_sync_suppliers')) {
        return [
            'run_id' => null, 'status' => 'skipped',
            'counts' => ['customers_new' => 0, 'customers_updated' => 0, 'suppliers_new' => 0, 'suppliers_updated' => 0, 'sales_tx_new' => 0, 'purchases_tx_new' => 0],
            'errors' => ['في مزامنة موردين شغّالة حالياً (يمكن ضغطت الزر مرتين) - انتظر لين تخلص وجرب تاني.'],
        ];
    }
    try {
        $db = get_db();
        pos_mark_stale_running_syncs_as_failed();
        $stmt = $db->prepare("INSERT INTO pos_sync_runs (started_at, status, sync_type) VALUES (NOW(), 'running', ?)");
        $stmt->execute([$sync_type]);
        $run_id = $db->lastInsertId();

        $counts = [
            'customers_new' => 0, 'customers_updated' => 0,
            'suppliers_new' => 0, 'suppliers_updated' => 0,
            'sales_tx_new' => 0, 'purchases_tx_new' => 0,
        ];
        $errors = [];

        try { pos_sync_suppliers_v2($run_id, $counts); } catch (Throwable $e) { $errors[] = "pos_sync_suppliers_v2: " . $e->getMessage(); }
        try { pos_sync_supplier_ledgers($run_id, $counts, $cutoff_override); } catch (Throwable $e) { $errors[] = "pos_sync_supplier_ledgers: " . $e->getMessage(); }

        if (!empty($counts['supplier_ledger_failed'])) {
            $errors[] = "تعذّر سحب كشف حساب {$counts['supplier_ledger_failed']} مورد (على الأغلب rate limit) - رح تتصحح تلقائياً بالمزامنة الجاية. أول رسالة خطأ: " . ($counts['supplier_ledger_failed_sample'] ?? '');
        }

        $status = empty($errors) ? 'success' : (pos_sum_numeric_counts($counts) > 0 ? 'partial' : 'failed');
        $db->prepare('UPDATE pos_sync_runs SET finished_at = NOW(), status = ?, customers_new=?, customers_updated=?, suppliers_new=?, suppliers_updated=?, sales_tx_new=?, purchases_tx_new=?, error_message=? WHERE id = ?')
           ->execute([
               $status,
               $counts['customers_new'], $counts['customers_updated'],
               $counts['suppliers_new'], $counts['suppliers_updated'],
               $counts['sales_tx_new'], $counts['purchases_tx_new'],
               $errors ? implode(' | ', $errors) : null,
               $run_id,
           ]);

        return ['run_id' => $run_id, 'status' => $status, 'counts' => $counts, 'errors' => $errors];
    } finally {
        pos_release_lock('pos_sync_suppliers');
    }
}

/**
 * الدالة الرئيسية: بتشغل كل خطوات المزامنة وبتسجل نتيجة كل تشغيلة بجدول pos_sync_runs
 * $cutoff_override: تايم ستامب (strtotime) اختياري - لو انبعت، بيتجاوز فترة اليوم الواحد الافتراضية
 * ويجيب كل السجلات الأحدث من هاد التاريخ (مستخدم لمزامنة كامل التاريخ من أول مرة فقط).
 */
function pos_run_full_sync($cutoff_override = null, $sync_type = 'manual') {
    $got_customers_lock = pos_try_acquire_lock('pos_sync_customers');
    $got_suppliers_lock = pos_try_acquire_lock('pos_sync_suppliers');
    if (!$got_customers_lock || !$got_suppliers_lock) {
        if ($got_customers_lock) pos_release_lock('pos_sync_customers');
        if ($got_suppliers_lock) pos_release_lock('pos_sync_suppliers');
        return [
            'run_id' => null, 'status' => 'skipped',
            'counts' => ['customers_new' => 0, 'customers_updated' => 0, 'suppliers_new' => 0, 'suppliers_updated' => 0, 'sales_tx_new' => 0, 'purchases_tx_new' => 0],
            'errors' => ['في مزامنة شغّالة حالياً (عملاء أو موردين) - انتظر لين تخلص وجرب تاني.'],
        ];
    }
    try {
        $db = get_db();
        pos_mark_stale_running_syncs_as_failed();
        $stmt = $db->prepare("INSERT INTO pos_sync_runs (started_at, status, sync_type) VALUES (NOW(), 'running', ?)");
        $stmt->execute([$sync_type]);
        $run_id = $db->lastInsertId();

        $counts = [
            'customers_new' => 0, 'customers_updated' => 0,
            'suppliers_new' => 0, 'suppliers_updated' => 0,
            'sales_tx_new' => 0, 'purchases_tx_new' => 0,
        ];
        $errors = [];

        try { pos_sync_customers($run_id, $counts); } catch (Throwable $e) { $errors[] = "pos_sync_customers: " . $e->getMessage(); }
        try { pos_sync_suppliers_v2($run_id, $counts); } catch (Throwable $e) { $errors[] = "pos_sync_suppliers_v2: " . $e->getMessage(); }
        try { pos_sync_sales($run_id, $counts, $cutoff_override); } catch (Throwable $e) { $errors[] = "pos_sync_sales: " . $e->getMessage(); }
        try { pos_sync_supplier_ledgers($run_id, $counts, $cutoff_override); } catch (Throwable $e) { $errors[] = "pos_sync_supplier_ledgers: " . $e->getMessage(); }

        if (!empty($counts['supplier_ledger_failed'])) {
            $errors[] = "تعذّر سحب كشف حساب {$counts['supplier_ledger_failed']} مورد (على الأغلب rate limit) - رح تتصحح تلقائياً بالمزامنة الجاية.";
        }

        $status = empty($errors) ? 'success' : (pos_sum_numeric_counts($counts) > 0 ? 'partial' : 'failed');
        $db->prepare('UPDATE pos_sync_runs SET finished_at = NOW(), status = ?, customers_new=?, customers_updated=?, suppliers_new=?, suppliers_updated=?, sales_tx_new=?, purchases_tx_new=?, error_message=? WHERE id = ?')
           ->execute([
               $status,
               $counts['customers_new'], $counts['customers_updated'],
               $counts['suppliers_new'], $counts['suppliers_updated'],
               $counts['sales_tx_new'], $counts['purchases_tx_new'],
               $errors ? implode(' | ', $errors) : null,
               $run_id,
           ]);

        return ['run_id' => $run_id, 'status' => $status, 'counts' => $counts, 'errors' => $errors];
    } finally {
        pos_release_lock('pos_sync_customers');
        pos_release_lock('pos_sync_suppliers');
    }
}

// ==================== كشف حساب عملاء (نفس منطق الموردين بالضبط - customer_supplier_reports) ====================
/**
 * $batch_size: لو انبعت رقم (مش null)، ما منعالج كل العملاء بمرة وحدة - منعالج
 * دفعة بس بحجم $batch_size، ومنحفظ "وين وصلنا" بجدول pos_sync_checkpoints
 * (checkpoint key: customer_ledger_batch_cursor). المرة الجاية بتكمل من وين
 * وقفت، ولما توصل لآخر عميل بترجع تلف من الأول تاني. هيك لو قسمنا اليوم لـ 12
 * مزامنة (كل وحدة دفعة ~29 عميل من 346)، كل دفعة بتضل تحت سقف الـ POS بأمان،
 * وبعد الـ 12 مزامنة بيكونوا انسحبوا كلهم.
 */
function pos_sync_customer_ledgers($run_id, array &$counts, $cutoff_override = null, $end_date_override = null, $batch_size = null) {
    $db = get_db();

    if ($cutoff_override !== null) {
        $start_date = date('Y-m-d', $cutoff_override);
    } else {
        $days_back = (int) env('POS_SYNC_DAYS_BACK', 1);
        $start_date = date('Y-m-d', strtotime("-{$days_back} days"));
    }
    // لو انبعت تاريخ نهاية محدد (مزامنة تاريخية على دفعات لتفادي timeout)، نستخدمه
    // بدل "اليوم" الافتراضي - هيك تقدر تسحب مثلاً 2024-01-01 → 2024-06-30 بس بمرة وحدة
    $end_date = $end_date_override !== null ? date('Y-m-d', $end_date_override) : date('Y-m-d');

    $all_customers = $db->query("SELECT id, pos_id FROM customers WHERE pos_id IS NOT NULL AND pos_id <> '' ORDER BY id")->fetchAll();

    if ($batch_size !== null && $batch_size > 0 && count($all_customers) > 0) {
        $checkpoint_key = 'customer_ledger_batch_cursor';
        $cursor = pos_get_checkpoint($checkpoint_key); // 1-indexed، أول مرة = 1
        $total = count($all_customers);
        $start_idx = ($cursor - 1) % $total; // نلف تلقائياً لو وصلنا آخر القائمة

        $customers = [];
        for ($i = 0; $i < min($batch_size, $total); $i++) {
            $customers[] = $all_customers[($start_idx + $i) % $total];
        }

        $next_cursor = $start_idx + count($customers) + 1;
        if ($next_cursor > $total) $next_cursor = (($next_cursor - 1) % $total) + 1;
        pos_set_checkpoint($checkpoint_key, $next_cursor);

        $counts['customer_ledger_batch_info'] = "دفعة {$batch_size} من أصل {$total} عميل (بدأت من ترتيب " . ($start_idx + 1) . ")";
    } else {
        $customers = $all_customers;
    }

    foreach ($customers as $cust) {
        $local_customer_id = $cust['id'];
        $pos_customer_id = $cust['pos_id'];

        // ⚠️ لازم نغلّف كل عميل بمحاولة/التقاط لحاله - لو فشل عميل واحد (حتى
        // بعد إعادة المحاولات جوا pos_api_get)، ما لازم يسقط باقي الـ 345 عميل
        // معه. منسجل الفشل ومنكمل - وبيتصحح تلقائياً بالمزامنة الجاية.
        try {
            // ⚠️ لازم نسحب على دفعات (pos_fetch_all_pages) مش بطلب واحد - لأنه لما يكون
            // مدى التاريخ طويل (من 2020 مثلاً) الـ API بيرجع آلاف السجلات دفعة وحدة
            // ورد الـ JSON الضخم كان بيعبي الذاكرة المسموحة لـ PHP ويطيح الصفحة
            // (Allowed memory size exhausted بملف pos_api_client.php).
            $rows = pos_fetch_all_pages('customer_supplier_reports/customer_statement', [
                'customer_id' => $pos_customer_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
            ]);
        } catch (Throwable $e) {
            // ⚠️ ما منسجلها بجدول pos_sync_items لأنه عمود entity_type/action فيه enum
            // بقيم محددة سلفاً وما بيقبل قيم جديدة زي "error" - بنكتفي بعدّاد هون،
            // ورسالة الخطأ الكاملة بتنضاف لملخص العملية (pos_sync_runs.error_message)
            // من الدالة يلي بتنادي هاي الدالة.
            $counts['customer_ledger_failed'] = ($counts['customer_ledger_failed'] ?? 0) + 1;
            $counts['customer_ledger_failed_sample'] = $counts['customer_ledger_failed_sample'] ?? $e->getMessage();

            // 🛑 Circuit breaker: لو السبب rate limit، يعني الحصة (quota) مستنفذة
            // فعلياً - وباقي العملاء رح يفشلوا كمان أكيد. نوقف الحلقة فوراً بدل
            // ما نضيع وقت (ودقايق تنفيذ السيرفر) على 300+ محاولة فاشلة أكيد.
            // باقي العملاء رح ينسحبوا بالمزامنة الجاية لما الحصة تترجع.
            if (pos_is_rate_limit_error($e->getMessage())) {
                $counts['customer_ledger_stopped_early'] = true;
                break;
            }

            usleep(300000);
            continue;
        }

        foreach ($rows as $row) {
            $document_id = pos_field($row, ['document_id']);
            if (!$document_id) continue;

            $signed = round((float) pos_field($row, ['signed_amount'], 0), 2);
            if (abs($signed) <= POS_AMOUNT_EPSILON) continue;

            $movement_type = pos_field($row, ['movement_type'], '');
            // مبيعة بترفع دين العميل (debit) / دفعة من العميل بتنزّل الدين (credit)
            $type = $signed > 0 ? 'debit' : 'credit';
            $amount = abs($signed);
            if ($type === 'credit') {
                // دفعة فعلية من العميل - نقرأ طريقة الدفع الحقيقية من الـ POS بدل افتراض كاش
                [$paid_from_bank, $no_bank_reason] = pos_resolve_payment_method(pos_field($row, ['paid_by']));
            } else {
                $paid_from_bank = null;
                $no_bank_reason = 'مبيعة POS - متبقي غير مدفوع';
            }

            $date_raw = pos_field($row, ['date']);
            $tx_date = $date_raw ? date('Y-m-d', strtotime($date_raw)) : date('Y-m-d');

            // 🔒 نفس حماية الموردين: تجاهل أي حركة بتاريخ القطع أو قبله (ملخّصة
            // أصلاً بالرصيد الافتتاحي).
            if ($tx_date <= OPENING_BALANCE_CUTOFF_DATE) continue;

            $note = pos_clean_note(pos_field($row, ['note'], ''));
            $ref_no = pos_field($row, ['reference_no'], '');
            $label = $movement_type === 'sale' ? 'مبيعة POS' : 'حركة POS';
            $description = $label . ' #' . $document_id . ($ref_no ? " ($ref_no)" : '') . ($note !== '' ? " - $note" : '');

            // ⚠️ نفس درس الموردين: لازم نربط الفحص بالعميل نفسه، مش برقم المستند بس
            $stmt = $db->prepare("SELECT id, amount, type FROM customer_transactions WHERE pos_ref_type = 'customer_ledger' AND pos_ref_id = ? AND customer_id = ?");
            $stmt->execute([$document_id, $local_customer_id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                $db->prepare("INSERT INTO customer_transactions
                        (customer_id, type, amount, description, tx_date, created_by, source, pos_ref_type, pos_ref_id, paid_from_bank, no_bank_reason)
                     VALUES (?, ?, ?, ?, ?, ?, 'pos', 'customer_ledger', ?, ?, ?)")
                   ->execute([$local_customer_id, $type, $amount, $description, $tx_date, POS_SYNC_SYSTEM_USER_ID, $document_id, $paid_from_bank, $no_bank_reason]);
                $new_id = $db->lastInsertId();

                $balance_delta = $type === 'debit' ? $amount : -$amount;
                $db->prepare('UPDATE customers SET balance = balance + ? WHERE id = ?')->execute([$balance_delta, $local_customer_id]);

                $counts['sales_tx_new']++;
                pos_log_item($run_id, 'customer_transaction', 'insert', $new_id, "$description - " . ($type === 'debit' ? "دين $amount" : "دفعة $amount"));
            } else {
                $old_signed = $existing['type'] === 'debit' ? (float) $existing['amount'] : -(float) $existing['amount'];
                if (abs($signed - $old_signed) > POS_AMOUNT_EPSILON) {
                    $db->prepare('UPDATE customer_transactions SET type = ?, amount = ?, description = ?, paid_from_bank = ?, no_bank_reason = ? WHERE id = ?')
                       ->execute([$type, $amount, $description, $paid_from_bank, $no_bank_reason, $existing['id']]);

                    $delta = $signed - $old_signed;
                    $db->prepare('UPDATE customers SET balance = balance + ? WHERE id = ?')->execute([$delta, $local_customer_id]);

                    $counts['sales_tx_new']++;
                    pos_log_item($run_id, 'customer_transaction', 'update', $existing['id'], "$description - تغيّرت من $old_signed إلى $signed");
                }
            }
        }

        usleep(300000); // نفس وقفة الموردين لتفادي rate limit
    }
}

/**
 * تشغيل يدوي فقط - ما بينضاف لأي Cron Job.
 * لازم يستدعى بس من زر بصفحة، أو من CLI يدوياً.
 */
function pos_run_customer_ledgers_sync($cutoff_override = null, $sync_type = 'manual_customer_ledger', $end_date_override = null, $batch_size = null) {
    if (!pos_try_acquire_lock('pos_sync_customer_ledgers')) {
        return [
            'run_id' => null, 'status' => 'skipped',
            'counts' => ['customers_new' => 0, 'customers_updated' => 0, 'suppliers_new' => 0, 'suppliers_updated' => 0, 'sales_tx_new' => 0, 'purchases_tx_new' => 0],
            'errors' => ['في مزامنة كشف حساب عملاء شغّالة حالياً - انتظر لين تخلص وجرب تاني.'],
        ];
    }
    try {
        $db = get_db();
        pos_mark_stale_running_syncs_as_failed();
        $stmt = $db->prepare("INSERT INTO pos_sync_runs (started_at, status, sync_type) VALUES (NOW(), 'running', ?)");
        $stmt->execute([$sync_type]);
        $run_id = $db->lastInsertId();

        $counts = [
            'customers_new' => 0, 'customers_updated' => 0,
            'suppliers_new' => 0, 'suppliers_updated' => 0,
            'sales_tx_new' => 0, 'purchases_tx_new' => 0,
        ];
        $errors = [];

        try { pos_sync_customers_v2($run_id, $counts); } catch (Throwable $e) { $errors[] = "pos_sync_customers_v2: " . $e->getMessage(); }
        try { pos_sync_customer_ledgers($run_id, $counts, $cutoff_override, $end_date_override, $batch_size); } catch (Throwable $e) { $errors[] = "pos_sync_customer_ledgers: " . $e->getMessage(); }

        if (!empty($counts['customer_ledger_failed'])) {
            $errors[] = "تعذّر سحب كشف حساب {$counts['customer_ledger_failed']} عميل (على الأغلب rate limit) - رح تتصحح تلقائياً بالمزامنة الجاية. أول رسالة خطأ: " . ($counts['customer_ledger_failed_sample'] ?? '');
        }

        $status = empty($errors) ? 'success' : (pos_sum_numeric_counts($counts) > 0 ? 'partial' : 'failed');
        $db->prepare('UPDATE pos_sync_runs SET finished_at = NOW(), status = ?, customers_new=?, customers_updated=?, suppliers_new=?, suppliers_updated=?, sales_tx_new=?, purchases_tx_new=?, error_message=? WHERE id = ?')
           ->execute([
               $status,
               $counts['customers_new'], $counts['customers_updated'],
               $counts['suppliers_new'], $counts['suppliers_updated'],
               $counts['sales_tx_new'], $counts['purchases_tx_new'],
               $errors ? implode(' | ', $errors) : null,
               $run_id,
           ]);

        return ['run_id' => $run_id, 'status' => $status, 'counts' => $counts, 'errors' => $errors];
    } finally {
        pos_release_lock('pos_sync_customer_ledgers');
    }
}

// ==================== مزامنة تاريخية: رصيد العميل فقط (بدون تسجيل أي حركة) ====================
/**
 * بتحسب صافي رصيد كل عميل من مجموع "signed_amount" بكشف الحساب الكامل من
 * $start_date لـ $as_of_date، وبتحط الرقم النهائي مباشرة بعمود customers.balance
 * (استبدال كامل - مش إضافة/طرح، ومش زي pos_sync_customer_ledgers).
 *
 * ⚠️ الفرق الجوهري عن pos_sync_customer_ledgers:
 * - ما بتلمس جدول customer_transactions إطلاقاً - ولا سطر واحد.
 * - يعني ما رح تشوف أي فواتير/دفعات قديمة بكشف حساب العميل داخل النظام،
 *   بس الرصيد الحالي (الرقم الإجمالي) رح يكون مطابق لما هو بالـ POS.
 * - الهدف منها: نقل نقطة انطلاق صحيحة (opening balance) أول مرة بس، وبعدها
 *   المزامنة اليومية (pos_run_customer_ledgers_sync) هي اللي بتسجل الحركات
 *   يوم بيوم فوق هالرصيد.
 * - آمنة لإعادة التشغيل: كل مرة بتحسب من الصفر وبتستبدل الرقم، مش بتراكم.
 */
function pos_sync_customer_balances_only($run_id, array &$counts, $start_date = '2000-01-01', $as_of_date = null) {
    $db = get_db();
    $end_date = $as_of_date !== null ? date('Y-m-d', $as_of_date) : date('Y-m-d');

    $customers = $db->query("SELECT id, pos_id, name, balance FROM customers WHERE pos_id IS NOT NULL AND pos_id <> ''")->fetchAll();

    foreach ($customers as $cust) {
        $local_customer_id = $cust['id'];
        $pos_customer_id = $cust['pos_id'];

        // نفس التصحيح: سحب على دفعات لتفادي تعبئة الذاكرة عند مدى تاريخي طويل
        $rows = pos_fetch_all_pages('customer_supplier_reports/customer_statement', [
            'customer_id' => $pos_customer_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
        ]);

        $net_balance = 0.0;
        foreach ($rows as $row) {
            $net_balance += (float) pos_field($row, ['signed_amount'], 0);
        }
        $net_balance = round($net_balance, 2);
        $old_balance = round((float) $cust['balance'], 2);

        if (abs($net_balance - $old_balance) > POS_AMOUNT_EPSILON) {
            $db->prepare('UPDATE customers SET balance = ?, updated_at = NOW() WHERE id = ?')
               ->execute([$net_balance, $local_customer_id]);
            $counts['balances_set']++;
            pos_log_item($run_id, 'customer_balance', 'set', $local_customer_id,
                "رصيد \"{$cust['name']}\" اتضبط من $old_balance إلى $net_balance (نقل تاريخي - بدون حركات)");
        }

        usleep(300000); // نفس وقفة كشف الحساب لتفادي rate limit الـ POS API
    }
}

/**
 * نقطة الدخول: مزامنة تاريخية لأرصدة العملاء فقط - بتضبط customers.balance
 * على الرقم الصحيح من الـ POS، بدون ما تنشئ ولا حركة بجدول customer_transactions.
 * تستخدم مرة وحدة (أو كل ما احتجت "تصفير" الفرق) - يدوياً فقط، ما بتنضاف لأي Cron.
 *
 * $start_date: أقدم تاريخ تسحب منه كشف الحساب (افتراضياً من بداية التعامل).
 * $as_of_date_override: تايم ستامب اختياري لتاريخ "نهاية" حساب الرصيد (افتراضياً اليوم).
 */
function pos_run_customer_balances_only_sync($start_date = '2000-01-01', $as_of_date_override = null, $sync_type = 'historical_balances_only') {
    if (!pos_try_acquire_lock('pos_sync_customer_ledgers')) {
        return [
            'run_id' => null, 'status' => 'skipped',
            'counts' => ['customers_new' => 0, 'customers_updated' => 0, 'suppliers_new' => 0, 'suppliers_updated' => 0, 'sales_tx_new' => 0, 'purchases_tx_new' => 0, 'balances_set' => 0],
            'errors' => ['في مزامنة عملاء شغّالة حالياً - انتظر لين تخلص وجرب تاني.'],
        ];
    }
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO pos_sync_runs (started_at, status, sync_type) VALUES (NOW(), 'running', ?)");
        $stmt->execute([$sync_type]);
        $run_id = $db->lastInsertId();

        $counts = [
            'customers_new' => 0, 'customers_updated' => 0,
            'suppliers_new' => 0, 'suppliers_updated' => 0,
            'sales_tx_new' => 0, 'purchases_tx_new' => 0,
            'balances_set' => 0,
        ];
        $errors = [];

        try { pos_sync_customers_v2($run_id, $counts); } catch (Throwable $e) { $errors[] = "pos_sync_customers_v2: " . $e->getMessage(); }
        try { pos_sync_customer_balances_only($run_id, $counts, $start_date, $as_of_date_override); } catch (Throwable $e) { $errors[] = "pos_sync_customer_balances_only: " . $e->getMessage(); }

        $status = empty($errors) ? 'success' : ((pos_sum_numeric_counts($counts)) > 0 ? 'partial' : 'failed');
        // ملاحظة: العمود balances_set لازم يكون مضاف بجدول pos_sync_runs
        // (شغّل database/migration_customer_balances_only_sync.sql مرة وحدة أول شي)
        $db->prepare('UPDATE pos_sync_runs SET finished_at = NOW(), status = ?, customers_new=?, customers_updated=?, balances_set=?, suppliers_new=?, suppliers_updated=?, sales_tx_new=?, purchases_tx_new=?, error_message=? WHERE id = ?')
           ->execute([
               $status,
               $counts['customers_new'], $counts['customers_updated'], $counts['balances_set'],
               $counts['suppliers_new'], $counts['suppliers_updated'],
               $counts['sales_tx_new'], $counts['purchases_tx_new'],
               $errors ? implode(' | ', $errors) : null,
               $run_id,
           ]);

        return ['run_id' => $run_id, 'status' => $status, 'counts' => $counts, 'errors' => $errors];
    } finally {
        pos_release_lock('pos_sync_customer_ledgers');
    }
}
