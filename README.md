# 📊 Accounting ERP + Arabic AI Assistant

**English** | [العربية](#-نظام-محاسبة-erp-مع-مساعد-ذكي-بالعربية)

A PHP/MySQL accounting system built for a real trading business, with a conversational AI assistant that understands Arabic (Jordanian dialect) and turns plain messages — text, voice or receipt photos — into accounting entries that the owner confirms before anything is saved.

> **Example:** the owner sends `"كليك دفعت للمورد أبو خالد 200"` on WhatsApp. The assistant identifies the bank, the supplier and the amount, asks for confirmation, and only then writes the entry.

---

## ✨ Features

### 💬 AI assistant
- Understands Arabic dialect and business slang, and converts messages into structured accounting actions through OpenAI.
- **Confirm-before-save:** nothing is written to the database without the owner's approval.
- Undo support and an AI conversation log (`pages/chat_log.php`).
- Asks clarifying questions instead of guessing when a message is ambiguous.
- Voice messages (speech-to-text) and receipt/invoice photo analysis (vision model).

### 📱 WhatsApp Cloud API
- Webhook endpoint: `api/whatsapp_webhook.php`
- Request signature verification (`WHATSAPP_APP_SECRET`) and owner-number allowlist.
- Supports text, voice and image messages.

### 🏦 Accounting modules (`pages/`)
Dashboard, customers, suppliers, banks, cash box, cheques, employees, drivers/fleet, expenses, external revenues, profits/shareholders, workshop, personal loans, and AI chat log.

### 📈 Reports (`reports/`)
Trial balance, income statement, balance sheet, tax report, chart of accounts dashboard, bank reconciliation, bus/fleet and workshop profit reports, with PDF export (TCPDF) and Excel export (built-in XLSX writer).

### 🔄 POS synchronization
- Syncs customers, suppliers and ledgers from an external POS API (`includes/pos_sync/`).
- Daily cron jobs in `cron/` and an admin sync log.

### 🔒 Security
- Session-based authentication with role-based access (`includes/auth.php`).
- CSRF protection (`includes/csrf.php`) and login throttling (`includes/login_throttle.php`).
- Secrets only in `.env`; sensitive folders protected with `.htaccess`.

---

## 🛠️ Tech stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.x |
| Database | MySQL |
| AI | OpenAI (chat, vision, speech-to-text) |
| Channel | WhatsApp Cloud API (Meta) |
| PDF | TCPDF (Arabic RTL) |
| Excel | Custom XLSX writer |
| Frontend | Vanilla JS, installable PWA |
| Jobs | PHP CLI cron scripts |

---

## 📁 Project structure

```
├── admin/         # Migration runner, sync log, balance-fix tools (admin only)
├── api/           # whatsapp_webhook.php, chat.php, chat_image.php, attachment.php
├── assets/        # CSS, JS, icons
├── config/        # config.php (reads .env)
├── cron/          # Daily customer/supplier sync, payment reminders
├── database/      # Incremental SQL migrations
├── includes/      # ai_brain/, actions/, pos_sync/, auth, db, helpers
├── pages/         # Application screens
├── reports/       # Financial reports and exports
├── uploads/       # User attachments (not tracked in Git)
├── vendor/        # TCPDF
├── login.php, logout.php, index.php
├── manifest.json, sw.js   # PWA
├── WHATSAPP_SETUP.md      # Meta app configuration guide
└── .env.example
```

---

## ⚙️ Setup

**1. Clone and configure**
```bash
git clone https://github.com/yazanhamde/accounting-erp.git
cd accounting-erp
cp .env.example .env
```

**2. Fill in `.env`** — database credentials, `OPENAI_API_KEY`, WhatsApp credentials, POS API settings and `CRON_SECRET`. Never commit this file.

**3. Install TCPDF**
Place the full [TCPDF](https://github.com/tecnickcom/TCPDF) library in `vendor/tcpdf/`.

**4. Database**
Run the SQL files in `database/` in order (or use `admin/migrations.php`). They are incremental migrations on top of the base schema.

**5. WhatsApp webhook**
Register `https://yourdomain.com/api/whatsapp_webhook.php` in your Meta app and subscribe to **messages**. See `WHATSAPP_SETUP.md`.

**6. Cron jobs** (adjust paths to your server)
```
/usr/local/bin/php /home/YOUR_USER/public_html/accounting-erp/cron/cron_daily_customers_sync.php
/usr/local/bin/php /home/YOUR_USER/public_html/accounting-erp/cron/cron_daily_suppliers_sync.php
/usr/local/bin/php /home/YOUR_USER/public_html/accounting-erp/cron/cron_reminders.php
```

---

## ⚠️ Notes
- `APP_BASE_URL` defaults to `/accounting-system`; change it in `.env` if you deploy under a different path.
- Protect `admin/` on any public server — it contains migration and balance-fix tools.

## 📄 License
MIT — see [LICENSE](LICENSE).

## 👤 Author
**Yazan Hamdi** — Full Stack AI Engineer
[github.com/yazanhamde](https://github.com/yazanhamde) · [goldensketch.com/yazan-hamdi-demo](https://goldensketch.com/yazan-hamdi-demo/)

---
---

<div dir="rtl">

# 📊 نظام محاسبة ERP مع مساعد ذكي بالعربية

نظام محاسبة مبني بـ PHP وMySQL لشركة تجارية حقيقية، ومعه مساعد ذكي يفهم العربية (اللهجة الأردنية) ويحوّل الرسائل النصية أو الصوتية أو صور الفواتير إلى قيود محاسبية، ولا يحفظ أي شي إلا بعد موافقة صاحب العمل.

> **مثال:** صاحب العمل يرسل على واتساب: `"كليك دفعت للمورد أبو خالد 200"`. المساعد يتعرف على البنك والمورد والمبلغ، ويطلب التأكيد، وبعدها فقط يسجّل القيد.

## ✨ المزايا

### 💬 المساعد الذكي
- يفهم اللهجة والمصطلحات التجارية، ويحوّل الرسائل لعمليات محاسبية منظمة عبر OpenAI.
- **تأكيد قبل الحفظ:** ما بنكتب أي شي بقاعدة البيانات بدون موافقة صاحب العمل.
- إمكانية التراجع (Undo) وسجل لمحادثات المساعد (`pages/chat_log.php`).
- يسأل أسئلة توضيحية بدل ما يخمّن لما تكون الرسالة غامضة.
- الرسائل الصوتية وتحليل صور الفواتير والإيصالات.

### 📱 واتساب (WhatsApp Cloud API)
- مسار الـ webhook: `api/whatsapp_webhook.php`
- التحقق من توقيع الطلبات (`WHATSAPP_APP_SECRET`) وقائمة أرقام مسموحة.
- يدعم النص والصوت والصور.

### 🏦 الوحدات المحاسبية (`pages/`)
لوحة التحكم، العملاء، الموردين، البنوك، الصندوق، الشيكات، الموظفين، السائقين، المصاريف، الإيرادات الخارجية، الأرباح والشركاء، الورشة، القروض الشخصية، وسجل المحادثات.

### 📈 التقارير (`reports/`)
ميزان المراجعة، قائمة الدخل، الميزانية العمومية، التقرير الضريبي، شجرة الحسابات، التسوية البنكية، وتقارير أرباح الباصات والورشة، مع تصدير PDF وExcel.

### 🔄 مزامنة نظام المبيعات (POS)
- مزامنة العملاء والموردين والحسابات من POS خارجي (`includes/pos_sync/`).
- مهام يومية بمجلد `cron/` وسجل مزامنة بمجلد `admin/`.

### 🔒 الأمان
- تسجيل دخول بالجلسات وصلاحيات حسب الدور.
- حماية CSRF وتحديد محاولات تسجيل الدخول.
- كل الأسرار بملف `.env` فقط، والمجلدات الحساسة محمية بـ `.htaccess`.

## 📁 بنية المشروع

```
├── admin/         # المايجريشن وسجل المزامنة وأدوات تصحيح الأرصدة
├── api/           # webhook واتساب ونقاط الدردشة والمرفقات
├── assets/        # CSS وJS وأيقونات
├── config/        # config.php (يقرأ من .env)
├── cron/          # مزامنة يومية للعملاء والموردين وتذكيرات الدفع
├── database/      # ملفات SQL للمايجريشن
├── includes/      # ai_brain/ وactions/ وpos_sync/ والمصادقة
├── pages/         # شاشات التطبيق
├── reports/       # التقارير المالية والتصدير
├── uploads/       # مرفقات المستخدمين (غير متتبعة بـ Git)
├── vendor/        # TCPDF
└── .env.example
```

## ⚙️ التثبيت

```bash
git clone https://github.com/yazanhamde/accounting-erp.git
cd accounting-erp
cp .env.example .env
```

1. عبّي ملف `.env` ببيانات قاعدة البيانات ومفتاح OpenAI وبيانات واتساب وإعدادات الـ POS و`CRON_SECRET`. **لا ترفع هذا الملف أبداً.**
2. ضع مكتبة [TCPDF](https://github.com/tecnickcom/TCPDF) كاملة بالمجلد `vendor/tcpdf/`.
3. شغّل ملفات `database/` بالترتيب أو استخدم `admin/migrations.php`.
4. سجّل الـ webhook بلوحة Meta: `https://yourdomain.com/api/whatsapp_webhook.php` واشترك بحدث **messages** (التفاصيل بـ `WHATSAPP_SETUP.md`).
5. أضف مهام cron من مجلد `cron/` بمسارات السيرفر عندك.

## ⚠️ ملاحظات
- المسار الافتراضي للتطبيق `/accounting-system`، غيّره عبر `APP_BASE_URL` بملف `.env` إذا نشرت بمسار مختلف.
- احمِ مجلد `admin/` على أي سيرفر عام، لأنه فيه أدوات المايجريشن وتصحيح الأرصدة.

## 📄 الترخيص
MIT — راجع ملف [LICENSE](LICENSE).

## 👤 المطوّر
**يزن حمدي** — Full Stack AI Engineer

</div>
