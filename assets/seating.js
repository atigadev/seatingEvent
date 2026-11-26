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
    fitVideoFrame(sw, sh, left, top, W, H);
  }
  /* Frame video = frame denah yang TERLIHAT di layar (sisi kanan/bawah latar bisa terpotong di layar
     non-16:9), lalu layar 16:9 diletakkan di dalamnya dengan jarak tetap ke tepi → jarak kanan selalu ada. */
  function fitVideoFrame(sw, sh, left, top, W, H) {
    const frame = $('video-frame'), screen = frame && frame.querySelector('.vf-screen');
    if (!screen) return;
    const fx = sw * 0.297, fy = sh * 0.2075;
    const fw = Math.min(sw * 0.703, (W - left) - fx);      // batas kanan layar (dalam koordinat stage)
    const fh = Math.min(sh * 0.703, (H - top) - fy);       // batas bawah layar
    const gL = sw * 0.011, gR = sw * 0.022, gV = sh * 0.016;  // jarak: kiri, kanan (lebih lega), atas/bawah
    const aw = fw - gL - gR, ah = fh - gV * 2;
    const w = Math.max(0, Math.min(aw, ah * 16 / 9)), h = w * 9 / 16;
    Object.assign(frame.style, { width: fw + 'px', height: fh + 'px' });
    Object.assign(screen.style, {
      width: w + 'px', height: h + 'px',
      left: (gL + (aw - w) / 2) + 'px', top: ((fh - h) / 2) + 'px',
    });
  }
  fitStage();
  window.addEventListener('resize', fitStage);
  window.addEventListener('orientationchange', () => setTimeout(fitStage, 200));

  /* ------------------------------------------------------------------
   * Video idle di frame denah
   * - tampil setelah {delay} detik tanpa scan (hanya saat layar idle / belum ada data)
   * - beberapa video diputar bergantian; satu video → diulang
   * - ada scan / sentuhan → disembunyikan & dijeda; idle lagi → dilanjutkan dari posisi terakhir
   * ------------------------------------------------------------------ */
  const IdleVideo = (function () {
    const frame = $('video-frame');
    const vid = $('idle-video');
    const ytWrap = $('yt-wrap');
    let cfg = C.idleVideo || { enabled: false, items: [], delay: 30, muted: true, version: '' };
    if (!Array.isArray(cfg.items)) cfg.items = [];
    let idx = 0, loaded = -1, timer = null, showing = false, idle = false, errors = 0;

    /* ---- YouTube IFrame API (dimuat hanya bila ada video YouTube) ---- */
    let ytApi = null, yt = null, ytReady = false, ytCheck = null, ytReveal = null;
    function loadYTApi() {
      if (ytApi) return ytApi;
      ytApi = new Promise((resolve, reject) => {
        if (window.YT && window.YT.Player) { resolve(); return; }
        const t = setTimeout(() => { ytApi = null; reject(new Error('timeout')); }, 15000);
        const prev = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => { clearTimeout(t); if (prev) prev(); resolve(); };
        const s = document.createElement('script');
        s.src = 'https://www.youtube.com/iframe_api';
        s.async = true;
        s.onerror = () => { clearTimeout(t); s.remove(); ytApi = null; reject(new Error('offline')); };
        document.head.appendChild(s);
      });
      return ytApi;
    }
    function ytMute() { if (!yt || !ytReady) return; if (cfg.muted) yt.mute(); else yt.unMute(); }
    function ytLoad(id) {
      if (yt && ytReady) {
        ytMute();
        if (showing) yt.loadVideoById(id); else yt.cueVideoById(id);
        return;
      }
      if (yt) return;  // pemutar sedang dibuat → onReady memuat video yang sedang aktif
      loadYTApi().then(() => {
        yt = new YT.Player('yt-player', {
          host: 'https://www.youtube-nocookie.com',
          width: '100%', height: '100%',
          videoId: id,
          playerVars: {
            autoplay: 0, controls: 0, disablekb: 1, fs: 0, rel: 0, playsinline: 1,
            iv_load_policy: 3, modestbranding: 1, mute: cfg.muted ? 1 : 0,
            origin: /^https?:/.test(location.protocol) ? location.origin : undefined,
          },
          events: {
            onReady() {
              ytReady = true;
              try { const f = yt.getIframe(); f.setAttribute('tabindex', '-1'); f.setAttribute('aria-hidden', 'true'); } catch (e) {}
              ytMute();
              const it = cfg.items[idx];
              if (it && it.type === 'youtube' && loaded === idx) {
                if (showing) { yt.loadVideoById(it.id); ytVerify(); } else yt.cueVideoById(it.id);
              }
            },
            onStateChange(ev) {
              if (ev.data === YT.PlayerState.PLAYING) {
                errors = 0;
                // tunggu animasi ikon putar/jeda bawaan YouTube selesai sebelum video diperlihatkan
                clearTimeout(ytReveal);
                ytReveal = setTimeout(() => ytWrap.classList.add('playing'), 900);
              }
              if (ev.data === YT.PlayerState.ENDED) next();
            },
            // 2 = ID salah, 5 = HTML5 error, 100 = video dihapus/privat, 101/150 = tidak boleh di-embed
            onError() { skip(); },
          },
        });
      }).catch(() => skip());   // tidak ada internet / YouTube diblokir → lewati
    }
    function ytPlay() {
      if (!yt || !ytReady) return;   // akan diputar di onReady
      ytMute();
      const st = yt.getPlayerState();
      if (st !== YT.PlayerState.PLAYING && st !== YT.PlayerState.BUFFERING) yt.playVideo();   // hindari pemicu ganda
      ytVerify();
    }
    function ytVerify() {
      // autoplay bersuara bisa ditolak browser → ulangi tanpa suara
      clearTimeout(ytCheck);
      ytCheck = setTimeout(() => {
        if (!showing || !yt || cur().type !== 'youtube') return;
        const st = yt.getPlayerState();
        if (st !== YT.PlayerState.PLAYING && st !== YT.PlayerState.BUFFERING) { yt.mute(); yt.playVideo(); }
      }, 2500);
    }

    /* ---- daftar putar campuran: file unggahan + YouTube ---- */
    function cur() { return cfg.items[idx] || {}; }
    function apply() {
      vid.muted = !!cfg.muted;
      vid.loop = cfg.items.length === 1 && cur().type === 'file';
      ytMute();
    }
    function load(i) {
      const n = cfg.items.length;
      if (!n) return;
      idx = ((i % n) + n) % n;
      loaded = idx;
      const it = cfg.items[idx];
      apply();
      if (it.type === 'youtube') {
        vid.pause(); vid.removeAttribute('src'); vid.load();
        vid.hidden = true; ytWrap.hidden = false;
        ytWrap.classList.remove('playing');   // layar loading/thumbnail YouTube tidak diperlihatkan
        ytLoad(it.id);
      } else {
        clearTimeout(ytCheck);
        if (yt && ytReady) yt.stopVideo();
        ytWrap.hidden = true; vid.hidden = false;
        vid.src = it.src;
        vid.load();
      }
    }
    function play() {
      if (cur().type === 'youtube') { ytPlay(); return; }
      const p = vid.play();
      if (p && p.catch) p.catch(() => { vid.muted = true; vid.play().catch(() => {}); });
    }
    function pause() {
      clearTimeout(ytCheck); clearTimeout(ytReveal);
      ytWrap.classList.remove('playing');   // saat dilanjutkan, tampil lagi setelah ikon putar YouTube hilang
      vid.pause();
      if (yt && ytReady) { try { yt.pauseVideo(); } catch (e) {} }
    }
    function next() {
      if (cfg.items.length > 1) { load(idx + 1); if (showing) play(); return; }
      // satu video YouTube → ulangi dari awal (file biasa memakai vid.loop)
      if (cur().type === 'youtube' && yt && ytReady) { yt.seekTo(0, true); if (showing) yt.playVideo(); }
    }
    function skip() {
      // video rusak / hilang / tidak bisa diputar → lewati (berhenti bila semua gagal)
      if (++errors >= cfg.items.length) { hide(); return; }
      load(idx + 1);
      if (showing) play();
    }
    function show() {
      if (!cfg.enabled || !cfg.items.length || !idle || errors >= cfg.items.length) return;
      showing = true;
      if (loaded < 0) load(0);
      frame.classList.add('show');
      play();
    }
    function hide() {
      showing = false;
      frame.classList.remove('show');
      pause();
    }
    function arm() {
      clearTimeout(timer);
      if (cfg.enabled && cfg.items.length && idle) timer = setTimeout(show, cfg.delay * 1000);
    }
    vid.addEventListener('ended', () => { if (cfg.items.length > 1) next(); });
    vid.addEventListener('playing', () => { errors = 0; });
    vid.addEventListener('error', () => { if (vid.getAttribute('src')) skip(); });
    apply();

    return {
      setIdle(v) { idle = v; if (v) { errors = 0; arm(); } else { clearTimeout(timer); hide(); } },
      activity() { if (!idle) return; hide(); arm(); },   // sentuhan / ketikan saat idle
      configure(n) {
        if (!n || n.version === cfg.version) return;
        if (!Array.isArray(n.items)) n.items = [];
        const itemsChanged = JSON.stringify(n.items) !== JSON.stringify(cfg.items);
        cfg = n; errors = 0;
        if (itemsChanged) {
          hide();
          vid.removeAttribute('src'); vid.load();
          if (yt && ytReady) yt.stopVideo();
          idx = 0; loaded = -1;
        }
        apply();
        if (!cfg.enabled) { clearTimeout(timer); hide(); } else if (!showing) arm();
      },
    };
  })();
  document.addEventListener('pointerdown', () => IdleVideo.activity(), { passive: true });

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

  /* Nomor meja saja (tanpa huruf kursi), angka minimal 2 digit:
     "04E" → "04", "4" → "04", "40D" → "40", "A1"/"A01" → "A01" (meja deret A) */
  const pad2 = (n) => String(parseInt(n, 10)).padStart(2, '0');
  function tableOnly(raw) {
    const s = String(tableKey(raw) || raw || '').toUpperCase().replace(/\s+/g, '');
    let m = /^0*(\d+)[-._]?[A-Z]*$/.exec(s);                 // 04E / 4 → 04
    if (m) return pad2(m[1]);
    m = /^([A-Z]+)[-._]?0*(\d+)[A-Z]?$/.exec(s);             // A1 / A01 → A01
    if (m) return m[1] + pad2(m[2]);
    return String(raw || '');
  }

  /* ---------- tampilan ---------- */
  function setStatus(text, kind) {
    const el = $('status');
    el.textContent = text || '';
    el.className = 'status' + (kind ? ' ' + kind : '');
  }

  /* ------------------------------------------------------------------
   * Penjaga teks: memastikan isi TIDAK PERNAH melewati batas kotak.
   *  1) perkecil huruf bertahap sampai muat 1 baris (min. 72% ukuran asli)
   *  2) bila masih tidak muat → 2 baris, perkecil lagi sampai tinggi muat (min. 55%)
   *  3) batas akhir → dipotong "…" di dalam kotak
   * Ukuran dasar berasal dari CSS (cqw), jadi dihitung ulang saat layar berubah ukuran.
   * ------------------------------------------------------------------ */
  function fitText(el, opts) {
    const box = el.parentElement;
    el.style.fontSize = '';
    el.classList.remove('wrap2');
    if (!el.textContent) return;
    const cs = getComputedStyle(box);
    const availW = box.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - 2;
    const availH = box.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom) - 1;
    const base = parseFloat(getComputedStyle(el).fontSize);
    let size = base;
    const set = (s) => { size = s; el.style.fontSize = s + 'px'; };

    // 1) satu baris
    while (el.scrollWidth > availW && size > base * (opts.minOne || 0.72)) set(size * 0.95);
    if (el.scrollWidth <= availW) return;
    if (!opts.allowWrap) { set(Math.max(size, base * (opts.minOne || 0.72))); return; } // sisa: "…" (CSS)

    // 2) dua baris
    el.classList.add('wrap2');
    set(base * 0.9);
    while ((el.scrollHeight > availH || el.scrollWidth > availW) && size > base * 0.55) set(size * 0.95);
  }

  function fitRow(el, text) {
    el.textContent = text || '';
    fitText(el, { allowWrap: true });
  }

  function fitAllText() {
    ['v-nama', 'v-jabatan', 'v-instansi'].forEach((id) => fitText($(id), { allowWrap: true }));
    fitText($('v-meja'), { allowWrap: false, minOne: 0.35 });
  }
  window.addEventListener('resize', () => requestAnimationFrame(fitAllText));
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitAllText);

  function showIdle() {
    current = null;
    $('v-meja').textContent = '';
    $('v-meja').className = '';
    $('idle-hint').hidden = false;
    $('box-num').classList.add('is-idle');
    $('idle-hint').classList.toggle('paused', !scanActive);
    $('ih-title').textContent = scanActive ? 'Scan QR Code' : 'Pemindaian Belum Dimulai';
    $('ih-sub').textContent = scanActive ? 'pada ID Card Anda' : 'Mohon menunggu sebentar';
    $('ih-tip-text').textContent = scanActive ? (cam ? 'Arahkan ke kamera' : 'Arahkan ke scanner') : 'Segera dimulai';
    ['v-nama', 'v-jabatan', 'v-instansi'].forEach((id) => fitRow($(id), ''));
    $('marker').hidden = true;
    btn.hidden = true;           // tombol PRINT hanya muncul setelah data tampil
    IdleVideo.setIdle(true);     // hitung mundur video idle dimulai
    btn.classList.remove('busy');
    setStatus('');
  }

  function showData(qr, d) {
    current = { qr, data: d };
    IdleVideo.setIdle(false);    // data tampil → video disembunyikan & dijeda
    $('idle-hint').hidden = true;
    $('box-num').classList.remove('is-idle');
    const meja = d.unassigned ? 'Belum ditentukan' : (tableOnly(d.kursi) || '-');
    const v = $('v-meja');
    v.textContent = meja;
    v.className = d.unassigned ? 'pending' : (meja.length > 4 ? 'long' : '');
    fitText(v, { allowWrap: false, minOne: 0.35 });   // nomor meja tidak boleh keluar kotak
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

  // kembali ke tampilan awal setelah {sec} detik (bawaan: lama data tampil sebelum menekan PRINT)
  function scheduleReset(sec) {
    clearTimeout(resetTimer);
    resetTimer = setTimeout(showIdle, (sec || C.holdSeconds) * 1000);
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
    IdleVideo.setIdle(false);    // ada scan → video langsung disembunyikan & dijeda
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
      scheduleReset(C.errorSeconds);
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
    let printed = false;
    try {
      const station = window.Station ? Station.get() : '';
      const res = await fetch(C.printUrl, { method: 'POST', body: new URLSearchParams({ qr: current.qr, station }), cache: 'no-store' });
      const json = await res.json().catch(() => ({ ok: false, message: 'Respons server tidak valid.' }));
      if (!json.ok) throw new Error(json.message || 'Gagal mencetak.');

      if (json.print_mode === 'cable') {
        if (json.ticket && json.ticket.png && window.TicketPrint) await TicketPrint.print(json.ticket, 1);
        else await printBrowser(json.data);
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
          // printer stasiun ini mati → tiket keluar di stasiun lain (beri waktu baca pesan)
          printed = true;
          setStatus('Tiket tercetak di printer stasiun ' + r.station.toUpperCase() + '. Silakan ambil di sana.', 'ok');
          return;
        }
      } else if (json.printed === false) {
        throw new Error(json.print_error || 'Gagal mencetak.');
      }
      printed = true;
      setStatus(json.reprint > 0 ? `Tiket dicetak ulang (sudah ${json.reprint}x sebelumnya). Silakan ambil tiket Anda.` : 'Tiket tercetak. Silakan ambil tiket Anda.', 'ok');
    } catch (e) {
      if (e && e.name === 'NotFoundError') setStatus('Pemilihan printer dibatalkan.', 'err');
      else setStatus('Gagal cetak: ' + (e.message || e), 'err');
      beep(220, 0.35);
    } finally {
      busy = false;
      btn.classList.remove('busy');
      // tercetak → kembali ke awal lebih cepat; gagal → tetap tampil agar bisa tekan PRINT lagi
      scheduleReset(printed ? C.afterPrintSeconds : C.holdSeconds);
      focusInput();
    }
  });

  function printBrowser(d) {
    $('t-kursi').textContent = tableOnly(d.kursi) || '-';   // nomor meja 2 digit, mis. 04
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
      if (!current) showIdle(); // petunjuk "Arahkan ke kamera"
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
    if (!current) showIdle(); // petunjuk kembali "Arahkan ke scanner"
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
      // halaman awal diganti ke Kiosk Scan → bila halaman ini tampil di alamat utama (bukan seating.php langsung), ikut berpindah
      if (s.start_page === 'index' && !/seating\.php$/i.test(location.pathname) && !busy && !current) { location.reload(); return; }
      if (s.idle_video) IdleVideo.configure(s.idle_video);   // pengaturan video diubah dari Pengaturan
      if (s.timing) {   // Pengaturan › Waktu Tampil (berlaku untuk tampilan berikutnya)
        C.holdSeconds = s.timing.seating_display_seconds || C.holdSeconds;
        C.afterPrintSeconds = s.timing.seating_after_print_seconds || C.afterPrintSeconds;
        C.errorSeconds = s.timing.error_display_seconds || C.errorSeconds;
        if (typeof s.timing.duplicate_cooldown_seconds === 'number') C.cooldownSeconds = s.timing.duplicate_cooldown_seconds;
      }
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
  if (p.get('demo')) showData('DEMO', { id: 'DEMO', kursi: p.get('demo'),
    nama: p.get('nama') || 'Prof. Dr. H. Muhammad Syafii Antonio, M.Ec',
    jabatan: p.get('jabatan') || 'Rektor', instansi: p.get('instansi') || 'Tazkia University' });
})();
