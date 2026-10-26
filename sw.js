// Service Worker لنظام المحاسبة الذكي
// وظيفته: يخزن مؤقتاً بعض الملفات الثابتة عشان التطبيق يفتح بسرعة (PWA)

const CACHE_NAME = 'accounting-app-v4';
const STATIC_ASSETS = [
    'assets/css/style.css',
    'assets/js/app.js',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => {})
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// كاش للملفات الثابتة (css/js/icons) بستراتيجية "الشبكة أول":
// نجرب نجيب أحدث نسخة من السيرفر مباشرة من غير ما نمر إطلاقاً على كاش المتصفح العادي
// (cache: 'reload' يجبر المتصفح يطلع للسيرفر حتى لو الملف عنده بذاكرة الـ HTTP العادية)
// وإذا ما في إنترنت وقتها بس، نستخدم النسخة المحفوظة كحل بديل.
// المهم: لازم نرجع Response صالح بكل الحالات، وإلا Chrome بيطلع "File not found"
// (هاي كانت المشكلة الأصلية: لو ما في نت ولا نسخة بالكاش، كان بيرجع undefined).
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    const isStatic = STATIC_ASSETS.some((a) => url.pathname.endsWith(a));

    if (isStatic) {
        event.respondWith(
            fetch(event.request.url, { cache: 'reload' })
                .then((freshResponse) => {
                    const responseToCache = freshResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseToCache));
                    return freshResponse;
                })
                .catch(() =>
                    caches.match(event.request).then((cached) =>
                        cached || new Response('', { status: 504, statusText: 'Offline' })
                    )
                )
        );
        return;
    }

    // صفحات التطبيق (dashboard.php, login.php...): نحاول الشبكة أول،
    // ولو فشلت (لا نت لحظة فتح التطبيق) منحاول الكاش، وإذا برضه ما في شي
    // منرجع صفحة بسيطة بدل ما نخلي المتصفح يطلع "File not found".
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() =>
                caches.match(event.request).then((cached) =>
                    cached || new Response(
                        '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لا يوجد اتصال</title></head>' +
                        '<body style="font-family:sans-serif; text-align:center; padding:60px 20px; direction:rtl;">' +
                        '<h2>⚠️ لا يوجد اتصال بالإنترنت حالياً</h2>' +
                        '<p>تأكد من الاتصال بالشبكة وحاول تفتح التطبيق مرة ثانية.</p>' +
                        '</body></html>',
                        { status: 200, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
                    )
                )
            )
        );
    }
});