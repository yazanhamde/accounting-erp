<?php
/**
 * فحص الصور المرفوعة (من الشات / واتساب / الحفظ بالمرفقات)
 * ----------------------------------------------------------
 * - النوع الحقيقي بيتحدد من محتوى الملف نفسه (getimagesizefromstring) مش من اللي كتبه المستخدم
 * - قائمة بيضاء: JPEG / PNG / GIF / WEBP فقط (ممنوع SVG و HTML و PHP وغيرها)
 * - الامتداد والـ mime بيجوا من النوع المكتشف، مش من نص الـ data URL
 * - حد للحجم (8MB) وللأبعاد، ورفض أي ملف فيه وسم PHP مخفي
 */
const IMG_MAX_BYTES = 8 * 1024 * 1024;
const IMG_MAX_SIDE = 12000;
const IMG_MAX_PIXELS = 80000000;

function image_allowed_types() {
    return [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_GIF  => ['gif',  'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];
}

/** @return array|null ['ext','mime','width','height','binary'] أو null لو الملف مرفوض */
function validate_image_binary($binary) {
    if (!is_string($binary) || $binary === '' || strlen($binary) > IMG_MAX_BYTES) return null;
    $info = @getimagesizefromstring($binary);
    if (!$info || empty($info[0]) || empty($info[1])) return null;
    $allowed = image_allowed_types();
    if (!isset($allowed[$info[2]])) return null;
    if ($info[0] > IMG_MAX_SIDE || $info[1] > IMG_MAX_SIDE || ($info[0] * $info[1]) > IMG_MAX_PIXELS) return null;
    // ملف "صورة + كود PHP" (polyglot): بنرفض أي وسم PHP جوا المحتوى
    if (stripos($binary, '<?php') !== false || strpos($binary, '<?=') !== false) return null;
    return ['ext' => $allowed[$info[2]][0], 'mime' => $allowed[$info[2]][1],
            'width' => $info[0], 'height' => $info[1], 'binary' => $binary];
}

/** يقبل data URL بالشكل data:image/xxx;base64,.... ويرجّع نفس بنية validate_image_binary + data_url نظيف */
function validate_image_data_url($data_url) {
    if (!is_string($data_url)) return null;
    // ما منستخدم regex على كامل النص (ممكن يكون ميغابايتات): منفصل الهيدر بـ strpos
    $pos = strpos($data_url, ';base64,');
    if ($pos === false || $pos > 40 || strlen($data_url) > (int) (IMG_MAX_BYTES * 1.4) + 100) return null;
    $header = substr($data_url, 0, $pos);
    if (!preg_match('#^data:image/(jpeg|jpg|png|gif|webp)$#i', $header)) return null;
    $b64 = preg_replace('/\s+/', '', substr($data_url, $pos + 8));
    $binary = base64_decode($b64, true); // strict: أي حرف غلط = رفض
    if ($binary === false) return null;
    $v = validate_image_binary($binary);
    if (!$v) return null;
    $v['data_url'] = 'data:' . $v['mime'] . ';base64,' . base64_encode($binary);
    return $v;
}
