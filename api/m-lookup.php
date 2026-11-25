<?php
declare(strict_types=1);

/**
 * Cek peserta untuk aplikasi petugas (m/) — BACA SAJA: tidak mencetak, tidak menulis log,
 * tidak terpengaruh saklar pemindaian kiosk.
 * GET/POST qr=<isi QR atau ID>
 * → { ok, data:{id,nama,jabatan,instansi,kursi}, meja, unassigned, pos:{x,y}|null, prints:{count,last} }
 */
require __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/ticket.php';

date_default_timezone_set('Asia/Jakarta');
header('X-Robots-Tag: noindex');

$qr = trim((string) ($_POST['qr'] ?? $_GET['qr'] ?? ''));
if ($qr === '') {
    json_response(['ok' => false, 'message' => 'QR kosong.'], 400);
}

try {
    [$url, $id] = resolve_api_target($qr);
    $data = api_fetch_participant($url, $id);
} catch (Throwable $ex) {
    json_response(['ok' => false, 'message' => $ex->getMessage()], 422);
}

$cfg = app_config();
$kursi = trim((string) ($data['kursi'] ?? ''));
$unassigned = !empty($data['unassigned']) || $kursi === '';

/* Posisi meja pada potongan denah (m/denah.php): frame denah x 2372–8000, y 934–4096 pada gambar 8000×4500 */
$pos = null;
if (!$unassigned) {
    $tables = json_decode((string) @file_get_contents(__DIR__ . '/../assets/seating-tables.json'), true) ?: [];
    $s = strtoupper((string) preg_replace('/\s+/', '', $kursi));
    $key = null;
    if (preg_match('/^(\d+)[-._]?[A-Z]*$/', $s, $m)) $key = (string) (int) $m[1];
    elseif (preg_match('/^([A-Z]+)[-._]?(\d+)[A-Z]?$/', $s, $m)) $key = $m[1] . (int) $m[2];
    if ($key !== null && isset($tables[$key])) {
        [$tx, $ty] = $tables[$key];
        $pos = ['x' => round(($tx - 2372) / 5628 * 100, 2), 'y' => round(($ty - 934) / 3162 * 100, 2)];
    }
}

/* Riwayat cetak tiket dari log kiosk */
$count = 0;
$last = null;
$file = $cfg['log_file'];
if (is_file($file) && ($fh = fopen($file, 'r'))) {
    while (($row = fgetcsv($fh)) !== false) {
        if (isset($row[1], $row[5]) && $row[1] === $id && $row[5] === 'OK') {
            $count++;
            $last = $row[0];
        }
    }
    fclose($fh);
}

json_response([
    'ok'         => true,
    'data'       => [
        'id'       => (string) ($data['id'] ?? $id),
        'nama'     => (string) ($data['nama'] ?? ''),
        'jabatan'  => (string) ($data['jabatan'] ?? ''),
        'instansi' => (string) ($data['instansi'] ?? ''),
        'kursi'    => $kursi,
    ],
    'meja'       => $unassigned ? '' : ticket_table_only($kursi),
    'unassigned' => $unassigned,
    'pos'        => $pos,
    'prints'     => ['count' => $count, 'last' => $last],
    'checked_at' => date('H:i:s'),
]);
