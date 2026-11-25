<?php
declare(strict_types=1);
/**
 * Aplikasi petugas (PWA, khusus HP): scan QR ID Card peserta dengan kamera HP →
 * nomor meja, detail peserta, riwayat cetak tiket & lokasi meja di denah.
 * Tidak mencetak dan tidak terpengaruh saklar pemindaian kiosk.
 */
require_once __DIR__ . '/../lib/functions.php';

const M_VERSION = '1.0.1';
$cfg = app_config();
$event = (string) $cfg['event_name'];
$sub = (string) ($cfg['event_sub'] ?? '');
$v = function (string $f): string { return $f . '?v=' . (is_file(__DIR__ . '/' . $f) ? filemtime(__DIR__ . '/' . $f) : M_VERSION); };
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Cek Meja Petugas — <?= e($event) ?></title>
<meta name="description" content="Scan QR ID Card peserta untuk melihat nomor meja dan detail peserta.">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0d2a6e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Cek Meja">
<meta name="app-version" content="<?= M_VERSION ?>">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/icons/favicon-32.png">
<link rel="apple-touch-icon" href="../assets/icons/apple-touch-icon.png">
<link rel="stylesheet" href="<?= $v('app.css') ?>">
</head>
<body>

<header class="top">
  <div class="top-text">
    <b>Cek Meja</b>
    <span><?= e($event) ?><?= $sub !== '' ? ' · ' . e($sub) : '' ?></span>
  </div>
  <span class="net" id="net" title="Koneksi"><i></i><em>Online</em></span>
</header>

<main class="wrap">

  <!-- ============ SCANNER ============ -->
  <section class="card scan" id="view-scan">
    <div class="cam" id="cam">
      <div id="reader"></div>
      <div class="cam-guide" aria-hidden="true"><i></i><i></i><i></i><i></i><span class="cam-line"></span></div>
      <div class="cam-idle" id="cam-idle">
        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M5 15V8a3 3 0 0 1 3-3h7M33 5h7a3 3 0 0 1 3 3v7M43 33v7a3 3 0 0 1-3 3h-7M15 43H8a3 3 0 0 1-3-3v-7"/>
          <rect x="14" y="14" width="7" height="7" rx="1" fill="currentColor" stroke="none"/>
          <rect x="27" y="14" width="7" height="7" rx="1" fill="currentColor" stroke="none"/>
          <rect x="14" y="27" width="7" height="7" rx="1" fill="currentColor" stroke="none"/>
          <path d="M27 27h3v3M34 27v7h-7" stroke-width="2.4"/>
        </svg>
        <p>Arahkan kamera ke QR Code<br>pada ID Card peserta</p>
        <button type="button" class="btn primary" id="btn-start">Mulai Scan</button>
      </div>
      <div class="cam-tools" id="cam-tools" hidden>
        <button type="button" class="icon-btn" id="btn-torch" title="Senter" aria-label="Senter" hidden>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2h8l-1 6H9L8 2zM9 8h6v3l-2 3v8h-2v-8l-2-3V8z"/></svg>
        </button>
        <button type="button" class="icon-btn" id="btn-switch" title="Ganti kamera" aria-label="Ganti kamera" hidden>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-3l-2-3H9L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M9 13a3 3 0 0 1 5.2-2M15 13a3 3 0 0 1-5.2 2"/><path d="M14 9.5V11h-1.5M10 16.5V15h1.5"/></svg>
        </button>
        <button type="button" class="icon-btn" id="btn-stop" title="Hentikan kamera" aria-label="Hentikan kamera">
          <svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
        </button>
      </div>
    </div>

    <p class="msg" id="msg" hidden></p>

    <div class="alt">
      <label class="btn ghost file-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="15" rx="2"/><circle cx="12" cy="12.5" r="3.5"/><path d="M8 5l1.5-2h5L16 5"/></svg>
        Foto QR
        <input type="file" id="qr-file" accept="image/*" capture="environment">
      </label>
      <form id="manual" class="manual" autocomplete="off">
        <input type="text" id="manual-id" placeholder="Ketik ID peserta" autocapitalize="characters" spellcheck="false" enterkeyhint="search" aria-label="ID peserta">
        <button class="btn primary" type="submit">Cek</button>
      </form>
    </div>
  </section>

  <!-- ============ HASIL ============ -->
  <section class="result" id="view-result" hidden aria-live="polite">
    <div class="card hero" id="hero">
      <span class="hero-label">Nomor Meja</span>
      <b class="hero-num" id="r-meja">--</b>
      <span class="hero-sub" id="r-kursi"></span>
    </div>

    <div class="chips" id="r-chips"></div>

    <div class="card">
      <dl class="detail">
        <div><dt>Nama</dt><dd id="r-nama">-</dd></div>
        <div><dt>Jabatan</dt><dd id="r-jabatan">-</dd></div>
        <div><dt>Instansi</dt><dd id="r-instansi">-</dd></div>
        <div><dt>ID Peserta</dt><dd id="r-id" class="mono">-</dd></div>
      </dl>
    </div>

    <div class="card map-card" id="map-card" hidden>
      <div class="card-head">
        <b>Lokasi Meja</b>
        <button type="button" class="link" id="btn-zoom">Perbesar</button>
      </div>
      <div class="map" id="map">
        <img src="denah.php" alt="Denah seating" id="map-img" loading="lazy">
        <span class="pin" id="pin"><i></i><b id="pin-num"></b></span>
      </div>
    </div>

    <p class="checked" id="r-checked"></p>
  </section>

  <!-- ============ RIWAYAT ============ -->
  <section class="history" id="history" hidden>
    <div class="card-head">
      <b>Riwayat scan di HP ini</b>
      <button type="button" class="link" id="btn-clear">Hapus</button>
    </div>
    <ul id="history-list"></ul>
  </section>

  <footer class="foot">
    v<?= M_VERSION ?> · <button type="button" class="link" id="btn-update" hidden>Cek update</button>
  </footer>
</main>

<!-- tombol bawah saat hasil tampil -->
<div class="dock" id="dock" hidden>
  <button type="button" class="btn primary big" id="btn-next">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/></svg>
    Scan Berikutnya
  </button>
</div>

<!-- denah diperbesar -->
<div class="zoom" id="zoom" hidden>
  <div class="zoom-bar"><b id="zoom-title">Lokasi Meja</b><button type="button" class="icon-btn" id="zoom-close" aria-label="Tutup">✕</button></div>
  <div class="zoom-body" id="zoom-body"><div class="zoom-inner" id="zoom-inner"><img src="denah.php" alt=""><span class="pin" id="zpin"><i></i><b id="zpin-num"></b></span></div></div>
</div>

<div class="loading" id="loading" hidden><span></span></div>

<script>window.M = <?= json_encode(['lookup' => '../api/m-lookup.php'], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="../assets/vendor/html5-qrcode.min.js"></script>
<script src="<?= $v('app.js') ?>"></script>
<script src="<?= $v('pwa.js') ?>" defer></script>
</body>
</html>
