/**
 * عامل الخدمة (Service Worker) لتطبيق الويب.
 *
 * المبدأ المتبع: الشبكة أولًا للصفحات والبيانات، والذاكرة أولًا للأصول
 * الثابتة (JS/CSS/صور). السبب أن هذا نظام تشغيلي — حجوزات وأرصدة ومخزون —
 * وعرض بيانات قديمة من الذاكرة أخطر من انتظار الشبكة. الذاكرة هنا
 * لتسريع الإقلاع ولإظهار صفحة انقطاع مفهومة، لا للعمل دون اتصال.
 */

const VERSION = 'v1';
const STATIC_CACHE = `bsrah-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';

const PRECACHE = [OFFLINE_URL, '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k.startsWith('bsrah-') && !k.endsWith(VERSION)).map((k) => caches.delete(k)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // الأصول المبنيّة تحمل بصمة في اسمها، فتخزينها آمن ولا يُقادم.
    const isBuiltAsset = url.pathname.startsWith('/build/') || url.pathname.startsWith('/pwa/');

    if (isBuiltAsset) {
        event.respondWith(
            caches.match(request).then((cached) =>
                cached ?? fetch(request).then((response) => {
                    const copy = response.clone();
                    caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
                    return response;
                }),
            ),
        );
        return;
    }

    // الصفحات: الشبكة أولًا، وصفحة الانقطاع عند فشلها.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );
    }
});
