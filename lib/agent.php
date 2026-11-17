<?php
declare(strict_types=1);

/**
 * Print Agent — cetak dari hosting ke printer di jaringan lokal venue.
 *
 *   kiosk ──scan──▶ server (hosting) ──antrean──▶ agen di PC venue ──LAN──▶ printer
 *
 * Server menyusun byte cetak (Brother Raster / ESC/POS) dan menaruhnya di antrean
 * storage/queue/. Agen (agent/print-agent.php) mengambil job lewat HTTPS dengan token,
 * mengirimnya ke printer, lalu melapor hasilnya. Hanya koneksi KELUAR dari venue (port 443).
 */

require_once __DIR__ . '/escpos.php';
require_once __DIR__ . '/brother.php';

const QUEUE_DIR = __DIR__ . '/../storage/queue';

function agent_cfg(?array $cfg = null): array
{
    $cfg = $cfg ?? app_config();
    return ($cfg['print']['agent'] ?? []) + ['token' => '', 'printer_type' => 'brother', 'job_ttl' => 90];
}

function queue_dir(): string
{
    if (!is_dir(QUEUE_DIR)) {
        mkdir(QUEUE_DIR, 0775, true);
    }
    return QUEUE_DIR;
}

/** Jalankan fungsi dengan kunci eksklusif antrean (aman bila ada beberapa kiosk/agen bersamaan) */
function queue_locked(callable $fn)
{
    $fh = fopen(queue_dir() . '/.lock', 'c');
    flock($fh, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function queue_valid_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

function queue_get(string $id): ?array
{
    if (!queue_valid_id($id)) {
        return null;
    }
    $f = queue_dir() . "/$id.json";
    $job = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($job) ? $job : null;
}

function queue_put(array $job): void
{
    $f = queue_dir() . '/' . $job['id'] . '.json';
    file_put_contents($f . '.tmp', json_encode($job, JSON_UNESCAPED_UNICODE));
    rename($f . '.tmp', $f);
}

/** Susun byte cetak sesuai jenis printer di venue */
function agent_build_bytes(array $d, ?array $cfg = null): string
{
    $cfg = $cfg ?? app_config();
    $a = agent_cfg($cfg);
    $copies = max(1, (int) $cfg['print']['copies']);
    if ($a['printer_type'] === 'escpos') {
        return str_repeat(escpos_build_ticket($d, $cfg), $copies);
    }
    $im = bql_render_ticket($d, $cfg);
    $bytes = bql_build_job($im, bql_cfg($cfg), $copies);
    imagedestroy($im);
    return $bytes;
}

/** Masukkan tiket ke antrean. Kembalikan id job. */
function queue_add(array $d, ?array $cfg = null): string
{
    $cfg = $cfg ?? app_config();
    $bytes = agent_build_bytes($d, $cfg);
    $id = bin2hex(random_bytes(8));
    $now = time();
    file_put_contents(queue_dir() . "/$id.bin", $bytes);
    queue_put([
        'id'       => $id,
        'created'  => $now,
        'expires'  => $now + max(20, (int) agent_cfg($cfg)['job_ttl']),
        'status'   => 'pending',         // pending → taken → done | failed
        'attempts' => 0,
        'label'    => trim(($d['kursi'] ?? '') . ' · ' . ($d['nama'] ?? '')),
        'bytes'    => strlen($bytes),
    ]);
    return $id;
}

/** Agen mengambil job tertua yang siap cetak (dengan sewa waktu agar tidak dicetak dua kali) */
function queue_claim(int $lease = 45): ?array
{
    return queue_locked(function () use ($lease) {
        $now = time();
        $files = glob(queue_dir() . '/*.json') ?: [];
        sort($files);
        $jobs = [];
        foreach ($files as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j)) $jobs[] = $j;
        }
        usort($jobs, function ($a, $b) { return $a['created'] <=> $b['created']; });
        foreach ($jobs as $j) {
            $open = $j['status'] === 'pending' || ($j['status'] === 'taken' && ($j['taken_at'] ?? 0) + $lease < $now);
            if (!$open) continue;
            if ($j['expires'] < $now) {
                $j['status'] = 'failed';
                $j['error'] = 'Kedaluwarsa — Print Agent tidak mengambil job tepat waktu.';
                queue_put($j);
                continue;
            }
            if ($j['attempts'] >= 3) {
                $j['status'] = 'failed';
                $j['error'] = $j['error'] ?? 'Gagal 3x dikirim ke printer.';
                queue_put($j);
                continue;
            }
            $j['status'] = 'taken';
            $j['taken_at'] = $now;
            $j['attempts']++;
            queue_put($j);
            return $j;
        }
        return null;
    });
}

function queue_finish(string $id, bool $ok, string $error = ''): bool
{
    return (bool) queue_locked(function () use ($id, $ok, $error) {
        $j = queue_get($id);
        if (!$j) return false;
        $j['status'] = $ok ? 'done' : 'failed';
        $j['finished'] = time();
        if (!$ok) $j['error'] = mb_substr($error ?: 'Gagal mencetak.', 0, 300);
        queue_put($j);
        @unlink(queue_dir() . "/$id.bin"); // data cetak (berisi nama peserta) tidak disimpan lebih lama
        return true;
    });
}

/** Status job untuk layar kiosk (job pending yang lewat batas waktu dianggap gagal) */
function queue_status(string $id): ?array
{
    $j = queue_get($id);
    if (!$j) return null;
    $now = time();
    if ($j['status'] === 'pending' && $j['expires'] < $now) {
        return ['status' => 'failed', 'error' => 'Print Agent tidak mengambil job. Periksa PC agen di venue.'];
    }
    if ($j['status'] === 'taken' && ($j['taken_at'] ?? 0) + 60 < $now) {
        return ['status' => 'failed', 'error' => 'Print Agent tidak melapor hasil cetak. Periksa printer & PC agen.'];
    }
    return ['status' => $j['status'], 'error' => $j['error'] ?? ''];
}

/** Bersihkan job lama: selesai > 1 jam, atau kedaluwarsa */
function queue_cleanup(): void
{
    if (mt_rand(1, 20) !== 1) return; // cukup sesekali
    $now = time();
    foreach (glob(queue_dir() . '/*.json') ?: [] as $f) {
        $j = json_decode((string) file_get_contents($f), true);
        if (!is_array($j)) continue;
        $old = in_array($j['status'], ['done', 'failed'], true) && ($j['finished'] ?? $j['created']) < $now - 3600;
        $stale = $j['expires'] < $now - 3600;
        if ($old || $stale) {
            @unlink($f);
            @unlink(substr($f, 0, -5) . '.bin');
        }
    }
}

/* ---------------- Status agen (heartbeat) ---------------- */
function agent_heartbeat(array $info): void
{
    file_put_contents(queue_dir() . '/agent.state', json_encode($info + ['last_seen' => time()], JSON_UNESCAPED_UNICODE));
}

function agent_state(): ?array
{
    $f = QUEUE_DIR . '/agent.state';
    $s = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($s)) return null;
    $s['online'] = ($s['last_seen'] ?? 0) > time() - 45;
    $s['pending'] = 0;
    foreach (glob(QUEUE_DIR . '/*.json') ?: [] as $f) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j) && in_array($j['status'], ['pending', 'taken'], true) && $j['expires'] > time()) $s['pending']++;
    }
    return $s;
}
