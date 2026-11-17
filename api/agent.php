<?php
declare(strict_types=1);

/**
 * Endpoint untuk Print Agent di venue. Wajib header X-Agent-Token.
 *   GET  ?action=next&wait=15   → { ok, job: { id, data(base64) } | null }   (long-poll)
 *   POST ?action=done&id=...    → body ok=1 | ok=0&error=...
 * Header opsional: X-Agent-Name, X-Agent-Printer, X-Agent-Version (ditampilkan di Pengaturan).
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

$info = [
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
    do {
        agent_heartbeat($info);
        $job = queue_claim();
        if ($job) {
            $bin = (string) @file_get_contents(QUEUE_DIR . '/' . $job['id'] . '.bin');
            if ($bin === '') {
                queue_finish($job['id'], false, 'Data cetak hilang di server.');
                continue;
            }
            json_response(['ok' => true, 'job' => ['id' => $job['id'], 'label' => $job['label'], 'data' => base64_encode($bin)]]);
        }
        usleep(400000);
    } while (microtime(true) < $until && !connection_aborted());
    json_response(['ok' => true, 'job' => null]);
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
