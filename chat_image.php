<?php
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
$image_data_url = trim($input['image'] ?? ''); // data URL كامل: data:image/jpeg;base64,....
$caption = trim($input['caption'] ?? '');

if ($image_data_url === '' || strpos($image_data_url, 'data:image/') !== 0) {
    echo json_encode(['success' => false, 'reply' => 'ما وصلت صورة صالحة، جرب ترفع الصورة مرة ثانية']);
    exit;
}

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
