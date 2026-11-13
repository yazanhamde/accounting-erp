<?php
// executor.php — execute_ai_action() switch/case dispatcher

// 🔒 تنفيذ تصحيح مؤكَّد: إلغاء العملية القديمة + تسجيل الجديدة بشكل ذرّي (all-or-nothing).
// بتشتغل جوا الـ transaction تبع مسار التأكيد (batch)، ولو انستدعت لحالها بتفتح transaction خاصة فيها.
// لو أي خطوة فشلت: بنرجع فشل (والـ transaction بتنعمل rollback) فالعملية الأصلية بتبقى زي ما هي.
function replace_last_entity($d, $user_id) {
    $db = get_db();
    $own_tx = !$db->inTransaction();
    $rollback = function () use ($db, $own_tx) {
        if ($own_tx && $db->inTransaction()) { $db->rollBack(); }
    };
    try {
        if ($own_tx) { $db->beginTransaction(); }

        // نتأكد إنو العملية الأصلية لسا موجودة وما انلغت/انصححت بين وقت الطلب ووقت التأكيد
        $st = $db->prepare("SELECT id, entity_table, entity_id FROM chat_log WHERE id = ? AND user_id = ? AND status = 'success' AND undone = 0 FOR UPDATE");
        $st->execute([(int)($d['log_id'] ?? 0), $user_id]);
        $old = $st->fetch();
        if (!$old || $old['entity_table'] !== ($d['entity_table'] ?? null) || (int)$old['entity_id'] !== (int)($d['entity_id'] ?? 0)) {
            $rollback();
            return ['success' => false, 'message' => 'العملية الأصلية انلغت أو تغيّرت قبل التأكيد، ما تم تنفيذ التصحيح.'];
        }

        $new_action = $d['new_action'] ?? '';
        $new_data = is_array($d['new_data'] ?? null) ? $d['new_data'] : [];
        // إعادة فحص إلزامي لمصدر الفلوس قبل أي تغيير فعلي
        if ($new_action === '' || missing_required_bank($new_action, $new_data)) {
            $rollback();
            return ['success' => false, 'message' => 'مصدر الفلوس (كاش/بنك) ناقص بالعملية المصحّحة، ما تم التصحيح.'];
        }

        $undo = undo_entity($old['entity_table'], $old['entity_id']);
        if (empty($undo['success'])) {
            $rollback();
            return ['success' => false, 'message' => 'تعذر إلغاء العملية القديمة: ' . ($undo['message'] ?? '')];
        }

        $new = execute_ai_action(['action' => $new_action, 'data' => $new_data], $user_id);
        if (empty($new['success'])) {
            $rollback();
            return ['success' => false, 'message' => 'فشل تسجيل العملية المصحّحة (العملية الأصلية ما انلمست): ' . ($new['message'] ?? '')];
        }

        $db->prepare('UPDATE chat_log SET undone = 1 WHERE id = ?')->execute([$old['id']]);
        // نسجّل الجديدة بالـ chat_log حتى "تراجع"/تصحيح لاحق يشتغل عليها
        log_chat_action($db, $user_id, '[تصحيح] ' . ($d['message'] ?? ''),
            ['action' => $new_action, 'data' => $new_data, 'reply' => ''], $new);

        if ($own_tx) { $db->commit(); }
        return [
            'success' => true,
            'message' => 'تم التصحيح ✅ ' . ($new['message'] ?? ''),
            'entity_table' => $new['entity_table'] ?? null,
            'entity_id' => $new['entity_id'] ?? null,
        ];
    } catch (\Throwable $e) {
        $rollback();
        error_log('[replace_last_entity] ' . $e->getMessage());
        if (!$own_tx) { throw $e; } // الـ transaction الخارجية لازم تعرف وتعمل rollback
        return ['success' => false, 'message' => 'خطأ بقاعدة البيانات، ما تم التصحيح.'];
    }
}
function execute_ai_action($parsed, $user_id) {
    $action = $parsed['action'];
    $d = $parsed['data'] ?? [];
    $today = date('Y-m-d');

    // حماية إضافية: لو الموديل رجّع قيمة مش صحيحة بحقل الحساب (مثل "unclear" أو أي نص عشوائي)
    // نلغيها بدل ما تنسجل بقاعدة البيانات غلط - أحسن ترجع للحساب المعلّق suspense
    if (isset($d['paid_from_bank'])) {
        $valid_banks = ['jordan','jordan_personal','jordan_business','etihad','islami','dinarak','investment','cash'];
        if (!in_array($d['paid_from_bank'], $valid_banks, true)) {
            $d['paid_from_bank'] = null;
        }
    }

    switch ($action) {
        case 'customer_transaction':
            return add_customer_transaction(
                $d['customer_name'] ?? '', $d['type'] ?? 'debit', $d['amount'] ?? 0,
                $d['description'] ?? '', $d['tx_date'] ?? $today, $user_id, null,
                $d['paid_from_bank'] ?? null, $d['no_bank_reason'] ?? null,
                $d['invoice_no'] ?? null, $d['tax_rate'] ?? null
            );

        case 'supplier_transaction':
            return add_supplier_transaction(
                $d['supplier_name'] ?? '', $d['type'] ?? 'debit', $d['amount'] ?? 0,
                $d['description'] ?? '', $d['tx_date'] ?? $today, $user_id, null,
                $d['paid_from_bank'] ?? null, $d['no_bank_reason'] ?? null
            );

        case 'personal_transaction':
            return add_personal_transaction(
                $d['person_name'] ?? '', $d['type'] ?? 'lend', $d['amount'] ?? 0,
                $d['description'] ?? '', $d['tx_date'] ?? $today, $user_id,
                $d['paid_from_bank'] ?? null, $d['no_bank_reason'] ?? null
            );

        case 'profit_distribution':
            return add_profit_distribution(
                $d['shareholder_name'] ?? '', $d['amount'] ?? 0, $d['period_label'] ?? '',
                $d['description'] ?? '', $d['tx_date'] ?? $today, $user_id,
                $d['paid_from_bank'] ?? null
            );

        case 'shareholder_contribution':
            return add_shareholder_contribution(
                $d['shareholder_name'] ?? '', $d['amount'] ?? 0,
                $d['description'] ?? '', $d['tx_date'] ?? $today, $user_id,
                $d['paid_from_bank'] ?? null, $d['no_bank_reason'] ?? null
            );

        case 'expense':
            return add_expense(
                $d['category'] ?? 'expense', $d['amount'] ?? 0, $d['description'] ?? '',
                $d['paid_from_bank'] ?? null, $d['tx_date'] ?? $today, $user_id,
                $d['no_bank_reason'] ?? null, $d['invoice_no'] ?? null, $d['tax_rate'] ?? null
            );

        case 'external_revenue':
            return add_external_revenue(
                $d['source_person'] ?? 'رمزي', $d['amount'] ?? 0, $d['description'] ?? '',
                $d['paid_from_bank'] ?? null, $d['tx_date'] ?? $today, $user_id
            );

        case 'bank_transaction':
            return add_bank_transaction(
                $d['bank'] ?? '', $d['tx_type'] ?? 'deposit', $d['amount'] ?? 0,
                $d['usage_type'] ?? null, $d['description'] ?? '', $d['tx_date'] ?? $today,
                $user_id, $d['related_customer'] ?? null
            );

        case 'cash_count':
            return add_cash_count(
                $d['count_date'] ?? $today, $d['expected_amount'] ?? 0,
                $d['actual_amount'] ?? 0, $d['notes'] ?? '', $user_id
            );

        case 'add_check':
            return add_check(
                $d['direction'] ?? 'incoming', $d['party_name'] ?? '', $d['amount'] ?? 0,
                $d['due_date'] ?? $today, $d['bank_name'] ?? 'islami', $d['check_number'] ?? '',
                $d['notes'] ?? '', $user_id,
                $d['entity_type'] ?? null, $d['entity_name'] ?? null
            );

        case 'employee_transaction':
            return add_employee_transaction(
                $d['employee_name'] ?? '', $d['type'] ?? 'salary', $d['amount'] ?? 0,
                $d['description'] ?? '', $d['tx_date'] ?? $today, $user_id, null,
                $d['paid_from_bank'] ?? null
            );

        case 'car_employee_transaction':
            return add_car_employee_transaction(
                $d['car_employee_name'] ?? '', $d['type'] ?? 'revenue', $d['amount'] ?? 0,
                $d['description'] ?? '', $d['tx_date'] ?? $today, $user_id, null, null,
                $d['customer_count'] ?? null, $d['paid_from_bank'] ?? null
            );

        case 'workshop_transaction':
            return add_workshop_transaction(
                $d['type'] ?? 'revenue', $d['amount'] ?? 0, $d['description'] ?? '',
                $d['tx_date'] ?? $today, $user_id,
                $d['paid_from_bank'] ?? null, $d['no_bank_reason'] ?? null
            );

        case 'query_overview':
            $ov = get_overview();
            return ['success' => true, 'message' => 'تم جلب نظرة عامة على الأرصدة', 'overview' => $ov, 'focus' => $d['focus'] ?? null];

        case 'query_upcoming_checks':
            $checks = get_upcoming_checks($d['days'] ?? 7);
            return ['success' => true, 'message' => 'تم جلب الشيكات القريبة', 'checks' => $checks];

        case 'query_entity':
            $info = get_entity_details($d['entity_type'] ?? '', $d['name'] ?? '');
            if (!$info) {
                return ['success' => false, 'message' => "ما لقيت \"{$d['name']}\" بجدول " . ($d['entity_type'] ?? '')];
            }
            if (isset($info['ambiguous'])) {
                return ['success' => false, 'message' => $info['message']];
            }
            return ['success' => true, 'message' => 'تم جلب البيانات', 'entity' => $info];

        case 'query_attachment':
            $att = search_attachments_by_entity_name($d['entity_type'] ?? '', $d['name'] ?? '');
            if (!$att) {
                return ['success' => false, 'message' => "ما لقيت \"{$d['name']}\""];
            }
            if (isset($att['ambiguous'])) {
                return ['success' => false, 'message' => $att['message']];
            }
            if (empty($att['attachments'])) {
                return ['success' => true, 'message' => "ما في صور محفوظة لـ {$att['name']} لهلق", 'attachments' => []];
            }
            return ['success' => true, 'message' => 'تم جلب الصور', 'attachments' => $att['attachments']];

        case '__replace_entity':
            return replace_last_entity($d, $user_id);

        case 'unclear':
        default:
            return ['success' => false, 'message' => $d['reason'] ?? 'الرسالة غير واضحة'];
    }
}
