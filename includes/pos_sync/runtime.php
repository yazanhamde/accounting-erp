<?php
/**
 * مزامنة POS - القفل ونقاط الاستئناف (checkpoint) وتسجيل العمليات وأخطاء الـ rate limit
 * (جزء من includes/pos_sync.php سابقاً - انقسم لملفات، المنطق ما تغيّر)
 */

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

/** مجموع الشغل الفعلي المنجز فقط (جديد/محدّث/حركات/محذوف) - بدون عدّادات الفشل، وإلا تشغيلة فاشلة بالكامل بتنحسب "partial" */
function pos_sum_progress_counts(array $counts) {
    $total = 0;
    foreach (['customers_new', 'customers_updated', 'suppliers_new', 'suppliers_updated', 'sales_tx_new', 'purchases_tx_new', 'deleted_tx'] as $k) {
        $total += (int) ($counts[$k] ?? 0);
    }
    return $total;
}

/** هل رسالة الخطأ هاي بسبب rate limit من الـ POS؟ (مش خطأ عابر - يعني إعادة المحاولة الفورية ما بتفيد) */
function pos_is_rate_limit_error($message) {
    return (stripos($message, 'time limit') !== false)
        || (stripos($message, 'rate limit') !== false)
        || (stripos($message, 'HTTP 429') !== false);
}

/** هل الخطأ مشكلة مفتاح/صلاحية (401/403)؟ - مش عابر: باقي الأطراف رح يفشلوا كمان، فنوقف الدفعة فوراً */
function pos_is_auth_error($message) {
    return (stripos($message, 'HTTP 401') !== false)
        || (stripos($message, 'HTTP 403') !== false);
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

/**
 * بتسجّل فشل حركة وحدة (بعد ما انعمل rollback لها) - المزامنة بتكمل ع الحركات التانية،
 * والعدّاد بيظهر كخطأ بسجل المزامنة (sync_log) عشان تنتبه وتراجع.
 */
function pos_record_tx_failure(array &$counts, Throwable $e) {
    $counts['tx_failed'] = ($counts['tx_failed'] ?? 0) + 1;
    $counts['tx_failed_sample'] = $counts['tx_failed_sample'] ?? $e->getMessage();
}
