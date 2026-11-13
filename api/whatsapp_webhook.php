<?php
/**
 * ==========================================================
 * api/whatsapp_webhook.php
 * ==========================================================
 * نقطة الوصول اللي ميتا (WhatsApp Cloud API) بترسلها كل رسالة واردة عليها.
 * رابطها اللي لازم تحطه بإعدادات الـ App على Meta for Developers:
 *   https://YOUR_DOMAIN/api/whatsapp_webhook.php
 *
 * بتشتغل بمسارين:
 *  GET  -> فحص التحقق الأولي (Verify Token) يلي ميتا بتطلبه مرة وحدة وقت الربط
 *  POST -> استقبال الرسائل الفعلية من العملاء عالواتساب
 *
 * المنطق الفعلي (فهم الرسالة، تنفيذ العمليات، التأكيد، التراجع...) هو نفسه
 * تماماً المستخدم بصفحة الشات بالداشبورد (api/chat.php + includes/ai_brain.php)
 * - ما انلمس ولا سطر فيه، بس عم "نغلفه" هون بمدخل/مخرج واتساب بدل الجلسة/الـ JSON العادي.
 * ==========================================================
 */

// الـ Webhook تابع لميتا (مش مستخدم مسجّل دخول) - مستثنى من حماية CSRF، وبيتحقق بالـ Verify Token
define('CSRF_SKIP', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai_brain.php';
require_once __DIR__ . '/../includes/whatsapp_client.php';

// ------------------------------------------------------------------
// 1) فحص التحقق (GET) - مرة وحدة بس وقت ما تربط الـ Webhook من لوحة ميتا
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === WHATSAPP_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ------------------------------------------------------------------
// 2) استقبال الرسائل الفعلية (POST)
// ------------------------------------------------------------------
$raw_body = file_get_contents('php://input');

// تحقق التوقيع (لو حاطط WHATSAPP_APP_SECRET بملف .env)
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!wa_verify_signature($raw_body, $signature)) {
    http_response_code(403);
    exit;
}

// دايماً نرد 200 بسرعة حتى ميتا ما تعتبر الطلب فاشل وتعيد إرساله - أي معالجة
// بعد هيك بتصير وبعدين، بس الرد نفسه (http status) لازم يطلع فوراً
http_response_code(200);
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    // بيخلي الاستجابة توصل لميتا فوراً بينما الكود يكمل شغلو بالخلفية (بيئات فيها PHP-FPM)
    fastcgi_finish_request();
}

$body = json_decode($raw_body, true);
$entry = $body['entry'][0] ?? null;
$change = $entry['changes'][0] ?? null;
$value = $change['value'] ?? null;

// لو الحدث "تحديث حالة رسالة" (delivered/read) مش رسالة واردة، تجاهله
$messages = $value['messages'] ?? null;
if (!$messages) {
    exit;
}

$db = get_db();

foreach ($messages as $msg) {
    process_incoming_whatsapp_message($msg, $db);
}
exit;

// =====================================================================
// معالجة رسالة واحدة واردة
// =====================================================================
function process_incoming_whatsapp_message($msg, $db) {
    $wa_message_id = $msg['id'] ?? null;
    $from = $msg['from'] ?? null; // رقم المرسل، صيغة دولية بدون +

    if (!$wa_message_id || !$from) return;

    // -------- منع تكرار التنفيذ لو ميتا أعادت إرسال نفس الرسالة (retry) --------
    try {
        $db->prepare('INSERT INTO whatsapp_processed_messages (wa_message_id) VALUES (?)')->execute([$wa_message_id]);
    } catch (Throwable $e) {
        // لو فشل الإدراج (duplicate key) يعني هاي الرسالة اتعالجت قبل هيك - تجاهلها
        return;
    }

    // -------- التأكد إنه المرسل هو صاحب الحساب المصرّح له فقط --------
    $allowed_phone = preg_replace('/\D/', '', WHATSAPP_OWNER_PHONE);
    if (preg_replace('/\D/', '', $from) !== $allowed_phone) {
        wa_send_text($from, 'عذراً، هذا الرقم غير مصرّح له باستخدام هذا البوت.');
        return;
    }

    // -------- نحاكي تسجيل الدخول (نفس شكل $_SESSION['user'] اللي بيعتمد عليه auth.php) --------
    $stmt = $db->prepare("SELECT * FROM users WHERE role = 'owner' ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $owner_row = $stmt->fetch();
    if (!$owner_row) {
        wa_send_text($from, 'تعذر تحديد صاحب الحساب بالنظام. تواصل مع الدعم الفني.');
        return;
    }
    $user = ['id' => $owner_row['id'], 'name' => $owner_row['full_name'], 'role' => $owner_row['role']];
    $_SESSION['user'] = $user;
    // @current_user_id لازم يتعبى يدوياً هون لأن get_db() استدعت قبل SESSION (static PDO)
    // بدونه الـ triggers (audit_log + صلاحية الحذف) ما تعرف مين المستخدم وبتسجل NULL
    $db->exec('SET @current_user_id = ' . (int) $user['id']);

    wa_mark_as_read($wa_message_id);

    $type = $msg['type'] ?? 'text';

    try {
        if ($type === 'text') {
            $message_text = trim($msg['text']['body'] ?? '');
            if ($message_text === '') return;
            $reply = handle_whatsapp_text_message($message_text, $user, $db);
        } elseif ($type === 'image') {
            $media_id = $msg['image']['id'] ?? null;
            $caption = trim($msg['image']['caption'] ?? '');
            if (!$media_id) {
                $reply = 'ما وصلت الصورة صح، جرب ترسلها مرة ثانية.';
            } else {
                $data_url = wa_media_to_data_url($media_id);
                if (!$data_url) {
                    $reply = 'تعذر تحميل الصورة من واتساب، جرب مرة ثانية.';
                } else {
                    $ai_result = call_openai_vision($data_url, $caption);
                    if (!$ai_result['success']) {
                        $reply = $ai_result['reply'];
                    } else {
                        $log_message = '[صورة]' . ($caption !== '' ? ' ' . $caption : '');
                        $result = handle_parsed_ai_action($ai_result['parsed'], $user, $db, $log_message);
                        $reply = $result['reply'] ?? 'تم.';
                    }
                }
            }
        } elseif ($type === 'audio') {
            $media_id = $msg['audio']['id'] ?? null;
            if (!$media_id) {
                $reply = 'ما وصلت الرسالة الصوتية صح، جرب ترسلها مرة ثانية.';
            } else {
                $media = wa_download_media($media_id);
                if (!$media['success']) {
                    $reply = 'تعذر تحميل الرسالة الصوتية من واتساب، جرب مرة ثانية.';
                } else {
                    $transcribe_result = call_openai_transcribe($media['data'], $media['mime_type']);
                    if (!$transcribe_result['success']) {
                        $reply = $transcribe_result['reply'];
                    } else {
                        $transcribed_text = $transcribe_result['text'];
                        $inner_reply = handle_whatsapp_text_message($transcribed_text, $user, $db);
                        $reply = "🎙️ سمعت: \"$transcribed_text\"\n\n" . $inner_reply;
                    }
                }
            }
        } else {
            // أنواع تانية (فيديو/ملف/موقع...) مش مدعومة حالياً
            $reply = 'حالياً بقدر أفهم بس رسائل نصية، صوتية، أو صور فواتير/إيصالات. جرب اكتب طلبك أو ابعت صورة/تسجيل صوتي.';
        }
    } catch (Throwable $e) {
        error_log('WhatsApp webhook error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $reply = '⚠️ خطأ تقني (تشخيص): ' . $e->getMessage() . ' | ' . basename($e->getFile()) . ':' . $e->getLine();
        } else {
            $reply = 'صار خطأ غير متوقع أثناء المعالجة. جرب مرة ثانية.';
        }
    }

    if (!empty($reply)) {
        wa_send_text($from, $reply);
    }
}

/**
 * نفس منطق api/chat.php بالضبط (تأكيد / تراجع / رسالة عادية) بس مصدر ومخرج الرسالة
 * هون واتساب بدل session+JSON. ما انلمس أي دالة من ai_brain.php.
 */
function handle_whatsapp_text_message($message, $user, $db) {
    $confirm_words = ['نعم', 'تأكيد', 'أكد', 'أكيد', 'ايوا', 'أيوا', 'اوك', 'ok', 'yes', 'صحيح', 'تمام'];
    $normalized = mb_strtolower(trim($message));

    // -------- 1) تأكيد عملية معلّقة --------
    if (in_array($normalized, $confirm_words, true)) {
        $stmt = $db->prepare('SELECT * FROM chat_pending_actions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user['id']]);
        $pending = $stmt->fetch();

        if (!$pending) {
            return 'ما في عملية معلّقة بانتظار تأكيدك حالياً.';
        }

        $parsed = json_decode($pending['action_json'], true);

        if (isset($parsed['actions']) && is_array($parsed['actions'])) {
            // التحقق من صحة كل العمليات قبل البدء (خارج transaction)
            foreach ($parsed['actions'] as $item) {
                if (missing_required_bank($item['action'] ?? '', $item['data'] ?? [])) {
                    return 'من أي حساب أو كاش صارت هالحركة؟ حدد البنك أو اكتب "كاش" قبل ما نقدر نأكدها.';
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
                        // عملية فشلت → نلغي كل شي
                        $db->rollBack();
                        $failed_msg = $r['message'] ?? 'خطأ غير معروف';
                        log_chat_action($db, $user['id'], $message, $parsed, ['success' => false, 'message' => "فشل batch: $failed_msg"]);
                        return "❌ فشلت إحدى العمليات، لم يتم تسجيل أي شيء:\n$failed_msg\n\nصحح المعلومات وأعد التأكيد.";
                    }
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log('[whatsapp_webhook.multi_action] ' . $e->getMessage());
                return 'خطأ في قاعدة البيانات، لم يتم تسجيل أي شيء. جرب مرة ثانية.';
            }

            $all_success = true;
            $exec_result = [
                'success' => $all_success,
                'message' => implode(' | ', array_map(fn($r) => $r['message'] ?? '', $results)),
                'batch_results' => $results,
            ];
            log_chat_action($db, $user['id'], $message, $parsed, $exec_result);
            $db->prepare('DELETE FROM chat_pending_actions WHERE id = ?')->execute([$pending['id']]);

            $lines = [];
            foreach ($results as $i => $r) {
                $lines[] = ($i + 1) . ") ✅ " . ($r['message'] ?? '');
            }
            return 'تم تسجيل كل العمليات ✅' . "\n" . implode("\n", $lines);
        }

        if (missing_required_bank($parsed['action'] ?? '', $parsed['data'] ?? [])) {
            $parsed['action'] = 'unclear';
            $parsed['data'] = ['reason' => 'من أي حساب أو كاش صارت هالحركة؟ حدد البنك أو اكتب "كاش".'];
        }

        $exec_result = execute_ai_action($parsed, $user['id']);
        log_chat_action($db, $user['id'], $message, $parsed, $exec_result);
        $db->prepare('DELETE FROM chat_pending_actions WHERE id = ?')->execute([$pending['id']]);

        return $exec_result['success']
            ? ('تم ✅ ' . ($parsed['reply'] ?? $exec_result['message']))
            : ('تعذر التنفيذ: ' . $exec_result['message']);
    }

    // -------- 2) تراجع عن آخر عملية --------
    $undo_words = ['تراجع', 'الغي آخر عملية', 'الغاء آخر عملية', 'احذف آخر شي', 'رجع اخر عملية', 'تراجع عن اخر عملية'];
    foreach ($undo_words as $w) {
        if (mb_strpos($normalized, $w) !== false) {
            $stmt = $db->prepare("SELECT * FROM chat_log WHERE user_id = ? AND status = 'success' AND entity_table IS NOT NULL AND undone = 0 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user['id']]);
            $last = $stmt->fetch();
            if (!$last) return 'ما لقيت عملية سابقة قابلة للتراجع.';

            $undo_result = undo_entity($last['entity_table'], $last['entity_id']);
            if ($undo_result['success']) {
                $db->prepare('UPDATE chat_log SET undone = 1 WHERE id = ?')->execute([$last['id']]);
            }
            return $undo_result['success'] ? 'تم التراجع عن آخر عملية ✅' : ('تعذر التراجع: ' . $undo_result['message']);
        }
    }

    // -------- 3) رسالة عادية: نبني ذاكرة المحادثة ونسأل الذكاء الاصطناعي --------
    $history_limit = 6;
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
        return $ai_result['reply'];
    }

    $result = handle_parsed_ai_action($ai_result['parsed'], $user, $db, $message);
    return $result['reply'] ?? 'تم.';
}
