<?php
declare(strict_types=1);

/**
 * API editor denah (khusus admin, butuh sesi login Pengaturan + token CSRF di header X-CSRF-Token).
 *   GET  ?action=draft            -> draf kerja
 *   GET  ?action=backups          -> daftar riwayat publikasi
 *   POST ?action=save             (body JSON)  -> simpan draf
 *   POST ?action=publish          (body JSON)  -> publikasikan ke kiosk (versi lama dicadangkan)
 *   POST ?action=upload           (multipart: image) -> unggah gambar denah
 *   POST ?action=restore&file=... -> muat riwayat ke draf
 */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/venue.php';

date_default_timezone_set('Asia/Jakarta');
session_start();

if (empty($_SESSION['seat_admin'])) {
    json_response(['ok' => false, 'error' => 'Sesi login habis, silakan login ulang di Pengaturan.'], 401);
}
$action = (string) ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'draft') {
        json_response(['ok' => true, 'data' => venue_draft(), 'live' => venue_version()]);
    }
    if ($action === 'backups') {
        json_response(['ok' => true, 'items' => venue_backups()]);
    }
    json_response(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
}

$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
    json_response(['ok' => false, 'error' => 'Token tidak valid. Muat ulang halaman.'], 403);
}

try {
    switch ($action) {
        case 'save':
        case 'publish':
            $data = json_decode((string) file_get_contents('php://input'), true);
            $err = venue_validate($data);
            if ($err) {
                json_response(['ok' => false, 'error' => $err], 400);
            }
            if ($action === 'save') {
                if (!venue_write(VENUE_DRAFT, $data)) {
                    throw new RuntimeException('Gagal menyimpan draf.');
                }
                json_response(['ok' => true, 'savedAt' => date('H:i:s')]);
            }
            if (empty($data['map']['image'])) {
                json_response(['ok' => false, 'error' => 'Unggah gambar denah terlebih dahulu.'], 400);
            }
            $meta = venue_publish($data);
            json_response(['ok' => true, 'meta' => $meta, 'publishedAt' => date('H:i:s')]);

        case 'upload':
            [$url, $w, $h] = venue_save_image($_FILES['image'] ?? []);
            json_response(['ok' => true, 'url' => $url, 'width' => $w, 'height' => $h]);

        case 'restore':
            $file = basename((string) ($_GET['file'] ?? ''));
            $data = preg_match('/^map-\d{8}-\d{6}\.json$/', $file) ? venue_read(VENUE_BACKUP . '/' . $file) : null;
            if (!$data) {
                json_response(['ok' => false, 'error' => 'Riwayat tidak ditemukan.'], 404);
            }
            venue_write(VENUE_DRAFT, $data);
            json_response(['ok' => true, 'data' => $data]);
    }
    json_response(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
} catch (Throwable $ex) {
    json_response(['ok' => false, 'error' => $ex->getMessage()], 500);
}
