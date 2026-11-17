<?php
declare(strict_types=1);

/** Pratinjau tiket Brother QL (PNG, persis seperti yang dikirim ke printer). Khusus admin. */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/brother.php';

date_default_timezone_set('Asia/Jakarta');
session_start();
if (empty($_SESSION['seat_admin'])) {
    http_response_code(401);
    exit('Login diperlukan.');
}

$sample = [
    'id' => 'TEST-0000', 'kursi' => 'A01',
    'nama' => 'Prof. Dr. H. Muhammad Syafii Antonio, M.Ec',
    'jabatan' => '', 'instansi' => 'Tazkia University',
];
$im = bql_render_ticket($sample);

// tampilkan hitam-putih murni (ambang yang sama dengan data raster)
imagefilter($im, IMG_FILTER_GRAYSCALE);
imagetruecolortopalette($im, false, 2);

header('Content-Type: image/png');
header('Cache-Control: no-store');
imagepng($im);
imagedestroy($im);
