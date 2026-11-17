<?php
declare(strict_types=1);

/**
 * Cetak ke Brother QL-800/810W/820NWB dengan Brother Raster Command (bukan ESC/POS).
 * Tiket digambar sebagai gambar 1-bit 300 dpi (GD), lalu dikirim baris demi baris.
 * Transport sama dengan mode server lain: port COM (Bluetooth SPP) atau IP:9100 (Wi-Fi/LAN).
 *
 * Media DK-22205 = label kontinu 62 mm:
 *   lebar area cetak 696 dot, kepala cetak 720 dot (90 byte/baris), margin kanan 12 dot.
 * Rujukan: Brother "Raster Command Reference QL-800/810W/820NWB" & pustaka brother_ql.
 */

require_once __DIR__ . '/escpos.php'; // escpos_send(): kirim byte mentah ke COM / IP:port

const BQL_HEAD_DOTS = 720;
const BQL_MEDIA = [
    'DK-22205' => ['name' => 'DK-22205 — label kontinu 62 mm', 'width_mm' => 62, 'printable' => 696, 'right_margin' => 12, 'feed_margin' => 35],
];

function bql_cfg(?array $cfg = null): array
{
    $cfg = $cfg ?? app_config();
    $b = $cfg['print']['brother'] ?? [];
    return $b + ['port' => '', 'cut' => true, 'media' => 'DK-22205', 'font_regular' => '', 'font_bold' => ''];
}

/** Cari file font TTF (config → font Windows → font Linux) */
function bql_font(array $b, bool $bold): string
{
    $candidates = array_filter([
        $bold ? $b['font_bold'] : $b['font_regular'],
        $bold ? 'C:/Windows/Fonts/arialbd.ttf' : 'C:/Windows/Fonts/arial.ttf',
        $bold ? 'C:/Windows/Fonts/segoeuib.ttf' : 'C:/Windows/Fonts/segoeui.ttf',
        $bold ? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    ]);
    foreach ($candidates as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    throw new RuntimeException('Font TrueType tidak ditemukan untuk mencetak ke Brother. Isi print.brother.font_regular / font_bold di config.php.');
}

/* ------------------------------------------------------------------
 * Gambar tiket
 * ------------------------------------------------------------------ */
function bql_text_width(string $font, float $size, string $text): int
{
    $box = imagettfbbox($size, 0, $font, $text);
    return (int) abs($box[2] - $box[0]);
}

/** Ukuran huruf terbesar (≤ $max) agar teks muat selebar $maxW */
function bql_fit(string $font, float $max, float $min, string $text, int $maxW): float
{
    $size = $max;
    while ($size > $min && bql_text_width($font, $size, $text) > $maxW) {
        $size -= 1;
    }
    return $size;
}

/** Bungkus teks per kata agar muat selebar $maxW */
function bql_wrap(string $font, float $size, string $text, int $maxW): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
        $try = $line === '' ? $word : $line . ' ' . $word;
        if ($line !== '' && bql_text_width($font, $size, $try) > $maxW) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

/**
 * Gambar tiket untuk Brother → resource/objek GD (lebar = area cetak media, tinggi menyesuaikan isi)
 */
function bql_render_ticket(array $d, ?array $cfg = null)
{
    $cfg = $cfg ?? app_config();
    $b = bql_cfg($cfg);
    $media = BQL_MEDIA[$b['media']] ?? BQL_MEDIA['DK-22205'];
    $W = $media['printable'];
    $pad = 18;
    $inner = $W - 2 * $pad;
    $reg = bql_font($b, false);
    $bold = bql_font($b, true);

    $im = imagecreatetruecolor($W, 2400);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $W, 2400, $white);
    $y = 24;

    // tulis satu baris rata tengah; $y = batas atas baris, kembalikan batas bawah
    $center = function (string $text, string $font, float $size, int $gapAfter = 10) use ($im, $black, $W, &$y): void {
        $box = imagettfbbox($size, 0, $font, 'ÁgjpqyX' . $text);
        $ascent = abs($box[7]);
        $height = abs($box[1]) + $ascent;
        $w = bql_text_width($font, $size, $text);
        imagettftext($im, $size, 0, (int) (($W - $w) / 2), $y + $ascent, $black, $font, $text);
        $y += $height + $gapAfter;
    };
    $dash = function (int $gap = 16) use ($im, $black, $W, $pad, &$y): void {
        $y += $gap;
        for ($x = $pad; $x < $W - $pad; $x += 22) {
            imagefilledrectangle($im, $x, $y, min($x + 12, $W - $pad), $y + 3, $black);
        }
        $y += 4 + $gap;
    };

    // judul acara
    $event = (string) $cfg['event_name'];
    $center($event, $bold, bql_fit($bold, 34, 18, $event, $inner), 4);
    if (!empty($cfg['event_sub'])) {
        $center((string) $cfg['event_sub'], $reg, bql_fit($reg, 20, 12, (string) $cfg['event_sub'], $inner), 0);
    }
    $dash();

    // nomor meja (besar)
    $label = mb_strtoupper((string) ($cfg['kiosk']['seat_label'] ?? 'Nomor Kursi'));
    $center($label, $bold, 22, 6);
    $seat = (string) ($d['kursi'] ?? '-');
    $center($seat, $bold, bql_fit($bold, 130, 40, $seat, $inner), 4);
    $dash();

    // nama (maks 3 baris, huruf mengecil bila panjang)
    $name = (string) ($d['nama'] ?? '');
    if ($name !== '') {
        $size = 34;
        do {
            $lines = bql_wrap($bold, $size, $name, $inner);
            $size -= 2;
        } while (count($lines) > 3 && $size > 18);
        foreach ($lines as $l) {
            $center($l, $bold, $size + 2, 4);
        }
        $y += 6;
    }
    foreach (['jabatan' => [$reg, 22], 'instansi' => [$bold, 24]] as $k => [$font, $size]) {
        $t = trim((string) ($d[$k] ?? ''));
        if ($t === '') continue;
        foreach (bql_wrap($font, $size, $t, $inner) as $l) {
            $center($l, $font, $size, 4);
        }
    }

    // footer: ID kiri, waktu kanan
    $y += 14;
    $foot = 16;
    $id = (string) ($d['id'] ?? '');
    $time = date('d/m/Y H:i');
    $box = imagettfbbox($foot, 0, $reg, 'Ag');
    imagettftext($im, $foot, 0, $pad, $y + abs($box[7]), $black, $reg, $id);
    imagettftext($im, $foot, 0, $W - $pad - bql_text_width($reg, $foot, $time), $y + abs($box[7]), $black, $reg, $time);
    $y += abs($box[1]) + abs($box[7]) + 20;

    // potong ke tinggi isi
    $out = imagecreatetruecolor($W, $y);
    imagecopy($out, $im, 0, 0, 0, 0, $W, $y);
    imagedestroy($im);
    return $out;
}

/* ------------------------------------------------------------------
 * Gambar → perintah Brother Raster
 * ------------------------------------------------------------------ */

/** Ubah gambar menjadi baris raster 90 byte (1 = titik hitam), sudah dicerminkan sesuai arah kepala cetak */
function bql_raster_lines($im, array $media): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    $left = BQL_HEAD_DOTS - $media['printable'] - $media['right_margin']; // posisi tempel di baris 720 dot
    $bytes = intdiv(BQL_HEAD_DOTS, 8);
    $lines = [];
    for ($y = 0; $y < $h; $y++) {
        $row = array_fill(0, $bytes, 0);
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $lum = ((($c >> 16) & 0xFF) * 299 + (($c >> 8) & 0xFF) * 587 + ($c & 0xFF) * 114) / 1000;
            if ($lum < 128) {
                // dicerminkan horizontal: titik kiri gambar = bit terakhir baris
                $dot = BQL_HEAD_DOTS - 1 - ($left + $x);
                $row[$dot >> 3] |= 0x80 >> ($dot & 7);
            }
        }
        $lines[] = pack('C*', ...$row);
    }
    return $lines;
}

/** Susun byte perintah lengkap untuk satu atau beberapa salinan tiket */
function bql_build_job($im, array $b, int $copies = 1): string
{
    $media = BQL_MEDIA[$b['media']] ?? BQL_MEDIA['DK-22205'];
    $lines = bql_raster_lines($im, $media);
    $n = count($lines);

    $out = str_repeat("\x00", 400);          // invalidate (kosongkan buffer)
    $out .= "\x1B\x40";                     // ESC @  initialize
    $out .= "\x1B\x69\x61\x01";             // ESC i a 01  mode raster
    for ($page = 0; $page < $copies; $page++) {
        // ESC i z: info cetak — flag (recover|kind|width|length|kualitas), kontinu (0x0A), lebar 62 mm, panjang 0, jumlah baris
        $out .= "\x1B\x69\x7A" . chr(0x80 | 0x40 | 0x02 | 0x04 | 0x08) . "\x0A" . chr($media['width_mm']) . "\x00"
              . pack('V', $n) . ($page === 0 ? "\x00" : "\x01") . "\x00";
        $out .= "\x1B\x69\x4D" . ($b['cut'] ? "\x40" : "\x00");   // ESC i M  auto cut
        $out .= "\x1B\x69\x41\x01";                               // ESC i A  potong setiap 1 label
        $out .= "\x1B\x69\x4B" . ($b['cut'] ? "\x08" : "\x00");   // ESC i K  potong di akhir
        $out .= "\x1B\x69\x64" . pack('v', $media['feed_margin']); // ESC i d  margin
        foreach ($lines as $l) {
            $out .= "g\x00" . chr(strlen($l)) . $l;               // g 00 5A + 90 byte data
        }
        $out .= $page === $copies - 1 ? "\x1A" : "\x0C";          // cetak (+ keluarkan) / halaman berikutnya
    }
    return $out;
}

/**
 * Tanyakan status printer (ESC i S) lewat jaringan — tidak mencetak apa pun.
 * Balasan 32 byte: [8] error-1, [9] error-2, [10] lebar media (mm), [11] jenis media, [18] jenis status.
 */
function bql_status(string $port): array
{
    $net = printer_net_address($port);
    if (!$net) {
        throw new RuntimeException('Cek status hanya tersedia untuk printer Wi-Fi/LAN (IP). Untuk Bluetooth gunakan Test Cetak.');
    }
    // 1) halaman status web printer (paling andal untuk QL-820NWB lewat jaringan)
    $web = bql_status_web($net[0]);
    if ($web) {
        return $web;
    }
    // 2) cadangan: perintah status ESC i S lewat port raw
    [$host, $tcp] = $net;
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen($host, $tcp, $errno, $errstr, 5);
    if (!$sock) {
        throw new RuntimeException("Printer $host:$tcp tidak terjangkau: $errstr");
    }
    stream_set_timeout($sock, 5);
    fwrite($sock, str_repeat("\x00", 400) . "\x1B\x40" . "\x1B\x69\x53");
    fflush($sock);
    $resp = '';
    while (strlen($resp) < 32) {
        $chunk = fread($sock, 32 - strlen($resp));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $resp .= $chunk;
    }
    fclose($sock);

    if (strlen($resp) < 32 || ord($resp[0]) !== 0x80) {
        return [
            'ok' => true,
            'errors' => [],
            'info' => ["Port $host:$tcp terbuka, tetapi printer tidak mengirim status (wajar pada sebagian printer Brother). Gunakan Test Cetak."],
            'raw' => bin2hex($resp),
        ];
    }
    $e1 = ord($resp[8]);
    $e2 = ord($resp[9]);
    $err1 = [0x01 => 'Label tidak terpasang', 0x02 => 'Label habis', 0x04 => 'Pemotong macet', 0x10 => 'Printer sedang dipakai',
             0x20 => 'Printer mati', 0x40 => 'Adaptor tegangan tinggi', 0x80 => 'Kipas bermasalah'];
    $err2 = [0x01 => 'Jenis label salah / perlu diganti', 0x02 => 'Buffer penuh', 0x04 => 'Kesalahan komunikasi', 0x08 => 'Buffer komunikasi penuh',
             0x10 => 'Tutup printer terbuka', 0x20 => 'Dibatalkan', 0x40 => 'Label tidak bisa diumpan', 0x80 => 'Kesalahan sistem'];
    $errors = [];
    foreach ($err1 as $bit => $txt) if ($e1 & $bit) $errors[] = $txt;
    foreach ($err2 as $bit => $txt) if ($e2 & $bit) $errors[] = $txt;

    $width = ord($resp[10]);
    $type = ord($resp[11]);
    $typeName = $type === 0x0A ? 'kontinu' : ($type === 0x0B ? 'die-cut (potongan jadi)' : ($type === 0x00 ? 'tidak ada' : sprintf('0x%02X', $type)));
    if ($width && $width !== 62) {
        $errors[] = "Gulungan terpasang lebar $width mm — aplikasi diatur untuk DK-22205 (62 mm)";
    } elseif ($type === 0x0B) {
        $errors[] = 'Gulungan terpasang jenis die-cut — aplikasi diatur untuk label kontinu DK-22205';
    }
    return [
        'ok'     => !$errors,
        'errors' => $errors,
        'media'  => $width ? "$width mm, $typeName" : 'tidak terdeteksi',
        'raw'    => bin2hex($resp),
    ];
}

/**
 * Baca halaman http://IP/general/status.html bawaan printer Brother:
 * Device Status (READY / COVER OPEN / NO MEDIA …), Emulation (mode perintah), Media Status, Media Type.
 */
function bql_status_web(string $host): ?array
{
    $ch = curl_init('http://' . $host . '/general/status.html');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'SeatKiosk/1.0']);
    $html = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($html) || $code !== 200 || stripos($html, 'Device') === false) {
        return null;
    }
    $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
    $tokens = array_values(array_filter(array_map(function ($t) {
        return trim(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }, preg_split('#<[^>]+>#', $html) ?: []), 'strlen'));
    $after = function (string $label) use ($tokens): string {
        $i = array_search($label, $tokens, true);
        return $i === false ? '' : (string) ($tokens[$i + 1] ?? '');
    };
    // "Device Status" muncul juga di menu; ambil kemunculan terakhir yang diikuti nilai
    $device = '';
    foreach ($tokens as $i => $t) {
        if ($t === 'Device Status' && isset($tokens[$i + 1]) && $tokens[$i + 1] !== 'Total Print Length(m)') {
            $device = $tokens[$i + 1];
        }
    }
    $emulation = $after('Emulation');
    $mediaStatus = $after('Media Status');
    $mediaType = $after('Media Type');

    $errors = [];
    $info = [];
    if ($device !== '' && strcasecmp($device, 'READY') !== 0 && stripos($device, 'Sleep') === false) {
        $errors[] = "Status printer: $device";
    }
    if ($mediaStatus !== '' && stripos($mediaStatus, 'Not Empty') === false) {
        $errors[] = "Gulungan: $mediaStatus";
    }
    if ($mediaType !== '' && strpos($mediaType, '62') === false) {
        $errors[] = "Gulungan terpasang $mediaType — aplikasi diatur untuk DK-22205 (62 mm)";
    }
    if ($emulation !== '' && stripos($emulation, 'Raster') === false) {
        $info[] = "Mode perintah printer: $emulation. Disarankan diubah ke \"Raster\" agar tiket dari aplikasi pasti diterima.";
    }
    return [
        'ok'        => !$errors,
        'errors'    => $errors,
        'info'      => $info,
        'device'    => $device,
        'emulation' => $emulation,
        'media'     => trim($mediaType . ($mediaStatus ? " ($mediaStatus)" : '')),
    ];
}

/** Cetak tiket ke Brother QL sesuai pengaturan */
function bql_print_ticket(array $d, ?array $cfg = null): void
{
    $cfg = $cfg ?? app_config();
    $b = bql_cfg($cfg);
    if (trim((string) $b['port']) === '') {
        throw new RuntimeException('Port printer Brother belum diatur (COM Bluetooth atau IP:9100).');
    }
    $im = bql_render_ticket($d, $cfg);
    $job = bql_build_job($im, $b, max(1, (int) $cfg['print']['copies']));
    imagedestroy($im);
    escpos_send((string) $b['port'], $job);
}
