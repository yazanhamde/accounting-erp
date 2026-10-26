<?php
/**
 * عميل الاتصال بـ Stock Manager Advance (SMA) API - Read Only
 * حسب التوثيق الرسمي: Base URL .../api/v1/  |  Header: api-key
 */

function pos_api_base_url() {
    return rtrim(env('POS_API_BASE_URL', ''), '/');
}

function pos_api_key() {
    return env('POS_API_KEY', '');
}

/**
 * طلب GET لأي endpoint. $params: مصفوفة query string.
 * الحد الأقصى المسموح للـ limit حسب التوثيق هو 20.
 * ⚠️ ملاحظة مهمة: كنا جربنا نعيد المحاولة تلقائياً هون (3 مرات بفاصل متزايد)
 * لما يصطدم بـ rate limit، بس هاد كان بيبطّئ المزامنة كتير - لو الحصة (quota)
 * مستنفذة فعلياً لكل الساعة، إعادة المحاولة بعد 5/15/40 ثانية ما بتفيد (الحصة
 * لسا مستنفذة)، وبس بتضيع وقت. الحل الصح: نخليها تفشل بسرعة، والكود اللي
 * بينادي (foreach العملاء/الموردين) هو المسؤول يوقف بسرعة (circuit breaker)
 * بدل ما يعيد المحاولة بشكل عشوائي.
 */
function pos_api_get($path, $params = []) {
    $base = pos_api_base_url();
    $key  = pos_api_key();
    if (!$base || !$key) {
        throw new Exception('POS_API_BASE_URL أو POS_API_KEY مش معبّية بملف .env');
    }

    $params['format'] = 'json';
    $url = $base . '/' . ltrim($path, '/') . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'api-key: ' . $key, // اسم الهيدر الصحيح حسب توثيق SMA
        ],
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("فشل الاتصال بـ POS API ($path): $curl_err");
    }
    if ($http_code >= 400) {
        throw new Exception("POS API رجّع خطأ HTTP $http_code على $path — الرد: " . substr($response, 0, 500));
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("رد POS API مش JSON صالح على $path — الرد الخام: " . substr($response, 0, 300));
    }
    return $data;
}

/**
 * الردود بترجع بشكل: {"data":[...], "limit":10, "start":1, "total":N}
 * أو أحياناً array مباشر. هاي الدالة بترجع القائمة الفعلية بكل الحالات.
 */
function pos_api_extract_list($decoded) {
    if (!is_array($decoded)) return [];
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        return $decoded['data'];
    }
    if (array_keys($decoded) === range(0, count($decoded) - 1)) {
        return $decoded;
    }
    return [];
}
