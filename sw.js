/*
 * Service worker Seat Kiosk.
 * - Aset statis (css/js/gambar/ikon): cache-first → kiosk cepat & tahan sinyal putus-sambung.
 * - Halaman (index.php / settings.php): network-first, fallback ke cache bila server tak terjangkau.
 * - API (api/*, mock/api.php): selalu ke jaringan, tidak pernah di-cache.
 * Naikkan VERSION setiap kali aset diubah agar perangkat mengambil versi baru.
 */
const VERSION = 'seat-v8';
const STATIC_CACHE = VERSION + '-static';
const PAGE_CACHE = VERSION + '-pages';

const PRECACHE = [
  './',
  'assets/style.css?v=11',
  'assets/app.js?v=14',
  'assets/wayfinding.css?v=1',
  'assets/router.js?v=1',
  'assets/wayfinding.js?v=2',
  'assets/bt-printer.js?v=1',
  'assets/vendor/html5-qrcode.min.js',
  'assets/img/bg.jpg',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/icons/apple-touch-icon.png',
  'manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => Promise.all(PRECACHE.map((url) => cache.add(url).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                 // POST lookup → langsung ke jaringan
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  if (url.pathname.includes('/api/') || url.pathname.includes('/mock/')) return;

  // Halaman: network-first
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(PAGE_CACHE).then((c) => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req).then((r) => r || caches.match('./')))
    );
    return;
  }

  // Aset statis: cache-first
  event.respondWith(
    caches.match(req).then((cached) => cached || fetch(req).then((res) => {
      if (res.ok) {
        const copy = res.clone();
        caches.open(STATIC_CACHE).then((c) => c.put(req, copy));
      }
      return res;
    }))
  );
});
