<?php
declare(strict_types=1);

/**
 * Cetak tiket untuk peserta yang sudah di-scan (tombol PRINT di halaman Seating Arrangement).
 * POST qr=<isi QR>
 * Data diambil ulang dari server registrasi (tidak memakai data dari browser), lalu dicetak
 * sesuai mode printer di Pengaturan:
 *   cable        → { print_mode:'cable', data }            browser mencetak (window.print)
 *   webbluetooth → { print_mode:'webbluetooth', escpos }   browser kirim ke printer BLE
 *   bluetooth / brother → server mencetak langsung         { printed, print_error }
 *   agent        → masuk antrean Print Agent               { job_id }
 */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/escpos.php';
require __DIR__ . '/../lib/brother.php';
require __DIR__ . '/../lib/agent.php';

date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Gunakan POST.'], 405);
}
$cfg = app_config();
if (empty($cfg['kiosk']['scan_active'])) {
    json_response(['ok' => false, 'message' => 'Pemindaian belum dimulai.'], 423);
}
$qr = trim((string) ($_POST['qr'] ?? ''));
if ($qr === '') {
    json_response(['ok' => false, 'message' => 'Scan QR terlebih dahulu.'], 400);
}

$id = $qr;
$data = [];
try {
    [$url, $id] = resolve_api_target($qr);
    $data = api_fetch_participant($url, $id);
} catch (Throwable $ex) {
    json_response(['ok' => false, 'message' => $ex->getMessage()], 422);
}
if (!empty($data['unassigned'])) {
    json_response(['ok' => false, 'message' => 'Meja belum ditentukan — tiket tidak dapat dicetak.'], 422);
}

$mode = $cfg['print']['mode'];
$data = ticket_prepare($data);   // nomor antrian (bila Desain Tiket memakainya)
$out = ['ok' => true, 'data' => $data, 'print_mode' => $mode, 'printed' => null, 'print_error' => ''];
try {
    switch ($mode) {
        case 'cable':
            $out['ticket'] = ticket_for_browser($data, $cfg);   // gambar tiket sesuai Desain Tiket
            break;
        case 'webbluetooth':
            $out['escpos'] = base64_encode(str_repeat(escpos_build_ticket($data, $cfg, 'text'), max(1, (int) $cfg['print']['copies'])));
            break;
        case 'bluetooth':
            escpos_print_ticket($data);
            $out['printed'] = true;
            break;
        case 'brother':
            bql_print_ticket($data);
            $out['printed'] = true;
            break;
        case 'agent':
            $job = queue_add($data, null, (string) ($_POST['station'] ?? ''));
            $out['job_id'] = $job['id'];
            $out['station'] = $job['station'];
            $out['rerouted'] = $job['rerouted'];
            break;
        // cable: browser yang mencetak
    }
} catch (Throwable $ex) {
    $out['printed'] = false;
    $out['print_error'] = $ex->getMessage();
}

$status = $out['printed'] === false ? 'GAGAL CETAK' : ($mode === 'agent' ? 'ANTRE' : 'OK');
$out['reprint'] = log_print($id, $data, $status, $out['print_error'] ?: (isset($out['job_id']) ? 'job ' . $out['job_id'] : ''));
json_response($out);
