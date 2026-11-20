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
    return ($cfg['print']['agent'] ?? []) + ['token' => '', 'printer_type' => 'brother', 'job_ttl' => 90, 'failover' => true];
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

/* ---------------- Stasiun cetak ----------------
 * Setiap tablet/kiosk memilih stasiun (mis. "pintu-utara"); setiap agen melayani satu stasiun
 * dengan satu printer. Job hanya diambil agen stasiunnya. Stasiun kosong = boleh dicetak agen mana pun.
 * Pengalihan (failover, bisa dimatikan di Pengaturan): bila agen stasiun tujuan offline, job dicetak
 * di stasiun lain yang online, dan kiosk memberi tahu peserta di printer mana tiketnya keluar. */
const STATION_FAILOVER_WAIT = 8;   // detik job menunggu sebelum boleh diambil stasiun lain (bila agen tujuan offline)

function station_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim($s, '-'), 0, 30);
}

/** Masukkan tiket ke antrean untuk sebuah stasiun.
 *  Kembalikan [ 'id', 'station' (tujuan akhir), 'requested', 'rerouted' ]. */
function queue_add(array $d, ?array $cfg = null, string $station = ''): array
{
    $cfg = $cfg ?? app_config();
    $requested = station_slug($station);
    $target = $requested;
    $rerouted = false;
    if ($requested !== '' && !empty(agent_cfg($cfg)['failover']) && !agent_station_online($requested)) {
        $alt = agent_best_station($requested);
        if ($alt !== null) {
            $target = $alt;
            $rerouted = true;
        }
    }
    $bytes = agent_build_bytes($d, $cfg);
    $id = bin2hex(random_bytes(8));
    $now = time();
    file_put_contents(queue_dir() . "/$id.bin", $bytes);
    queue_put([
        'id'        => $id,
        'created'   => $now,
        'expires'   => $now + max(20, (int) agent_cfg($cfg)['job_ttl']),
        'status'    => 'pending',         // pending → taken → done | failed
        'attempts'  => 0,
        'station'   => $target,
        'requested' => $requested,
        'label'     => trim(($d['kursi'] ?? '') . ' · ' . ($d['nama'] ?? '')),
        'bytes'     => strlen($bytes),
    ]);
    return ['id' => $id, 'station' => $target, 'requested' => $requested, 'rerouted' => $rerouted];
}

/** Bolehkah agen stasiun $station mengambil job ini? */
function queue_job_for_station(array $j, string $station, bool $failover, int $now): bool
{
    $js = (string) ($j['station'] ?? '');
    if ($js === '' || $station === '' || $js === $station) {
        return true; // job tanpa stasiun, agen lama tanpa stasiun, atau stasiun yang sama
    }
    // pengalihan: agen stasiun tujuan offline & job sudah menunggu beberapa detik
    return $failover && $now - (int) $j['created'] >= STATION_FAILOVER_WAIT && !agent_station_online($js);
}

/** Agen mengambil job tertua untuk stasiunnya (dengan sewa waktu agar tidak dicetak dua kali) */
function queue_claim(string $station = '', int $lease = 20): ?array
{
    $failover = !empty(agent_cfg()['failover']);
    return queue_locked(function () use ($lease, $station, $failover) {
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
            if (!queue_job_for_station($j, $station, $failover, $now)) continue;
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
            $j['printed_by'] = $station !== '' ? $station : (string) ($j['station'] ?? '');
            queue_put($j);
            return $j;
        }
        return null;
    });
}

/** Kembalikan job yang sudah diambil ke antrean (agen putus sebelum menerimanya) */
function queue_release(string $id): void
{
    queue_locked(function () use ($id) {
        $j = queue_get($id);
        if ($j && $j['status'] === 'taken') {
            $j['status'] = 'pending';
            $j['attempts'] = max(0, (int) $j['attempts'] - 1);
            unset($j['taken_at'], $j['printed_by']);
            queue_put($j);
        }
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
    $printedAt = (string) ($j['printed_by'] ?? $j['station'] ?? '');
    $requested = (string) ($j['requested'] ?? '');
    return [
        'status'   => $j['status'],
        'error'    => $j['error'] ?? '',
        'station'  => $printedAt,                                        // stasiun yang mencetak
        'rerouted' => $requested !== '' && $printedAt !== '' && $printedAt !== $requested,
    ];
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

/* ---------------- Status agen (heartbeat, satu file per agen) ---------------- */
const AGENT_ONLINE_SECONDS = 25;   // agen hidup memperbarui status terus-menerus (long-poll)

function agent_states_dir(): string
{
    $d = queue_dir() . '/agents';
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}

function agent_heartbeat(array $info): void
{
    $station = station_slug((string) ($info['station'] ?? ''));
    // satu file per stasiun; agen tanpa stasiun dibedakan dari nama + IP
    $key = $station !== '' ? 'st-' . $station : 'any-' . substr(md5(($info['name'] ?? '') . '|' . ($info['ip'] ?? '')), 0, 10);
    $info['station'] = $station;
    file_put_contents(agent_states_dir() . "/$key.json", json_encode($info + ['last_seen' => time()], JSON_UNESCAPED_UNICODE));
}

/** Semua agen yang pernah terhubung (24 jam terakhir), dengan status online & jumlah antrean per stasiun */
function agent_states(): array
{
    $now = time();
    $pending = [];
    foreach (glob(QUEUE_DIR . '/*.json') ?: [] as $f) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j) && in_array($j['status'], ['pending', 'taken'], true) && $j['expires'] > $now) {
            $k = (string) ($j['station'] ?? '');
            $pending[$k] = ($pending[$k] ?? 0) + 1;
        }
    }
    $out = [];
    foreach (glob(QUEUE_DIR . '/agents/*.json') ?: [] as $f) {
        $s = json_decode((string) file_get_contents($f), true);
        if (!is_array($s) || ($s['last_seen'] ?? 0) < $now - 86400) continue;
        $s['online'] = ($s['last_seen'] ?? 0) > $now - AGENT_ONLINE_SECONDS;
        $s['pending'] = $pending[$s['station'] ?? ''] ?? 0;
        $out[] = $s;
    }
    usort($out, function ($a, $b) { return [$b['online'], $a['station']] <=> [$a['online'], $b['station']]; });
    return $out;
}

function agent_station_online(string $station): bool
{
    foreach (agent_states() as $s) {
        if ($s['online'] && ($s['station'] ?? '') === $station) return true;
    }
    return false;
}

/** Stasiun online lain dengan antrean paling sedikit (untuk pengalihan) */
function agent_best_station(string $exclude): ?string
{
    $best = null;
    foreach (agent_states() as $s) {
        $st = (string) ($s['station'] ?? '');
        if (!$s['online'] || $st === '' || $st === $exclude) continue;
        if ($best === null || $s['pending'] < $best['pending']) $best = $s;
    }
    return $best ? (string) $best['station'] : null;
}

/** Ringkasan untuk Pengaturan (kompatibel): agen online pertama, atau yang terakhir terlihat */
function agent_state(): ?array
{
    $all = agent_states();
    return $all[0] ?? null;
}

/** Daftar stasiun (untuk pilihan di kiosk) */
function agent_station_list(): array
{
    $list = [];
    foreach (agent_states() as $s) {
        $st = (string) ($s['station'] ?? '');
        if ($st !== '' && !isset($list[$st])) $list[$st] = ['station' => $st, 'online' => (bool) $s['online']];
    }
    return array_values($list);
}
