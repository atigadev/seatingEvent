<?php
declare(strict_types=1);
require __DIR__ . '/lib/functions.php';

session_start();
if (empty($_SESSION['seat_admin'])) {
    header('Location: settings.php?next=admin-denah.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$cfg = app_config();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e($_SESSION['csrf']) ?>">
<title>Denah &amp; Wayfinding — <?= e($cfg['event_name']) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">
<link rel="stylesheet" href="assets/admin-denah.css?v=1">
</head>
<body>
<div class="ed">
  <header class="ed-head">
    <div class="ed-title">
      <strong>Denah &amp; Wayfinding</strong>
      <span id="save-status" class="muted">Memuat…</span>
    </div>
    <div class="ed-actions">
      <button type="button" data-act="undo" title="Batalkan (Ctrl+Z)">↶ Undo</button>
      <button type="button" data-act="history">Riwayat</button>
      <button type="button" data-act="publish" class="primary">Publikasikan ke Kiosk</button>
      <a href="settings.php" class="btn">Pengaturan</a>
      <a href="index.php" class="btn">Kiosk</a>
    </div>
  </header>

  <div class="ed-tools" id="tools">
    <button type="button" data-tool="select" title="Pilih & geser (V)">🖐 Pilih</button>
    <button type="button" data-tool="aisle" title="Gambar lorong (L)">〰 Lorong</button>
    <button type="button" data-tool="table" title="Gambar meja / area (M)">▭ Meja</button>
    <button type="button" data-tool="kiosk" title="Tempatkan kiosk (K)">📍 Kiosk</button>
    <button type="button" data-tool="calibrate" title="Kalibrasi skala">📏 Skala</button>
    <button type="button" data-tool="delete" title="Hapus (D)">🗑 Hapus</button>
    <span class="sep"></span>
    <button type="button" data-act="zoom-out" title="Perkecil">−</button>
    <span id="zoom-lbl" class="muted">100%</span>
    <button type="button" data-act="zoom-in" title="Perbesar">+</button>
    <button type="button" data-act="zoom-fit" title="Seluruh denah">⤢</button>
    <span id="hint" class="hint"></span>
  </div>

  <div class="ed-body">
    <div class="canvas-wrap" id="cw">
      <div class="empty-canvas" id="empty-canvas">
        <div>
          <div class="big">Belum ada denah</div>
          <p>Unggah gambar denah seating area (JPG / PNG / PDF) melalui panel di kanan,<br>atau impor data dari Satumap.</p>
        </div>
      </div>
      <div class="stage" id="stage" hidden>
        <img id="map-img" alt="Denah" draggable="false">
        <svg id="svg"></svg>
      </div>
    </div>
    <aside class="panel" id="panel"></aside>
  </div>
</div>

<input type="file" id="file-image" accept="image/png,image/jpeg,image/webp,application/pdf" hidden>
<input type="file" id="file-json" accept="application/json,.json" hidden>
<dialog id="dlg"></dialog>
<div id="toast" class="toast" hidden></div>

<script src="assets/router.js?v=1"></script>
<script src="assets/wayfinding.js?v=2"></script>
<script src="assets/admin-denah.js?v=1"></script>
</body>
</html>
