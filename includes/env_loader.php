<?php
/**
 * قارئ خفيف لملف .env - يحول كل سطر KEY=VALUE لمتغير بيئة
 * ما بيحتاج composer ولا أي مكتبة خارجية، يشتغل على أي استضافة PHP عادية
 */
function load_env($path) {
    if (!file_exists($path)) {
        die('ملف .env غير موجود. انسخ .env.example وسمّيه .env وعبّي بياناتك فيه.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // تجاهل التعليقات والأسطر الفاضية
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // شيل علامات الاقتباس إذا موجودة "value" أو 'value'
        if (strlen($value) > 1 && (
            ($value[0] === '"' && $value[strlen($value)-1] === '"') ||
            ($value[0] === "'" && $value[strlen($value)-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if (!array_key_exists($key, $_ENV)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// دالة مساعدة لقراءة متغير مع قيمة افتراضية
function env($key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    return $value !== false && $value !== null ? $value : $default;
}
