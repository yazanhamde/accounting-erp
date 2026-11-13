<?php
// openai.php — OpenAI API calls (chat, vision, transcribe)
function call_openai_chat($user_message, $conversation_history = []) {
    if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
        return [
            'success' => false,
            'reply' => 'ما تم ربط مفتاح OpenAI API بعد. أضف مفتاحك بملف config/config.php بالسطر OPENAI_API_KEY حتى يشتغل الشات بوت.',
        ];
    }

    // 💡 Prompt Caching: الـ system prompt ثابت بكل محادثة (~2000 token)
    // OpenAI بيعمل الكاش تلقائياً للـ prefix الثابت (cache_control هي صيغة Anthropic ومش مدعومة هون)
    // بيوفر ~50% تكلفة input tokens على كل رسالة ثانية فصاعداً
    $messages = array_merge(
        [['role' => 'system', 'content' => get_system_prompt()]],
        $conversation_history,
        [['role' => 'user', 'content' => $user_message]]
    );

    $payload = [
        'model' => OPENAI_MODEL,
        'messages' => $messages,
        'temperature' => 0.1,
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'reply' => 'صار خطأ بالاتصال مع OpenAI: ' . $curl_error];
    }

    $decoded = json_decode($response, true);
    if (!isset($decoded['choices'][0]['message']['content'])) {
        $err = $decoded['error']['message'] ?? 'رد غير متوقع من OpenAI';
        return ['success' => false, 'reply' => 'خطأ: ' . $err];
    }

    $content = $decoded['choices'][0]['message']['content'];
    $parsed = json_decode($content, true);

    if (!$parsed || (!isset($parsed['action']) && !isset($parsed['actions']))) {
        return ['success' => false, 'reply' => 'ما قدرت افهم الرد. جرب صيغة الرسالة بشكل تاني.'];
    }

    return ['success' => true, 'parsed' => $parsed];
}
function get_vision_extra_instructions() {
    return <<<PROMPT

مهم جداً - أنت الآن بتحلل صورة مرفقة (سكرين شوت حوالة بنكية، فاتورة/إيصال شراء بضاعة، أو صورة شيك ورقي)، وليس رسالة نصية عادية.

⚠️⚠️ قاعدة حاسمة تحكم كل شي تحت: أنت بتتصرف متل محاسب محترف بيراجع مستند مش واضح 100%، مش متل موظف بيخمّن ويكمل. أي حقل من الأربعة الحساسة التالية غير واضح بثقة كاملة من الصورة نفسها = **توقف واسأل**، ما تكمل بتخمين ولا بافتراضي:
  1) **البنك/الحساب** (paid_from_bank أو bank_name)
  2) **المبلغ** (amount)
  3) **اسم الطرف** (اسم العميل/المورد/الشخص المستفيد أو المُصدر)
  4) **رقم الشيك أو رقم/مرجع الحوالة** (check_number أو أي رقم مرجعي بالحوالة إن وجد)
ممنوع نهائياً استخدام بنك افتراضي ("jordan" أو غيره) لمجرد إنه مش واضح - هاي كانت قاعدة قديمة انلغت. بدل هيك دايماً استخدم action = "unclear" واسأل سؤال محدد ومباشر بصيغة تعطي خيارات واضحة، مش سؤال عام.

طريقة صياغة السؤال (مهم يكون بأسلوب محاسب محترف يراجع مستند، مو بس "مش واضح"):
- البنك: لو قدرت تقرأ جزء من الشعار/الاسم بس مش متأكد 100%، اسأل بالتحديد بصيغة اختيار: "الصورة مش واضحة تماماً لاسم البنك - هل هي [اسمك المرجّح] ولا بنك تاني (الأردني/الاتحاد/الإسلامي/دينارك/الاستثماري)؟". لو ما قدرت تقرأ شي إطلاقاً، اسأل: "ما قدرت أحدد اسم البنك من الصورة، من انو حساب/بنك كانت هاي الحركة بالضبط؟"
- المبلغ: لو رقم غير واضح تماماً أو فيه احتمال قراءة مزدوجة (مثلاً ممكن يكون 3000 أو 5000)، اسأل بالتحديد: "المبلغ مش واضح 100% بالصورة - هل هو [رقمك المرجّح] دينار ولا رقم تاني؟" ولا تسجل الحركة أبداً برقم أنت نفسك مش متأكد منه.
- اسم الطرف: لو الاسم مقصوص من الصورة أو غير مقروء بوضوح، اسأل: "ما قدرت أقرأ اسم [العميل/المورد/المستفيد] بوضوح بالصورة - مين بالضبط؟"
- رقم الشيك/رقم الحوالة: لو موجود بالصورة بس مش واضح (رقم مموّه أو مقصوص)، اسأل عنه صراحة بدل ما تسيبه فاضي أو تخترعه. لو مش موجود أصلاً بالصورة (بعض الحوالات ما فيها رقم مرجعي ظاهر)، خليه فاضي بدون ما تسأل - السؤال فقط لو الرقم موجود بس غير مقروء.

لو أكتر من حقل حساس مش واضح بنفس الصورة، اسألهم كلهم سوا برسالة وحدة مرتبة (مش عدة رسائل)، مثال: "الصورة مش واضحة كفاية، بس احتاج أتأكد من: 1) اسم البنك... 2) المبلغ... 3) اسم الزبون...".

الحقول التانية (التاريخ، اتجاه الحركة deposit/withdraw أو incoming/outgoing) تقدر تحدد بثقة عادية زي قبل - بس لو التاريخ نفسه غير واضح (خصوصاً السنة)، برضو اسأل بدل ما تخمن.

الآن التفاصيل حسب نوع المستند:

- إذا الصورة شيك ورقي (فيها شكل شيك تقليدي: اسم بنك بالأعلى، خانة "Pay Against This Cheque" أو "ادفعوا بموجب هذا الشيك"، خانة توقيع، رقم شيك، خانة مبلغ بالأرقام والحروف): استخدم action = "add_check" **حصراً** - ممنوع تسجلها كـ bank_transaction حتى لو فيها اسم بنك واضح بأعلى الصورة، لأنه شكل الشيك نفسه هو المحدد، مش الشعار.
  - direction: "incoming" لو المحل/المستخدم هو المستفيد المكتوب بخانة "To"/"لأمر"، أو "outgoing" لو المحل هو مُصدر الشيك (اسمه بخانة التوقيع/المُصدر). لو مش واضح مين المستفيد ومين المُصدر، اسأل.
  - party_name = الاسم المكتوب بخانة "To"/"لأمر" (المستفيد) بغض النظر عن الاتجاه. لو غير مقروء بوضوح، اسأل عنه (قاعدة الحقول الحساسة فوق).
  - amount = المبلغ بالأرقام (خانة "دينار"/"J.D"), وتأكد يطابق المبلغ المكتوب بالحروف تحته - لو فيه تعارض بين الرقمي والكتابي، أو أي التباس بقراءة الرقم، استخدم "unclear" واسأل بالتحديد شو الرقمين المحتملين.
  - due_date = التاريخ المكتوب بخانة "Date"/"التاريخ" محوّل لصيغة YYYY-MM-DD. لو السنة مش واضحة بالصورة، استخدم "unclear" واسأل عن السنة بدل ما تخمنها.
  - bank_name = اسم البنك المكتوب بأعلى الشيك (jordan_personal/jordan_business/etihad/islami/dinarak/investment - أقرب تطابق). لو الشيك من "بنك الأردني" وما تحدد إذا هو حساب مؤسسة ولا شخصي (شيكات الأفراد نادراً بتوضح هيك من شكل الشيك نفسه)، اسأل "بنك الأردني مؤسسة ولا شخصي؟" - ما تفترض. لو اسم البنك عموماً غير واضح، اسأل (قاعدة الحقول الحساسة فوق) - ما تخمّن.
  - check_number = رقم الشيك لو ظاهر وواضح بالصورة. لو موجود بس مش مقروء بوضوح، اسأل عنه. لو مش ظاهر أصلاً بالصورة، خليه فاضي بدون سؤال.

- إذا الصورة سكرين شوت تحويل بنكي أو إشعار إيداع/سحب (من تطبيق بنك أو محفظة إلكترونية، مش شيك ورقي): استخدم action = "bank_transaction".
  - حدد اسم البنك من شعار/اسم التطبيق بالصورة إن أمكن، أو من اسم البنك يلي ذكره المستخدم بأي رسالة سابقة بنفس المحادثة (اعتمده حتى لو ما تطابق مع كود SWIFT/IBAN الظاهر بالصورة - هذا طبيعي وليس تناقضاً). إذا ما قدرت تحدده بثقة كاملة من أي مصدر، **لا تفترض أي بنك افتراضي** - استخدم "unclear" واسأل بالتحديد (قاعدة الحقول الحساسة فوق).
  - حدد tx_type: "deposit" لو الحوالة واردة (استلمنا فلوس)، "withdraw" لو صادرة لمستفيد (حولنا فلوس). لو الاتجاه نفسه ملتبس، اسأل.
  - amount = قيمة "المجموع الكلي" (أو Total) إن وجدت بالصورة، وليس قيمة "الرسوم". لو الرقم مش واضح بثقة كاملة، اسأل بالتحديد شو الاحتمالين. اقرأ التاريخ من الصورة بدقة وحوّله لصيغة YYYY-MM-DD - لو غير واضح اسأل.
  - لا تستخدم "unclear" لمجرد إنه كود SWIFT/IBAN ما بيطابق اسم البنك المذكور - هذا فرق طبيعي وليس سبب للسؤال. اسأل فقط لو اسم البنك نفسه (مش الكود) غير واضح.
  - اسم الطرف الآخر بالحوالة (المستفيد أو المُرسل) لو موجود بالصورة بس غير مقروء بوضوح، اسأل عنه (قاعدة الحقول الحساسة فوق).

- إذا الصورة فاتورة أو إيصال شراء بضاعة شخصية أو بضاعة للمحل من مورد: استخدم action = "supplier_transaction" (type: "debit") لو واضح اسم المورد/المحل، أو action = "expense" (category: "expense") لو مصروف عام ما إله مورد محدد. لو اسم المورد أو المبلغ الإجمالي مش واضح بثقة، اسأل بدل ما تخمّن.

- لو الصورة مش واضحة أو مش متعلقة بحوالة بنكية أو فاتورة شراء إطلاقاً، استخدم action = "unclear" واشرح بالـ reply انك ما قدرت تفهم الصورة.
- لا تخترع أرقام أو أسماء مش موجودة فعلياً بالصورة. أي حقل حساس (بنك/مبلغ/اسم/رقم مرجعي) غير مقروء بثقة كاملة = سؤال محدد بدل تخمين، دايماً وبدون استثناء.
PROMPT;
}
function call_openai_vision($image_data_url, $caption, $conversation_history = []) {
    if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
        return [
            'success' => false,
            'reply' => 'ما تم ربط مفتاح OpenAI API بعد. أضف مفتاحك بملف config/config.php بالسطر OPENAI_API_KEY حتى يشتغل تحليل الصور.',
        ];
    }

    $system_prompt = get_system_prompt() . get_vision_extra_instructions();

    $user_content = [
        ['type' => 'text', 'text' => $caption !== '' ? $caption : 'حلل هاي الصورة وسجل الحركة المناسبة (حوالة بنكية، فاتورة شراء بضاعة، أو شيك).'],
        ['type' => 'image_url', 'image_url' => ['url' => $image_data_url]],
    ];

    // 💡 Prompt Caching: نفس منطق call_openai_chat - الـ system prompt ثابت وكبير (~2500 token)
    // نرسله كـ array of content parts حتى OpenAI يحتفظ فيه بالكاش تلقائياً
    $messages = array_merge(
        [['role' => 'system', 'content' => $system_prompt]],
        $conversation_history,
        [['role' => 'user', 'content' => $user_content]]
    );

    $payload = [
        'model' => OPENAI_VISION_MODEL,
        'messages' => $messages,
        'temperature' => 0.1,
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 45,
    ]);
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'reply' => 'صار خطأ بالاتصال مع OpenAI: ' . $curl_error];
    }

    $decoded = json_decode($response, true);
    if (!isset($decoded['choices'][0]['message']['content'])) {
        $err = $decoded['error']['message'] ?? 'رد غير متوقع من OpenAI';
        return ['success' => false, 'reply' => 'خطأ: ' . $err];
    }

    $content = $decoded['choices'][0]['message']['content'];
    $parsed = json_decode($content, true);

    if (!$parsed || !isset($parsed['action'])) {
        return ['success' => false, 'reply' => 'ما قدرت افهم محتوى الصورة. جرب صورة أوضح.'];
    }

    return ['success' => true, 'parsed' => $parsed];
}
function call_openai_transcribe($audio_binary, $mime_type = 'audio/ogg') {
    if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
        return ['success' => false, 'reply' => 'ما تم ربط مفتاح OpenAI API بعد.'];
    }

    // Whisper بده اسم ملف بامتداد واضح حتى يتعرف على الصيغة - نكتب الملف مؤقتاً بامتداد مناسب
    $ext = match (true) {
        str_contains($mime_type, 'ogg') => 'ogg',
        str_contains($mime_type, 'mp4') => 'mp4',
        str_contains($mime_type, 'mpeg'), str_contains($mime_type, 'mp3') => 'mp3',
        str_contains($mime_type, 'wav') => 'wav',
        str_contains($mime_type, 'webm') => 'webm',
        default => 'ogg',
    };
    $tmp_path = sys_get_temp_dir() . '/wa_voice_' . uniqid() . '.' . $ext;
    file_put_contents($tmp_path, $audio_binary);

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($tmp_path, $mime_type, 'voice.' . $ext),
            'model' => defined('OPENAI_WHISPER_MODEL') ? OPENAI_WHISPER_MODEL : 'whisper-1',
            'language' => 'ar',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp_path);

    if ($curl_error) {
        return ['success' => false, 'reply' => 'صار خطأ بالاتصال مع Whisper: ' . $curl_error];
    }

    $decoded = json_decode($response, true);
    if ($http_code >= 400 || !isset($decoded['text'])) {
        $err = $decoded['error']['message'] ?? 'رد غير متوقع من Whisper';
        return ['success' => false, 'reply' => 'ما قدرت افهم الرسالة الصوتية: ' . $err];
    }

    $text = trim($decoded['text']);
    if ($text === '') {
        return ['success' => false, 'reply' => 'ما سمعت أي كلام واضح بالتسجيل، جرب سجل مرة ثانية بمكان أهدأ.'];
    }

    return ['success' => true, 'text' => $text];
}
