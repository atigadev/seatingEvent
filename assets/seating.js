/* Seating Arrangement — scan → data + meja berkelap-kelip → PRINT */
(function () {
  'use strict';

  const C = window.SEATING;
  const $ = (id) => document.getElementById(id);
  const input = $('scan-input');
  const btn = $('btn-print');

  let current = null;       // { qr, data }
  let busy = false;
  let resetTimer = null;
  let lastCode = '', lastAt = 0;
  let scanActive = !!C.scanActive;

  /* ------------------------------------------------------------------
   * Background layar penuh (responsif)
   * Gambar diperbesar sampai MENUTUP layar, tetapi bagian yang boleh terpotong dibatasi
   * agar panel kiri, judul, dan semua meja tetap terlihat:
   *   kiri ≤ 0,5% · kanan ≤ 6,5% (slogan "…BERDAYA TAHAN" berakhir di ±93%) · atas ≤ 2% · bawah ≤ 10% (pita merah)
   * Bila layar terlalu berbeda dari 16:9, sisa ruang diisi versi blur (body::before).
   * ------------------------------------------------------------------ */
  const CROP = { left: 0.005, right: 0.065, top: 0.02, bottom: 0.10 };
  const stage = $('stage');
  function fitStage() {
    const W = window.innerWidth, H = window.innerHeight;
    const iw = C.imageW, ih = C.imageH;
    const contain = Math.min(W / iw, H / ih);
    const cover = Math.max(W / iw, H / ih);
    const maxByW = W / (iw * (1 - CROP.left - CROP.right));   // batas potong horizontal
    const maxByH = H / (ih * (1 - CROP.top - CROP.bottom));   // batas potong vertikal
    const s = Math.max(contain, Math.min(cover, maxByW, maxByH));
    const sw = iw * s, sh = ih * s;
    // bagi bagian yang terpotong sesuai porsi yang diizinkan (kiri/kanan, atas/bawah)
    const overX = sw - W, overY = sh - H;
    const left = overX > 0 ? -overX * CROP.left / (CROP.left + CROP.right) : (W - sw) / 2;
    const top = overY > 0 ? -overY * CROP.top / (CROP.top + CROP.bottom) : (H - sh) / 2;
    Object.assign(stage.style, { width: sw + 'px', height: sh + 'px', left: left + 'px', top: top + 'px' });
    stage.classList.add('fitted');
  }
  fitStage();
  window.addEventListener('resize', fitStage);
  window.addEventListener('orientationchange', () => setTimeout(fitStage, 200));

  /* ------------------------------------------------------------------
   * Nomor meja dari registrasi → label meja di denah
   *   "40" → 40   "040" → 40   "23A" / "09D" → 23 / 9 (meja + kursi)   "A01" / "A-1" → A1
   * ------------------------------------------------------------------ */
  function tableKey(raw) {
    const s = String(raw || '').toUpperCase().replace(/\s+/g, '');
    if (!s) return null;
    if (C.tables[s]) return s;
    let m = /^([A-Z]+)[-._]?0*(\d+)$/.exec(s);              // A01, A-1
    if (m && C.tables[m[1] + m[2]]) return m[1] + m[2];
    m = /^0*(\d+)[-._]?[A-Z]*$/.exec(s);                    // 40, 040, 23A, 09-D
    if (m && C.tables[m[1]]) return m[1];
    m = /^(?:MEJA|TABLE|NO)[-._]?([A-Z]*)0*(\d+)/.exec(s);  // MEJA 40
    if (m && C.tables[m[1] + m[2]]) return m[1] + m[2];
    return null;
  }

  /* ---------- tampilan ---------- */
  function setStatus(text, kind) {
    const el = $('status');
    el.textContent = text || '';
    el.className = 'status' + (kind ? ' ' + kind : '');
  }

  function fitRow(el, text) {
    el.textContent = text || '';
    el.classList.toggle('small', String(text || '').length > 46);
  }

  function showIdle() {
    current = null;
    $('v-meja').textContent = '';
    $('v-meja').className = '';
    $('idle-hint').hidden = false;
    $('idle-hint').innerHTML = scanActive ? 'Silakan scan QR Code<br>pada ID Card Anda' : 'Pemindaian<br>belum dimulai';
    ['v-nama', 'v-jabatan', 'v-instansi'].forEach((id) => fitRow($(id), ''));
    $('marker').hidden = true;
    btn.hidden = true;           // tombol PRINT hanya muncul setelah data tampil
    btn.classList.remove('busy');
    setStatus('');
  }

  function showData(qr, d) {
    current = { qr, data: d };
    $('idle-hint').hidden = true;
    const meja = d.unassigned ? 'Belum ditentukan' : (d.kursi || '-');
    const v = $('v-meja');
    v.textContent = meja;
    v.className = d.unassigned ? 'pending' : (meja.length > 4 ? 'long' : '');
    fitRow($('v-nama'), d.nama);
    fitRow($('v-jabatan'), d.jabatan);
    fitRow($('v-instansi'), d.instansi);

    const key = d.unassigned ? null : tableKey(d.kursi);
    const mk = $('marker');
    if (key) {
      const [x, y] = C.tables[key];
      mk.style.left = (x / C.imageW * 100) + '%';
      mk.style.top = (y / C.imageH * 100) + '%';
      $('m-disc').textContent = key;
      mk.hidden = false;
      // mulai ulang animasi agar langsung terlihat
      mk.querySelectorAll('span').forEach((s) => { s.style.animation = 'none'; void s.offsetWidth; s.style.animation = ''; });
    } else {
      mk.hidden = true;
    }

    btn.hidden = !!d.unassigned; // meja belum ditentukan → tidak ada yang bisa dicetak
    if (d.unassigned) setStatus('Meja Anda belum ditentukan. Silakan hubungi petugas registrasi.', 'err');
    else if (!key) setStatus('Meja ' + d.kursi + ' tidak ditemukan di denah. Silakan tekan PRINT atau hubungi petugas.', '');
    else setStatus('Silakan tekan PRINT untuk mencetak tiket meja Anda.', '');
    scheduleReset();
  }

  function scheduleReset() {
    clearTimeout(resetTimer);
    resetTimer = setTimeout(showIdle, C.holdSeconds * 1000);
  }

  /* ---------- scan ---------- */
  async function handleScan(raw, source) {
    const code = String(raw || '').trim();
    if (!code || busy) return;
    const now = Date.now();
    if (code === lastCode) {
      if (source === 'camera') {
        // kartu masih di depan kamera → jangan diproses berulang selama QR masih terlihat
        const held = now - lastAt < Math.max(C.cooldownSeconds, 8) * 1000;
        lastAt = now;
        if (held) return;
      } else if (now - lastAt < C.cooldownSeconds * 1000) {
        return;
      }
    }
    lastCode = code; lastAt = now;
    if (!scanActive) { showIdle(); return; }

    busy = true;
    setStatus('Mengambil data…');
    try {
      const res = await fetch(C.lookupUrl, { method: 'POST', body: new URLSearchParams({ qr: code, print: '0' }), cache: 'no-store' });
      const json = await res.json().catch(() => ({ ok: false, message: 'Respons server tidak valid.' }));
      if (json.inactive) { scanActive = false; showIdle(); return; }
      if (!json.ok) throw new Error(json.message || 'Data tidak ditemukan.');
      showData(code, json.data);
    } catch (e) {
      showIdle();
      setStatus(e.message || String(e), 'err');
      beep(220, 0.35);
      scheduleReset();
    } finally {
      busy = false;
      focusInput();
    }
  }

  /* ---------- PRINT ---------- */
  btn.addEventListener('click', async () => {
    if (!current || busy || btn.hidden) return;
    busy = true;
    btn.classList.add('busy');
    setStatus('Mencetak…');
    clearTimeout(resetTimer);
    try {
      const station = window.Station ? Station.get() : '';
      const res = await fetch(C.printUrl, { method: 'POST', body: new URLSearchParams({ qr: current.qr, station }), cache: 'no-store' });
      const json = await res.json().catch(() => ({ ok: false, message: 'Respons server tidak valid.' }));
      if (!json.ok) throw new Error(json.message || 'Gagal mencetak.');

      if (json.print_mode === 'cable') {
        await printBrowser(json.data);
      } else if (json.print_mode === 'webbluetooth') {
        if (!window.BtPrinter) throw new Error('Modul printer Bluetooth tidak termuat.');
        if (!BtPrinter.status().connected) {
          try { await BtPrinter.reconnect(); } catch (_) {}
          if (!BtPrinter.status().connected) await BtPrinter.pair(); // klik PRINT = gestur pengguna → dialog pilih printer
        }
        await BtPrinter.print(json.escpos);
      } else if (json.print_mode === 'agent') {
        if (json.printed === false) throw new Error(json.print_error);
        const r = await waitJob(json.job_id);
        if (r.status !== 'done') throw new Error(r.error || 'Printer tidak merespons.');
        if (r.rerouted && r.station) {
          // printer stasiun ini mati → tiket keluar di stasiun lain
          setStatus('Tiket tercetak di printer stasiun ' + r.station.toUpperCase() + '. Silakan ambil di sana.', 'ok');
          return;
        }
      } else if (json.printed === false) {
        throw new Error(json.print_error || 'Gagal mencetak.');
      }
      setStatus(json.reprint > 0 ? `Tiket dicetak ulang (sudah ${json.reprint}x sebelumnya). Silakan ambil tiket Anda.` : 'Tiket tercetak. Silakan ambil tiket Anda.', 'ok');
    } catch (e) {
      if (e && e.name === 'NotFoundError') setStatus('Pemilihan printer dibatalkan.', 'err');
      else setStatus('Gagal cetak: ' + (e.message || e), 'err');
      beep(220, 0.35);
    } finally {
      busy = false;
      btn.classList.remove('busy');
      scheduleReset();
      focusInput();
    }
  });

  function printBrowser(d) {
    $('t-kursi').textContent = d.kursi || '-';
    $('t-kursi').className = 't-seat' + (String(d.kursi || '').length > 6 ? ' len-l' : String(d.kursi || '').length > 4 ? ' len-m' : '');
    $('t-nama').textContent = d.nama || '';
    $('t-nama').className = 't-name' + (String(d.nama || '').length > 34 ? ' len-l' : String(d.nama || '').length > 22 ? ' len-m' : '');
    $('t-jabatan').textContent = d.jabatan || '';
    $('t-instansi').textContent = d.instansi || '';
    $('t-id').textContent = d.id || '';
    $('t-time').textContent = new Date().toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
    return new Promise((ok) => requestAnimationFrame(() => setTimeout(() => { window.print(); ok(); }, 120)));
  }

  async function waitJob(id) {
    const until = Date.now() + 25000;
    while (Date.now() < until) {
      try {
        const j = await (await fetch(C.jobUrl + '?id=' + encodeURIComponent(id), { cache: 'no-store' })).json();
        if (j.status === 'done' || j.status === 'failed') return j;
      } catch (_) {}
      await new Promise((r) => setTimeout(r, 800));
    }
    return { status: 'failed', error: 'Print Agent tidak merespons.' };
  }

  /* ---------- input scanner (keyboard wedge) ---------- */
  function focusInput() { if (document.activeElement !== input) input.focus({ preventScroll: true }); }
  $('scan-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const v = input.value;
    input.value = '';
    handleScan(v);
  });
  input.addEventListener('blur', () => setTimeout(focusInput, 100));
  document.addEventListener('click', (e) => { if (!e.target.closest('button')) focusInput(); });
  // cadangan: scanner tetap terbaca walau input tidak fokus (mis. di tablet)
  let buf = '', bufTimer = null;
  document.addEventListener('keydown', (e) => {
    if (e.target === input) return;
    if (e.key === 'Enter') { if (buf) handleScan(buf); buf = ''; }
    else if (e.key.length === 1) buf += e.key;
    clearTimeout(bufTimer); bufTimer = setTimeout(() => { buf = ''; }, 1000);
  });
  const isTouch = navigator.maxTouchPoints > 0 && matchMedia('(any-pointer: coarse)').matches;
  if (isTouch) {
    input.setAttribute('inputmode', 'none'); // keyboard layar tidak muncul
    // menu bawah: ketuk area bawah layar untuk menampilkan
    const bottom = $('bottom');
    let menuTimer = null;
    document.addEventListener('touchstart', (e) => {
      if (e.touches[0].clientY > window.innerHeight - 110 || bottom.contains(e.target)) {
        bottom.classList.add('show');
        clearTimeout(menuTimer);
        menuTimer = setTimeout(() => bottom.classList.remove('show'), 5000);
      }
    }, { passive: true });
  }

  /* ---------- kamera (opsional, tombol "Kamera" di menu bawah) ---------- */
  const btnCam = $('btn-camera');
  let cam = null;
  async function startCamera() {
    if (cam || !window.Html5Qrcode) return;
    $('camera-wrap').hidden = false;
    if (btnCam) btnCam.classList.add('active');
    cam = new Html5Qrcode('camera-box');
    try {
      await cam.start({ facingMode: C.cameraFacing }, { fps: 10, qrbox: { width: 200, height: 200 } },
        (text) => handleScan(text, 'camera'), () => {});
    } catch (err) {
      cam = null;
      $('camera-wrap').hidden = true;
      if (btnCam) btnCam.classList.remove('active');
      setStatus('Kamera tidak bisa dibuka' + (window.isSecureContext ? '' : ' (kamera butuh HTTPS)') + ': ' + err, 'err');
    }
  }
  async function stopCamera() {
    if (!cam) return;
    await cam.stop().catch(() => {});
    cam = null;
    $('camera-wrap').hidden = true;
    if (btnCam) btnCam.classList.remove('active');
  }
  if (btnCam && window.Html5Qrcode) {
    btnCam.addEventListener('click', () => (cam ? stopCamera() : startCamera()));
    if (C.cameraAutostart === true || (C.cameraAutostart === 'touch' && isTouch)) startCamera();
  } else if (btnCam) {
    btnCam.hidden = true;
  }

  /* ---------- indikator koneksi ---------- */
  function net() { $('status-dot').classList.toggle('offline', !navigator.onLine); }
  window.addEventListener('online', net);
  window.addEventListener('offline', net);
  net();

  /* ---------- status server (saklar scan, mode printer) ---------- */
  // pilihan stasiun cetak (mode Print Agent)
  const stationSel = $('station-sel');
  if (window.Station && stationSel) Station.bind(stationSel, () => focusInput());

  async function pollStatus() {
    try {
      const s = await (await fetch(C.statusUrl, { cache: 'no-store' })).json();
      if (window.Station && stationSel && Array.isArray(s.stations)) Station.fill(stationSel, s.stations);
      if (s.print_mode && s.print_mode !== C.printMode && !busy) { location.reload(); return; }
      if (typeof s.scan_active === 'boolean' && s.scan_active !== scanActive) {
        scanActive = s.scan_active;
        if (!current) showIdle();
      }
    } catch (_) {}
  }
  setInterval(pollStatus, 5000);
  pollStatus();

  /* ---------- ketuk logo BI 3x → Pengaturan ---------- */
  let taps = 0, tapTimer = null;
  $('hot-logo').addEventListener('click', () => {
    taps++; clearTimeout(tapTimer);
    if (taps >= 3) { location.href = 'settings.php'; return; }
    tapTimer = setTimeout(() => { taps = 0; }, 1200);
  });

  function beep(freq, dur) {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = freq; o.connect(g); g.connect(ctx.destination); g.gain.value = 0.15;
      o.start(); o.stop(ctx.currentTime + dur);
    } catch (_) {}
  }

  // layar tetap menyala di tablet
  async function keepAwake() { try { if ('wakeLock' in navigator && document.visibilityState === 'visible') await navigator.wakeLock.request('screen'); } catch (_) {} }
  document.addEventListener('visibilitychange', keepAwake);
  keepAwake();

  showIdle();
  focusInput();

  // pengujian: seating.php?scan=PTBI-0001  /  seating.php?demo=40 (tanpa server)
  const p = new URLSearchParams(location.search);
  if (p.get('scan')) handleScan(p.get('scan'));
  if (p.get('demo')) showData('DEMO', { id: 'DEMO', kursi: p.get('demo'), nama: 'Prof. Dr. H. Muhammad Syafii Antonio, M.Ec', jabatan: 'Rektor', instansi: 'Tazkia University' });
})();
