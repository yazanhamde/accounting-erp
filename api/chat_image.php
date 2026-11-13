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
require_once __DIR__ . '/../includes/image_validation.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'reply' => 'الرجاء تسجيل الدخول أولاً']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$image_data_url = trim($input['image'] ?? ''); // data URL كامل: data:image/jpeg;base64,....
$caption = trim($input['caption'] ?? '');

// 🔒 فحص حقيقي: لازم صورة JPEG/PNG/GIF/WEBP فعلية (مش بس نص بيبدأ بـ data:image/)
$checked_image = validate_image_data_url($image_data_url);
if (!$checked_image) {
    echo json_encode(['success' => false, 'reply' => 'ما وصلت صورة صالحة. المسموح: JPG أو PNG أو WEBP أو GIF (بحد أقصى 8 ميجا).']);
    exit;
}
$image_data_url = $checked_image['data_url']; // نرسل لـ OpenAI النسخة النظيفة بنوعها الحقيقي

// حماية بسيطة من ملفات كبيرة جداً (أكبر من ~8 ميجا بعد base64)
if (strlen($image_data_url) > 11 * 1024 * 1024) {
    echo json_encode(['success' => false, 'reply' => 'الصورة كبيرة كتير، جرب صورة أصغر حجم']);
    exit;
}

$db = get_db();

try {
    $ai_result = call_openai_vision($image_data_url, $caption);

    if (!$ai_result['success']) {
        echo json_encode(['success' => false, 'reply' => $ai_result['reply']]);
        exit;
    }

    $parsed = $ai_result['parsed'];
    $log_message = '[صورة]' . ($caption !== '' ? ' ' . $caption : '');

    $result = handle_parsed_ai_action($parsed, $user, $db, $log_message);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'reply' => 'حدث خطأ غير متوقع أثناء تحليل الصورة. جرب مرة ثانية أو بصورة أوضح.',
    ], JSON_UNESCAPED_UNICODE);
}
