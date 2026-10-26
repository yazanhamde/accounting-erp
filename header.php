<?php
require_once __DIR__ . '/auth.php';
require_login();
$user = current_user();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">

<!-- PWA: يخلي التطبيق قابل للتثبيت على الموبايل متل تطبيق عادي -->
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#16213a">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

</head>
<body>
<script>
(function () {
    try {
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
    } catch (e) {}
})();
</script>
<div class="app-shell">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <div class="sidebar" id="mainSidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 21V9L12 3L21 9V21H15V14H9V21H3Z" stroke="#e8cf8a" stroke-width="1.5" stroke-linejoin="round"/>
                    <path d="M9 21V14H15V21" stroke="#e8cf8a" stroke-width="1.5" stroke-linejoin="round"/>
                    <circle cx="12" cy="9.5" r="1.3" fill="#e8cf8a"/>
                </svg>
            </div>
            <h3><?= APP_NAME ?></h3>
        </div>
        <a href="dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">🏠 الرئيسية</a>
        <a href="banks.php" class="<?= $current_page === 'banks.php' ? 'active' : '' ?>">🏦 الحسابات البنكية</a>
        <a href="cash_box.php" class="<?= $current_page === 'cash_box.php' ? 'active' : '' ?>">💵 صندوق الكاش</a>
        <a href="checks.php" class="<?= $current_page === 'checks.php' ? 'active' : '' ?>">📄 الشيكات</a>
        <a href="customers.php" class="<?= $current_page === 'customers.php' ? 'active' : '' ?>">🧾 ذمم العملاء</a>
        <?php if (is_owner()): ?>
        <a href="fix_customer_balances.php" class="<?= $current_page === 'fix_customer_balances.php' ? 'active' : '' ?>">🔧 تصحيح أرصدة العملاء</a>
        <?php endif; ?>
        <a href="suppliers.php" class="<?= $current_page === 'suppliers.php' ? 'active' : '' ?>">📦 ذمم الموردين</a>
        <?php if (is_owner()): ?>
        <a href="fix_supplier_balances.php" class="<?= $current_page === 'fix_supplier_balances.php' ? 'active' : '' ?>">🔧 تصحيح أرصدة الموردين</a>
        <?php endif; ?>
        <a href="sync_log.php" class="<?= $current_page === 'sync_log.php' ? 'active' : '' ?>">🔄 مزامنة POS</a>
        <a href="reconciliation_readonly.php" class="<?= $current_page === 'reconciliation_readonly.php' ? 'active' : '' ?>">📋 تقرير مطابقة الجرد</a>
        <a href="employees.php" class="<?= $current_page === 'employees.php' ? 'active' : '' ?>">👥 الموظفين</a>
        <a href="car_employees.php" class="<?= $current_page === 'car_employees.php' ? 'active' : '' ?>">🚗 صيانة خارجية</a>
        <a href="bus_profit_report.php" class="<?= $current_page === 'bus_profit_report.php' ? 'active' : '' ?>">📊 أرباح الصيانة الخارجية</a>
        <a href="workshop.php" class="<?= $current_page === 'workshop.php' ? 'active' : '' ?>">🔧 مشغل</a>
        <a href="workshop_profit_report.php" class="<?= $current_page === 'workshop_profit_report.php' ? 'active' : '' ?>">📊 تقارير شهرية أرباح المشغل</a>
        <a href="personal.php" class="<?= $current_page === 'personal.php' ? 'active' : '' ?>">🤝 دائن ومدين شخصي</a>
        <a href="expenses.php" class="<?= $current_page === 'expenses.php' ? 'active' : '' ?>">🧻 مصاريف وسحوبات</a>
        <a href="external_revenues.php" class="<?= $current_page === 'external_revenues.php' ? 'active' : '' ?>">💚 إيرادات خارجية</a>
        <a href="profits.php" class="<?= $current_page === 'profits.php' ? 'active' : '' ?>">💰 توزيع الأرباح</a>
        <a href="reports.php" class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">📈 التقارير الشهرية</a>
        <a href="income_statement.php" class="<?= $current_page === 'income_statement.php' ? 'active' : '' ?>">💹 قائمة الدخل</a>
        <a href="trial_balance.php" class="<?= $current_page === 'trial_balance.php' ? 'active' : '' ?>">⚖️ ميزان المراجعة</a>
        <a href="reconciliation.php" class="<?= $current_page === 'reconciliation.php' ? 'active' : '' ?>">🔍 التسوية والإقفال</a>
        <a href="tax_report.php" class="<?= $current_page === 'tax_report.php' ? 'active' : '' ?>">🧾 تقرير الضريبة</a>
        <a href="balance_sheet.php" class="<?= $current_page === 'balance_sheet.php' ? 'active' : '' ?>">📊 الميزانية العمومية</a>
        <?php if (is_owner()): ?>
        <a href="migrations.php" class="<?= $current_page === 'migrations.php' ? 'active' : '' ?>">📜 سجل الترحيلات</a>
        <?php endif; ?>
        <a href="chat_log.php" class="<?= $current_page === 'chat_log.php' ? 'active' : '' ?>">💬 سجل محادثات البوت</a>
        <a href="/dashboard/index.html" class="logout" style="background:rgba(232,207,138,.12); border:1px solid rgba(232,207,138,.35); color:#e8cf8a; margin-bottom:6px;">🏛️ لوحة الأنظمة</a>
        <a href="logout.php" class="logout">🚪 تسجيل خروج</a>
    </div>
    <div class="main-content">
        <div class="topbar">
            <button type="button" class="menu-toggle-btn" onclick="toggleSidebar()">☰</button>
            <h1><?= $page_title ?? '' ?></h1>
            <div class="topbar-right">
                <button type="button" class="theme-toggle-btn" id="themeToggleBtn" onclick="toggleTheme()" title="تبديل الوضع الليلي/النهاري">🌙</button>
                <span class="user-chip">
                    <span class="user-avatar"><?= mb_substr(htmlspecialchars($user['name']), 0, 1) ?></span>
                    <span class="user-name">مرحباً، <?= htmlspecialchars($user['name']) ?></span>
                </span>
            </div>
        </div>
