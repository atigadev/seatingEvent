<?php
declare(strict_types=1);

/**
 * MOCK API — meniru API asli.
 *   GET mock/api.php?id=PTBI-0001
 * Respons sukses : {"success":true,"data":{"id","nama","jabatan","instansi","no_kursi"}}
 * Respons gagal  : HTTP 404 {"success":false,"message":"..."}
 * Tambahkan &delay=2 untuk simulasi API lambat.
 */
header('Content-Type: application/json; charset=utf-8');

$id    = trim((string) ($_GET['id'] ?? $_POST['id'] ?? ''));
$delay = min(10, max(0, (int) ($_GET['delay'] ?? 0)));
if ($delay > 0) {
    sleep($delay);
}

$db = require __DIR__ . '/data.php';

if ($id === '' || !isset($db[$id])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Peserta dengan ID "' . $id . '" tidak ditemukan.']);
    exit;
}

echo json_encode(['success' => true, 'data' => ['id' => $id] + $db[$id]], JSON_UNESCAPED_UNICODE);
