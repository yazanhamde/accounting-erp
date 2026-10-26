<?php
/**
 * ==========================================================
 * عميل بسيط للتعامل مع WhatsApp Cloud API (رسمي من ميتا)
 * كل الدوال هون تتعامل مباشرة مع Graph API - ما في أي مكتبة خارجية
 * ==========================================================
 */

// الرابط الأساسي لـ Graph API - ممكن تحدّث رقم النسخة v21.0 مستقبلاً لو ميتا رفعت نسخة أحدث
define('WHATSAPP_GRAPH_VERSION', 'v21.0');

/**
 * إرسال رسالة نصية عادية لرقم واتساب معين
 * $to لازم يكون بصيغة دولية بدون + وبدون مسافات، مثلاً: 962791234567
 */
function wa_send_text($to, $text) {
    if (!defined('WHATSAPP_TOKEN') || !WHATSAPP_TOKEN || !defined('WHATSAPP_PHONE_NUMBER_ID') || !WHATSAPP_PHONE_NUMBER_ID) {
        error_log('WhatsApp: WHATSAPP_TOKEN أو WHATSAPP_PHONE_NUMBER_ID غير معرّفين بملف .env');
        return false;
    }

    // واتساب فيه حد أقصى لطول الرسالة (~4096 حرف)، نقص لو أطول احتياطاً
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3990) . "\n… (تم اختصار الرد)";
    }

    $url = 'https://graph.facebook.com/' . WHATSAPP_GRAPH_VERSION . '/' . WHATSAPP_PHONE_NUMBER_ID . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $text, 'preview_url' => false],
    ];

    return wa_graph_post($url, $payload);
}

/**
 * تعليم رسالة واردة كـ "مقروءة" (اختياري بس بيعطي شكل احترافي - تظهر علامة صح زرقاء)
 */
function wa_mark_as_read($message_id) {
    if (!defined('WHATSAPP_TOKEN') || !WHATSAPP_TOKEN || !defined('WHATSAPP_PHONE_NUMBER_ID') || !WHATSAPP_PHONE_NUMBER_ID) {
        return false;
    }
    $url = 'https://graph.facebook.com/' . WHATSAPP_GRAPH_VERSION . '/' . WHATSAPP_PHONE_NUMBER_ID . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'status' => 'read',
        'message_id' => $message_id,
    ];
    return wa_graph_post($url, $payload);
}

/**
 * دالة مساعدة عامة لأي POST على Graph API
 */
function wa_graph_post($url, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WHATSAPP_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $http_code >= 300) {
        error_log('WhatsApp API error (HTTP ' . $http_code . '): ' . $err . ' | response: ' . $response);
        return false;
    }
    return json_decode($response, true);
}

/**
 * تحميل صورة/ملف مرسل عبر واتساب (خطوتين حسب توثيق ميتا):
 * 1) نجيب رابط التحميل المؤقت من الـ media id
 * 2) نحمّل المحتوى الفعلي من هداك الرابط (لازم نفس الـ Authorization header)
 * بترجع: ['success' => true, 'data' => binary content, 'mime_type' => '...'] أو ['success' => false]
 */
function wa_download_media($media_id) {
    $meta_url = 'https://graph.facebook.com/' . WHATSAPP_GRAPH_VERSION . '/' . $media_id;

    $ch = curl_init($meta_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_TOKEN],
        CURLOPT_TIMEOUT => 20,
    ]);
    $meta_response = curl_exec($ch);
    $meta_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($meta_http_code >= 300) {
        error_log('WhatsApp media meta error: ' . $meta_response);
        return ['success' => false];
    }

    $meta = json_decode($meta_response, true);
    $download_url = $meta['url'] ?? null;
    $mime_type = $meta['mime_type'] ?? 'image/jpeg';

    if (!$download_url) {
        return ['success' => false];
    }

    $ch2 = curl_init($download_url);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_TOKEN],
        CURLOPT_TIMEOUT => 30,
    ]);
    $binary = curl_exec($ch2);
    $dl_http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($dl_http_code >= 300 || $binary === false) {
        return ['success' => false];
    }

    return ['success' => true, 'data' => $binary, 'mime_type' => $mime_type];
}

/**
 * تحويل صورة واتساب لـ data URL (نفس الشكل يلي بتوقعه call_openai_vision)
 */
function wa_media_to_data_url($media_id) {
    $media = wa_download_media($media_id);
    if (!$media['success']) return null;
    return 'data:' . $media['mime_type'] . ';base64,' . base64_encode($media['data']);
}

/**
 * التحقق من إمضاء الطلب الجاي من ميتا (X-Hub-Signature-256) حتى نتأكد إنه
 * الطلب فعلاً جاي من واتساب مش من حدا عم يحاول يبعت طلبات مزوّرة لل webhook
 */
function wa_verify_signature($raw_body, $signature_header) {
    if (!defined('WHATSAPP_APP_SECRET') || !WHATSAPP_APP_SECRET) {
        // لو ما حطينا App Secret بعد بملف .env، نتخطى الفحص (مش مستحسن بالإنتاج - حطها أول ما تقدر)
        return true;
    }
    if (!$signature_header || strpos($signature_header, 'sha256=') !== 0) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $raw_body, WHATSAPP_APP_SECRET);
    return hash_equals($expected, $signature_header);
}
