    </div><!-- /.main-content -->
</div><!-- /.app-shell -->

<!-- ودجة الشات بوت - متاحة بكل صفحات الداشبورد -->
<div class="chat-widget chat-collapsed" id="chatWidget">
    <button type="button" class="chat-fab" id="chatFab" onclick="toggleChat()" title="اسأل النظام / سجّل قيد">🤖</button>
    <div class="chat-header" onclick="toggleChat()">
        <span>💬 اسأل النظام / سجّل قيد</span>
        <span id="chatToggleIcon">▔</span>
    </div>
    <div class="chat-body" id="chatBody">
        <div class="chat-msg bot">أهلاً! اكتبلي أي قيد بلغتك العادية، مثلاً: "أعطيت أحمد سلفة 100 دينار" أو "خالد سدد 50 دينار من الدين".</div>
    </div>
    <div class="chat-quick-replies" id="chatQuickReplies">
        <button type="button" onclick="sendQuickReply('أرصدة اليوم')">📊 أرصدة اليوم</button>
        <button type="button" onclick="sendQuickReply('شيكات مستحقة هذا الأسبوع')">🧾 شيكات الأسبوع</button>
        <button type="button" onclick="sendQuickReply('تراجع عن آخر عملية')">↩️ تراجع</button>
    </div>
    <div class="chat-input-row">
        <input type="text" id="chatInput" placeholder="اكتب هون..." onkeydown="if(event.key==='Enter') sendChat()">
        <input type="file" id="chatImageInput" accept="image/*" capture="environment" style="display:none;" onchange="handleChatImageSelected(event)">
        <button type="button" id="chatImageBtn" class="chat-mic-btn" onclick="document.getElementById('chatImageInput').click()" title="تحليل صورة (حوالة بنكية / فاتورة شراء)">📷</button>
        <button type="button" id="chatMicBtn" class="chat-mic-btn" onclick="toggleVoiceInput()" title="تسجيل صوتي">🎤</button>
        <button onclick="sendChat()">إرسال</button>
    </div>
</div>

<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
