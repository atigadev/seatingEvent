<?php
declare(strict_types=1);

/**
 * PRINT AGENT — dijalankan di PC venue (satu jaringan dengan printer).
 * Mengambil job cetak dari server (hosting) lewat HTTPS, mengirimnya ke printer lokal,
 * lalu melapor hasilnya. Hanya koneksi keluar — printer tidak dibuka ke internet.
 *
 * Jalankan:  php agent/print-agent.php [path-config]      (atau agent\start-agent.bat)
 * Berhenti:  Ctrl+C
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya untuk command line.');
}

const AGENT_VERSION = '1.0';
require __DIR__ . '/../lib/escpos.php'; // escpos_send(): kirim byte mentah ke IP:9100 / COM

date_default_timezone_set('Asia/Jakarta');

$cfgFile = $argv[1] ?? __DIR__ . '/agent-config.php';
if (!is_file($cfgFile)) {
    fwrite(STDERR, "Config tidak ditemukan: $cfgFile\nSalin agent-config.example.php menjadi agent-config.php lalu isi.\n");
    exit(1);
}
$cfg = (require $cfgFile) + ['name' => gethostname() ?: 'Agen', 'verify_ssl' => true, 'wait' => 15];
foreach (['server', 'token', 'printer'] as $k) {
    if (empty($cfg[$k]) || strpos((string) $cfg[$k], 'ISI-') === 0) {
        fwrite(STDERR, "Isi '$k' di $cfgFile terlebih dahulu.\n");
        exit(1);
    }
}
$server = rtrim((string) $cfg['server'], '/');
$printer = (string) $cfg['printer'];

function logline(string $msg): void
{
    echo '[' . date('H:i:s') . "] $msg\n";
}

/** Panggil API agen di server */
function call(string $url, array $cfg, ?array $post = null, int $timeout = 30): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'SeatPrintAgent/' . AGENT_VERSION,
        CURLOPT_HTTPHEADER     => [
            'X-Agent-Token: ' . $cfg['token'],
            'X-Agent-Name: ' . $cfg['name'],
            'X-Agent-Printer: ' . $cfg['printer'],
            'X-Agent-Version: ' . AGENT_VERSION,
        ],
        CURLOPT_SSL_VERIFYPEER => (bool) $cfg['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $cfg['verify_ssl'] ? 2 : 0,
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('Tidak bisa menghubungi server: ' . $err);
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        throw new RuntimeException("Respons server tidak valid (HTTP $code).");
    }
    if (empty($json['ok'])) {
        throw new RuntimeException(($json['error'] ?? 'Server menolak') . " (HTTP $code)");
    }
    return $json;
}

// ---------- mulai ----------
echo "==============================================\n";
echo " Seat Kiosk — Print Agent v" . AGENT_VERSION . "\n";
echo " Server : $server\n";
echo " Printer: $printer\n";
echo " Nama   : {$cfg['name']}\n";
echo "==============================================\n";

$net = printer_net_address($printer);
if ($net) {
    $s = @fsockopen($net[0], $net[1], $errno, $errstr, 4);
    if ($s) {
        fclose($s);
        logline("Printer {$net[0]}:{$net[1]} terjangkau.");
    } else {
        logline("PERINGATAN: printer {$net[0]}:{$net[1]} belum terjangkau ($errstr). Agen tetap berjalan.");
    }
}

$backoff = 2;
$wasOnline = false;
while (true) {
    try {
        $wait = max(0, min(20, (int) $cfg['wait']));
        $res = call("$server/api/agent.php?action=next&wait=$wait", $cfg, null, $wait + 25);
        if (!$wasOnline) {
            logline('Terhubung ke server. Menunggu tiket…');
            $wasOnline = true;
        }
        $backoff = 2;
        if (empty($res['job'])) {
            continue; // long-poll habis tanpa job → langsung tanya lagi
        }
        $job = $res['job'];
        $bytes = base64_decode((string) $job['data'], true);
        $ok = false;
        $error = '';
        $t0 = microtime(true);
        try {
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Data cetak rusak.');
            }
            escpos_send($printer, $bytes);
            $ok = true;
            logline(sprintf('✓ Tercetak  %s  (%s KB, %.1f dtk)', $job['label'] ?? $job['id'], number_format(strlen($bytes) / 1024, 0), microtime(true) - $t0));
        } catch (Throwable $e) {
            $error = $e->getMessage();
            logline('✗ Gagal cetak ' . ($job['label'] ?? $job['id']) . ': ' . $error);
        }
        // laporkan hasil (coba ulang singkat bila jaringan putus)
        for ($i = 0; $i < 3; $i++) {
            try {
                call("$server/api/agent.php?action=done&id=" . urlencode((string) $job['id']), $cfg, ['ok' => $ok ? '1' : '0', 'error' => $error], 20);
                break;
            } catch (Throwable $e) {
                sleep(2);
            }
        }
    } catch (Throwable $e) {
        if ($wasOnline || $backoff === 2) {
            logline('Koneksi ke server bermasalah: ' . $e->getMessage() . " — coba lagi dalam {$backoff} dtk");
        }
        $wasOnline = false;
        if (stripos($e->getMessage(), 'token') !== false) {
            $backoff = 30; // token salah: jangan membanjiri server
        }
        sleep($backoff);
        $backoff = min(30, $backoff * 2);
    }
}
