/* Seat Kiosk — alur: scan -> lookup API -> isi tiket -> print (silent) -> kembali scan */
(function () {
  'use strict';

  const C = window.KIOSK;
  const $ = (id) => document.getElementById(id);
  const screen = $('screen');
  const input = $('scan-input');

  let busy = false;          // sedang mengambil data / mencetak
  let resetTimer = null;
  let lastCode = '';
  let lastCodeAt = 0;
  let pendingEscpos = null;  // tiket yang gagal dicetak (mode Bluetooth Chrome), untuk cetak ulang
  let scanActive = !!C.scanActive;  // saklar pemindaian dari Pengaturan

  // layar diam: siap scan bila aktif, atau layar standby bila belum dimulai
  function idleState() { return scanActive ? 'ready' : 'standby'; }

  /* ---------- State tampilan ---------- */
  function setState(name) {
    screen.className = 'state-' + name;
  }

  function scheduleReset(seconds) {
    clearTimeout(resetTimer);
    resetTimer = setTimeout(() => { pendingEscpos = null; clearRoute(); setState(idleState()); focusInput(); }, seconds * 1000);
  }

  /* ---------- Wayfinding: rute kiosk → meja (logika Satumap) ---------- */
  let routeView = null;
  let routeShown = false;
  let wayfindingOn = !!C.wayfinding;  // saklar wayfinding dari Pengaturan
  const holdSeconds = () => (routeShown ? Math.max(C.mapSeconds, C.resultSeconds) : C.resultSeconds);

  function clearRoute() {
    if (routeView) routeView.destroy();
    routeView = null;
    routeShown = false;
    $('panel-success').classList.remove('has-map');
    $('wf-box').hidden = true;
    $('wf-map').innerHTML = '';
  }

  async function showRoute(d) {
    clearRoute();
    if (!wayfindingOn || !window.Wayfinding || !d.kursi) return;
    if (venueLoading) await venueLoading; // scan tepat setelah kiosk dibuka: tunggu denah selesai dimuat
    const data = Wayfinding.data;
    if (!data || $('r-kursi').textContent !== d.kursi) return; // tidak ada denah / sudah diganti scan lain
    const r = Wayfinding.plan(data, d.kursi);
    if (r.status !== 'ok') return; // meja tidak ada di denah / belum ada rute → tampil seperti biasa
    $('wf-table').textContent = r.booth.code + (r.booth.name ? ' · ' + r.booth.name : '');
    $('wf-stats').textContent = `± ${r.meters} m · ± ${r.minutes} menit jalan kaki`;
    $('wf-steps').innerHTML = r.steps.map((s, i) =>
      `<li class="${s.icon}" style="--i:${i}"><span class="n">${i + 1}</span><span>${s.text}</span></li>`).join('');
    $('panel-success').classList.add('has-map');
    $('wf-box').hidden = false;
    routeShown = true;
    // render setelah layout terbentuk agar ukuran peta benar
    requestAnimationFrame(() => { routeView = Wayfinding.render($('wf-map'), data, r); });
    if (!busy) scheduleReset(holdSeconds()); // rute muncul belakangan → perpanjang waktu tampil
  }

  let venueVersion = undefined;
  let venueLoading = null;
  function loadVenue(version) {
    if (!wayfindingOn || !window.Wayfinding) return null;
    venueLoading = (async () => {
      try {
        const data = await Wayfinding.load(C.venueUrl);
        venueVersion = version !== undefined ? version : (data && data.meta ? data.meta.version : null);
        fillKioskSelect(data);
        // muat gambar denah lebih awal (di latar belakang) agar peta langsung tampil utuh saat scan pertama
        if (data && data.map.image) {
          const im = new Image();
          im.src = data.map.image;
          if (im.decode) im.decode().catch(() => {});
        }
      } catch (_) { /* coba lagi pada pemeriksaan status berikutnya */ }
      venueLoading = null;
    })();
    return venueLoading;
  }

  // pilihan lokasi kiosk (titik "Anda di sini") untuk perangkat ini
  function fillKioskSelect(data) {
    const sel = $('kiosk-loc');
    const ks = data ? data.kiosks || [] : [];
    sel.hidden = ks.length < 2;
    if (ks.length < 2) return;
    const cur = Wayfinding.currentKiosk(data);
    sel.innerHTML = ks.map((k) => `<option value="${k.id}">📍 ${String(k.name || k.id).replace(/</g, '&lt;')}</option>`).join('');
    sel.value = cur ? cur.id : ks[0].id;
  }
  $('kiosk-loc').addEventListener('change', (e) => { Wayfinding.setKioskId(e.target.value); focusInput(); });

  function focusInput() {
    if (document.activeElement !== input) input.focus({ preventScroll: true });
  }

  /* ---------- Proses scan ---------- */
  // QR berisi link (https://registrasi.../seat/EI110446) → kirim ID-nya saja.
  // Parameter berisi alamat https:// domain lain dianggap serangan "Remote File Inclusion" oleh firewall
  // hosting (ModSecurity) dan bisa membuat IP kiosk diblokir.
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

  async function handleScan(raw, source) {
    const code = qrId(raw);
    if (!code || busy || !scanActive) return;  // scan diabaikan bila belum dimulai

    const now = Date.now();
    if (code === lastCode) {
      if (source === 'camera') {
        // kamera terus membaca selama kartu masih di depan lensa: jeda diperpanjang selama QR masih terlihat
        const held = now - lastCodeAt < Math.max(C.cooldownSeconds, 8) * 1000;
        lastCodeAt = now;
        if (held) return;
      } else if (now - lastCodeAt < C.cooldownSeconds * 1000) {
        return; // double-read scanner
      }
    }
    lastCode = code;
    lastCodeAt = now;

    busy = true;
    pendingEscpos = null;
    clearTimeout(resetTimer);
    $('loading-id').textContent = code;
    setState('loading');

    try {
      const body = new URLSearchParams({ qr: code, station: window.Station ? Station.get() : '' });
      const res = await fetch(C.lookupUrl, { method: 'POST', body, cache: 'no-store' });
      const json = await res.json().catch(() => ({ ok: false, message: 'Respons server tidak valid.' }));
      if (json.inactive) { applyStatus({ scan_active: false }); setState(idleState()); return; } // dihentikan dari Pengaturan
      if (!json.ok) throw new Error(json.message || 'Data tidak ditemukan.');

      showResult(json.data, json.reprint || 0);
      if (json.unassigned) {
        // data peserta ada, tetapi meja belum ditentukan panitia → tidak dicetak
        $('r-status').textContent = (json.data && json.data.message) || 'Meja Anda belum ditentukan. Silakan hubungi petugas registrasi.';
        $('r-status').classList.add('fail');
        beep(440, 0.25);
        scheduleReset(Math.max(C.resultSeconds, 8));
        return;
      }
      if (json.print_mode === 'agent') {
        // Print Agent di venue yang mencetak — pantau status job
        if (json.printed === false) return printFailed(json.print_error);
        if (json.job_id) {
          $('r-status').textContent = 'Mengirim ke printer…';
          const r = await waitJob(json.job_id);
          if (r.status !== 'done') return printFailed(r.error || 'Printer tidak merespons.');
          if (r.rerouted && r.station) {
            // printer stasiun ini mati → tiket keluar di stasiun lain
            $('r-status').textContent = 'Silakan ambil tiket Anda di printer stasiun ' + r.station.toUpperCase();
            scheduleReset(holdSeconds() + 4);
            return;
          }
        }
      } else if (json.print_mode === 'bluetooth' || json.print_mode === 'brother') {
        // server sudah mencetak (ESC/POS via COM/LAN, atau Brother QL via Bluetooth/Wi-Fi)
        if (json.printed === false) return printFailed(json.print_error);
      } else if (json.print_mode === 'webbluetooth') {
        // browser mengirim langsung ke printer BLE
        if (C.autoPrint) {
          try {
            await window.BtPrinter.print(json.escpos);
          } catch (e) {
            pendingEscpos = json.escpos;
            return printFailed(e.message || String(e), true);
          }
        }
      } else if (C.autoPrint) {
        await printTicket(json.data, json.ticket);
      }
      $('r-status').textContent = json.reprint > 0
        ? 'Tiket dicetak ulang (sudah dicetak ' + json.reprint + 'x sebelumnya)'
        : 'Silakan ambil tiket Anda';
      scheduleReset(holdSeconds());
    } catch (err) {
      showError(err.message || String(err));
    } finally {
      busy = false;
      focusInput();
    }
  }

  function showResult(d) {
    $('r-kursi').textContent = d.kursi || 'Belum ditentukan';
    $('r-kursi').classList.toggle('pending', !d.kursi);
    $('r-nama').textContent = d.nama || '-';
    $('r-jabatan').textContent = d.jabatan || '';
    $('r-instansi').textContent = d.instansi || '';
    $('r-status').textContent = 'Mencetak…';
    $('r-status').classList.remove('fail');
    if ($('bt-retry')) $('bt-retry').hidden = true;
    setState('success');
    showRoute(d);
  }

  // Pantau job Print Agent sampai selesai / gagal (maks ±25 detik)
  async function waitJob(id) {
    const until = Date.now() + 25000;
    while (Date.now() < until) {
      try {
        const res = await fetch('api/job.php?id=' + encodeURIComponent(id), { cache: 'no-store' });
        const j = await res.json();
        if (j.status === 'done' || j.status === 'failed') return j;
        if (j.status === 'taken') $('r-status').textContent = 'Mencetak…';
      } catch (_) { /* jaringan sesaat terputus — coba lagi */ }
      await new Promise((r) => setTimeout(r, 800));
    }
    return { status: 'failed', error: 'Print Agent tidak merespons. Periksa PC agen di venue.' };
  }

  function printFailed(msg, canRetry) {
    $('r-status').textContent = 'Gagal cetak: ' + msg;
    $('r-status').classList.add('fail');
    if (canRetry && $('bt-retry')) $('bt-retry').hidden = false;
    beep(220, 0.35);
    scheduleReset(holdSeconds() + (canRetry ? 15 : 4));
  }

  function showError(msg) {
    $('e-msg').textContent = msg;
    setState('error');
    beep(220, 0.35);
    scheduleReset(C.errorSeconds);
  }

  /* ---------- Cetak ---------- */
  // kelas ukuran berdasarkan panjang teks agar muat di lebar kertas
  function sizeClass(el, text, steps) {
    el.classList.remove('len-m', 'len-l');
    if (text.length > steps[1]) el.classList.add('len-l');
    else if (text.length > steps[0]) el.classList.add('len-m');
  }

  function printTicket(d, ticket) {
    // Desain Tiket dari server (gambar, ukuran kertas dinamis)
    if (ticket && ticket.png && window.TicketPrint) return TicketPrint.print(ticket, C.copies);
    // cadangan: tiket HTML lama
    // nomor meja 2 digit tanpa huruf kursi: 04E → 04, A1 → A01
    const kursi = String(d.kursi || '').toUpperCase().replace(/\s+/g, '');
    const mNum = /^(\d+)[-._]?[A-Z]*$/.exec(kursi), mAlp = /^([A-Z]+)[-._]?(\d+)[A-Z]?$/.exec(kursi);
    $('t-kursi').textContent = mNum ? String(+mNum[1]).padStart(2, '0') : mAlp ? mAlp[1] + String(+mAlp[2]).padStart(2, '0') : (d.kursi || '-');
    sizeClass($('t-kursi'), d.kursi || '', [4, 6]);
    $('t-nama').textContent = d.nama || '';
    sizeClass($('t-nama'), d.nama || '', [22, 34]);
    $('t-jabatan').textContent = d.jabatan || '';
    $('t-instansi').textContent = d.instansi || '';
    $('t-id').textContent = d.id || '';
    $('t-time').textContent = new Date().toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

    return new Promise((resolve) => {
      // beri waktu browser me-render tiket sebelum print
      requestAnimationFrame(() => setTimeout(() => {
        for (let i = 0; i < C.copies; i++) {
          window.print(); // Dengan Chrome/Edge --kiosk-printing: langsung ke printer default tanpa dialog
        }
        resolve();
      }, 150));
    });
  }

  /* ---------- Input scanner USB (keyboard wedge) ---------- */
  $('scan-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const v = input.value;
    input.value = '';
    handleScan(v);
  });
  // jaga fokus selalu di input agar scanner selalu tertangkap
  document.addEventListener('click', (e) => { if (!e.target.closest('button')) focusInput(); });
  input.addEventListener('blur', () => setTimeout(focusInput, 100));
  window.addEventListener('focus', focusInput);

  /* ---------- Scan via kamera (opsional) ---------- */
  const btnCam = $('btn-camera');
  let cam = null;
  const isTouch = navigator.maxTouchPoints > 0 && matchMedia('(any-pointer: coarse)').matches;

  async function startCamera() {
    if (cam || !window.Html5Qrcode) return;
    const box = $('camera-wrap');
    box.hidden = false;
    if (btnCam) btnCam.classList.add('active');
    $('ready-hint').textContent = 'Arahkan QR Code pada ID Card ke kamera. Nomor kursi akan tercetak otomatis.';
    cam = new Html5Qrcode('camera-box');
    try {
      await cam.start({ facingMode: C.cameraFacing }, { fps: 10, qrbox: { width: 200, height: 200 } },
        (text) => handleScan(text, 'camera'), () => {});
    } catch (err) {
      cam = null; box.hidden = true;
      if (btnCam) btnCam.classList.remove('active');
      const insecure = !window.isSecureContext ? ' (kamera butuh HTTPS)' : '';
      showError('Kamera tidak bisa dibuka' + insecure + ': ' + err);
    }
  }

  async function stopCamera() {
    if (!cam) return;
    await cam.stop().catch(() => {});
    cam = null;
    $('camera-wrap').hidden = true;
    if (btnCam) btnCam.classList.remove('active');
    $('ready-hint').textContent = 'Arahkan QR Code ke scanner. Nomor kursi akan tercetak otomatis.';
  }

  if (btnCam && window.Html5Qrcode) {
    btnCam.addEventListener('click', () => (cam ? stopCamera() : startCamera()));
    if (C.cameraAutostart === true || (C.cameraAutostart === 'touch' && isTouch)) startCamera();
  } else if (btnCam) {
    btnCam.hidden = true;
  }

  /* ---------- Tablet / iPad ---------- */
  if (isTouch) {
    // cegah keyboard layar muncul saat input scanner difokuskan
    input.setAttribute('inputmode', 'none');
    // menu bawah: ketuk area bawah layar untuk menampilkan
    const bottom = document.querySelector('.bottom');
    let menuTimer = null;
    document.addEventListener('touchstart', (e) => {
      if (e.touches[0].clientY > window.innerHeight - 110 || bottom.contains(e.target)) {
        bottom.classList.add('show');
        clearTimeout(menuTimer);
        menuTimer = setTimeout(() => bottom.classList.remove('show'), 5000);
      }
    }, { passive: true });
  }

  // Scanner Bluetooth/USB (keyboard) tetap tertangkap walau input tidak fokus (mis. di iPad)
  let keyBuf = '';
  let keyTimer = null;
  document.addEventListener('keydown', (e) => {
    if (e.target === input || e.target.closest('input, textarea, select')) return;
    if (e.key === 'Enter') {
      if (keyBuf) handleScan(keyBuf);
      keyBuf = '';
    } else if (e.key.length === 1) {
      keyBuf += e.key;
    }
    clearTimeout(keyTimer);
    keyTimer = setTimeout(() => { keyBuf = ''; }, 1000);
  });

  // Layar tetap menyala (Wake Lock) — tablet tidak masuk sleep
  let wakeLock = null;
  async function keepAwake() {
    try {
      if ('wakeLock' in navigator && document.visibilityState === 'visible' && !wakeLock) {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => { wakeLock = null; });
      }
    } catch (_) {}
  }
  document.addEventListener('visibilitychange', keepAwake);
  document.addEventListener('click', keepAwake);
  keepAwake();

  /* ---------- Util ---------- */
  function beep(freq, dur) {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const o = ctx.createOscillator(); const g = ctx.createGain();
      o.frequency.value = freq; o.connect(g); g.connect(ctx.destination);
      g.gain.value = 0.15; o.start(); o.stop(ctx.currentTime + dur);
    } catch (_) {}
  }

  function tick() {
    $('clock').textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  }
  tick(); setInterval(tick, 10000);

  // indikator koneksi
  function net() { $('status-dot').classList.toggle('offline', !navigator.onLine); }
  window.addEventListener('online', net); window.addEventListener('offline', net); net();

  focusInput();

  /* ---------- Saklar scan & teks standby (dipantau dari server) ---------- */
  let cameraWasOn = false;
  // pilihan stasiun cetak (mode Print Agent)
  const stationSel = $('station-sel');
  if (window.Station && stationSel) Station.bind(stationSel, () => focusInput());

  function applyStatus(s) {
    // Halaman awal diganti ke Seating dari Pengaturan → kiosk di alamat utama ikut berpindah (saat layar idle).
    // Tidak berlaku bila halaman ini dibuka khusus (index.php?page=scan / ?kiosk= / ?testprint=).
    if (s.start_page === 'seating' && !/[?&](page|kiosk|testprint)=/.test(location.search)
        && !busy && screen.className === 'state-' + idleState()) { location.reload(); return; }
    if (window.Station && stationSel && Array.isArray(s.stations)) Station.fill(stationSel, s.stations);
    if (s.timing) {   // Pengaturan › Waktu Tampil (berlaku untuk tampilan berikutnya)
      C.resultSeconds = s.timing.result_display_seconds || C.resultSeconds;
      C.mapSeconds = s.timing.map_display_seconds || C.mapSeconds;
      C.errorSeconds = s.timing.error_display_seconds || C.errorSeconds;
      if (typeof s.timing.duplicate_cooldown_seconds === 'number') C.cooldownSeconds = s.timing.duplicate_cooldown_seconds;
    }
    if (s.standby) {
      $('sb-title').textContent = s.standby.title || '';
      $('sb-sub').textContent = s.standby.subtitle || '';
    }
    // saklar wayfinding diubah dari Pengaturan
    if (typeof s.wayfinding === 'boolean' && s.wayfinding !== wayfindingOn) {
      wayfindingOn = s.wayfinding;
      if (!wayfindingOn) {
        if (routeShown) {
          clearRoute();
          if (!busy && screen.className === 'state-success') scheduleReset(C.resultSeconds);
        }
        $('kiosk-loc').hidden = true;
      } else {
        venueVersion = undefined; // muat ulang denah di bawah
      }
    }
    // denah dipublikasikan ulang dari admin → muat versi baru
    if (wayfindingOn && s.venue_version !== undefined && s.venue_version !== venueVersion) loadVenue(s.venue_version);
    if (s.print_mode && s.print_mode !== C.printMode && !busy) {
      location.reload(); // mode printer diganti → muat ulang agar komponen printer sesuai
      return;
    }
    if (typeof s.scan_active !== 'boolean' || s.scan_active === scanActive) return;

    scanActive = s.scan_active;
    if (!scanActive) {
      cameraWasOn = !!cam;
      stopCamera();
      lastCode = ''; // scan pertama setelah dimulai lagi tidak dianggap duplikat
    } else if (cameraWasOn) {
      startCamera();
    }
    // jangan potong layar hasil/eror yang sedang tampil; pindah saat reset berikutnya
    const cur = screen.className.replace('state-', '');
    if (cur === 'ready' || cur === 'standby') setState(idleState());
    focusInput();
  }

  async function pollStatus() {
    try {
      const res = await fetch(C.statusUrl, { cache: 'no-store' });
      if (res.ok) applyStatus(await res.json());
    } catch (_) { /* server sementara tak terjangkau — coba lagi nanti */ }
  }
  setInterval(pollStatus, 3000);
  loadVenue();
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') pollStatus(); });

  // Testing: index.php?scan=PTBI-0001 langsung memproses ID tsb
  const params = new URLSearchParams(location.search);
  const testId = params.get('scan');
  if (testId) handleScan(testId);

  /* ---------- Printer Bluetooth langsung dari Chrome ---------- */
  const BT = window.BtPrinter;
  if (C.printMode === 'webbluetooth' && BT) {
    BT.onChange((s) => {
      const chip = $('bt-chip');
      chip.classList.toggle('on', s.connected);
      chip.classList.toggle('busy', s.connecting);
      $('bt-chip-text').textContent = !s.supported ? 'Web Bluetooth tidak didukung'
        : s.connecting ? 'Menghubungkan ' + (s.name || 'printer') + '…'
        : s.connected ? 'Printer BT: ' + s.name
        : 'Printer BT: belum terhubung';
      $('bt-warning').hidden = s.connected || s.connecting;
      $('bt-warning-text').textContent = s.supported
        ? 'Printer Bluetooth belum terhubung'
        : 'Browser ini tidak mendukung Web Bluetooth. Gunakan Chrome / Edge.';
    });

    // tombol "Hubungkan Printer" (butuh klik pengguna untuk membuka dialog)
    document.querySelectorAll('[data-bt-pair]').forEach((btn) => btn.addEventListener('click', async () => {
      try {
        await BT.pair();
        if (pendingEscpos) {
          const data = pendingEscpos; pendingEscpos = null;
          $('r-status').textContent = 'Mencetak ulang…';
          $('r-status').classList.remove('fail');
          $('bt-retry').hidden = true;
          await BT.print(data);
          $('r-status').textContent = 'Silakan ambil tiket Anda';
          scheduleReset(C.resultSeconds);
        }
      } catch (e) {
        if (e && e.name === 'NotFoundError') return; // dialog dibatalkan
        if (pendingEscpos) printFailed(e.message || String(e), true);
        else showError('Gagal menghubungkan printer: ' + (e.message || e));
      } finally {
        focusInput();
      }
    }));

    BT.reconnect().catch(() => {}); // sambung otomatis ke printer terakhir
  }

  // Test cetak dari halaman Pengaturan: index.php?testprint=1
  if (params.has('testprint')) {
    (async () => {
      const sample = { id: 'TEST-0000', kursi: 'A-01', nama: 'Test Cetak Printer', jabatan: 'Jabatan Contoh', instansi: 'Instansi Contoh' };
      showResult(sample);
      try {
        if (C.printMode === 'webbluetooth' && BT) {
          const t = await (await fetch('api/test-ticket.php', { cache: 'no-store' })).json();
          try {
            await BT.print(t.escpos);
          } catch (e) {
            pendingEscpos = t.escpos;
            return printFailed(e.message || String(e), true);
          }
        } else {
          // Kabel USB: gambar Desain Tiket dengan ukuran printer — sama persis dengan cetak sungguhan
          let ticket = null;
          if (C.printMode === 'cable') {
            try { ticket = (await (await fetch('api/test-ticket.php?for=cable', { cache: 'no-store' })).json()).ticket; } catch (e) {}
          }
          await printTicket(sample, ticket);
        }
        $('r-status').textContent = 'Test cetak terkirim ke printer';
        scheduleReset(C.resultSeconds);
      } catch (e) {
        printFailed(e.message || String(e));
      }
    })();
  }
})();
