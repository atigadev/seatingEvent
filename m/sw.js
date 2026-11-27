/*
 * Service worker aplikasi petugas (scope: m/).
 * - Halaman: network-first, cadangan cache → aplikasi tetap terbuka saat sinyal hilang.
 * - Aset (css/js/ikon/denah): stale-while-revalidate.
 * - API (../api/): selalu ke jaringan, tidak pernah di-cache (data peserta).
 * Naikkan VERSION setiap kali aset diubah.
 */
const VERSION = 'm-1.0.3';
const CACHE = 'cekmeja-' + VERSION;
const SHELL = ['./', 'app.css', 'app.js', 'pwa.js', 'manifest.json', 'denah.php', '../assets/vendor/html5-qrcode.min.js', '../assets/icons/icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => Promise.all(SHELL.map((u) => c.add(u).catch(() => null)))));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('cekmeja-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (!event.data) return;
  if (event.data.type === 'SKIP_WAITING') self.skipWaiting();
  if (event.data.type === 'GET_VERSION' && event.ports[0]) event.ports[0].postMessage({ version: VERSION.replace(/^m-/, '') });
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin || url.pathname.includes('/api/')) return;

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => { const copy = res.clone(); caches.open(CACHE).then((c) => c.put('./', copy)); return res; })
        .catch(() => caches.match('./'))
    );
    return;
  }

  // abaikan versi (?v=) agar aset tetap tersedia offline
  event.respondWith(
    caches.open(CACHE).then((c) => c.match(req, { ignoreSearch: true }).then((cached) => {
      const net = fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => cached);
      return cached || net;
    }))
  );
});
