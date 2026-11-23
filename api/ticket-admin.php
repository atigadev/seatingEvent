<?php
declare(strict_types=1);

/**
 * API halaman Desain Tiket (admin-tiket.php). Wajib login Pengaturan; POST wajib header X-CSRF-Token.
 *   GET  ?action=load                         → desain aktif, desain bawaan, daftar kertas & field, nomor antrian
 *   POST ?action=preview  {template, sample}  → { png(base64), width_mm, height_mm, width_px, height_px, overflow }
 *   POST ?action=save     {template}          → simpan desain
 *   POST ?action=reset                        → kembali ke desain bawaan
 *   POST ?action=counter  {value}             → atur nomor antrian berikutnya
 */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/ticket.php';

date_default_timezone_set('Asia/Jakarta');
session_start();
if (empty($_SESSION['seat_admin'])) {
    json_response(['ok' => false, 'error' => 'Sesi login habis, silakan login ulang di Pengaturan.'], 401);
}
$action = (string) ($_GET['action'] ?? '');

if ($action === 'load') {
    $papers = [];
    foreach (TICKET_PAPERS as $k => $p) $papers[] = ['id' => $k] + $p;
    json_response([
        'ok'       => true,
        'template' => ticket_template(),
        'default'  => ticket_normalize(ticket_default()),
        'papers'   => $papers,
        'fields'   => TICKET_FIELDS,
        'counter'  => ticket_counter_peek(),
        'mode'     => app_config()['print']['mode'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
}
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    json_response(['ok' => false, 'error' => 'Token tidak valid. Muat ulang halaman.'], 403);
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];

try {
    switch ($action) {
        case 'preview':
            $tpl = ticket_normalize((array) ($in['template'] ?? []));
            $s = (array) ($in['sample'] ?? []);
            $sample = [
                'id'       => mb_substr((string) ($s['id'] ?? 'AA210558'), 0, 40),
                'kursi'    => mb_substr((string) ($s['kursi'] ?? '40D'), 0, 20),
                'nama'     => mb_substr((string) ($s['nama'] ?? ''), 0, 120),
                'jabatan'  => mb_substr((string) ($s['jabatan'] ?? ''), 0, 120),
                'instansi' => mb_substr((string) ($s['instansi'] ?? ''), 0, 120),
                'antrian'  => ticket_counter_peek() + 1,   // contoh: nomor berikutnya (tidak dipakai)
            ];
            $r = ticket_render($sample, null, $tpl);
            $png = ticket_png($r);
            imagedestroy($r['im']);
            json_response(['ok' => true, 'png' => base64_encode($png), 'width_mm' => $r['width_mm'], 'height_mm' => $r['height_mm'],
                           'width_px' => $r['width_px'], 'height_px' => $r['height_px'], 'dpi' => $r['dpi'], 'overflow' => $r['overflow']]);

        case 'save':
            $tpl = ticket_normalize((array) ($in['template'] ?? []));
            if (!$tpl['elements']) {
                json_response(['ok' => false, 'error' => 'Desain kosong — tambahkan minimal satu elemen.'], 400);
            }
            $s = load_settings();
            $s['ticket'] = $tpl;            // ganti utuh (bukan digabung) agar elemen yang dihapus benar-benar hilang
            save_settings($s);
            json_response(['ok' => true, 'template' => $tpl, 'savedAt' => date('H:i:s')]);

        case 'reset':
            $s = load_settings();
            unset($s['ticket']);
            save_settings($s);
            json_response(['ok' => true, 'template' => ticket_template()]);

        case 'counter':
            ticket_counter_set((int) ($in['value'] ?? 0));
            json_response(['ok' => true, 'counter' => ticket_counter_peek()]);
    }
    json_response(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
} catch (Throwable $ex) {
    json_response(['ok' => false, 'error' => $ex->getMessage()], 500);
}
