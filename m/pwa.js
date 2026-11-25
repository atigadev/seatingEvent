// Service worker, banner "Versi baru" + tombol Update, dan banner "Pasang aplikasi" (pola dari aplikasi EMS Scan)
(function () {
  'use strict';
  const meta = document.querySelector('meta[name="app-version"]');
  const CURRENT = meta ? meta.content : '';

  function bar(cls, html) {
    const d = document.createElement('div');
    d.className = 'pwa-bar ' + cls;
    d.hidden = true;
    d.innerHTML = html;
    document.body.appendChild(d);
    return d;
  }
  let toastTimer;
  function toast(text) {
    let t = document.querySelector('.pwa-toast');
    if (!t) { t = document.createElement('div'); t.className = 'pwa-toast'; document.body.appendChild(t); }
    t.textContent = text;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, 3000);
  }

  /* ---------- update ---------- */
  const updateBar = bar('pwa-update',
    '<div class="pwa-bar-text"><strong>Versi baru tersedia</strong><span></span></div>' +
    '<button type="button" class="btn primary">Update</button>');

  function askVersion(worker) {
    return new Promise((resolve) => {
      const ch = new MessageChannel();
      ch.port1.onmessage = (e) => resolve(e.data && e.data.version);
      worker.postMessage({ type: 'GET_VERSION' }, [ch.port2]);
      setTimeout(() => resolve(null), 1000);
    });
  }
  function showUpdate(worker) {
    askVersion(worker).then((v) => {
      updateBar.querySelector('span').textContent = v ? 'v' + CURRENT + ' → v' + v : 'Tekan Update untuk memuat versi terbaru';
      updateBar.hidden = false;
    });
    updateBar.querySelector('button').onclick = function () {
      this.disabled = true;
      this.textContent = 'Memperbarui…';
      worker.postMessage({ type: 'SKIP_WAITING' });
    };
  }
  function installed(worker) {
    return new Promise((resolve) => {
      if (worker.state === 'installed') return resolve(worker);
      worker.addEventListener('statechange', () => {
        if (worker.state === 'installed') resolve(worker);
        if (worker.state === 'redundant') resolve(null);
      });
    });
  }
  function watch(reg) {
    if (reg.waiting && navigator.serviceWorker.controller) showUpdate(reg.waiting);
    reg.addEventListener('updatefound', () => {
      installed(reg.installing).then((w) => { if (w && navigator.serviceWorker.controller) showUpdate(w); });
    });
    setInterval(() => reg.update().catch(() => {}), 30 * 60 * 1000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) reg.update().catch(() => {}); });

    const btn = document.getElementById('btn-update');
    if (btn) {
      btn.hidden = false;
      btn.addEventListener('click', () => {
        btn.disabled = true;
        btn.textContent = 'Mengecek…';
        reg.update().then(() => {
          const pending = reg.installing || reg.waiting;
          if (!pending) { toast('Sudah versi terbaru (v' + CURRENT + ')'); return; }
          return installed(pending).then((w) => { if (w) showUpdate(w); });
        }).catch(() => toast('Gagal cek update, periksa koneksi'))
          .then(() => { btn.disabled = false; btn.textContent = 'Cek update'; });
      });
    }
  }

  if ('serviceWorker' in navigator && window.isSecureContext) {
    let reloading = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (reloading) return;
      reloading = true;
      location.reload();
    });
    navigator.serviceWorker.register('sw.js', { scope: './', updateViaCache: 'none' })
      .then(watch)
      .catch((err) => console.warn('Service worker gagal didaftarkan:', err));
  }

  /* ---------- pasang aplikasi ---------- */
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  if (standalone) return;

  let deferred = null;
  const installBar = bar('pwa-install',
    '<img src="../assets/icons/icon-192.png" alt="">' +
    '<div class="pwa-bar-text"><strong>Pasang Cek Meja</strong><span></span></div>' +
    '<button type="button" class="btn primary">Pasang</button>' +
    '<button type="button" class="pwa-bar-close" aria-label="Tutup">×</button>');
  const dismissed = () => { try { return localStorage.getItem('m-pwa-dismissed') === '1'; } catch (e) { return false; } };
  function showInstall(text, withButton) {
    if (dismissed()) return;
    installBar.querySelector('span').innerHTML = text;
    installBar.querySelector('.btn').hidden = !withButton;
    installBar.hidden = false;
  }
  installBar.querySelector('.pwa-bar-close').addEventListener('click', () => {
    installBar.hidden = true;
    try { localStorage.setItem('m-pwa-dismissed', '1'); } catch (e) { /* abaikan */ }
  });
  installBar.querySelector('.btn').addEventListener('click', () => {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.then(() => { deferred = null; installBar.hidden = true; });
  });
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferred = e;
    showInstall('Pasang di layar utama agar scan lebih cepat', true);
  });
  window.addEventListener('appinstalled', () => { installBar.hidden = true; });
  if (/iphone|ipad|ipod/i.test(navigator.userAgent)) {
    showInstall('Ketuk <b>Bagikan</b> → <em>Tambah ke Layar Utama</em>', false);
  }
})();
