<?php
declare(strict_types=1);
require __DIR__ . '/lib/functions.php';

session_start();
if (empty($_SESSION['seat_admin'])) {
    header('Location: settings.php?next=admin-tiket.php');
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
<title>Desain Tiket — <?= e($cfg['event_name']) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">
<link rel="stylesheet" href="assets/admin-tiket.css?v=1">
</head>
<body>
<header class="head">
  <div>
    <strong>Desain Tiket</strong>
    <span class="muted" id="state">Memuat…</span>
  </div>
  <div class="actions">
    <button type="button" id="btn-reset" class="ghost">Kembalikan bawaan</button>
    <button type="button" id="btn-save" class="primary">Simpan Desain</button>
    <a href="settings.php#printer" class="btn ghost">Pengaturan</a>
    <a href="index.php" class="btn ghost">Kiosk</a>
  </div>
</header>

<main class="wrap">
  <section class="editor">
    <!-- kertas -->
    <div class="card">
      <h3>Ukuran Kertas</h3>
      <div class="grid2">
        <label>Jenis kertas
          <select id="paper-preset"></select>
        </label>
        <label>Tinggi tiket
          <select id="paper-hmode">
            <option value="auto">Otomatis (mengikuti isi)</option>
            <option value="fixed">Tetap (mm)</option>
          </select>
        </label>
        <label id="lbl-width" hidden>Lebar area cetak (mm)
          <input type="number" id="paper-width" min="20" max="210" step="0.5">
        </label>
        <label id="lbl-dpi" hidden>Resolusi printer (dpi)
          <select id="paper-dpi"><option>203</option><option>300</option><option>180</option><option>600</option></select>
        </label>
        <label id="lbl-height" hidden>Tinggi (mm)
          <input type="number" id="paper-height" min="10" max="400" step="1">
        </label>
        <label>Margin atas &amp; bawah (mm)
          <input type="number" id="paper-margin" min="0" max="15" step="0.5">
        </label>
      </div>
      <p class="hint" id="paper-hint"></p>
    </div>

    <!-- elemen -->
    <div class="card">
      <h3>Isi Tiket <span class="muted small">— urutan dari atas ke bawah</span></h3>
      <div id="elements"></div>
      <div class="add">
        <span class="muted small">Tambah:</span>
        <button type="button" data-add="field">Data peserta</button>
        <button type="button" data-add="text">Teks bebas</button>
        <button type="button" data-add="counter">Nomor antrian</button>
        <button type="button" data-add="pair">Kiri–kanan</button>
        <button type="button" data-add="line">Garis</button>
        <button type="button" data-add="spacer">Jarak</button>
      </div>
    </div>

    <!-- nomor antrian -->
    <div class="card">
      <h3>Nomor Antrian</h3>
      <p class="hint">Bertambah 1 setiap tiket dicetak (hanya bila elemen <b>Nomor antrian</b> ditampilkan).
        Nomor berikutnya: <b id="counter-next">-</b></p>
      <div class="row">
        <label>Mulai dari nomor
          <input type="number" id="counter-value" min="1" step="1" value="1">
        </label>
        <button type="button" id="btn-counter">Atur</button>
      </div>
    </div>
  </section>

  <aside class="preview-col">
    <div class="card sticky">
      <h3>Pratinjau <span class="muted small" id="dims"></span></h3>
      <div class="sample">
        <select id="sample-preset">
          <option value="long">Contoh: nama panjang</option>
          <option value="short">Contoh: nama pendek</option>
          <option value="custom">Isi sendiri…</option>
        </select>
        <div id="sample-fields" hidden>
          <input id="s-kursi" placeholder="Nomor meja">
          <input id="s-nama" placeholder="Nama">
          <input id="s-jabatan" placeholder="Jabatan">
          <input id="s-instansi" placeholder="Instansi">
        </div>
      </div>
      <div class="paper-wrap" id="paper-wrap">
        <div class="paper" id="paper"><img id="preview" alt="Pratinjau tiket"></div>
      </div>
      <p class="warn" id="overflow" hidden>⚠ Isi tiket lebih tinggi dari tinggi tetap — sebagian terpotong. Perbesar tinggi atau perkecil huruf.</p>
      <p class="hint">Ukuran tampilan = ukuran asli di kertas. Hasil cetak identik dengan pratinjau
        (Kabel USB, thermal ESC/POS, Brother QL, Print Agent).</p>
    </div>
  </aside>
</main>

<div id="toast" class="toast" hidden></div>
<script src="assets/admin-tiket.js?v=1"></script>
</body>
</html>
