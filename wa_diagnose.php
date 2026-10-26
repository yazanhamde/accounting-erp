<?php
/**
 * صفحة تشخيص مؤقتة - افتحها من المتصفح مباشرة:
 * https://goldensketch.com/accounting-system/api/wa_diagnose.php
 * بعد ما تخلص التشخيص، احذفها من السيرفر لأسباب أمنية.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/whatsapp_client.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='direction:ltr;font-family:monospace;font-size:14px;line-height:1.8'>";

function step($label, $ok, $detail = '') {
    echo ($ok ? "✅ " : "❌ ") . $label . ($detail ? " -- $detail" : '') . "\n";
    return $ok;
}

echo "=== 1) فحص القيم بملف .env (مقنّعة جزئياً) ===\n";
$checks = [
    'WHATSAPP_TOKEN' => WHATSAPP_TOKEN,
    'WHATSAPP_PHONE_NUMBER_ID' => WHATSAPP_PHONE_NUMBER_ID,
    'WHATSAPP_OWNER_PHONE' => WHATSAPP_OWNER_PHONE,
    'WHATSAPP_APP_SECRET' => WHATSAPP_APP_SECRET,
];
$all_set = true;
foreach ($checks as $k => $v) {
    $ok = !empty($v);
    if (!$ok) $all_set = false;
    $masked = $v ? (strlen($v) > 8 ? substr($v, 0, 4) . '...' . substr($v, -4) . ' (len=' . strlen($v) . ')' : $v) : '(EMPTY)';
    step($k, $ok, $masked);
}

echo "\n=== 2) الاتصال بقاعدة البيانات ===\n";
try {
    $db = get_db();
    step('DB connection', true);
} catch (Throwable $e) {
    step('DB connection', false, $e->getMessage());
    echo "</pre>";
    exit;
}

echo "\n=== 3) البحث عن صاحب الحساب (role=owner) ===\n";
$stmt = $db->prepare("SELECT id, full_name, role FROM users WHERE role = 'owner' ORDER BY id ASC LIMIT 1");
$stmt->execute();
$owner = $stmt->fetch();
step('Owner user found', (bool)$owner, $owner ? "id={$owner['id']} name={$owner['full_name']}" : 'لا يوجد مستخدم بـ role=owner');

echo "\n=== 4) جدول منع التكرار whatsapp_processed_messages ===\n";
try {
    $db->query("SELECT 1 FROM whatsapp_processed_messages LIMIT 1");
    step('Table exists', true);
} catch (Throwable $e) {
    step('Table exists', false, $e->getMessage());
}

echo "\n=== 5) إرسال رسالة اختبارية فعلية عبر WhatsApp API لرقم WHATSAPP_OWNER_PHONE ===\n";
if (!$all_set) {
    step('Send test message', false, 'تخطينا الإرسال لأنو في قيم ناقصة بـ .env (شوف الخطوة 1)');
} else {
    $result = wa_send_text(WHATSAPP_OWNER_PHONE, '🔧 رسالة تشخيص تلقائية من نظام المحاسبة - إذا وصلتك هاي الرسالة، يبقى الإرسال شغال تمام.');
    if ($result === false) {
        step('Send test message', false, 'فشل الإرسال - شوف تفاصيل الخطأ بالأسفل (من PHP error_log كمان)');
    } else {
        step('Send test message', true, 'تم الإرسال! تأكد وصلتك الرسالة على واتساب');
        echo "\nAPI response:\n" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n=== 6) اختبار الوصول لملف whatsapp_webhook.php نفسه (GET) ===\n";
$test_url = 'https://goldensketch.com/accounting-system/api/whatsapp_webhook.php?hub_mode=ping';
$ch = curl_init($test_url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
step('Webhook file reachable via HTTPS (self-call)', $code === 403 || $code === 200, "HTTP $code" . ($err ? " / curl error: $err" : '') . " / body: " . substr($resp, 0, 200));
echo "(متوقع HTTP 403 هون لأنو ما بعتنا hub_verify_token صحيح - المهم إنو الطلب وصل ورجع رد، مش timeout أو SSL error)\n";

echo "</pre>";
