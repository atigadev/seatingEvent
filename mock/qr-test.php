<?php
declare(strict_types=1);
require __DIR__ . '/../lib/functions.php';

$db = require __DIR__ . '/data.php';
$ids = array_keys($db);
$ids[] = 'PTBI-9999'; // sengaja tidak ada -> test error
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR Test — Mock ID Card</title>
<style>
  body { font-family: "Segoe UI", Arial, sans-serif; margin: 24px; background: #f4f6fa; color: #1c2430; }
  h1 { margin: 0 0 4px; font-size: 22px; }
  p { margin: 0 0 20px; color: #5a6575; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 16px; }
  .card { background: #fff; border-radius: 10px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  .card.bad { outline: 2px dashed #e5484d; }
  .qr { display: inline-block; padding: 8px; background: #fff; }
  .id { font-weight: 700; margin-top: 8px; font-family: Consolas, monospace; }
  .meta { font-size: 13px; color: #5a6575; margin-top: 2px; }
  a.btn { display: inline-block; margin-top: 8px; font-size: 13px; color: #1d5bd8; }
  @media print { body { background: #fff; } .card { box-shadow: none; border: 1px solid #ccc; break-inside: avoid; } a.btn, p { display: none; } }
</style>
</head>
<body>
<h1>QR Test — Mock ID Card</h1>
<p>Tampilkan di HP / cetak halaman ini, lalu scan ke kiosk. Kartu bergaris merah = ID tidak terdaftar (test error).
   Endpoint mock: <code>mock/api.php?id=…</code></p>
<div class="card" style="max-width:420px;margin-bottom:20px;text-align:left">
  <b>Buat QR dari ID asli</b>
  <p style="margin:4px 0 8px">Untuk mencoba data dari server registrasi (Pengaturan › Sumber Data = Server Registrasi).</p>
  <input id="custom-id" value="AA210558" style="padding:8px;border:1px solid #ccc;border-radius:6px;width:60%">
  <button onclick="makeCustom()" style="padding:8px 14px">Buat QR</button>
  <div id="custom-qr" class="qr" style="margin-top:10px"></div>
</div>
<div class="grid">
<?php foreach ($ids as $id): $p = $db[$id] ?? null; ?>
  <div class="card<?= $p ? '' : ' bad' ?>">
    <div class="qr" data-qr="<?= e($id) ?>"></div>
    <div class="id"><?= e($id) ?></div>
    <div class="meta"><?= $p ? e($p['nama']) . ' · Kursi ' . e($p['no_kursi']) : 'Tidak terdaftar' ?></div>
    <a class="btn" href="api.php?id=<?= e(rawurlencode($id)) ?>" target="_blank">lihat JSON</a>
  </div>
<?php endforeach; ?>
</div>
<script src="../assets/vendor/qrcode.min.js"></script>
<script>
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    new QRCode(el, { text: el.dataset.qr, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
  });
  function makeCustom() {
    const box = document.getElementById('custom-qr');
    box.innerHTML = '';
    const v = document.getElementById('custom-id').value.trim();
    if (v) new QRCode(box, { text: v, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
  }
  makeCustom();
</script>
</body>
</html>
