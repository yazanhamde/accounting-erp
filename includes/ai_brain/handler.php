<?php
// handler.php — parsing, validation, fixes, main dispatcher
// =====================================================================
// 🔒 تصحيح آخر عملية (correct_last) - صار يمر على تأكيد المستخدم مثل أي عملية مالية
//   - الحقول المسموح تصحيحها محصورة بقائمة بيضاء + تحقق من القيمة
//   - لو التصحيح غيّر مصدر الفلوس (paid_from_bank) بيمر على enforce_money_rules
//   - ما بينفذ شي هون: بس بيخزّن عملية معلّقة "__replace_entity" بملخص (القديم ← الجديد)
//   - التنفيذ الفعلي (إلغاء القديمة + تسجيل الجديدة) بيصير بعد "نعم" داخل transaction وحدة
//     (مسار الـ batch الموجود أصلاً بـ chat.php و whatsapp_webhook.php)
// =====================================================================
function correction_always_allowed_fields() {
    return ['amount', 'description', 'tx_date', 'paid_from_bank', 'no_bank_reason', 'invoice_no', 'tax_rate'];
}
function correction_name_fields() {
    // حقول الأسماء: مسموحة بس لو موجودة أصلاً بالعملية القديمة (ما منضيف حقل جديد)
    return ['customer_name', 'supplier_name', 'person_name', 'shareholder_name',
            'employee_name', 'car_employee_name', 'party_name', 'category', 'period_label'];
}
// يرجّع [القيمة المنظّفة, null] أو [null, رسالة الخطأ]
function sanitize_correction_value($field, $value) {
    $value = is_string($value) ? trim($value) : $value;
    switch ($field) {
        case 'amount':
            $v = str_replace([',', '،', ' '], '', (string)$value);
            $v = strtr($v, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.']);
            if (!is_numeric($v) || (float)$v <= 0) return [null, 'المبلغ الجديد غير صالح'];
            return [$v + 0, null];
        case 'tx_date':
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return [null, 'التاريخ الجديد لازم يكون بصيغة YYYY-MM-DD'];
            }
            return [(string)$value, null];
        case 'tax_rate':
            if (!is_numeric($value) || $value < 0 || $value > 100) return [null, 'نسبة الضريبة غير صالحة'];
            return [$value + 0, null];
        case 'paid_from_bank':
            $b = normalize_bank_key((string)$value);
            $valid = ['jordan', 'jordan_personal', 'jordan_business', 'etihad', 'islami', 'dinarak', 'investment', 'cash'];
            if ($b === null || !in_array($b, $valid, true)) return [null, 'الحساب الجديد غير معروف. حدد كاش أو اسم البنك'];
            return [$b, null];
        default:
            $v = trim((string)$value);
            if ($v === '' || mb_strlen($v) > 255) return [null, 'القيمة الجديدة فاضية أو طويلة كتير'];
            return [$v, null];
    }
}
function prepare_correction_confirmation($db, $user, $correction, $message_for_log) {
    $fail = function ($msg) { return ['success' => false, 'reply' => $msg]; };

    $field = trim((string)($correction['field'] ?? ''));
    $raw_value = $correction['new_value'] ?? null;
    if ($field === '' || $raw_value === null || $raw_value === '') {
        return $fail('ما وضح شو بالضبط بدك تصحح. مثلاً: "المبلغ 500" أو "البنك كاش".');
    }

    $stmt = $db->prepare("SELECT * FROM chat_log WHERE user_id = ? AND status = 'success' AND entity_table IS NOT NULL AND undone = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user['id']]);
    $last = $stmt->fetch();
    if (!$last) {
        return $fail('ما لقيت عملية سابقة أصححها');
    }

    $old = json_decode($last['parsed_action'], true);
    $old_action = $old['action'] ?? '';
    $old_data = is_array($old['data'] ?? null) ? $old['data'] : [];
    if ($old_action === '' || empty($old_data)) {
        return $fail('ما قدرت أقرأ بيانات العملية السابقة للتصحيح. الغِ آخر عملية (اكتب "تراجع") وسجّلها من جديد.');
    }

    // قائمة بيضاء للحقول
    $allowed = in_array($field, correction_always_allowed_fields(), true)
        || (in_array($field, correction_name_fields(), true) && array_key_exists($field, $old_data));
    if (!$allowed) {
        return $fail('ما بقدر أصحح هالحقل بالتصحيح السريع (مثل نوع الحركة مدين/دائن). الغِ آخر عملية بكلمة "تراجع" وسجّلها من جديد.');
    }
    if ($field === 'paid_from_bank' && !action_moves_money($old_action, $old_data)) {
        return $fail('هاي العملية ما فيها مصدر فلوس (كاش/بنك) حتى أغيّره.');
    }

    list($value, $err) = sanitize_correction_value($field, $raw_value);
    if ($err !== null) {
        return $fail($err);
    }

    $new_data = $old_data;
    $new_data[$field] = $value;

    // تغيير مصدر الفلوس = نفس قواعد التسجيل العادي (الكاش/البنك مذكور صراحة، والأردني مؤسسة/شخصي)
    if ($field === 'paid_from_bank') {
        unset($new_data['_non_cash_ok'], $new_data['no_bank_reason']);
        $enforced = enforce_money_rules(['action' => $old_action, 'data' => $new_data], $message_for_log, $user['id'], $db);
        if (($enforced['action'] ?? '') === 'unclear') {
            return $fail($enforced['reply'] ?? 'هاي الحركة كاش أو بنك؟ حدد البنك أو اكتب "كاش".');
        }
        $new_data = $enforced['data'];
    }
    if (missing_required_bank($old_action, $new_data)) {
        return $fail('بنك الأردني لازم تحدد مؤسسة ولا شخصي، أو اكتب كاش/بنك ثاني.');
    }

    $old_summary = build_action_summary($old_action, $old_data);
    $new_summary = build_action_summary($old_action, $new_data);
    $summary = "تصحيح آخر عملية:\nقبل: {$old_summary}\nبعد: {$new_summary}";

    $pending = ['actions' => [[
        'action' => '__replace_entity',
        'data' => [
            'log_id'       => (int)$last['id'],
            'entity_table' => $last['entity_table'],
            'entity_id'    => (int)$last['entity_id'],
            'new_action'   => $old_action,
            'new_data'     => $new_data,
            'message'      => $message_for_log,
        ],
    ]]];

    $ins = $db->prepare('INSERT INTO chat_pending_actions (user_id, action_json, summary) VALUES (?,?,?)');
    $ins->execute([$user['id'], json_encode($pending, JSON_UNESCAPED_UNICODE), $summary]);
    $pending_id = $db->lastInsertId();

    log_chat_action($db, $user['id'], $message_for_log, ['action' => 'correct_last', 'data' => $correction, 'reply' => $summary],
        ['success' => null, 'message' => 'بانتظار تأكيد التصحيح']);

    return [
        'success' => true,
        'needs_confirmation' => true,
        'pending_id' => $pending_id,
        'reply' => "$summary\n\nأكد التصحيح؟ اكتب \"نعم\" للتأكيد (العملية القديمة ما بتنلغي إلا بعد التأكيد).",
    ];
}
function count_likely_action_verbs($message) {
    $verbs = ['سدد', 'دفع', 'سحب', 'حول', 'اشتريت', 'اشترينا', 'استلمنا', 'استلمت', 'وزعت',
              'صرفت', 'صرفنا', 'أعطيت', 'اعطيت', 'خصمنا', 'راتب', 'سلفة', 'شريت', 'بعنا', 'بعت',
              'حصّل', 'حصل', 'جمع'];
    $count = 0;
    $found = [];
    foreach ($verbs as $v) {
        $c = substr_count($message, $v);
        if ($c > 0 && !in_array($v, $found, true)) {
            // نتجنب عدّ نفس الفعل مرتين لو تكرر بصيغتين قريبتين (مثل صرفت/صرفنا)
            $found[] = $v;
            $count += $c;
        }
    }
    return $count;
}
function handle_multi_actions($parsed, $user, $db, $message_for_log) {
    // حد أقصى صارم: أكتر من 4 عمليات بنفس الرسالة مرفوض تماماً، لتقليل احتمال خطأ/تفويت
    if (count($parsed['actions']) > 4) {
        return [
            'success' => false,
            'reply' => 'رسالتك فيها أكتر من 4 عمليات بنفس الوقت، وهذا كتير بمرة وحدة. قسّمها لرسالتين أو أكتر (بحد أقصى 4 عمليات بكل رسالة) حتى نضمن التسجيل الصحيح.',
        ];
    }

    $no_confirmation_needed = ['query_overview', 'query_upcoming_checks', 'query_entity', 'query_attachment', 'unclear', 'undo_last'];
    $immediate_results = [];
    $pending_actions = [];
    $pending_summaries = [];

    foreach ($parsed['actions'] as $item) {
        if (($item['action'] ?? '') === 'correct_last') {
            return [
                'success' => false,
                'reply' => 'التصحيح لازم يكون برسالة لحاله (مثلاً: "المبلغ 500")، بدون ما تخلطه مع عمليات ثانية. أعد إرسال العمليات الجديدة بعد التصحيح.',
            ];
        }
        $item = fix_customer_supplier_confusion($item, $message_for_log);
        $item = fix_entity_table_by_history($item, $message_for_log);
        $item = auto_fill_goods_return_reason($item, $message_for_log);
        $item = enforce_money_rules($item, $message_for_log, $user['id'], $db);
        if (missing_required_bank($item['action'] ?? '', $item['data'] ?? [])) {
            $item['action'] = 'unclear';
            $item['data'] = ['reason' => 'هاي الحركة كاش أو بنك؟ حدد البنك أو اكتب "كاش".'];
        }
        $item_action = $item['action'] ?? 'unclear';
        if (in_array($item_action, $no_confirmation_needed, true)) {
            $exec = execute_ai_action($item, $user['id']);
            $immediate_results[] = ['action' => $item_action, 'result' => $exec];
        } else {
            $pending_actions[] = $item;
            $pending_summaries[] = build_action_summary($item_action, $item['data'] ?? []);
        }
    }

    // لو وحدة من العمليات ناقصها توضيح (مثلاً كاش ولا بنك) ما منكمل ولا منخزّن الباقي - نسأل الأول، وما نضيع السؤال
    $unclear_msgs = [];
    foreach ($immediate_results as $r) {
        if (($r['action'] ?? '') === 'unclear') { $unclear_msgs[] = $r['result']['message'] ?? ''; }
    }
    if (!empty($pending_actions) && !empty($unclear_msgs)) {
        $q = implode("\n", array_unique($unclear_msgs));
        $parsed['action'] = 'unclear';
        $parsed['reply'] = $q;
        log_chat_action($db, $user['id'], $message_for_log, $parsed, ['success' => false, 'message' => $q]);
        return [
            'success' => false,
            'reply' => "قبل ما أسجّل أي عملية من رسالتك، لازم توضيح:\n$q\n\nجاوبني وبرتب كل العمليات من جديد.",
        ];
    }

    // ما في ولا عملية تحتاج تأكيد (كلها استعلامات/أو حالات "غير واضح") -> نرجع النتائج فوراً
    if (empty($pending_actions)) {
        $all_ok = true;
        foreach ($immediate_results as $r) { if (empty($r['result']['success'])) { $all_ok = false; break; } }
        $combined_message = implode(' | ', array_map(fn($r) => $r['result']['message'] ?? '', $immediate_results));
        log_chat_action($db, $user['id'], $message_for_log, $parsed, ['success' => $all_ok, 'message' => $combined_message]);
        return [
            'success' => $all_ok,
            'reply' => $all_ok ? ($parsed['reply'] ?? $combined_message) : $combined_message,
            'details' => ['multi_immediate' => $immediate_results],
        ];
    }

    // في عمليات لازم تأكيد -> نخزنهم مع بعض كـ"عملية معلّقة" واحدة، ونعرض ملخص مرقّم
    $count = count($pending_actions);
    $summary_lines = [];
    foreach ($pending_summaries as $i => $s) {
        $summary_lines[] = ($i + 1) . ") $s";
    }
    $full_summary = "لقيت $count عمليات:\n" . implode("\n", $summary_lines);

    // فحص أمان: قارن عدد الأفعال المحاسبية بالرسالة الأصلية مع عدد العمليات يلي فهمتها -
    // لو الرسالة فيها أفعال أكتر بوضوح، حذّر المستخدم يراجع قبل التأكيد (يمكن فاتت عملية)
    $total_understood = $count + count($immediate_results);
    $likely_verbs = count_likely_action_verbs($message_for_log);
    $mismatch_warning = '';
    if ($likely_verbs > $total_understood) {
        $mismatch_warning = "\n\n⚠️ تنبيه: رسالتك فيها إشارات لعمليات أكتر مما فهمته ({$likely_verbs} مقابل {$total_understood}) - راجع الملخص فوق منيح، ممكن فاتتني عملية. لو ناقص شي، اكتبه لحاله بعد ما تأكد الباقي.";
    }

    $stmt = $db->prepare('INSERT INTO chat_pending_actions (user_id, action_json, summary) VALUES (?,?,?)');
    $stmt->execute([$user['id'], json_encode(['actions' => $pending_actions], JSON_UNESCAPED_UNICODE), $full_summary]);
    $pending_id = $db->lastInsertId();

    log_chat_action($db, $user['id'], $message_for_log, $parsed, ['success' => null, 'message' => 'بانتظار التأكيد - عمليات متعددة']);

    return [
        'success' => true,
        'needs_confirmation' => true,
        'pending_id' => $pending_id,
        'reply' => "$full_summary$mismatch_warning\n\nأكد التسجيل؟ اكتب \"نعم\" للتأكيد.",
    ];
}
function fix_customer_supplier_confusion($item, $message_for_log) {
    if (!in_array($item['action'] ?? '', ['customer_transaction', 'supplier_transaction'], true)) {
        return $item;
    }
    $has_customer_word = (mb_strpos($message_for_log, 'عميل') !== false) || (mb_strpos($message_for_log, 'زبون') !== false) || (mb_strpos($message_for_log, 'الزباين') !== false);
    $has_supplier_word = (mb_strpos($message_for_log, 'مورد') !== false);

    if ($has_customer_word && !$has_supplier_word && $item['action'] === 'supplier_transaction') {
        $item['action'] = 'customer_transaction';
        $item['data']['customer_name'] = $item['data']['supplier_name'] ?? ($item['data']['customer_name'] ?? '');
        unset($item['data']['supplier_name']);
    } elseif ($has_supplier_word && !$has_customer_word && $item['action'] === 'customer_transaction') {
        $item['action'] = 'supplier_transaction';
        $item['data']['supplier_name'] = $item['data']['customer_name'] ?? ($item['data']['supplier_name'] ?? '');
        unset($item['data']['customer_name']);
    }
    return $item;
}
function auto_fill_goods_return_reason($item, $message_for_log) {
    if (!in_array($item['action'] ?? '', ['customer_transaction', 'supplier_transaction', 'personal_transaction'], true)) return $item;
    if (!empty($item['data']['paid_from_bank'])) return $item;

    // حالة "مسامحة/إسقاط دين" شخصي - بدون فلوس فعلياً كمان، نفس منطق مرتجع البضاعة تماماً
    if (($item['action'] ?? '') === 'personal_transaction') {
        $has_forgive = (mb_strpos($message_for_log, 'مسامح') !== false) || (mb_strpos($message_for_log, 'اسقاط') !== false)
            || (mb_strpos($message_for_log, 'إسقاط') !== false) || (mb_strpos($message_for_log, 'تنازل') !== false)
            || (mb_strpos($message_for_log, 'شطب') !== false);
        if ($has_forgive) {
            $item['data']['type'] = 'repay';
            if (empty($item['data']['no_bank_reason'])) {
                $item['data']['no_bank_reason'] = 'مسامحة/إسقاط دين - بدون حركة نقدية';
            }
        }
        return $item;
    }

    $has_goods = mb_strpos($message_for_log, 'بضاع') !== false;
    $has_return = (mb_strpos($message_for_log, 'مرتجع') !== false) || (mb_strpos($message_for_log, 'رجعنا') !== false)
        || (mb_strpos($message_for_log, 'رجّعنا') !== false) || (mb_strpos($message_for_log, 'رجعت') !== false)
        || (mb_strpos($message_for_log, 'استرجعنا') !== false) || (mb_strpos($message_for_log, 'راجعين') !== false);

    if ($has_goods && $has_return) {
        // إرجاع بضاعة دايماً بينقص الدين (زي دفعة) - نفرضها credit بغض النظر شو خمّن الموديل
        $item['data']['type'] = 'credit';
        if (empty($item['data']['no_bank_reason'])) {
            $item['data']['no_bank_reason'] = 'مرتجع بضاعة - بدون حركة نقدية';
        }
    }
    return $item;
}
// =====================================================================
// 🔒 قواعد "مصدر الفلوس" - تُفرض بالكود نفسه وليس فقط بالـ prompt:
//   1) أي حركة فيها فلوس فعلية (دفع/تسديد/راتب/إيراد خارجي/مصروف/تحصيل...) لازم تعرف كاش أو أي بنك.
//   2) المصدر لازم يكون مذكور فعلاً بكلام المستخدم (الرسالة الحالية أو توضيح سابق) - ممنوع تخمين الموديل.
//   3) بنك الأردني بالذات: لازم يتحدد "مؤسسة" أو "شخصي" صراحةً، ممنوع الافتراض.
// =====================================================================
function ai_norm_ar($s) {
    $s = mb_strtolower((string)$s);
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
    return str_replace(['أ', 'إ', 'آ', 'ة', 'ى', 'ؤ', 'ئ'], ['ا', 'ا', 'ا', 'ه', 'ي', 'و', 'ي'], $s);
}
function action_moves_money($action, $data) {
    $t = $data['type'] ?? '';
    switch ($action) {
        case 'customer_transaction':
        case 'supplier_transaction':
            return $t === 'credit';
        case 'personal_transaction':
            return in_array($t, ['lend', 'repay'], true);
        case 'profit_distribution':
        case 'shareholder_contribution':
        case 'external_revenue':
        case 'expense':
        case 'workshop_transaction':
            return true;
        case 'employee_transaction':
            return in_array($t, ['advance', 'payment'], true);
        case 'car_employee_transaction':
            return !in_array($t, ['deduction', 'secretary_salary'], true);
        default:
            return false;
    }
}
function non_cash_exempt($action, $data, $message) {
    $m = ai_norm_ar($message);
    $t = $data['type'] ?? '';
    if (in_array($action, ['customer_transaction', 'supplier_transaction'], true) && $t === 'credit') {
        $has_goods = mb_strpos($m, 'بضاع') !== false;
        foreach (['مرتجع', 'رجعنا', 'رجعت', 'استرجعنا', 'راجعين'] as $kw) {
            if (mb_strpos($m, $kw) !== false && ($has_goods || $kw === 'مرتجع')) return true;
        }
        return false;
    }
    if ($action === 'personal_transaction' && $t === 'repay') {
        foreach (['مسامح', 'اسقاط', 'تنازل', 'شطب'] as $kw) {
            if (mb_strpos($m, $kw) !== false) return true;
        }
        return false;
    }
    if ($action === 'shareholder_contribution') {
        // احتساب ربح داخلي بدون فلوس فعلية
        foreach (['ربح', 'ارباح', 'احسب', 'احتسب', 'زيد رصيد'] as $kw) {
            if (mb_strpos($m, $kw) !== false) return true;
        }
    }
    return false;
}
function bank_keywords_map() {
    return [
        'cash'       => ['كاش', 'نقد', 'صندوق', 'cash'],
        'etihad'     => ['الاتحاد', 'اتحاد', 'كليك', 'click', 'etihad'],
        'islami'     => ['الاسلامي', 'اسلامي', 'islami'],
        'dinarak'    => ['دينارك', 'dinarak'],
        'investment' => ['استثماري', 'investment'],
        'jordan'     => ['الاردن', 'jordan'],
    ];
}
function bank_supported_by_context($bank, $ctx_norm) {
    $key = (strpos($bank, 'jordan') === 0) ? 'jordan' : $bank;
    $map = bank_keywords_map();
    if (!isset($map[$key])) return false;
    foreach ($map[$key] as $kw) {
        if (mb_strpos($ctx_norm, $kw) !== false) return true;
    }
    return false;
}
function jordan_subaccount_explicit($ctx_norm, $msg_norm, $has_clarification) {
    $kw = '(?:موسس|شخصي|business|personal)';
    if (preg_match('/jordan_(?:business|personal)/u', $ctx_norm)) return true;
    if (preg_match('/(?:الاردن|الاردني|jordan)\s*(?:ال)?\s*' . $kw . '/u', $ctx_norm)) return true;
    // جواب قصير على سؤال "مؤسسة ولا شخصي؟" - بدون أرقام ولا كلمات عملية (سلفة/دين/راتب) عشان "سلفة شخصية" ما تنحسب جواب
    if ($has_clarification && mb_strlen(trim($msg_norm)) <= 25 && preg_match('/' . $kw . '/u', $msg_norm)
        && !preg_match('/\d|سلف|دين|راتب/u', $msg_norm)) return true;
    return false;
}
function get_clarification_context($db, $user_id, $message) {
    // نضم الرسائل السابقة بس إذا كانت أسئلة توضيح لسا ما انحلت (status = failed) - عشان ما نتأثر بحركات قديمة
    $ctx = $message;
    $has_clar = false;
    try {
        $stmt = $db->prepare('SELECT user_message, status FROM chat_log WHERE user_id = ? ORDER BY id DESC LIMIT 4');
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll() as $row) {
            if (($row['status'] ?? '') !== 'failed') break;
            $ctx .= ' ' . $row['user_message'];
            $has_clar = true;
        }
    } catch (\Throwable $e) {
        error_log('[get_clarification_context] ' . $e->getMessage());
    }
    return ['text' => ai_norm_ar($ctx), 'has_clarification' => $has_clar];
}
function ask_money_source_item($question) {
    return ['action' => 'unclear', 'data' => ['reason' => $question], 'reply' => $question];
}
function enforce_money_rules($item, $message, $user_id, $db) {
    $action = $item['action'] ?? '';
    if (!isset($item['data']) || !is_array($item['data'])) $item['data'] = [];
    // الموديل ممنوع يحط علامة الإعفاء بنفسه - بس هالدالة بتحطها
    unset($item['data']['_non_cash_ok']);

    // "اعطيت فلان راتبه" = دفع فعلي (payment) مش استحقاق (salary) - الاستحقاق ما فيه فلوس بس الدفع فيه
    if ($action === 'employee_transaction' && ($item['data']['type'] ?? '') === 'salary') {
        $m0 = ai_norm_ar($message);
        if (mb_strpos($m0, 'راتب') !== false) {
            foreach (['اعطيت', 'دفعت', 'سلمت', 'استلم', 'اخذ', 'قبض', 'صرفت'] as $v) {
                if (mb_strpos($m0, $v) !== false) { $item['data']['type'] = 'payment'; break; }
            }
        }
    }

    $ctx = get_clarification_context($db, $user_id, $message);
    $msg_norm = ai_norm_ar($message);
    $q_source = 'هاي الحركة كاش أو بنك؟ إذا بنك، حدد أي بنك (الأردني / الاتحاد / الإسلامي / دينارك / الاستثماري).';
    $q_jordan = 'بنك الأردني مؤسسة ولا شخصي؟';

    // حركات البنك المباشرة: بنك الأردني لازم مؤسسة/شخصي صراحةً
    if ($action === 'bank_transaction') {
        $b = normalize_bank_key($item['data']['bank'] ?? '');
        if ($b !== null && strpos($b, 'jordan') === 0 && !jordan_subaccount_explicit($ctx['text'], $msg_norm, $ctx['has_clarification'])) {
            return ask_money_source_item($q_jordan);
        }
        return $item;
    }

    if (!action_moves_money($action, $item['data'])) return $item;

    $bank = normalize_bank_key($item['data']['paid_from_bank'] ?? null);
    $bank = $bank === null ? '' : trim($bank);
    $valid = ['jordan', 'jordan_personal', 'jordan_business', 'etihad', 'islami', 'dinarak', 'investment', 'cash'];

    if ($bank === '' || !in_array($bank, $valid, true)) {
        if (non_cash_exempt($action, $item['data'], $message)) {
            $item['data']['_non_cash_ok'] = true;
            return $item;
        }
        return ask_money_source_item($q_source);
    }

    // المصدر لازم يكون مذكور فعلاً بكلام المستخدم، مش تخمين من الموديل
    if (!bank_supported_by_context($bank, $ctx['text'])) {
        return ask_money_source_item($q_source);
    }

    if (strpos($bank, 'jordan') === 0) {
        if ($bank === 'jordan' || !jordan_subaccount_explicit($ctx['text'], $msg_norm, $ctx['has_clarification'])) {
            return ask_money_source_item($q_jordan);
        }
    }
    return $item;
}
function missing_required_bank($action, $data) {
    if (!action_moves_money($action, $data)) return false;
    if (!empty($data['_non_cash_ok'])) return false;
    $bank = trim((string)($data['paid_from_bank'] ?? ''));
    // "jordan" لحاله ممنوع - لازم jordan_personal أو jordan_business
    return $bank === '' || $bank === 'jordan';
}
function fix_entity_table_by_history($item, $message_for_log) {
    $map = [
        'customer_transaction' => ['field' => 'customer_name', 'table' => 'customers', 'label' => 'عميل'],
        'supplier_transaction' => ['field' => 'supplier_name', 'table' => 'suppliers', 'label' => 'مورد'],
        'personal_transaction' => ['field' => 'person_name', 'table' => 'personal_accounts', 'label' => 'حساب شخصي'],
        'employee_transaction' => ['field' => 'employee_name', 'table' => 'employees', 'label' => 'موظف'],
        'car_employee_transaction' => ['field' => 'car_employee_name', 'table' => 'car_employees', 'label' => 'سائق'],
    ];
    // الجداول اللي منقدر نتحقق منها بقاعدة البيانات ونصحح عليها تلقائياً (عميل/مورد/شخصي فقط -
    // موظف/سائق مستبعدين من فحص "أكتر من جدول" لأنه لهم منطق عمل مختلف تماماً عن سلفة شخصية عادية،
    // بس هني برضه أهداف صحيحة لو المستخدم صرّح بالكلمة أو الاسم موجود بجدولهم بس).
    $db_checkable = ['customer_transaction', 'supplier_transaction', 'personal_transaction', 'employee_transaction', 'car_employee_transaction'];

    $action = $item['action'] ?? '';
    if (!isset($map[$action])) return $item;

    // نلاقي اسم الجهة أياً كان الحقل المستخدم حالياً بالبيانات
    $name = '';
    foreach ($map as $info) {
        if (!empty($item['data'][$info['field']])) { $name = trim($item['data'][$info['field']]); break; }
    }
    if ($name === '') return $item;

    // كلمة صريحة بالرسالة أقوى من أي شي - بتفرض التحويل حتى لو الموديل أصلاً حطها موظف/سائق
    $forced_action = null;
    if ((mb_strpos($message_for_log, 'عميل') !== false) || (mb_strpos($message_for_log, 'زبون') !== false) || (mb_strpos($message_for_log, 'الزباين') !== false)) {
        $forced_action = 'customer_transaction';
    } elseif (mb_strpos($message_for_log, 'مورد') !== false) {
        $forced_action = 'supplier_transaction';
    } elseif (mb_strpos($message_for_log, 'شخص') !== false) {
        $forced_action = 'personal_transaction';
    } elseif (mb_strpos($message_for_log, 'موظف') !== false) {
        $forced_action = 'employee_transaction';
    } elseif ((mb_strpos($message_for_log, 'سائق') !== false) || (mb_strpos($message_for_log, 'شوفير') !== false)) {
        $forced_action = 'car_employee_transaction';
    }

    if ($forced_action !== null) {
        return switch_entity_action($item, $action, $forced_action, $name, $map);
    }

    // بدون كلمة صريحة: نتحقق من قاعدة البيانات بس للجداول الثلاثة (عميل/مورد/شخصي) -
    // ما منلمس موظف/سائق هون لأنه لو الموديل أصلاً حطها موظف وهو فعلاً موظف عندك، ما في داعي نغيّرها.
    if (!in_array($action, $db_checkable, true)) return $item;

    $db = get_db();
    $normalized_name = normalize_name_for_match($name);
    $found_in = [];
    foreach ($map as $act => $info) {
        if (!in_array($act, $db_checkable, true)) continue;
        $stmt = $db->prepare("SELECT id FROM {$info['table']} WHERE REPLACE(name, 'ة', 'ه') = ? LIMIT 1");
        $stmt->execute([$normalized_name]);
        if ($stmt->fetch()) $found_in[] = $act;
    }

    // نفس الاسم موجود كمان بجدول المساهمين؟ (شوفولاند منفصل عن $map لأنه أكشناته shareholder_contribution/profit_distribution
    // مختلفة بالبنية عن باقي الجداول، فما بنقدر نبدّل action تلقائياً - بس لازم ننبّه لو في تعارض)
    $stmt_sh = $db->prepare("SELECT id FROM shareholders WHERE REPLACE(name, 'ة', 'ه') = ? LIMIT 1");
    $stmt_sh->execute([$normalized_name]);
    $is_also_shareholder = (bool)$stmt_sh->fetch();
    if ($is_also_shareholder && !in_array($action, ['shareholder_contribution', 'profit_distribution'], true)) {
        $found_in[] = '__shareholder__';
    }

    // نفس الاسم موجود بأكتر من قسم بنفس الوقت -> ممنوع نخمّن، نوقف ونسأل بالتحديد
    if (count($found_in) > 1) {
        $labels = array_map(fn($a) => $a === '__shareholder__' ? 'مساهم' : $map[$a]['label'], $found_in);
        $item['action'] = 'unclear';
        $item['data'] = ['reason' => "\"$name\" عندك مسجل كـ" . implode(' و', $labels) . " بنفس الوقت. هاي الحركة على حساب مين بالضبط؟ اكتب مثلاً \"العميل $name\" أو \"المورد $name\" أو \"الموظف $name\" أو \"السائق $name\" أو \"المساهم $name\"."];
        return $item;
    }

    if (count($found_in) === 1 && $found_in[0] !== $action) {
        return switch_entity_action($item, $action, $found_in[0], $name, $map);
    }
    return $item;
}
function entity_type_direction($action, $type) {
    $out_types = ['debit', 'lend', 'advance', 'ads', 'salary', 'expense'];
    return in_array($type, $out_types, true) ? 'out' : 'in';
}
function entity_type_from_direction($action, $direction) {
    switch ($action) {
        case 'customer_transaction':
        case 'supplier_transaction':
            return $direction === 'out' ? 'debit' : 'credit';
        case 'personal_transaction':
            return $direction === 'out' ? 'lend' : 'repay';
        case 'employee_transaction':
            return $direction === 'out' ? 'advance' : 'deduction';
        case 'car_employee_transaction':
            return $direction === 'out' ? 'advance' : 'revenue';
        default:
            return $direction === 'out' ? 'debit' : 'credit';
    }
}
function switch_entity_action($item, $old_action, $new_action, $name, $map) {
    if ($old_action === $new_action) return $item;
    foreach ($map as $info) { unset($item['data'][$info['field']]); }
    $item['data'][$map[$new_action]['field']] = $name;

    if (isset($item['data']['type'])) {
        $direction = entity_type_direction($old_action, $item['data']['type']); // 'out' فلوس طلعت منا | 'in' فلوس/تسديد رجع لنا
        $item['data']['type'] = entity_type_from_direction($new_action, $direction);
    }
    $item['action'] = $new_action;
    return $item;
}
function handle_parsed_ai_action($parsed, $user, $db, $message_for_log) {
    // لو الموديل رجع أكتر من عملية بنفس الرسالة (مصفوفة "actions")، نحوّلها لمسار خاص منفصل
    if (isset($parsed['actions']) && is_array($parsed['actions'])) {
        return handle_multi_actions($parsed, $user, $db, $message_for_log);
    }

    $parsed = fix_customer_supplier_confusion($parsed, $message_for_log);
    $parsed = fix_entity_table_by_history($parsed, $message_for_log);
    $parsed = auto_fill_goods_return_reason($parsed, $message_for_log);
    $enforced = enforce_money_rules(['action' => $parsed['action'] ?? '', 'data' => $parsed['data'] ?? []], $message_for_log, $user['id'], $db);
    $parsed['action'] = $enforced['action'];
    $parsed['data'] = $enforced['data'];
    if (isset($enforced['reply'])) { $parsed['reply'] = $enforced['reply']; }
    if (missing_required_bank($parsed['action'] ?? '', $parsed['data'] ?? [])) {
        $parsed['action'] = 'unclear';
        $parsed['data'] = ['reason' => 'هاي الحركة كاش أو بنك؟ حدد البنك أو اكتب "كاش".'];
        $parsed['reply'] = $parsed['data']['reason'];
    }

    $action = $parsed['action'] ?? 'unclear';
    $no_confirmation_needed = ['query_overview', 'query_upcoming_checks', 'query_entity', 'query_attachment', 'unclear', 'undo_last'];

    // حماية إضافية: ما نعتمد على الذكاء الاصطناعي وحده لتحديد "focus" - نفحص كلمات
    // مفتاحية برسالة المستخدم نفسها كطبقة أكيدة (deterministic) بغض النظر شو رجع الموديل
    if ($action === 'query_overview' && empty($parsed['data']['focus'])) {
        $keyword_map = [
            'bank_jordan'     => ['الأردني', 'بنك الاردني', 'البنك الاردني'],
            'bank_etihad'     => ['الاتحاد', 'اتحاد'],
            'bank_islami'     => ['الإسلامي', 'الاسلامي'],
            'bank_dinarak'    => ['دينارك'],
            'bank_investment' => ['الاستثماري', 'استثماري'],
            'cash'            => ['الكاش', 'كاش', 'الصندوق'],
            'customers'       => ['العملاء', 'الزباين'],
            'suppliers'       => ['الموردين', 'الموردون'],
            'personal'        => ['الشخصي', 'حسابي الشخصي'],
            'employees'       => ['الموظفين', 'الموظفون'],
            'car_employees'   => ['السائقين', 'السواقين'],
        ];
        foreach ($keyword_map as $focus_value => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($message_for_log, $kw) !== false) {
                    $parsed['data']['focus'] = $focus_value;
                    break 2;
                }
            }
        }
    }

    // حماية إضافية ثانية: لو رد الموديل بجملة "وعد بدون تنفيذ" (سأتحقق/سأتأكد...) بدل ما ينفذ فعلياً
    // استعلام عن جهة معينة، نكتشف الجهة والاسم بأنفسنا من رسالة المستخدم ونجبر تنفيذ query_entity حقيقي
    // نطبقها بحالتين: (1) الموديل رد بوعد فاضي بدون تنفيذ، أو (2) الموديل اختار query_entity صح بس رجع اسم فاضي/ناقص
    $placeholder_signals = ['سأتحقق', 'سأتأكد', 'رح أتحقق', 'رح أشوف', 'لحظة', 'دقيقة وبفحص', 'خليني أشوف'];
    $reply_text = $parsed['reply'] ?? '';
    $looks_like_placeholder = false;
    foreach ($placeholder_signals as $sig) {
        if (mb_strpos($reply_text, $sig) !== false) { $looks_like_placeholder = true; break; }
    }
    $query_entity_missing_name = ($action === 'query_entity' && empty(trim($parsed['data']['name'] ?? '')));

    if (($looks_like_placeholder && !in_array($action, ['query_overview', 'query_upcoming_checks', 'query_entity', 'query_attachment'], true)) || $query_entity_missing_name) {
        $entity_keywords = [
            'car_employee' => ['سائق', 'سواق', 'شوفير'],
            'employee'     => ['موظف', 'راتب', 'رواتب'],
            'supplier'     => ['مورد', 'الموردين'],
            'customer'     => ['عميل', 'زبون', 'الزباين'],
            'personal'     => ['حساب شخصي', 'شخصي'],
            'shareholder'  => ['مساهم', 'شريك'],
        ];
        // لو أصلاً معروف نوع الجهة (query_entity رجع entity_type صح بس الاسم ناقص)، استخدمه بدل ما نعيد اكتشافه
        $detected_type = $parsed['data']['entity_type'] ?? null;
        if (!$detected_type) {
            foreach ($entity_keywords as $type => $kws) {
                foreach ($kws as $kw) {
                    if (mb_strpos($message_for_log, $kw) !== false) { $detected_type = $type; break 2; }
                }
            }
        }
        if ($detected_type && isset($entity_keywords[$detected_type])) {
            // نستخرج اسم الجهة: نشيل كلمات الاستفهام والكلمات المفتاحية ونشوف شو ضل
            $stopwords = array_merge(['كم', 'ضل', 'ضلت', 'من', 'رصيد', 'قديش', 'عليه', 'عليها', 'له', 'الحالي', 'حالياً'], $entity_keywords[$detected_type]);
            $clean_msg = str_replace(['؟', '?'], '', $message_for_log);
            $words = preg_split('/\s+/', trim($clean_msg));
            $name_words = array_filter($words, function($w) use ($stopwords) {
                foreach ($stopwords as $sw) {
                    if (mb_strpos($w, $sw) !== false || $w === $sw) return false;
                }
                return $w !== '';
            });
            $name = trim(implode(' ', $name_words));
            if ($name !== '') {
                $action = 'query_entity';
                $parsed['data'] = ['entity_type' => $detected_type, 'name' => $name];
                // نمسح نص "الوعد الفاضي" القديم حتى يظهر الرد الحقيقي (نتيجة التنفيذ) بدل الوعد
                unset($parsed['reply']);
            }
        }
    }

    // 🔒 التصحيح ما بينفذ مباشرة: بيتخزّن كعملية معلّقة وبينتظر "نعم" (شوف prepare_correction_confirmation)
    if ($action === 'correct_last') {
        return prepare_correction_confirmation($db, $user, $parsed['data'] ?? [], $message_for_log);
    }

    if (in_array($action, $no_confirmation_needed, true)) {
        if ($action === 'undo_last') {
            $stmt = $db->prepare("SELECT * FROM chat_log WHERE user_id = ? AND status = 'success' AND entity_table IS NOT NULL AND undone = 0 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user['id']]);
            $last = $stmt->fetch();
            $exec_result = $last ? undo_entity($last['entity_table'], $last['entity_id']) : ['success' => false, 'message' => 'ما في عملية سابقة للتراجع عنها'];
            if ($last && $exec_result['success']) {
                $db->prepare('UPDATE chat_log SET undone = 1 WHERE id = ?')->execute([$last['id']]);
            }
        } else {
            $exec_result = execute_ai_action($parsed, $user['id']);
        }

        log_chat_action($db, $user['id'], $message_for_log, $parsed, $exec_result);

        return [
            'success' => $exec_result['success'],
            // لو فشلت، لازم السبب الحقيقي يظهر - مش رد الموديل يلي انكتب قبل ما ينفذ فعلياً ويعرف النتيجة
            'reply' => $exec_result['success'] ? ($parsed['reply'] ?? $exec_result['message']) : $exec_result['message'],
            'details' => $exec_result,
        ];
    }

    // باقي الأوامر (كلها بتحرك أموال/بيانات) لازم تأكيد المستخدم أولاً
    $summary = build_action_summary($action, $parsed['data'] ?? []);

    // فحص أمان: لو الرسالة فيها أفعال محاسبية أكتر من عملية وحدة، نحذّر يمكن فاتت عملية
    $mismatch_warning = '';
    if (count_likely_action_verbs($message_for_log) > 1) {
        $mismatch_warning = "\n\n⚠️ تنبيه: رسالتك فيها إشارات لأكتر من عملية بس فهمت وحدة بس - راجعها منيح، ولو ناقص شي اكتبه لحاله بعد التأكيد.";
    }

    $stmt = $db->prepare('INSERT INTO chat_pending_actions (user_id, action_json, summary) VALUES (?,?,?)');
    $stmt->execute([$user['id'], json_encode($parsed, JSON_UNESCAPED_UNICODE), $summary]);

    log_chat_action($db, $user['id'], $message_for_log, $parsed, ['success' => null, 'message' => 'بانتظار التأكيد']);

    return [
        'success' => true,
        'needs_confirmation' => true,
        'pending_id' => $db->lastInsertId(),
        'reply' => "$summary$mismatch_warning\n\nأكد التسجيل؟ اكتب \"نعم\" للتأكيد.",
    ];
}
