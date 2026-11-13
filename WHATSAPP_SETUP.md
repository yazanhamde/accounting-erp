# ربط بوت واتساب (WhatsApp Cloud API)

## الملفات الجديدة
- `includes/whatsapp_client.php` — إرسال/استقبال عبر Graph API
- `api/whatsapp_webhook.php` — نقطة الاستقبال (Webhook) — بتستخدم نفس منطق `api/chat.php` بدون أي تعديل عليه
- `database/migration_whatsapp_bot.sql` — جدول لمنع تكرار تنفيذ نفس الرسالة

## خطوات التفعيل

### 1) قاعدة البيانات
نفّذ الملف `database/migration_whatsapp_bot.sql` مرة وحدة على القاعدة (نفس طريقة باقي ملفات migration).

### 2) إعداد ميتا (Meta for Developers)
1. سوّي حساب Meta Business (إذا مش موجود) ووثّقه بالسجل التجاري.
2. من https://developers.facebook.com أنشئ App جديد نوع **Business**، وفعّل منتج **WhatsApp**.
3. اربط رقم واتساب (رقم جديد تماماً — لازم يكون رقم ما ولا استُخدم على واتساب عادي/بزنس قبل هيك — أو استخدم رقم Test الافتراضي للتجربة الأولى).
4. من صفحة WhatsApp > API Setup خد:
   - `Temporary/Permanent Access Token`
   - `Phone Number ID`
5. لتوليد **Permanent Token** (بدل المؤقت يلي بينتهي بعد 24 ساعة): سوّي System User بإعدادات الـ Business Manager واعطيه صلاحية على الـ App، وولّد توكن دائم منه.

### 3) تعبئة ملف `.env`
عبّي هاي القيم (أضفتها بآخر الملف مسبقاً):
```
WHATSAPP_TOKEN=              # التوكن الدائم
WHATSAPP_PHONE_NUMBER_ID=    # رقم الـ Phone Number ID
WHATSAPP_VERIFY_TOKEN=       # اختار أي نص سري من عندك، مثلاً: mySecretVerify123
WHATSAPP_APP_SECRET=         # من App Settings > Basic بلوحة ميتا (لتوثيق الطلبات الواردة)
WHATSAPP_OWNER_PHONE=        # رقم صاحب الحساب المصرّح له، دولي بدون +، مثال: 962791234567
```

### 4) ربط الـ Webhook بلوحة ميتا
بصفحة WhatsApp > Configuration بالـ App:
- **Callback URL**: `https://YOUR_DOMAIN/api/whatsapp_webhook.php`
- **Verify Token**: نفس القيمة يلي حطيتها بـ `WHATSAPP_VERIFY_TOKEN`
- اضغط Verify and Save
- فعّل الاشتراك (Subscribe) بحقل `messages` تحت Webhook fields

### 5) التجربة
ابعت رسالة واتساب عادية من رقمك (نفس رقم `WHATSAPP_OWNER_PHONE`) للرقم يلي ربطته — البوت لازم يرد بنفس أسلوب شات الداشبورد بالضبط (فهم الرسالة، طلب تأكيد، تنفيذ، تراجع، وتحليل صور فواتير).

## ملاحظات مهمة
- **رسمي 100%**: هاد الحل ما بيحتاج فتح واتساب ويب أو سكان QR ولا في خطر حظر الرقم — هو تكامل رسمي من ميتا.
- **التكلفة**: أول 1000 محادثة (conversation) بالشهر مجانية، وبعدها في تسعيرة بسيطة لكل محادثة حسب نوعها — تفاصيل محدثة دايماً على developers.facebook.com/docs/whatsapp/pricing.
- **الأمان**: حالياً البوت مربوط برقم واحد بس (`WHATSAPP_OWNER_PHONE`) — أي رقم تاني بيبعت رسالة رح ياخد رد رفض تلقائي. لو حبيتوا تفتحوها لأكتر من مستخدم مستقبلاً (كل موظف إلو رقمه)، لازم نضيف عمود `phone` لجدول `users` ونعدّل دالة تحديد المستخدم بـ `whatsapp_webhook.php`.
- **الصور**: البوت بيقدر يحلل صور فواتير/إيصالات مبعوتة كـ "صورة" (image) بواتساب، تماماً متل خاصية "تحليل صورة" بالداشبورد.
