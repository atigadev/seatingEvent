<?php
declare(strict_types=1);

/**
 * Endpoint untuk Print Agent di venue. Wajib header X-Agent-Token.
 *   GET  ?action=next&wait=15   → { ok, job: { id, data(base64) } | null }   (long-poll)
 *   POST ?action=done&id=...    → body ok=1 | ok=0&error=...
 * Header opsional: X-Agent-Station (stasiun cetak yang dilayani), X-Agent-Name, X-Agent-Printer,
 * X-Agent-Version (ditampilkan di Pengaturan). Agen hanya mengambil job stasiunnya.
 */
require __DIR__ . '/../lib/functions.php';
require __DIR__ . '/../lib/agent.php';

date_default_timezone_set('Asia/Jakarta');

$token = (string) (agent_cfg()['token'] ?? '');
$given = (string) ($_SERVER['HTTP_X_AGENT_TOKEN'] ?? '');
if ($token === '' || !hash_equals($token, $given)) {
    usleep(500000);
    json_response(['ok' => false, 'error' => 'Token agen tidak valid. Salin token dari Pengaturan › Printer › Print Agent.'], 401);
}

$station = station_slug((string) ($_SERVER['HTTP_X_AGENT_STATION'] ?? ($_GET['station'] ?? '')));
$info = [
    'station' => $station,
    'name'    => mb_substr((string) ($_SERVER['HTTP_X_AGENT_NAME'] ?? 'Agen'), 0, 60),
    'printer' => mb_substr((string) ($_SERVER['HTTP_X_AGENT_PRINTER'] ?? ''), 0, 60),
    'version' => mb_substr((string) ($_SERVER['HTTP_X_AGENT_VERSION'] ?? ''), 0, 20),
    'ip'      => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
];
$action = (string) ($_GET['action'] ?? '');

if ($action === 'next') {
    $wait = max(0, min(20, (int) ($_GET['wait'] ?? 15)));
    @set_time_limit($wait + 15);
    $until = microtime(true) + $wait;
    queue_cleanup();
    // Deteksi agen yang sudah putus (PC mati / Wi-Fi hilang) selama long-poll: kirim spasi (aman untuk JSON)
    // lalu cek koneksi — agar server tidak memperbarui status "online" & tidak mengambil job untuk klien yang mati.
    ignore_user_abort(true);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    do {
        echo ' ';
        @flush();
        if (connection_aborted()) {
            exit;
        }
        agent_heartbeat($info);
        $job = queue_claim($station);
        if ($job) {
            $bin = (string) @file_get_contents(QUEUE_DIR . '/' . $job['id'] . '.bin');
            if ($bin === '') {
                queue_finish($job['id'], false, 'Data cetak hilang di server.');
                continue;
            }
            // header sudah terkirim (spasi long-poll) → tulis JSON langsung
            echo json_encode(['ok' => true, 'job' => ['id' => $job['id'], 'label' => $job['label'], 'station' => $job['station'] ?? '', 'data' => base64_encode($bin)]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @flush();
            if (connection_aborted()) {
                // agen putus tepat saat job dikirim → lepaskan agar bisa diambil ulang
                queue_release($job['id']);
            }
            exit;
        }
        usleep(400000);
    } while (microtime(true) < $until);
    echo json_encode(['ok' => true, 'job' => null]);
    exit;
}

if ($action === 'done' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    agent_heartbeat($info);
    $id = (string) ($_GET['id'] ?? '');
    $ok = ($_POST['ok'] ?? '') === '1';
    if (!queue_finish($id, $ok, (string) ($_POST['error'] ?? ''))) {
        json_response(['ok' => false, 'error' => 'Job tidak ditemukan.'], 404);
    }
    json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
