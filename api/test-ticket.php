<?php
declare(strict_types=1);

/** Tiket contoh untuk test cetak dari Chrome: ESC/POS (Bluetooth) atau gambar Desain Tiket (Kabel USB) */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/escpos.php';
require_once __DIR__ . '/../lib/ticket.php';

date_default_timezone_set('Asia/Jakarta');

$cfg = app_config();
$sample = ['id' => 'TEST-0000', 'kursi' => 'A-01', 'nama' => 'Test Cetak Printer', 'jabatan' => 'Jabatan Contoh', 'instansi' => 'Instansi Contoh'];
$out = ['ok' => true, 'data' => $sample];
if (($_GET['for'] ?? '') === 'cable') {
    $out['ticket'] = ticket_for_browser($sample, $cfg);   // sama persis dengan cetak sungguhan
} else {
    $out['escpos'] = base64_encode(escpos_build_ticket($sample, $cfg, 'text'));
}
json_response($out);
