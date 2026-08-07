/**
 * Service Worker — سوبر آبل PWA
 *
 * إستراتيجية محافظة عن قصد:
 *  - ملفات الواجهة الثابتة (شعار، أيقونات، خطوط) → من الكاش أولًا (فتح أسرع)
 *  - index.html → من الشبكة أولًا (عشان أي "إعادة نشر" توصل فورًا بدون ما يعلق المستخدم بنسخة قديمة)
 *  - أي طلب لـ /api/ → من الشبكة فقط، بدون أي تخزين إطلاقًا
 *    (البيانات الحية زي المهام والدوام والنقاط ما يجوز تُعرض من كاش قديم)
 */

const CACHE_VERSION = 'superapple-v1';
const STATIC_ASSETS = [
  './assets/logo.png',
  './assets/logo-small.png',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting()) // لو فشل تخزين أي أصل، ما نمنع التثبيت
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // 1) طلبات الـ API: شبكة فقط، بدون كاش نهائيًا
  if (url.pathname.includes('/api/')) return;

  // 2) طلبات من نطاقات خارجية (خطوط، مكتبات): نتركها للمتصفح
  if (url.origin !== self.location.origin) return;

  // 3) صفحة التطبيق نفسها: الشبكة أولًا، والكاش احتياطي لو ما في نت
  const isDocument = req.mode === 'navigate' || url.pathname.endsWith('/') || url.pathname.endsWith('index.html');
  if (isDocument) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req).then((r) => r || caches.match('./')))
    );
    return;
  }

  // 4) باقي الأصول الثابتة: كاش أولًا، ثم شبكة
  event.respondWith(
    caches.match(req).then((cached) => {
      if (cached) return cached;
      return fetch(req).then((res) => {
        if (res && res.status === 200 && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
        }
        return res;
      });
    })
  );
});
