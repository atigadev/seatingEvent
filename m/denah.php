<?php
declare(strict_types=1);

/**
 * Potongan denah untuk aplikasi petugas: hanya frame denah dari assets/img/seating-bg.jpg
 * (x 2372–8000, y 934–4096 px), diperkecil ke 1600 px agar ringan di HP.
 * Dibuat sekali lalu disimpan di storage/cache/; dibuat ulang otomatis bila gambar latar diganti.
 */
$src = __DIR__ . '/../assets/img/seating-bg.jpg';
$dir = __DIR__ . '/../storage/cache';
$out = $dir . '/m-denah.jpg';

if (!is_file($src)) {
    http_response_code(404);
    exit;
}
if (!is_file($out) || filemtime($out) < filemtime($src)) {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $im = @imagecreatefromjpeg($src);
    if (!$im) {
        http_response_code(500);
        exit;
    }
    $sx = 2372; $sy = 934; $sw = 5628; $sh = 3162;
    $w = 1600; $h = (int) round($w * $sh / $sw);
    $dst = imagecreatetruecolor($w, $h);
    imagecopyresampled($dst, $im, 0, 0, $sx, $sy, $w, $h, $sw, $sh);
    imagejpeg($dst, $out . '.tmp', 82);
    imagedestroy($im);
    imagedestroy($dst);
    @rename($out . '.tmp', $out);
}

$etag = '"' . md5((string) filemtime($out) . filesize($out)) . '"';
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($out));
readfile($out);
