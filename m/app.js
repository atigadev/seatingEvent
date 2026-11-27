/*
 * Cek Meja Petugas — scan QR ID Card dengan kamera HP → nomor meja & detail peserta.
 * Kamera: html5-qrcode (Html5Qrcode). Data: ../api/m-lookup.php (baca saja).
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const CAM_KEY = 'm-camera', HIST_KEY = 'm-history', HIST_MAX = 20;

  const cam = $('cam'), camIdle = $('cam-idle'), camTools = $('cam-tools');
  const btnStart = $('btn-start'), btnStop = $('btn-stop'), btnSwitch = $('btn-switch'), btnTorch = $('btn-torch');
  const viewScan = $('view-scan'), viewResult = $('view-result'), dock = $('dock');

  const scanner = new Html5Qrcode('reader', { formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE], verbose: false });
  let running = false, paused = false, starting = false, torchOn = false;
  let cameras = [], showingResult = false, busy = false, resumeOnVisible = false;
  let last = { code: '', at: 0 };

  /* ---------------- util ---------------- */
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* abaikan */ } },
  };
  function msg(text, isErr) {
    const el = $('msg');
    el.textContent = text || '';
    el.className = 'msg' + (isErr ? ' err' : '');
    el.hidden = !text;
  }
  function vibrate(p) { if (navigator.vibrate) try { navigator.vibrate(p); } catch (e) { /* abaikan */ } }
  function timeStr(t) { const d = new Date(t); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); }

  /* ---------------- kamera ---------------- */
  function setLive(on) {
    running = on;
    cam.classList.toggle('live', on);
    camIdle.hidden = on;
    camTools.hidden = !on;
  }

  function camConstraint() {
    const saved = store.get(CAM_KEY, '');
    if (saved && cameras.some((c) => c.id === saved)) return { deviceId: { exact: saved } };
    return { facingMode: 'environment' };   // kamera belakang HP
  }

  async function start() {
    if (running || starting) return;
    starting = true;
    msg('');
    btnStart.disabled = true;
    try {
      await scanner.start(camConstraint(), {
        fps: 12,
        qrbox: (w, h) => { const s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: s }; },
        aspectRatio: 1,
      }, onScan, () => {});
      paused = false;
      setLive(true);
      afterStart();
    } catch (err) {
      setLive(false);
      if (!window.isSecureContext) {
        msg('Kamera hanya bisa dipakai lewat HTTPS. Buka halaman ini dengan https://, atau gunakan tombol Foto QR / ketik ID.', true);
      } else {
        msg('Kamera tidak bisa dibuka. Izinkan akses kamera untuk situs ini, lalu tekan Mulai Scan lagi. (' + (err && err.message ? err.message : err) + ')', true);
      }
    } finally {
      starting = false;
      btnStart.disabled = false;
    }
  }

  async function afterStart() {
    // daftar kamera (sesudah izin diberikan, label kamera tersedia) → tombol ganti kamera
    try {
      cameras = await Html5Qrcode.getCameras();
      btnSwitch.hidden = cameras.length < 2;
    } catch (e) { btnSwitch.hidden = true; }
    // senter (Android Chrome)
    torchOn = false;
    btnTorch.classList.remove('on');
    try {
      const caps = scanner.getRunningTrackCapabilities();
      btnTorch.hidden = !(caps && caps.torch);
    } catch (e) { btnTorch.hidden = true; }
  }

  async function stop() {
    if (!running) return;
    try { await scanner.stop(); } catch (e) { /* abaikan */ }
    paused = false;
    setLive(false);
  }

  function pauseCam() {
    if (running && !paused) { try { scanner.pause(true); paused = true; } catch (e) { /* abaikan */ } }
  }
  function resumeCam() {
    if (running && paused) { try { scanner.resume(); paused = false; } catch (e) { /* abaikan */ } }
    else if (!running) start();
  }

  btnStart.addEventListener('click', start);
  btnStop.addEventListener('click', stop);
  btnSwitch.addEventListener('click', async () => {
    if (cameras.length < 2) return;
    const cur = store.get(CAM_KEY, '');
    let i = cameras.findIndex((c) => c.id === cur);
    if (i < 0) i = cameras.findIndex((c) => /back|rear|environment|belakang/i.test(c.label));
    const next = cameras[(i + 1 + cameras.length) % cameras.length];
    store.set(CAM_KEY, next.id);
    await stop();
    start();
  });
  btnTorch.addEventListener('click', async () => {
    torchOn = !torchOn;
    try {
      await scanner.applyVideoConstraints({ advanced: [{ torch: torchOn }] });
      btnTorch.classList.toggle('on', torchOn);
    } catch (e) { torchOn = false; btnTorch.hidden = true; }
  });

  // hemat baterai: kamera mati saat aplikasi di latar belakang
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      resumeOnVisible = running && !paused;
      if (running) stop();
    } else if (resumeOnVisible && !showingResult) {
      resumeOnVisible = false;
      start();
    }
  });

  /* ---------------- scan ---------------- */
  function onScan(text) {
    text = String(text || '').trim();
    if (!text || busy || showingResult) return;
    const now = Date.now();
    if (text === last.code && now - last.at < 2500) return;
    last = { code: text, at: now };
    pauseCam();
    cam.classList.remove('hit'); void cam.offsetWidth; cam.classList.add('hit');
    vibrate(80);
    lookup(text);
  }

  $('qr-file').addEventListener('change', async (e) => {
    const file = e.target.files && e.target.files[0];
    e.target.value = '';
    if (!file) return;
    msg('');
    try {
      await stop();
      const text = await scanner.scanFile(file, false);
      vibrate(80);
      lookup(text);
    } catch (err) {
      msg('QR tidak ditemukan di foto. Coba foto lebih dekat, terang dan tidak buram.', true);
    }
  });

  $('manual').addEventListener('submit', (e) => {
    e.preventDefault();
    const v = $('manual-id').value.trim();
    if (!v) { $('manual-id').focus(); return; }
    $('manual-id').blur();
    lookup(v);
  });

  // QR berisi link → kirim ID-nya saja (alamat https:// di parameter bisa dianggap serangan oleh firewall hosting)
  function qrId(raw) {
    const s = String(raw || '').trim();
    if (!/^https?:\/\//i.test(s)) return s;
    try {
      const u = new URL(s);
      const q = u.searchParams.get('id');
      if (q) return q.trim();
      const seg = u.pathname.split('/').filter(Boolean).pop();
      if (seg) return decodeURIComponent(seg).trim();
    } catch (_) { /* bukan URL valid */ }
    return s;
  }

  async function lookup(code) {
    code = qrId(code);
    if (busy) return;
    busy = true;
    $('loading').hidden = false;
    let res = null;
    try {
      const body = new URLSearchParams({ qr: code });
      const r = await fetch(M.lookup, { method: 'POST', body, cache: 'no-store' });
      res = await r.json().catch(() => ({ ok: false, message: 'Respons server tidak valid (HTTP ' + r.status + ').' }));
    } catch (e) {
      res = { ok: false, message: navigator.onLine ? 'Server tidak dapat dihubungi. Coba lagi.' : 'Tidak ada koneksi internet.' };
    }
    $('loading').hidden = true;
    busy = false;
    showResult(res, code);
  }

  /* ---------------- hasil ---------------- */
  function chip(cls, text) { return '<span class="chip ' + cls + '"><i></i>' + text + '</span>'; }
  function esc(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  function showResult(res, code) {
    const hero = $('hero');
    const d = (res && res.data) || {};
    hero.className = 'card hero';
    $('r-chips').innerHTML = '';
    $('map-card').hidden = true;

    if (!res || !res.ok) {
      vibrate([60, 60, 60]);
      hero.classList.add('err');
      $('r-meja').textContent = 'Data tidak ditemukan';
      $('r-kursi').textContent = (res && res.message) || 'Terjadi kesalahan.';
      fillDetail({ id: code });
    } else {
      fillDetail(d);
      if (res.unassigned) {
        hero.classList.add('none');
        $('r-meja').textContent = 'Meja belum ditentukan';
        $('r-kursi').textContent = d.message || 'Arahkan peserta ke meja registrasi';
        $('r-chips').innerHTML = chip('warn', 'Belum ada meja');
      } else {
        $('r-meja').textContent = res.meja || d.kursi;
        $('r-kursi').textContent = d.kursi && d.kursi !== res.meja ? 'Kode kursi: ' + d.kursi : '';
        const p = res.prints || {};
        $('r-chips').innerHTML = p.count > 0
          ? chip('ok', 'Tiket sudah dicetak ' + p.count + '×') + (p.last ? chip('info', 'Terakhir ' + esc(String(p.last).slice(11, 16))) : '')
          : chip('info', 'Tiket belum dicetak');
        showMap(res.pos, res.meja || d.kursi);
      }
      addHistory({ id: d.id || code, nama: d.nama || '', instansi: d.instansi || '', meja: res.unassigned ? '' : (res.meja || d.kursi || ''), t: Date.now() });
    }
    $('r-checked').textContent = res && res.checked_at ? 'Dicek pukul ' + res.checked_at : '';

    showingResult = true;
    viewScan.hidden = true;
    viewResult.hidden = false;
    dock.hidden = false;
    document.body.classList.add('has-dock');
    window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
    msg('');
  }

  function fillDetail(d) {
    $('r-nama').textContent = d.nama || '-';
    $('r-jabatan').textContent = d.jabatan || '-';
    $('r-instansi').textContent = d.instansi || '-';
    $('r-id').textContent = d.id || '-';
  }

  function showMap(pos, label) {
    if (!pos) return;
    for (const [pin, num] of [['pin', 'pin-num'], ['zpin', 'zpin-num']]) {
      $(pin).style.left = pos.x + '%';
      $(pin).style.top = pos.y + '%';
      $(num).textContent = label;
    }
    $('zoom-title').textContent = 'Lokasi Meja ' + label;
    $('map-card').hidden = false;
  }

  function next() {
    showingResult = false;
    viewResult.hidden = true;
    dock.hidden = true;
    document.body.classList.remove('has-dock');
    viewScan.hidden = false;
    $('manual-id').value = '';
    last = { code: '', at: 0 };
    window.scrollTo(0, 0);
    resumeCam();
  }
  $('btn-next').addEventListener('click', next);

  /* ---------------- denah diperbesar ---------------- */
  function openZoom() {
    $('zoom').hidden = false;
    document.body.style.overflow = 'hidden';
    const body = $('zoom-body'), inner = $('zoom-inner'), pin = $('zpin');
    requestAnimationFrame(() => {
      const x = parseFloat(pin.style.left) / 100 * inner.offsetWidth;
      const y = parseFloat(pin.style.top) / 100 * inner.offsetHeight;
      body.scrollTo(x - body.clientWidth / 2, y - body.clientHeight / 2);
    });
  }
  function closeZoom() { $('zoom').hidden = true; document.body.style.overflow = ''; }
  $('map').addEventListener('click', openZoom);
  $('btn-zoom').addEventListener('click', openZoom);
  $('zoom-close').addEventListener('click', closeZoom);

  /* ---------------- riwayat ---------------- */
  function renderHistory() {
    const list = store.get(HIST_KEY, []);
    $('history').hidden = !list.length;
    $('history-list').innerHTML = list.map((h, i) =>
      '<li><button type="button" data-i="' + i + '">' +
        '<span class="h-num' + (h.meja ? '' : ' none') + '">' + (h.meja ? esc(h.meja) : '—') + '</span>' +
        '<span class="h-text"><b>' + esc(h.nama || h.id) + '</b><span>' + esc(h.instansi || h.id) + '</span></span>' +
        '<span class="h-time">' + timeStr(h.t) + '</span>' +
      '</button></li>').join('');
  }
  function addHistory(item) {
    const list = store.get(HIST_KEY, []).filter((h) => h.id !== item.id);
    list.unshift(item);
    store.set(HIST_KEY, list.slice(0, HIST_MAX));
    renderHistory();
  }
  $('history-list').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-i]');
    if (!b) return;
    const h = store.get(HIST_KEY, [])[+b.dataset.i];
    if (h) { pauseCam(); lookup(h.id); }
  });
  $('btn-clear').addEventListener('click', () => {
    if (confirm('Hapus riwayat scan di HP ini?')) { store.set(HIST_KEY, []); renderHistory(); }
  });
  renderHistory();

  /* ---------------- koneksi ---------------- */
  function net() {
    const on = navigator.onLine;
    $('net').classList.toggle('off', !on);
    $('net').querySelector('em').textContent = on ? 'Online' : 'Offline';
  }
  window.addEventListener('online', net);
  window.addEventListener('offline', net);
  net();

  // ?id=XXXX → langsung cek (mis. dari shortcut)
  const qid = new URLSearchParams(location.search).get('id');
  if (qid) lookup(qid);

  // izin kamera sudah pernah diberikan → kamera langsung menyala
  if (!qid && window.isSecureContext && navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({ name: 'camera' }).then((p) => { if (p.state === 'granted') start(); }).catch(() => {});
  }
})();
