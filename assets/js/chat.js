// =====================================================================
// chat.js — ودجة الشات بوت (نص + صورة + صوت)
// بيعتمد على: window.APP_BASE (من footer.php). ما بيعتمد على ui.js.
// الدوال بتنادى من includes/footer.php عبر onclick (toggleChat, sendChat, ...)
// =====================================================================

// يبني رابط مطلق من المسار الأساسي للتطبيق (عشان الصفحات بمجلدات مختلفة)
function appUrl(path) {
    const base = (window.APP_BASE || '/accounting-system').replace(/\/$/, '');
    return base + '/' + String(path).replace(/^\//, '');
}

function toggleChat() {
    document.getElementById('chatWidget').classList.toggle('chat-collapsed');
    const icon = document.getElementById('chatToggleIcon');
    icon.textContent = document.getElementById('chatWidget').classList.contains('chat-collapsed') ? '▔' : '▁';
}

// يبني نص عرض الأرصدة - كامل أو بس البند المطلوب (focus)
function formatOverview(ov, focus) {
    const labels = {
        customers: `👥 العملاء: ${ov.customers_total}`,
        suppliers: `🏭 الموردين: ${ov.suppliers_total}`,
        personal: `👤 شخصي: ${ov.personal_total}`,
        employees: `👷 الموظفين: ${ov.employees_total}`,
        car_employees: `🚗 السائقين: ${ov.car_employees_total}`,
        bank_jordan: `🏦 البنك الأردني: ${ov.bank_jordan}`,
        bank_etihad: `🏦 بنك الاتحاد: ${ov.bank_etihad}`,
        bank_islami: `🏦 البنك الإسلامي: ${ov.bank_islami}`,
        bank_dinarak: `🏦 محفظة دينارك: ${ov.bank_dinarak}`,
        bank_investment: `🏦 البنك الاستثماري: ${ov.bank_investment}`,
        cash: `💰 رصيد الكاش الفعلي: ${ov.cash_real_balance} (فرق العدّ: ${ov.cash_box_total})`,
    };
    if (focus && labels[focus]) {
        return labels[focus];
    }
    return Object.values(labels).join('\n');
}

// يعرض صورة محفوظة (شيك/فاتورة) جوا فقاعة رسالة البوت
function appendImageMessage(imagePath, caption) {
    const body = document.getElementById('chatBody');
    const wrapper = document.createElement('div');
    wrapper.className = 'chat-msg bot';
    wrapper.innerHTML = `
        <img src="${imagePath}" style="max-width:220px; border-radius:8px; display:block; cursor:pointer;" onclick="window.open('${imagePath}','_blank')">
        ${caption ? `<div style="font-size:12px; margin-top:4px; opacity:0.8;">${caption}</div>` : ''}
    `;
    body.appendChild(wrapper);
    body.scrollTop = body.scrollHeight;
}

function appendMessage(text, sender) {
    const body = document.getElementById('chatBody');
    const div = document.createElement('div');
    div.className = 'chat-msg ' + sender;
    // منسمح بأسطر متعددة (زي ملخص التأكيد) بدون ما نفتح ثغرة HTML
    div.textContent = text;
    div.innerHTML = div.innerHTML.replace(/\n/g, '<br>');
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
    return div;
}

// بيضيف زري "نعم" و "إلغاء" تحت رسالة البوت لما يطلب تأكيد عملية
function appendConfirmButtons(pendingId) {
    const body = document.getElementById('chatBody');
    const row = document.createElement('div');
    row.className = 'chat-confirm-row';
    row.innerHTML = `
        <button type="button" class="chat-confirm-yes">✅ تأكيد</button>
        <button type="button" class="chat-confirm-no">✖️ إلغاء</button>
    `;
    row.querySelector('.chat-confirm-yes').onclick = () => { row.remove(); sendChatMessage('نعم', pendingId); };
    row.querySelector('.chat-confirm-no').onclick = () => { row.remove(); appendMessage('تم الإلغاء، ما تسجلت العملية.', 'bot'); };
    body.appendChild(row);
    body.scrollTop = body.scrollHeight;
}

// أزرار الاقتراحات السريعة (أرصدة اليوم / شيكات الأسبوع / تراجع)
function sendQuickReply(text) {
    sendChatMessage(text);
}

async function sendChat() {
    clearTimeout(window._voiceAutoSendTimer);
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message) return;
    input.value = '';
    await sendChatMessage(message);
}

// النواة المشتركة لإرسال رسالة (من صندوق الكتابة أو من زر اقتراح سريع أو من زر تأكيد)
// pendingId اختياري: يترسل بس لما يكون تأكيد عملية محددة بالضبط (يمنع تعارض لو أكتر من عملية معلّقة)
async function sendChatMessage(message, pendingId) {
    appendMessage(message, 'user');
    appendMessage('...جاري المعالجة', 'bot');

    try {
        const payload = { message };
        if (pendingId) payload.pending_id = pendingId;
        const res = await fetch(appUrl('api/chat.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        // نشيل رسالة "جاري المعالجة"
        const body = document.getElementById('chatBody');
        body.removeChild(body.lastChild);

        appendMessage(data.reply || 'تم.', 'bot');

        // لو العملية بحاجة تأكيد من المستخدم، منظهر أزرار تأكيد/إلغاء واضحة (مع ربط رقم العملية بالضبط)
        if (data.needs_confirmation) {
            appendConfirmButtons(data.pending_id);
        }

        // إذا في overview، نعرضها - كاملة أو بس البند المطلوب (focus)
        if (data.details && data.details.overview) {
            appendMessage(formatOverview(data.details.overview, data.details.focus), 'bot');
        }

        // إذا في قائمة شيكات مستحقة
        if (data.details && data.details.checks) {
            const list = data.details.checks;
            if (list.length === 0) {
                appendMessage('ما في شيكات مستحقة بهالفترة 👍', 'bot');
            } else {
                let txt = 'الشيكات المستحقة:\n' + list.map(c =>
                    `• ${c.direction === 'incoming' ? 'مستلم من' : 'مدفوع لـ'} ${c.party_name} | ${c.amount} | استحقاق: ${c.due_date}`
                ).join('\n');
                appendMessage(txt, 'bot');
            }
        }

        // إذا استعلام عن جهة محددة (موظف/عميل/سائق/مورد...)
        if (data.details && data.details.entity) {
            const e = data.details.entity;
            let txt = `📋 ${e.name}`;
            if (e.balance !== null && e.balance !== undefined) txt += ` | الرصيد الحالي: ${e.balance}`;
            if (e.recent_transactions && e.recent_transactions.length > 0) {
                txt += '\nآخر الحركات:\n' + e.recent_transactions.map(t =>
                    `• ${t.tx_date} | ${t.type || t.direction || ''} | ${t.amount}`
                ).join('\n');
            }
            appendMessage(txt, 'bot');
        }

        // إذا استعلام عن صور/مرفقات محفوظة (شيكات، فواتير...)
        if (data.details && data.details.attachments) {
            const atts = data.details.attachments;
            if (atts.length === 0) {
                appendMessage('ما في صور محفوظة لهالجهة لهلق 🤷', 'bot');
            } else {
                atts.forEach(a => {
                    appendImageMessage(appUrl('api/attachment.php?f=' + encodeURIComponent(a.file_path)), a.caption || '');
                });
            }
        }

        // بعد أي تسجيل ناجح، منحدث الصفحة الحالية إذا فيها جدول بيانات
        if (data.success && typeof window.refreshPageData === 'function') {
            window.refreshPageData();
        }
    } catch (err) {
        const body = document.getElementById('chatBody');
        body.removeChild(body.lastChild);
        appendMessage('صار خطأ بالاتصال، حاول مرة ثانية', 'bot');
    }
}

// ---------- تحليل صورة (حوالة بنكية / فاتورة شراء بضاعة) ----------
function handleChatImageSelected(event) {
    const file = event.target.files && event.target.files[0];
    event.target.value = ''; // نفضي الحقل حتى يقدر يختار نفس الصورة مرة ثانية لاحقاً
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        appendMessage('الملف المختار مش صورة، جرب صورة (jpg/png).', 'bot');
        return;
    }
    if (file.size > 8 * 1024 * 1024) {
        appendMessage('الصورة كبيرة كتير (أكبر من 8 ميجا)، جرب صورة أصغر.', 'bot');
        return;
    }

    const reader = new FileReader();
    reader.onload = () => sendChatImage(reader.result);
    reader.onerror = () => appendMessage('تعذر قراءة الصورة، جرب مرة ثانية.', 'bot');
    reader.readAsDataURL(file);
}

async function sendChatImage(imageDataUrl) {
    appendMessage('📷 صورة مرفقة', 'user');
    appendMessage('...جاري تحليل الصورة', 'bot');

    try {
        const res = await fetch(appUrl('api/chat_image.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({ image: imageDataUrl, caption: '' })
        });
        const data = await res.json();

        const body = document.getElementById('chatBody');
        body.removeChild(body.lastChild); // نشيل "جاري تحليل الصورة"

        appendMessage(data.reply || 'تم.', 'bot');

        if (data.needs_confirmation) {
            appendConfirmButtons(data.pending_id);
        }

        if (data.details && data.details.overview) {
            appendMessage(formatOverview(data.details.overview, data.details.focus), 'bot');
        }

        if (data.success && typeof window.refreshPageData === 'function') {
            window.refreshPageData();
        }
    } catch (err) {
        const body = document.getElementById('chatBody');
        body.removeChild(body.lastChild);
        appendMessage('صار خطأ بالاتصال أثناء تحليل الصورة، حاول مرة ثانية', 'bot');
    }
}

// ---------- تسجيل صوتي (Web Speech API) ----------
let voiceRecognizer = null;
let isRecording = false;

function getSpeechRecognition() {
    return window.SpeechRecognition || window.webkitSpeechRecognition || null;
}

function toggleVoiceInput() {
    const SpeechRecognitionClass = getSpeechRecognition();
    const micBtn = document.getElementById('chatMicBtn');

    // التسجيل الصوتي بيتطلب اتصال آمن (HTTPS) أو localhost، وإلا المتصفح بيرفض بصمت
    if (!window.isSecureContext) {
        appendMessage('التسجيل الصوتي بيحتاج الموقع يفتح عبر HTTPS (رابط آمن). تأكد إن الرابط يبدأ بـ https:// وجرب مرة ثانية', 'bot');
        return;
    }

    if (!SpeechRecognitionClass) {
        appendMessage('المتصفح أو التطبيق المثبّت ما بيدعم التسجيل الصوتي. جرب تفتح الموقع من متصفح Chrome مباشرة (مش عن طريق تطبيق مثبّت) وجرب من جديد', 'bot');
        return;
    }

    // إذا شغال، نوقفه (toggle)
    if (isRecording) {
        voiceRecognizer.stop();
        return;
    }

    // نطلب إذن المايكروفون بشكل واضح أول، عشان نضمن ظهور نافذة الإذن ونعرف سبب الفشل بدقة
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ audio: true })
            .then((stream) => {
                stream.getTracks().forEach((t) => t.stop()); // ما بدنا نستخدمه مباشرة، بس نتأكد من الإذن
                startVoiceRecognition(SpeechRecognitionClass, micBtn);
            })
            .catch((err) => {
                appendMessage('ما قدرنا نوصل للمايكروفون: تأكد إنك عطيت إذن الميكروفون للموقع من إعدادات المتصفح/الجهاز', 'bot');
            });
    } else {
        // إذا المتصفح ما بيدعم getUserMedia، نجرب مباشرة على SpeechRecognition
        startVoiceRecognition(SpeechRecognitionClass, micBtn);
    }
}

// كم مرة نحاول تلقائياً لو ما انسمع صوت (بدون ما نزعج المستخدم برسالة كل مرة)
const MAX_NO_SPEECH_RETRIES = 1;
let noSpeechRetryCount = 0;

function startVoiceRecognition(SpeechRecognitionClass, micBtn) {
    clearTimeout(window._voiceAutoSendTimer);
    const input = document.getElementById('chatInput');

    voiceRecognizer = new SpeechRecognitionClass();
    voiceRecognizer.lang = 'ar-JO';
    // نخلي النتائج المؤقتة شغالة حتى المستخدم يشوف كلامه وهو لسا عم يحكي
    voiceRecognizer.interimResults = true;
    voiceRecognizer.continuous = true;
    voiceRecognizer.maxAlternatives = 1;

    // نص أصلي كان موجود بالحقل قبل ما نبلش تسجيل (لو المستخدم كان كاتب شي)
    const baseText = input.value ? input.value.trim() + ' ' : '';
    let baseTextAccumulated = '';
    let silenceTimer = null;

    const scheduleAutoSend = () => {
        // بعد ثانية ونص هدوء (يعني خلص يحكي)، منرسل تلقائياً
        clearTimeout(silenceTimer);
        silenceTimer = setTimeout(() => {
            if (isRecording) voiceRecognizer.stop();
        }, 1500);
    };

    voiceRecognizer.onstart = () => {
        isRecording = true;
        noSpeechRetryCount = 0;
        micBtn.classList.add('recording');
        micBtn.textContent = '⏹️';
        micBtn.title = 'إيقاف التسجيل';
        input.placeholder = '🎙️ عم بسمعلك... احكي هلق';
    };

    voiceRecognizer.onresult = (event) => {
        let finalText = '';
        let interimText = '';

        for (let i = event.resultIndex; i < event.results.length; i++) {
            const transcript = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
                finalText += transcript;
            } else {
                interimText += transcript;
            }
        }

        if (finalText) {
            baseTextAccumulated = (baseTextAccumulated || '') + finalText + ' ';
        }

        // منعرض بالحقل: النص النهائي المتراكم + النص المؤقت الحالي (رمادي بصرياً عبر placeholder-like لكن هون مباشرة بالقيمة)
        input.value = (baseText + (baseTextAccumulated || '') + interimText).trim();

        // كل ما يحكي كلمة جديدة، منصفّر مؤقت الصمت
        scheduleAutoSend();
    };

    voiceRecognizer.onerror = (event) => {
        if (event.error === 'not-allowed' || event.error === 'permission-denied') {
            appendMessage('لازم تسمح للموقع باستخدام المايكروفون من إعدادات المتصفح', 'bot');
        } else if (event.error === 'no-speech') {
            // ما سمع صوت أبداً - نحاول مرة وحدة كمان بصمت قبل ما نزعج المستخدم برسالة
            if (noSpeechRetryCount < MAX_NO_SPEECH_RETRIES) {
                noSpeechRetryCount++;
                return; // onend رح يعيد المحاولة
            }
            appendMessage('ما سمعت صوت، جرب تحكي أقرب من المايكروفون وبصوت أوضح', 'bot');
        } else if (event.error === 'aborted') {
            // المستخدم ضغط إيقاف بنفسه، ما في داعي لرسالة خطأ
        } else {
            appendMessage('صار خطأ بالتسجيل الصوتي (' + event.error + ')، جرب مرة ثانية', 'bot');
        }
    };

    voiceRecognizer.onend = () => {
        isRecording = false;
        micBtn.classList.remove('recording');
        micBtn.textContent = '🎤';
        micBtn.title = 'تسجيل صوتي';
        input.placeholder = 'اكتب هون...';
        clearTimeout(silenceTimer);

        // إعادة محاولة صامتة لما ما نسمع صوت بالمحاولة الأولى
        if (noSpeechRetryCount > 0 && noSpeechRetryCount <= MAX_NO_SPEECH_RETRIES && !input.value.trim()) {
            noSpeechRetryCount = 0;
            setTimeout(() => toggleVoiceInput(), 300);
            return;
        }

        // إذا في نص متجمع، منعرضه بالحقل ثانية ونص حتى المستخدم يتأكد منه ويقدر يلغي/يعدّل،
        // وبعدها منرسله تلقائياً (بدل الإرسال الفوري يلي ما بيعطي فرصة تصحيح خطأ بالفهم الصوتي)
        if (input.value.trim()) {
            input.focus();
            clearTimeout(window._voiceAutoSendTimer);
            window._voiceAutoSendTimer = setTimeout(() => {
                if (input.value.trim()) sendChat();
            }, 1500);
        }
    };

    voiceRecognizer.start();
}
