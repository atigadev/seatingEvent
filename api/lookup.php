<?php
declare(strict_types=1);

/**
 * Endpoint yang dipanggil halaman kiosk.
 * POST qr=<isi QR>  -> JSON { ok, data:{id,nama,jabatan,instansi,kursi}, reprint, print_mode, printed, print_error }
 *
 * Mode 'cable'     : browser yang mencetak (window.print + --kiosk-printing).
 * Mode 'bluetooth' : server langsung mencetak ESC/POS ke port COM di sini.
 */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/escpos.php';
require __DIR__ . '/../lib/brother.php';
require __DIR__ . '/../lib/agent.php';

date_default_timezone_set('Asia/Jakarta');

$qr = (string) ($_POST['qr'] ?? $_GET['qr'] ?? '');
if (trim($qr) === '') {
    json_response(['ok' => false, 'message' => 'QR kosong.'], 400);
}

$cfg = app_config();
$mode = $cfg['print']['mode'];

if (empty($cfg['kiosk']['scan_active'])) {
    json_response(['ok' => false, 'inactive' => true, 'message' => 'Pemindaian belum dimulai.'], 423);
}
$id = trim($qr);
$data = [];

try {
    [$url, $id] = resolve_api_target($qr);
    $data = api_fetch_participant($url, $id);
} catch (Throwable $ex) {
    log_print($id, [], 'GAGAL', $ex->getMessage());
    json_response(['ok' => false, 'message' => $ex->getMessage()], 422);
}

// Meja belum ditentukan: tampilkan data, jangan cetak tiket
if (!empty($data['unassigned'])) {
    log_print($id, $data, 'BELUM ADA MEJA');
    json_response(['ok' => true, 'data' => $data, 'unassigned' => true, 'print_mode' => $mode, 'printed' => null]);
}

// print=0 (halaman Seating Arrangement): hanya tampilkan data, cetak lewat tombol PRINT (api/print.php)
if (($_POST['print'] ?? $_GET['print'] ?? '1') === '0') {
    log_print($id, $data, 'SCAN');
    json_response(['ok' => true, 'data' => $data, 'print_mode' => $mode, 'printed' => null, 'print_deferred' => true]);
}

// Print Agent: masukkan ke antrean, agen di venue yang mencetak (kiosk memantau status job)
if ($mode === 'agent' && $cfg['print']['auto_print']) {
    try {
        $job = queue_add($data, null, (string) ($_POST['station'] ?? ''));
        $reprint = log_print($id, $data, 'ANTRE', 'job ' . $job['id'] . ($job['station'] !== '' ? ' @' . $job['station'] : ''));
        json_response(['ok' => true, 'data' => $data, 'reprint' => $reprint, 'print_mode' => 'agent', 'job_id' => $job['id'],
                       'station' => $job['station'], 'rerouted' => $job['rerouted'], 'printed' => null]);
    } catch (Throwable $ex) {
        $reprint = log_print($id, $data, 'GAGAL CETAK', $ex->getMessage());
        json_response(['ok' => true, 'data' => $data, 'reprint' => $reprint, 'print_mode' => 'agent', 'printed' => false, 'print_error' => $ex->getMessage()]);
    }
}

$printed = null;      // null = dicetak oleh browser (mode kabel / Bluetooth Chrome)
$printError = '';
$escpos = null;
if ($mode === 'webbluetooth') {
    // browser yang mengirim ke printer BLE; server hanya menyusun byte ESC/POS
    $escpos = base64_encode(str_repeat(escpos_build_ticket($data, $cfg), max(1, (int) $cfg['print']['copies'])));
}
if (($mode === 'bluetooth' || $mode === 'brother') && $cfg['print']['auto_print']) {
    try {
        if ($mode === 'brother') {
            bql_print_ticket($data);
        } else {
            escpos_print_ticket($data);
        }
        $printed = true;
    } catch (Throwable $ex) {
        $printed = false;
        $printError = $ex->getMessage();
    }
}

$reprint = log_print($id, $data, $printed === false ? 'GAGAL CETAK' : 'OK', $printError);

json_response([
    'ok'          => true,
    'data'        => $data,
    'reprint'     => $reprint,
    'print_mode'  => $mode,
    'printed'     => $printed,
    'print_error' => $printError,
    'escpos'      => $escpos,
]);
