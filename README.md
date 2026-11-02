# 📊 Enterprise Accounting ERP + Arabic AI Assistant

> A production-ready accounting system built for a real trading business — with a conversational AI assistant that understands Arabic dialect and turns plain messages into validated accounting entries.

---

## 🧠 What Makes This Different

Most accounting systems require the user to navigate forms, select accounts, and fill in fields. This system lets the **business owner type or speak in everyday Arabic** — the AI understands, structures the entry, asks for confirmation, and executes. No accounting knowledge required.

> **Example:** The owner types `"كليك دفعت للمورد أبو خالد 200"` — the system recognizes "كليك" as a specific bank, identifies the supplier, extracts the amount, and prepares a confirmed debit entry — all before a single button is clicked.

---

## ✨ Core Features

### 💬 AI Accounting Assistant
- Understands **Jordanian Arabic dialect** and business slang (e.g. "كليك" = Union Bank, "فيزة" = visa card)
- Converts natural language into structured JSON accounting actions via OpenAI
- **Confirm-before-save**: no entry is written to the database without owner approval
- Supports **undo** for any entry
- Handles ambiguous messages by asking clarifying questions instead of guessing
- Processes **voice messages** via Whisper transcription
- Analyzes **receipt and invoice photos** via GPT-4o Vision

### 📱 WhatsApp Cloud API Integration
- Full webhook integration with verified signatures
- Duplicate-message protection
- Owner-number allowlist for security
- Supports text, voice, and image messages

### 🏦 Full Accounting Coverage
- **Customers & Suppliers** — full receivables/payables with aging
- **Banks** — multiple bank accounts (Jordan Business, Jordan Personal, Etihad, Islami, Dinarak, Investment)
- **Cash Box** — daily cash counts with variance tracking
- **Cheques** — incoming and outgoing cheque management
- **Employees & Drivers** — salaries, advances, deductions, bonuses
- **Shareholders** — capital contributions and profit distributions
- **Workshop Module** — revenue and expense tracking for a service department
- **Personal Loans** — lend and repay tracking for non-business transactions
- **Expenses** — operational expenses with tax and invoice number support

### 📈 Reports & Exports
- **Trial Balance** — live double-entry verification
- **Income Statement** — revenue vs. expenses
- **Balance Sheet** — assets, liabilities, equity
- **Tax Report** — sales tax breakdown
- **Profit Analysis** — per-shareholder profit tracking
- **Bus/Fleet Profit Report**
- PDF export (Arabic RTL via TCPDF)
- Excel export (custom XLSX writer, no external dependencies)

### 🔄 POS Synchronization
- Idempotent sync from an external POS system
- Opening-balance cutoff to prevent double-counting
- Daily cron jobs for customers and suppliers sync
- Sync log with conflict detection

### 🔒 Security
- Role-based access control
- Webhook signature verification on every WhatsApp request
- `.env`-only secrets — nothing hardcoded
- Protected upload directory via `.htaccess`
- Session-based authentication with auth middleware

---

## 🛠️ Tech Stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.x |
| AI | OpenAI GPT-4o-mini, GPT-4o Vision, Whisper |
| Channel | WhatsApp Cloud API (Meta) |
| Database | MySQL |
| PDF | TCPDF (Arabic RTL support) |
| Excel | Custom XLSX writer |
| Frontend | Vanilla JS + PWA (installable) |
| Cron | PHP CLI scheduled jobs |

---

## 📁 Project Structure

```
├── api/
│   ├── whatsapp_webhook.php     # WhatsApp Cloud API webhook handler
│   ├── chat.php                 # AI chat endpoint (text)
│   ├── chat_image.php           # AI chat endpoint (image/voice)
│   └── wa_diagnose.php          # Webhook diagnostics tool
│
├── includes/
│   ├── ai_brain.php             # Core AI: builds system prompt + calls OpenAI
│   ├── actions.php              # All database write actions (executed after confirmation)
│   ├── whatsapp_client.php      # WhatsApp message sender
│   ├── pos_sync.php             # POS synchronization logic
│   ├── report_data.php          # Report data queries
│   ├── notifications.php        # Owner alert system
│   ├── auth.php                 # Session auth middleware
│   └── db.php                   # Database connection
│
├── database/
│   └── migration_*.sql          # Safe, incremental schema migrations
│
├── dashboard.php                # Main overview (receivables, payables, cash, banks)
├── customers.php                # Customer ledger
├── suppliers.php                # Supplier ledger
├── banks.php                    # Bank accounts
├── cash_box.php                 # Cash tracking
├── checks.php                   # Cheque management
├── employees.php                # Employee payroll
├── workshop.php                 # Workshop module
├── profits.php                  # Shareholder profit tracking
├── reports.php                  # Financial reports
├── trial_balance.php            # Double-entry verification
├── balance_sheet.php            # Balance sheet
├── income_statement.php         # Income statement
├── tax_report.php               # Tax report
├── export_pdf.php               # PDF export
├── export_excel.php             # Excel export
├── reconciliation.php           # Bank reconciliation
├── chat_log.php                 # AI conversation history
├── cron_reminders.php           # Scheduled payment reminders
├── cron_pos_sync.php            # Daily POS sync job
└── migrations.php               # Migration runner UI
```

---

## ⚙️ Setup

### 1. Clone & configure

```bash
git clone https://github.com/yazanhamde/accounting-system
cd accounting-system
cp .env.example .env
```

### 2. Fill in `.env`

```env
DB_HOST=localhost
DB_NAME=your_db_name
DB_USER=your_db_user
DB_PASS=your_db_password

OPENAI_API_KEY=your_openai_key
OPENAI_MODEL=gpt-4o-mini

APP_NAME=نظام المحاسبة الذكي
APP_TIMEZONE=Asia/Amman
APP_CURRENCY=JOD

WHATSAPP_TOKEN=
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_VERIFY_TOKEN=
WHATSAPP_APP_SECRET=
WHATSAPP_OWNER_PHONE=

POS_API_BASE_URL=
POS_API_KEY=
```

### 3. Run migrations

```bash
# Via browser
https://yourdomain.com/migrations.php

# Or via MySQL directly
mysql -u user -p dbname < database/migration_workshop_module.sql
```

### 4. Register WhatsApp webhook

```
https://yourdomain.com/api/whatsapp_webhook.php
```

Subscribe to: **messages**

See `WHATSAPP_SETUP.md` for full Meta App configuration steps.

### 5. Set up cron jobs

```bash
# Daily POS sync
0 2 * * * php /path/to/cron_pos_sync.php

# Payment reminders
0 9 * * * php /path/to/cron_reminders.php
```

---

## 🗄️ Database

The schema covers **59 tables** across:

- Customers, suppliers, transactions, and aging
- Multiple bank accounts with per-bank usage types
- Cash box with daily count variance
- Cheques (incoming/outgoing) linked to parties
- Employees, drivers, salaries, advances
- Shareholders and capital accounts
- Workshop revenue and expenses
- POS sync log with idempotency keys
- AI chat log with action history and undo support
- Schema migrations tracking table

> Full schema available on request.

---

## 📊 Production Stats

- Handles daily accounting operations for a real trading business
- Processes Arabic voice messages and invoice photos
- Owner receives automated payment reminders
- Zero double-entries due to idempotent POS sync
- Full audit trail on every AI-executed action

---

## 👤 Author

**Yazan Hamdi** — Full Stack AI Engineer  
[github.com/yazanhamde](https://github.com/yazanhamde) · [goldensketch.com/yazan-hamdi](https://goldensketch.com/yazan-hamdi/)
