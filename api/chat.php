<?php
// ✅ لو صار Fatal Error (مثلاً ملف ناقص أو خطأ PHP) نرجع JSON مفهوم بدل صفحة فاضية،
// حتى الواجهة ما تعرض "خطأ بالاتصال" وإحنا ما نعرف السبب. التفاصيل بتنكتب بـ error_log.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[chat fatal] ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'reply' => '⚠️ خطأ داخلي بالسيرفر: ' . basename($e['file']) . ':' . $e['line'] . ' — ' . mb_substr($e['message'], 0, 160),
        ], JSON_UNESCAPED_UNICODE);
    }
});
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_brain.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'reply' => 'الرجاء تسجيل الدخول أولاً']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$explicit_pending_id = isset($input['pending_id']) ? (int)$input['pending_id'] : null;

if ($message === '') {
    echo json_encode(['success' => false, 'reply' => 'اكتب رسالة أولاً']);
    exit;
}

$db = get_db();

// كل الأخطاء غير المتوقعة (SQL، PHP...) لازم ترجع JSON مفهوم، مش Fatal Error يكسر الواجهة
try {

    // ------------------------------------------------------------------
    // 1) هل الرسالة "تأكيد" لعملية معلّقة بانتظار موافقة المستخدم؟
    // ------------------------------------------------------------------
    $confirm_words = ['نعم', 'تأكيد', 'أكد', 'أكيد', 'ايوا', 'أيوا', 'اوك', 'ok', 'yes', 'صحيح', 'تمام'];
    $normalized = mb_strtolower(trim($message));
    if (in_array($normalized, $confirm_words, true)) {
        $pending = null;
        if ($explicit_pending_id) {
            $stmt = $db->prepare('SELECT * FROM chat_pending_actions WHERE id = ? AND user_id = ?');
            $stmt->execute([$explicit_pending_id, $user['id']]);
            $pending = $stmt->fetch();
        }
        // لو ما لقينا بالرقم المحدد (أو ما انبعت رقم أصلاً)، نرجع للطريقة الاحتياطية: آخر عملية معلّقة للمستخدم
        if (!$pending) {
            $stmt = $db->prepare('SELECT * FROM chat_pending_actions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$user['id']]);
            $pending = $stmt->fetch();
        }

        if (!$pending) {
            echo json_encode(['success' => false, 'reply' => 'ما في عملية معلّقة بانتظار تأكيدك حالياً.']);
            exit;
        }

        $parsed = json_decode($pending['action_json'], true);

        // ---- Batch (عمليات متعددة) ----
        // 🔒 Fix: wrapped in a single DB transaction — all or nothing (same as whatsapp_webhook.php)
        if (isset($parsed['actions']) && is_array($parsed['actions'])) {
            // التحقق من صحة كل العمليات قبل البدء (خارج transaction)
            foreach ($parsed['actions'] as $item) {
                if (missing_required_bank($item['action'] ?? '', $item['data'] ?? [])) {
                    echo json_encode([
                        'success' => false,
                        'reply' => 'من أي حساب أو كاش صارت هالحركة؟ حدد البنك أو اكتب "كاش" قبل ما نقدر نأكدها.',
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            // تنفيذ كل العمليات داخل transaction واحدة - إما كلها تنجح أو كلها تُلغى
            $db->beginTransaction();
            try {
                $results = [];
                foreach ($parsed['actions'] as $item) {
                    $r = execute_ai_action($item, $user['id']);
                    $results[] = $r;
                    if (empty($r['success'])) {
                        // عملية فشلت → نلغي كل شي ونُبقي الـ pending لإعادة المحاولة
                        $db->rollBack();
                        $failed_msg = $r['message'] ?? 'خطأ غير معروف';
                        log_chat_action($db, $user['id'], $message, $parsed, ['success' => false, 'message' => "فشل batch: $failed_msg"]);
                        echo json_encode([
                            'success' => false,
                            'reply' => "❌ فشلت إحدى العمليات، لم يتم تسجيل أي شيء:\n$failed_msg\n\nصحح المعلومات وأعد التأكيد.",
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log('[chat.php.multi_action] ' . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'reply' => 'خطأ في قاعدة البيانات، لم يتم تسجيل أي شيء. جرب مرة ثانية.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $exec_result = [
                'success' => true,
                'message' => implode(' | ', array_map(fn($r) => $r['message'] ?? '', $results)),
                'batch_results' => $results,
            ];
            log_chat_action($db, $user['id'], $message, $parsed, $exec_result);
            $db->prepare('DELETE FROM chat_pending_actions WHERE id = ?')->execute([$pending['id']]);

            $lines = [];
            foreach ($results as $i => $r) {
                $lines[] = ($i + 1) . ') ✅ ' . ($r['message'] ?? '');
            }
            echo json_encode([
                'success' => true,
                'reply' => 'تم تسجيل كل العمليات ✅' . "\n" . implode("\n", $lines),
                'details' => $exec_result,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- عملية وحدة ----
        // نفس فحص "مصدر الفلوس" — لازم يتكرر هون قبل التنفيذ الفعلي
        if (missing_required_bank($parsed['action'] ?? '', $parsed['data'] ?? [])) {
            $parsed['action'] = 'unclear';
            $parsed['data'] = ['reason' => 'من أي حساب أو كاش صارت هالحركة؟ حدد البنك أو اكتب "كاش".'];
        }

        $exec_result = execute_ai_action($parsed, $user['id']);

        // 🔒 Fix: لو فشل بسبب اسم غامض (أكثر من عميل)، نُبقي الـ pending ونطلب التوضيح
        // بدل ما نمسحه ونضيع السياق — المستخدم يرسل الاسم الصحيح والمحاولة الجاية تكمل
        if (!$exec_result['success']) {
            log_chat_action($db, $user['id'], $message, $parsed, $exec_result);
            echo json_encode([
                'success' => false,
                'reply' => 'تعذر التنفيذ: ' . $exec_result['message'],
                'details' => $exec_result,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            // ⚠️ الـ pending ما انمسح — المستخدم يقدر يعيد التأكيد بعد ما يوضح
        }

        log_chat_action($db, $user['id'], $message, $parsed, $exec_result);
        $db->prepare('DELETE FROM chat_pending_actions WHERE id = ?')->execute([$pending['id']]);

        echo json_encode([
            'success' => true,
            'reply' => 'تم ✅ ' . ($parsed['reply'] ?? $exec_result['message']),
            'details' => $exec_result,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------------------------------------------------
    // 2) هل الرسالة طلب "تراجع" عن آخر عملية؟
    // ------------------------------------------------------------------
    $undo_words = ['تراجع', 'الغي آخر عملية', 'الغاء آخر عملية', 'احذف آخر شي', 'رجع اخر عملية', 'تراجع عن اخر عملية'];
    $is_undo = false;
    foreach ($undo_words as $w) {
        if (mb_strpos($normalized, $w) !== false) { $is_undo = true; break; }
    }
    if ($is_undo) {
        $stmt = $db->prepare("SELECT * FROM chat_log WHERE user_id = ? AND status = 'success' AND entity_table IS NOT NULL AND undone = 0 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user['id']]);
        $last = $stmt->fetch();

        if (!$last) {
            echo json_encode(['success' => false, 'reply' => 'ما لقيت عملية سابقة قابلة للتراجع.']);
            exit;
        }

        $undo_result = undo_entity($last['entity_table'], $last['entity_id']);
        if ($undo_result['success']) {
            $db->prepare('UPDATE chat_log SET undone = 1 WHERE id = ?')->execute([$last['id']]);
        }

        echo json_encode([
            'success' => $undo_result['success'],
            'reply' => $undo_result['success'] ? 'تم التراجع عن آخر عملية ✅' : ('تعذر التراجع: ' . $undo_result['message']),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ------------------------------------------------------------------
    // 3) الوضع العادي: نبني ذاكرة المحادثة ونسأل الذكاء الاصطناعي
    // ------------------------------------------------------------------
    $history_limit = 6; // آخر 6 تبادلات (سؤال+رد) يعني 12 رسالة تقريباً
    $conversation_history = [];

    $stmt = $db->prepare('SELECT user_message, parsed_action, bot_reply FROM chat_log WHERE user_id = ? ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $history_limit, PDO::PARAM_INT);
    $stmt->execute();
    $recent_rows = array_reverse($stmt->fetchAll());

    foreach ($recent_rows as $row) {
        $conversation_history[] = ['role' => 'user', 'content' => $row['user_message']];
        if (!empty($row['parsed_action'])) {
            $conversation_history[] = ['role' => 'assistant', 'content' => $row['parsed_action']];
        } elseif (!empty($row['bot_reply'])) {
            $conversation_history[] = ['role' => 'assistant', 'content' => $row['bot_reply']];
        }
    }

    $ai_result = call_openai_chat($message, $conversation_history);

    if (!$ai_result['success']) {
        echo json_encode(['success' => false, 'reply' => $ai_result['reply']]);
        exit;
    }

    $parsed = $ai_result['parsed'];

    // ------------------------------------------------------------------
    // 4) نقرر: تنفيذ فوري (استعلام/غير واضح) أو تخزين كعملية معلّقة بانتظار تأكيد
    // ------------------------------------------------------------------
    $result = handle_parsed_ai_action($parsed, $user, $db, $message);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[chat.php] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'reply' => defined('DEBUG_MODE') && DEBUG_MODE
            ? '⚠️ خطأ: ' . $e->getMessage() . ' | ' . basename($e->getFile()) . ':' . $e->getLine()
            : 'حدث خطأ غير متوقع أثناء المعالجة. جرب توضيح رسالتك أو حاول مرة ثانية.',
    ], JSON_UNESCAPED_UNICODE);
}

// =====================================================================
// باقي الدوال المساعدة (log_chat_action, build_action_summary, apply_correction_to_last)
// انتقلت لملف includes/ai_brain.php حتى تنستخدم من api/chat.php و api/chat_image.php مع بعض
// =====================================================================
