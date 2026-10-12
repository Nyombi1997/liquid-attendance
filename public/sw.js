// Service worker : rend l'application installable et affiche une page
// hors ligne. Les pages et /api/ ne sont jamais mises en cache (toujours à jour).
//
// Fichiers statiques : les pages les appellent avec « ?v=<date de modification> »
// (helper PHP asset()). Une URL versionnée ne change jamais de contenu : elle
// est servie depuis le cache, et seule la dernière version de chaque fichier
// est gardée. Inutile de modifier CACHE à chaque mise à jour.
const CACHE = 'liquid-v4'; // v4 : page hors ligne (thème clair forcé)
const PRECACHE = ['/offline.html', '/assets/js/offline.js', '/assets/icons.svg', '/assets/img/logo.svg', '/assets/img/icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

// Met en cache la réponse sous son URL complète, puis retire les autres
// versions du même fichier.
async function store(req, res) {
  const cache = await caches.open(CACHE);
  await cache.put(req, res);
  const path = new URL(req.url).pathname;
  const keys = await cache.keys();
  await Promise.all(keys
    .filter((k) => k.url !== req.url && new URL(k.url).pathname === path)
    .map((k) => cache.delete(k)));
}

async function versioned(req) {
  const hit = await caches.match(req);
  if (hit) return hit;
  const res = await fetch(req);
  if (res.ok) await store(req, res.clone());
  return res;
}

// URL sans version (page hors ligne, icônes du manifeste) : réseau d'abord,
// puis n'importe quelle version en cache si le réseau ne répond pas.
async function unversioned(req) {
  try {
    return await fetch(req);
  } catch (err) {
    const hit = await caches.match(req, { ignoreSearch: true });
    if (hit) return hit;
    throw err;
  }
}

// Pages : au réveil du téléphone, le réseau met parfois une seconde à revenir.
// Deux nouveaux essais avant d'afficher la page hors ligne (qui réessaie
// ensuite d'elle-même, voir assets/js/offline.js).
async function navigate(req) {
  for (const wait of [0, 1500, 3000]) {
    if (wait) await new Promise((r) => setTimeout(r, wait));
    try {
      return await fetch(req);
    } catch (err) { /* nouvel essai */ }
  }
  return (await caches.match('/offline.html')) || Response.error();
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  // Données en direct (/api/) : toujours le réseau, jamais de cache
  if (url.pathname.startsWith('/api/')) return;
  if (req.mode === 'navigate') {
    e.respondWith(navigate(req));
    return;
  }
  if (url.pathname.startsWith('/assets/')) {
    e.respondWith(url.searchParams.has('v') ? versioned(req) : unversioned(req));
  }
});
