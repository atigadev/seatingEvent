<?php
declare(strict_types=1);
/**
 * Unduh skrip kiosk (start-kiosk-hosting.bat) yang sudah berisi alamat situs ini.
 * Hanya untuk admin yang sudah login di Pengaturan.
 * GET station=<nama stasiun>  (opsional, untuk mode Print Agent)
 */
require_once __DIR__ . '/lib/functions.php';

session_start();
if (empty($_SESSION['seat_admin'])) {
    header('Location: settings.php');
    exit;
}

$tpl = __DIR__ . '/start-kiosk-hosting.bat';
if (!is_file($tpl)) {
    http_response_code(404);
    exit('Template skrip kiosk tidak ditemukan.');
}

// alamat aplikasi = alamat halaman ini (mendukung HTTPS di balik proxy/LiteSpeed)
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
$host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$url = ($https ? 'https' : 'http') . '://' . $host . $dir . '/';

$station = strtolower(trim((string) ($_GET['station'] ?? '')));
$station = preg_replace('/[^a-z0-9_-]/', '', $station);

$bat = (string) file_get_contents($tpl);
$bat = preg_replace('/^set URL=.*$/m', 'set URL=' . $url, $bat, 1);
$bat = preg_replace('/^set STATION=.*$/m', 'set STATION=' . $station, $bat, 1);
$bat = preg_replace("/\r?\n/", "\r\n", $bat);           // file .bat wajib CRLF

$name = 'start-kiosk' . ($station !== '' ? '-' . $station : '') . '.bat';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($bat));
header('Cache-Control: no-store');
echo $bat;
