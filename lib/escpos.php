<?php
declare(strict_types=1);

/**
 * Cetak langsung ke printer thermal via perintah ESC/POS.
 * Dipakai untuk mode Bluetooth (port COM virtual hasil pairing Windows).
 * Port juga boleh berupa nama share printer, mis. \\localhost\POS80 (USB raw).
 */

const ESC = "\x1B";
const GS  = "\x1D";

/** Hilangkan karakter non-ASCII agar aman di code page printer */
function escpos_text(string $s): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;
    if ($ascii === false) {
        $ascii = $s;
    }
    return trim((string) preg_replace('/[^\x20-\x7E]/', '', $ascii));
}

/** GS ! n — ukuran karakter (lebar & tinggi 1..8) */
function escpos_size(int $w, int $h): string
{
    $w = max(1, min(8, $w));
    $h = max(1, min(8, $h));
    return GS . '!' . chr((($w - 1) << 4) | ($h - 1));
}

function escpos_wrap(string $text, int $cols): string
{
    return $text === '' ? '' : wordwrap($text, max(1, $cols), "\n", true) . "\n";
}

require_once __DIR__ . '/ticket.php';

/**
 * Susun byte ESC/POS untuk tiket.
 *   'image' (bawaan): tiket digambar sesuai Desain Tiket lalu dikirim sebagai raster — sama persis dengan pratinjau.
 *   'text'          : perintah teks ESC/POS (data kecil & cepat; dipakai Bluetooth dari Chrome / BLE yang lambat).
 */
function escpos_build_ticket(array $d, array $cfg, ?string $method = null): string
{
    $method = $method ?? ($cfg['print']['bluetooth']['method'] ?? 'image');
    if ($method === 'image') {
        return ticket_escpos_raster($d, $cfg, !empty($cfg['print']['bluetooth']['cut']));
    }
    return escpos_build_ticket_text($d, $cfg);
}

/** Tiket versi teks ESC/POS (tanpa gambar) */
function escpos_build_ticket_text(array $d, array $cfg): string
{
    $bt   = $cfg['print']['bluetooth'];
    $cols = max(24, (int) $bt['chars_per_line']);
    $line = str_repeat('-', $cols) . "\n";

    $seat = escpos_text(ticket_table_only((string) ($d['kursi'] ?? '')) ?: '-');   // nomor meja 2 digit, mis. 04
    $name = escpos_text($d['nama'] ?? '');

    // ukuran nomor kursi sebesar mungkin yang muat 1 baris (maks 6x)
    $seatSize = 6;
    while ($seatSize > 1 && strlen($seat) * $seatSize > $cols) {
        $seatSize--;
    }

    $out  = ESC . '@';                 // reset printer
    $out .= ESC . 't' . chr(0);        // code page PC437
    $out .= ESC . 'a' . chr(1);        // rata tengah

    $out .= ESC . 'E' . chr(1) . escpos_size(2, 2) . escpos_wrap(escpos_text($cfg['event_name']), intdiv($cols, 2));
    $out .= ESC . 'E' . chr(0) . escpos_size(1, 1) . escpos_wrap(escpos_text($cfg['event_sub']), $cols);
    $out .= $line;
    $out .= ESC . 'E' . chr(1) . strtoupper(escpos_text((string) ($cfg['kiosk']['seat_label'] ?? 'Nomor Kursi'))) . "\n";
    $out .= escpos_size($seatSize, $seatSize) . $seat . "\n";
    $out .= escpos_size(1, 1) . ESC . 'E' . chr(0) . $line;

    // nama: 2x lebar jika muat <= 2 baris, selain itu tinggi saja
    $half = intdiv($cols, 2);
    $nameW = (substr_count(wordwrap($name, $half, "\n", true), "\n") <= 1) ? 2 : 1;
    $out .= ESC . 'E' . chr(1) . escpos_size($nameW, 2) . escpos_wrap($name, intdiv($cols, $nameW));
    $out .= escpos_size(1, 1) . ESC . 'E' . chr(0);
    $out .= escpos_wrap(escpos_text($d['jabatan'] ?? ''), $cols);
    $out .= ESC . 'E' . chr(1) . escpos_wrap(escpos_text($d['instansi'] ?? ''), $cols) . ESC . 'E' . chr(0);
    if (isset($d['antrian'])) {   // nomor antrian (bila Desain Tiket memakainya)
        $out .= "\n" . ESC . 'E' . chr(1) . escpos_size(2, 2) . 'No. ' . str_pad((string) $d['antrian'], 3, '0', STR_PAD_LEFT) . "\n" . escpos_size(1, 1) . ESC . 'E' . chr(0);
    }

    // footer: ID kiri, waktu kanan
    $left  = escpos_text($d['id'] ?? '');
    $right = date('d/m/Y H:i');
    $pad   = max(1, $cols - strlen($left) - strlen($right));
    $out .= "\n" . ESC . 'a' . chr(0) . $left . str_repeat(' ', $pad) . $right . "\n";

    $out .= ESC . 'd' . chr(4);                      // feed 4 baris
    if (!empty($bt['cut'])) {
        $out .= GS . 'V' . chr(66) . chr(0);         // feed & potong sebagian
    }
    return $out;
}

/**
 * Kenali alamat printer jaringan. Kembalikan [host, port] atau null (COM / share Windows).
 *   "172.16.16.192"        → [172.16.16.192, 9100]   (port bawaan printer raw/JetDirect)
 *   "172.16.16.192:9100"   → [172.16.16.192, 9100]
 *   "tcp://printer.local:9100", "printer.local"
 */
function printer_net_address(string $port): ?array
{
    $port = trim($port);
    if (preg_match('#^(?:tcp://)?([a-z0-9.\-]+):(\d{2,5})$#i', $port, $m)) {
        return [$m[1], (int) $m[2]];
    }
    if (preg_match('#^(?:tcp://)?(\d{1,3}(?:\.\d{1,3}){3}|[a-z0-9\-]+(?:\.[a-z0-9\-]+)+)$#i', $port, $m)) {
        return [$m[1], 9100];
    }
    return null;
}

/** Kirim byte mentah ke port printer */
function escpos_send(string $port, string $data): void
{
    $port = trim($port);
    if ($port === '') {
        throw new RuntimeException('Port printer Bluetooth belum diatur.');
    }
    // Printer LAN/WiFi: "192.168.1.50:9100", "tcp://printer.local:9100", atau IP saja (→ port 9100)
    $net = printer_net_address($port);
    if ($net) {
        [$host, $tcpPort] = $net;
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $tcpPort, $errno, $errstr, 5);
        if (!$sock) {
            throw new RuntimeException("Printer jaringan $host:$tcpPort tidak terjangkau: " . $errstr);
        }
        stream_set_timeout($sock, 15);
        $total = strlen($data);
        $sent = 0;
        while ($sent < $total) { // kirim bertahap sampai seluruh data terkirim
            $w = fwrite($sock, substr($data, $sent, 8192));
            if ($w === false || $w === 0) {
                break;
            }
            $sent += $w;
        }
        fflush($sock);
        fclose($sock);
        if ($sent !== $total) {
            throw new RuntimeException("Data cetak tidak terkirim penuh ke $host:$tcpPort ($sent dari $total byte).");
        }
        return;
    }

    $device = preg_match('/^COM\d+$/i', $port) ? '\\\\.\\' . strtoupper($port) : $port;

    $err = '';
    set_error_handler(function ($no, $msg) use (&$err) { $err = $msg; return true; });
    $fh = fopen($device, 'r+b') ?: fopen($device, 'wb');
    restore_error_handler();

    if (!$fh) {
        throw new RuntimeException('Printer tidak terhubung di ' . $port . '. Pastikan printer menyala & sudah di-pair. ' . $err);
    }
    $written = fwrite($fh, $data);
    fflush($fh);
    fclose($fh);
    if ($written !== strlen($data)) {
        throw new RuntimeException('Data cetak tidak terkirim penuh ke ' . $port . '.');
    }
}

/** Cetak tiket ke printer Bluetooth sesuai konfigurasi */
function escpos_print_ticket(array $d): void
{
    $cfg    = app_config();
    $data   = escpos_build_ticket($d, $cfg);
    $copies = max(1, (int) $cfg['print']['copies']);
    escpos_send($cfg['print']['bluetooth']['port'], str_repeat($data, $copies));
}

/** Daftar port COM di Windows beserta nama perangkat */
function escpos_list_ports(): array
{
    if (stripos(PHP_OS, 'WIN') !== 0 || !function_exists('shell_exec')) {
        return [];
    }
    $cmd = 'powershell -NoProfile -NonInteractive -Command "Get-CimInstance Win32_PnPEntity | Where-Object { $_.Name -match \'\(COM\d+\)\' } | ForEach-Object { $_.Name }"';
    $out = (string) @shell_exec($cmd);
    $ports = [];
    foreach (preg_split('/\r?\n/', trim($out)) as $name) {
        if (preg_match('/\((COM\d+)\)/', $name, $m)) {
            $ports[$m[1]] = trim($name);
        }
    }
    ksort($ports, SORT_NATURAL);
    return $ports;
}
