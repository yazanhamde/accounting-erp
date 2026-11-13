<?php
// logger.php — chat action logging & summary builder
// =====================================================================
// دوال مشتركة بين api/chat.php (نص) و api/chat_image.php (صورة)
// =====================================================================

function log_chat_action($db, $user_id, $message, $parsed, $exec_result) {
    $status = $exec_result['success'] === null ? 'needs_clarification' : ($exec_result['success'] ? 'success' : 'failed');
    $stmt = $db->prepare('INSERT INTO chat_log (user_id, user_message, parsed_action, bot_reply, status, entity_table, entity_id) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $user_id,
        $message,
        json_encode($parsed, JSON_UNESCAPED_UNICODE),
        $parsed['reply'] ?? '',
        $status,
        $exec_result['entity_table'] ?? null,
        $exec_result['entity_id'] ?? null,
    ]);
}
function bank_label($key) {
    $labels = [
        'jordan_personal' => 'بنك الأردني (شخصي)',
        'jordan_business' => 'بنك الأردني (مؤسسة)',
        'jordan'          => 'بنك الأردني (غير محدد!)',
        'etihad'          => 'بنك الاتحاد',
        'islami'          => 'البنك الإسلامي',
        'dinarak'         => 'دينارك',
        'investment'      => 'البنك الاستثماري',
        'cash'            => 'كاش (الصندوق)',
    ];
    return $labels[$key] ?? ($key ?: '');
}
function build_action_summary($action, $d) {
    // نفس الحماية: ما نعرض قيمة حساب غير صحيحة بمعاينة التأكيد
    if (isset($d['paid_from_bank'])) {
        $valid_banks = ['jordan','jordan_personal','jordan_business','etihad','islami','dinarak','investment','cash'];
        if (!in_array($d['paid_from_bank'], $valid_banks, true)) {
            $d['paid_from_bank'] = null;
        }
    }
    $pb = bank_label($d['paid_from_bank'] ?? null);
    $d['bank'] = isset($d['bank']) ? bank_label($d['bank']) : null;
    switch ($action) {
        case 'customer_transaction':
            $type = ($d['type'] ?? '') === 'debit' ? 'مدين (أخذ/عليه)' : 'دائن (دفع/سدد)';
            $src = !empty($d['paid_from_bank']) ? " | من: {$pb}" : '';
            return "عميل: {$d['customer_name']} | {$type} | مبلغ: {$d['amount']}{$src} | {$d['description']}";
        case 'supplier_transaction':
            $type = ($d['type'] ?? '') === 'debit' ? 'مدين (استلمنا ولسا ما دفعنا)' : 'دائن (دفعنا)';
            $src = !empty($d['paid_from_bank']) ? " | من: {$pb}" : '';
            return "مورد: {$d['supplier_name']} | {$type} | مبلغ: {$d['amount']}{$src} | {$d['description']}";
        case 'personal_transaction':
            $type = ($d['type'] ?? '') === 'lend' ? 'سلفة أعطيناها' : 'تسديد رجع لنا';
            $src = !empty($d['paid_from_bank']) ? " | من: {$pb}" : '';
            return "شخص: {$d['person_name']} | {$type} | مبلغ: {$d['amount']}{$src} | {$d['description']}";
        case 'profit_distribution':
            $src = !empty($d['paid_from_bank']) ? " | من: {$pb}" : '';
            return "توزيع أرباح لـ: {$d['shareholder_name']} | مبلغ: {$d['amount']}{$src} | فترة: {$d['period_label']}";
        case 'shareholder_contribution':
            $src = !empty($d['paid_from_bank']) ? " | دخلت لـ: {$pb}" : '';
            return "مساهمة/إضافة فلوس من: {$d['shareholder_name']} | مبلغ: {$d['amount']}{$src} | {$d['description']}";
        case 'expense':
            return "مصروف ({$d['category']}) | مبلغ: {$d['amount']} | من: " . ($pb !== '' ? $pb : 'غير محدد') . " | {$d['description']}";
        case 'external_revenue':
            $recv = !empty($d['paid_from_bank']) ? " | دخلت لـ: {$pb}" : '';
            return "إيراد خارجي | المصدر: " . ($d['source_person'] ?? 'رمزي') . " | مبلغ: {$d['amount']}{$recv} | {$d['description']}";
        case 'bank_transaction':
             return "بنك: {$d['bank']} | " . ($d['tx_type'] === 'deposit' ? 'إيداع' : 'سحب') . " | مبلغ: {$d['amount']} | {$d['description']}";
        case 'add_check':
            return "شيك " . ($d['direction'] === 'incoming' ? 'وارد' : 'صادر') . " | {$d['party_name']} | مبلغ: {$d['amount']} | استحقاق: {$d['due_date']}";
        case 'employee_transaction':
            $type_labels = ['salary' => 'استحقاق راتب', 'advance' => 'سلفة', 'deduction' => 'خصم', 'bonus' => 'مكافأة', 'payment' => 'دفع راتب مستحق'];
            $type = $type_labels[$d['type'] ?? ''] ?? ($d['type'] ?? '');
            $src = !empty($d['paid_from_bank']) ? " | من: {$pb}" : '';
            return "موظف: {$d['employee_name']} | {$type} | مبلغ: {$d['amount']}{$src} | {$d['description']}";
        case 'car_employee_transaction':
            $type_labels2 = ['revenue' => 'تحصيل', 'expense' => 'مصروف سيارة', 'advance' => 'سلفة', 'deduction' => 'خصم', 'ads' => 'إعلانات', 'fuel' => 'محروقات', 'parts' => 'قطع و صيانة لوحات', 'vehicle_maintenance' => 'صيانة مركبة', 'secretary_salary' => 'حصة راتب سكرتارية'];
            $type2 = $type_labels2[$d['type'] ?? ''] ?? ($d['type'] ?? '');
            $src2 = !empty($d['paid_from_bank']) ? " | من: {$pb}" : '';
            return "سائق: {$d['car_employee_name']} | {$type2} | مبلغ: {$d['amount']}{$src2} | {$d['description']}";
        case 'workshop_transaction':
            $type_labels3 = ['revenue' => 'إيراد مشغل', 'expense' => 'مصاريف مشغل'];
            $type3 = $type_labels3[$d['type'] ?? ''] ?? ($d['type'] ?? '');
            $src3 = !empty($d['paid_from_bank']) ? " | من: {$pb}" : '';
            return "مشغل | {$type3} | مبلغ: {$d['amount']}{$src3} | {$d['description']}";
        default:
            return 'عملية جديدة: ' . json_encode($d, JSON_UNESCAPED_UNICODE);
    }
}
