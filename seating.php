<?php
declare(strict_types=1);
/**
 * SEATING ARRANGEMENT — halaman scan alternatif (index.php tetap seperti semula).
 * Scan QR → nomor meja, nama, jabatan, instansi tampil di panel kiri,
 * meja di denah berkelap-kelip. Cetak tiket lewat tombol PRINT.
 * Posisi meja: assets/seating-tables.json (koordinat piksel pada gambar 8000×4500).
 */
require __DIR__ . '/lib/functions.php';

$cfg = app_config();
$seatLabel = (string) ($cfg['kiosk']['seat_label'] ?? 'Nomor Meja');
$tables = json_decode((string) file_get_contents(__DIR__ . '/assets/seating-tables.json'), true) ?: [];
$jsConfig = [
    'lookupUrl'      => 'api/lookup.php',
    'printUrl'       => 'api/print.php',
    'statusUrl'      => 'api/status.php',
    'jobUrl'         => 'api/job.php',
    'printMode'      => $cfg['print']['mode'],
    'scanActive'     => (bool) $cfg['kiosk']['scan_active'],
    'holdSeconds'    => (int) ($cfg['kiosk']['seating_display_seconds'] ?? 60),
    'cooldownSeconds'=> (int) $cfg['kiosk']['duplicate_cooldown_seconds'],
    'cameraFacing'   => $cfg['kiosk']['camera_facing'] ?? 'environment',
    'cameraAutostart'=> $cfg['kiosk']['camera_autostart'] ?? false,
    'imageW'         => 8000,   // ukuran asli assets/img/seating-bg.jpg (koordinat meja memakai skala ini)
    'imageH'         => 4500,
    'tables'         => $tables,
];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Seating Arrangement — <?= e($cfg['event_name']) ?></title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1f5ea8">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">
<link rel="stylesheet" href="assets/seating.css?v=8">
<style>
  @page { size: <?= e($cfg['print']['paper_size']) ?>; margin: 0; }
  @media print { #ticket { width: <?= e($cfg['print']['printable_width'] ?? '72mm') ?> !important; } }
</style>
</head>
<body>

<main class="stage" id="stage">
  <!-- tombol tersembunyi ke Pengaturan: ketuk logo Bank Indonesia 3x -->
  <button type="button" class="hot-logo" id="hot-logo" aria-label="Pengaturan"></button>

  <!-- panel kiri -->
  <div class="box box-num" id="box-num"><span id="v-meja"></span><span class="idle-hint" id="idle-hint">Silakan scan QR Code<br>pada ID Card Anda</span></div>
  <div class="box box-row box-nama"><span id="v-nama"></span></div>
  <div class="box box-row box-jabatan"><span id="v-jabatan"></span></div>
  <div class="box box-row box-instansi"><span id="v-instansi"></span></div>
  <button type="button" class="btn-print" id="btn-print" hidden>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>
    <span>PRINT</span>
  </button>
  <div class="status" id="status"></div>

  <!-- penanda meja di denah -->
  <div class="marker" id="marker" hidden>
    <span class="m-pulse"></span><span class="m-pulse p2"></span>
    <span class="m-ring"></span>
    <span class="m-disc" id="m-disc"></span>
  </div>
</main>

<!-- pratinjau kamera -->
<div id="camera-wrap" class="camera-box" hidden><div id="camera-box"></div></div>

<!-- menu bawah (sama dengan halaman kiosk utama): tersembunyi, muncul saat mouse/sentuhan di bawah layar -->
<footer class="bottom" id="bottom">
  <form id="scan-form" autocomplete="off">
    <input id="scan-input" name="qr" placeholder="Scan / ketik ID lalu Enter" spellcheck="false" aria-label="Scan">
  </form>
  <?php if ($cfg['kiosk']['enable_camera']): ?>
    <button type="button" id="btn-camera" class="btn-ghost">Kamera</button>
  <?php endif; ?>
  <select id="station-sel" class="btn-ghost station-sel" title="Stasiun cetak (printer terdekat)" hidden></select>
  <span class="printer-mode" title="Mode printer">Printer: <?= e(print_mode_label($cfg)) ?></span>
  <a href="settings.php" class="btn-ghost btn-icon" title="Pengaturan" aria-label="Pengaturan">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
  </a>
  <span class="status-dot" id="status-dot" title="Koneksi"></span>
</footer>

<!-- tiket cetak (mode Kabel USB: browser mencetak) -->
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

<script>window.SEATING = <?= json_encode($jsConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php if ($cfg['kiosk']['enable_camera']): ?>
<script src="assets/vendor/html5-qrcode.min.js"></script>
<?php endif; ?>
<?php if ($cfg['print']['mode'] === 'webbluetooth'): ?>
<script src="assets/bt-printer.js?v=1"></script>
<?php endif; ?>
<script src="assets/station.js?v=1"></script>
<script src="assets/seating.js?v=6"></script>
<script>
  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
  }
</script>
</body>
</html>
