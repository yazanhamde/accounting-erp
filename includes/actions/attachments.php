<?php
// attachments.php — image & attachment management
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../image_validation.php';
function save_temp_image($image_data_url) {
    // 🔒 فحص حقيقي للصورة: النوع من المحتوى، قائمة بيضاء، حد حجم/أبعاد (شوف image_validation.php)
    $img = validate_image_data_url($image_data_url);
    if (!$img) return null;

    $dir = dirname(__DIR__, 2) . '/uploads/attachments/' . date('Y-m');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;

    $filename = 'pending_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $img['ext'];
    $full_path = $dir . '/' . $filename;
    if (file_put_contents($full_path, $img['binary']) === false) return null;
    @chmod($full_path, 0644);

    return date('Y-m') . '/' . $filename;
}
function save_attachment($entity_table, $entity_id, $image_data_url, $caption, $user_id) {
    $img = validate_image_data_url($image_data_url);
    if (!$img) return null;
    // اسم الجدول بيدخل باسم الملف: نظّفه (حروف/أرقام/شرطة سفلية فقط) حتى ما يصير path traversal
    $safe_table = preg_replace('/[^a-z0-9_]/i', '', (string) $entity_table);
    if ($safe_table === '') return null;

    $dir = dirname(__DIR__, 2) . '/uploads/attachments/' . date('Y-m');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;

    $filename = $safe_table . '_' . (int) $entity_id . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $img['ext'];
    $full_path = $dir . '/' . $filename;
    if (file_put_contents($full_path, $img['binary']) === false) return null;
    @chmod($full_path, 0644);

    $relative_path = date('Y-m') . '/' . $filename;

    $db = get_db();
    $stmt = $db->prepare('INSERT INTO attachments (entity_table, entity_id, file_path, caption, uploaded_by) VALUES (?,?,?,?,?)');
    $stmt->execute([$entity_table, $entity_id, $relative_path, $caption, $user_id]);

    return $relative_path;
}
function get_attachments($entity_table, $entity_id) {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM attachments WHERE entity_table = ? AND entity_id = ? ORDER BY uploaded_at DESC');
    $stmt->execute([$entity_table, $entity_id]);
    return $stmt->fetchAll();
}
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
