// =====================================================================
// ui.js — واجهة عامة لكل صفحات الداشبورد (بدون أي منطق للشات بوت)
//   • تسجيل الـ Service Worker (PWA)
//   • النوافذ المنبثقة + القائمة الجانبية
//   • الوضع الليلي/النهاري
//   • الجداول القابلة للسحب أفقياً
// المسار الأساسي للتطبيق بيجي من window.APP_BASE (بيتعرّف بـ includes/footer.php من APP_BASE_URL)
// =====================================================================

// تسجيل الـ Service Worker (لتفعيل PWA + استقبال التنبيهات)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const base = (window.APP_BASE || '/accounting-system').replace(/\/$/, '');
        navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch((err) => {
            console.warn('SW registration failed:', err);
        });
    });
}

// ---------- نافذة التعديل المنبثقة (تُستخدم بكل صفحات الحركات) ----------
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('open');
}
function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('open');
}

function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('sidebar-open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

// ---------- الوضع الليلي / النهاري ----------
function updateThemeIcon() {
    const btn = document.getElementById('themeToggleBtn');
    if (!btn) return;
    btn.textContent = document.body.classList.contains('dark-mode') ? '☀️' : '🌙';
}
function toggleTheme() {
    document.body.classList.toggle('dark-mode');
    try {
        localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
    } catch (e) {}
    updateThemeIcon();
}
document.addEventListener('DOMContentLoaded', updateThemeIcon);

// نسكر القائمة تلقائياً لما تدوس على أي رابط بالموبايل
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('mainSidebar');
    if (sidebar) {
        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                sidebar.classList.remove('sidebar-open');
                document.getElementById('sidebarOverlay').classList.remove('active');
            });
        });
    }
});

// ---------- تحويل كل جدول بالصفحة لمنطقة قابلة للسحب أفقياً متل تطبيق حقيقي ----------
function updateTableScrollShadows(wrap) {
    // منستخدم Math.abs عشان بعض المتصفحات بترجع scrollLeft بالسالب بصفحات RTL
    const scrollLeft = Math.abs(wrap.scrollLeft);
    const maxScroll = wrap.scrollWidth - wrap.clientWidth;
    const atStart = scrollLeft <= 2;
    const atEnd = scrollLeft >= maxScroll - 2;

    wrap.classList.toggle('has-more-right', !atEnd && maxScroll > 2);
    wrap.classList.toggle('has-more-left', !atStart && maxScroll > 2);
}

function initSwipeableTables() {
    document.querySelectorAll('table').forEach((table) => {
        if (table.parentElement.classList.contains('table-scroll')) return; // ملفوف أصلاً

        const wrap = document.createElement('div');
        wrap.className = 'table-scroll';
        table.parentNode.insertBefore(wrap, table);
        wrap.appendChild(table);

        updateTableScrollShadows(wrap);
        wrap.addEventListener('scroll', () => updateTableScrollShadows(wrap), { passive: true });
        window.addEventListener('resize', () => updateTableScrollShadows(wrap));
    });
}

document.addEventListener('DOMContentLoaded', initSwipeableTables);
// بعض الصفحات بتحدّث الجدول ديناميكياً (refreshPageData) - منعيد الفحص بعدها لو موجودة
if (typeof window.refreshPageData === 'function') {
    const originalRefresh = window.refreshPageData;
    window.refreshPageData = function (...args) {
        const result = originalRefresh.apply(this, args);
        setTimeout(initSwipeableTables, 100);
        return result;
    };
}
