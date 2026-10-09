// Service worker : rend l'application installable et affiche une page
// hors ligne. Les données ne sont jamais mises en cache (toujours à jour).
const CACHE = 'liquid-v1';
const ASSETS = ['/offline.html', '/assets/css/app.css?v=1', '/assets/js/app.js?v=1', '/assets/icons.svg',
  '/assets/img/logo.svg', '/assets/img/icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET' || new URL(req.url).origin !== location.origin) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }
  if (req.url.includes('/assets/')) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
  }
});
