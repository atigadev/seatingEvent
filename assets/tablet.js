/*
 * Tablet / iPad untuk halaman Seating:
 * - Layar penuh otomatis pada ketukan pertama (Fullscreen API butuh gestur), + tombol di menu bawah.
 * - Kunci orientasi landscape bila didukung (Android, saat layar penuh).
 * - Ajakan "Pasang aplikasi" (Android: tombol Pasang; iPad/iPhone: petunjuk Tambah ke Layar Utama).
 * Tidak aktif di PC kiosk (Chrome --kiosk sudah layar penuh) maupun bila sudah dibuka sebagai aplikasi.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const isTouch = navigator.maxTouchPoints > 0 && matchMedia('(any-pointer: coarse)').matches;
  const isApp = () => matchMedia('(display-mode: fullscreen)').matches || matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const root = document.documentElement;
  const fsEl = () => document.fullscreenElement || document.webkitFullscreenElement;
  const canFs = !!(root.requestFullscreen || root.webkitRequestFullscreen);

  function enterFs() {
    if (fsEl() || !canFs) return Promise.resolve();
    const req = root.requestFullscreen ? root.requestFullscreen({ navigationUI: 'hide' }) : root.webkitRequestFullscreen();
    return Promise.resolve(req).then(() => {
      if (screen.orientation && screen.orientation.lock) screen.orientation.lock('landscape').catch(() => {});
    }).catch(() => {});
  }
  function exitFs() {
    if (!fsEl()) return;
    (document.exitFullscreen || document.webkitExitFullscreen).call(document);
  }

  /* ---------- tombol di menu bawah ---------- */
  const btn = $('btn-fullscreen');
  function syncBtn() {
    if (!btn) return;
    btn.hidden = !canFs || isApp();
    btn.classList.toggle('active', !!fsEl());
    btn.title = fsEl() ? 'Keluar layar penuh' : 'Layar penuh';
  }
  if (btn) btn.addEventListener('click', () => { fsEl() ? exitFs() : enterFs(); });
  document.addEventListener('fullscreenchange', syncBtn);
  document.addEventListener('webkitfullscreenchange', syncBtn);
  syncBtn();

  if (!isTouch || isApp()) return;

  /* ---------- layar penuh otomatis di tablet (ketukan pertama, dan setelah keluar tanpa sengaja) ---------- */
  let manualExit = false;
  if (btn) btn.addEventListener('click', () => { manualExit = !!fsEl(); });
  document.addEventListener('pointerdown', (e) => {
    if (manualExit || fsEl() || e.target.closest('#btn-fullscreen, .tb-install')) return;
    enterFs();
  }, { passive: true });

  /* ---------- ajakan pasang aplikasi ---------- */
  const KEY = 'seating-install-dismissed';
  let dismissed = false;
  try { dismissed = localStorage.getItem(KEY) === '1'; } catch (e) { /* abaikan */ }
  if (dismissed) return;

  const bar = document.createElement('div');
  bar.className = 'tb-install';
  bar.hidden = true;
  bar.innerHTML =
    '<img src="assets/icons/icon-192.png" alt="">' +
    '<div class="tb-text"><b>Pasang sebagai aplikasi</b><span></span></div>' +
    '<button type="button" class="tb-go">Pasang</button>' +
    '<button type="button" class="tb-x" aria-label="Tutup">×</button>';
  document.body.appendChild(bar);
  const hide = (remember) => {
    bar.hidden = true;
    if (remember) try { localStorage.setItem(KEY, '1'); } catch (e) { /* abaikan */ }
  };
  bar.querySelector('.tb-x').addEventListener('click', () => hide(true));

  let deferred = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferred = e;
    bar.querySelector('span').textContent = 'Tampil layar penuh tanpa bilah browser & terbuka langsung dari layar utama.';
    bar.querySelector('.tb-go').hidden = false;
    bar.hidden = false;
  });
  bar.querySelector('.tb-go').addEventListener('click', () => {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.then(() => { deferred = null; hide(false); });
  });
  window.addEventListener('appinstalled', () => hide(true));

  if (isIOS) {
    bar.querySelector('span').innerHTML = 'Ketuk <b>Bagikan</b> <span aria-hidden="true">⬆︎</span> → <b>Tambah ke Layar Utama</b>, lalu buka dari ikon di layar utama.';
    bar.querySelector('.tb-go').hidden = true;
    bar.hidden = false;
  }
  setTimeout(() => hide(false), 30000);   // tidak mengganggu peserta terlalu lama
})();
