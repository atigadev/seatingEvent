<?php
declare(strict_types=1);
require __DIR__ . '/lib/functions.php';

$cfg = app_config();
$seatLabel = (string) ($cfg['kiosk']['seat_label'] ?? 'Nomor Kursi');
$jsConfig = [
    'lookupUrl'      => 'api/lookup.php',
    'autoPrint'      => (bool) $cfg['print']['auto_print'],
    'copies'         => max(1, (int) $cfg['print']['copies']),
    'resultSeconds'  => (int) $cfg['kiosk']['result_display_seconds'],
    'errorSeconds'   => (int) $cfg['kiosk']['error_display_seconds'],
    'cooldownSeconds'=> (int) $cfg['kiosk']['duplicate_cooldown_seconds'],
    'enableCamera'   => (bool) $cfg['kiosk']['enable_camera'],
    'printMode'      => $cfg['print']['mode'],
    'cameraFacing'   => $cfg['kiosk']['camera_facing'] ?? 'environment',
    'seatLabel'      => $seatLabel,
    'cameraAutostart'=> $cfg['kiosk']['camera_autostart'] ?? false,
    'scanActive'     => (bool) $cfg['kiosk']['scan_active'],
    'statusUrl'      => 'api/status.php',
    'venueUrl'       => 'api/venue.php',
    'wayfinding'     => (bool) ($cfg['kiosk']['wayfinding'] ?? true),
    'mapSeconds'     => (int) ($cfg['kiosk']['map_display_seconds'] ?? 20),
];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Seat Kiosk — <?= e($cfg['event_name']) ?></title>

<!-- PWA -->
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0d2a6e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Seat PTBI">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">

<link rel="stylesheet" href="assets/style.css?v=10">
<link rel="stylesheet" href="assets/wayfinding.css?v=1">
<style>
  @page { size: <?= e($cfg['print']['paper_size']) ?>; margin: 0; }
  @media print { #ticket { width: <?= e($cfg['print']['printable_width'] ?? '72mm') ?> !important; } }
</style>
</head>
<body>

<!-- ===================== TAMPILAN LAYAR ===================== -->
<main id="screen" class="state-<?= $cfg['kiosk']['scan_active'] ? 'ready' : 'standby' ?>">
  <header class="top">
    <div>
      <div class="event-name"><?= e($cfg['event_name']) ?></div>
      <div class="event-sub"><?= e($cfg['event_sub']) ?></div>
    </div>
    <div class="clock" id="clock">--:--</div>
  </header>

  <section class="panel panel-ready">
    <div class="scan-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="120" height="120" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
        <path d="M3 7V4a1 1 0 0 1 1-1h3M17 3h3a1 1 0 0 1 1 1v3M21 17v3a1 1 0 0 1-1 1h-3M7 21H4a1 1 0 0 1-1-1v-3"/>
        <rect x="7" y="7" width="4" height="4"/><rect x="13" y="7" width="4" height="4"/><rect x="7" y="13" width="4" height="4"/>
        <path d="M13 13h4v4M13 17h1"/>
      </svg>
      <span class="scan-line"></span>
    </div>
    <h1>Silakan Scan QR pada ID Card Anda</h1>
    <p class="hint" id="ready-hint">Arahkan QR Code ke scanner. Nomor kursi akan tercetak otomatis.</p>
    <?php if ($cfg['print']['mode'] === 'webbluetooth'): ?>
      <div id="bt-warning" class="bt-warning" hidden>
        <span id="bt-warning-text">Printer Bluetooth belum terhubung</span>
        <button type="button" class="bt-connect" data-bt-pair>Hubungkan Printer</button>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel panel-standby">
    <div class="standby-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="96" height="96" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
    </div>
    <h1 id="sb-title"><?= e($cfg['kiosk']['standby']['title']) ?></h1>
    <p class="hint" id="sb-sub"><?= e($cfg['kiosk']['standby']['subtitle']) ?></p>
  </section>

  <section class="panel panel-loading">
    <div class="spinner"></div>
    <h1>Mengambil data…</h1>
    <p class="hint" id="loading-id"></p>
  </section>

  <section class="panel panel-success" id="panel-success">
    <div class="success-info">
    <div class="seat-label"><?= e($seatLabel) ?> Anda</div>
    <div class="seat-number" id="r-kursi">-</div>
    <div class="person">
      <div class="person-name" id="r-nama">-</div>
      <div class="person-meta"><span id="r-jabatan">-</span></div>
      <div class="person-meta strong" id="r-instansi">-</div>
    </div>
    <p class="print-status" id="r-status">Mencetak…</p>
    <?php if ($cfg['print']['mode'] === 'webbluetooth'): ?>
      <button type="button" class="bt-connect" id="bt-retry" data-bt-pair hidden>Hubungkan Printer &amp; Cetak Ulang</button>
    <?php endif; ?>
    </div>
    <!-- wayfinding: rute dari kiosk ke meja (logika Satumap) -->
    <div class="success-route" id="wf-box" hidden>
      <div class="wf-head">
        <span class="wf-title">Rute ke meja <b id="wf-table">-</b></span>
        <span class="wf-stats" id="wf-stats"></span>
      </div>
      <div class="wf-map" id="wf-map"></div>
      <ol class="wf-steps" id="wf-steps"></ol>
    </div>
  </section>

  <section class="panel panel-error">
    <div class="error-icon">!</div>
    <h1 id="e-title">Data tidak ditemukan</h1>
    <p class="hint" id="e-msg"></p>
  </section>

  <!-- pratinjau kamera (tetap tampil di semua layar agar scan berikutnya bisa langsung) -->
  <div id="camera-wrap" class="camera-box" hidden><div id="camera-box"></div></div>

  <footer class="bottom">
    <form id="scan-form" autocomplete="off">
      <input id="scan-input" name="qr" placeholder="Scan / ketik ID lalu Enter" autofocus spellcheck="false">
    </form>
    <?php if ($cfg['kiosk']['enable_camera']): ?>
      <button type="button" id="btn-camera" class="btn-ghost">Kamera</button>
    <?php endif; ?>
    <?php if ($cfg['print']['mode'] === 'webbluetooth'): ?>
      <button type="button" class="btn-ghost bt-chip" id="bt-chip" data-bt-pair title="Klik untuk pilih / ganti printer">
        <span class="bt-dot"></span><span id="bt-chip-text">Printer BT: belum terhubung</span>
      </button>
    <?php else: ?>
      <span class="printer-mode" title="Mode printer">Printer: <?= e(print_mode_label($cfg)) ?></span>
    <?php endif; ?>
    <select id="kiosk-loc" class="btn-ghost" title="Lokasi kiosk ini di denah" hidden></select>
    <a href="settings.php" class="btn-ghost btn-icon" title="Pengaturan" aria-label="Pengaturan">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
    </a>
    <span class="status-dot" id="status-dot" title="Status"></span>
  </footer>
</main>

<!-- ===================== AREA CETAK ===================== -->
<div id="ticket" aria-hidden="true">
  <div class="t-event"><?= e($cfg['event_name']) ?></div>
  <div class="t-sub"><?= e($cfg['event_sub']) ?></div>
  <div class="t-divider"></div>
  <div class="t-seat-label"><?= e(mb_strtoupper($seatLabel)) ?></div>
  <div class="t-seat" id="t-kursi"></div>
  <div class="t-divider"></div>
  <div class="t-name" id="t-nama"></div>
  <div class="t-row" id="t-jabatan"></div>
  <div class="t-row t-strong" id="t-instansi"></div>
  <div class="t-footer"><span id="t-id"></span><span id="t-time"></span></div>
</div>

<script>window.KIOSK = <?= json_encode($jsConfig, JSON_UNESCAPED_SLASHES) ?>;</script>
<?php if ($cfg['kiosk']['enable_camera']): ?>
<script src="assets/vendor/html5-qrcode.min.js"></script>
<?php endif; ?>
<?php if ($cfg['print']['mode'] === 'webbluetooth'): ?>
<script src="assets/bt-printer.js?v=1"></script>
<?php endif; ?>
<script src="assets/router.js?v=1"></script>
<script src="assets/wayfinding.js?v=2"></script>
<script src="assets/app.js?v=13"></script>
<script>
  // PWA: daftarkan service worker (hanya aktif di https atau localhost)
  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
  }
</script>
</body>
</html>
