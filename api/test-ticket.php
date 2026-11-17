<?php
declare(strict_types=1);

/** Data ESC/POS (base64) tiket contoh — untuk test cetak mode Bluetooth dari Chrome */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/escpos.php';

date_default_timezone_set('Asia/Jakarta');

$sample = ['id' => 'TEST-0000', 'kursi' => 'A-01', 'nama' => 'Test Cetak Printer', 'jabatan' => 'Jabatan Contoh', 'instansi' => 'Instansi Contoh'];
json_response(['ok' => true, 'data' => $sample, 'escpos' => base64_encode(escpos_build_ticket($sample, app_config()))]);
