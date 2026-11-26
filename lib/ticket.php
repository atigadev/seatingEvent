<?php
declare(strict_types=1);

/**
 * DESAIN TIKET — satu desain untuk semua printer.
 *
 * Tiket digambar sebagai gambar hitam-putih (GD) selebar area cetak kertas, lalu gambar yang sama dipakai:
 *   - Kabel USB (browser)   : <img> dicetak dengan @page sesuai ukuran
 *   - Thermal ESC/POS       : raster GS v 0
 *   - Brother QL (DK-22205) : Brother Raster (lebar printer 696 titik)
 * Desain disimpan di storage/settings.json ('ticket') dan diatur dari admin-tiket.php.
 *
 * Satuan: ukuran huruf dalam pt (1/72 inci), jarak & kertas dalam mm → dikonversi ke titik printer (dpi).
 */

/** Ukuran kertas siap pakai (area cetak efektif) */
const TICKET_PAPERS = [
    'thermal80' => ['label' => 'Thermal 80 mm (area cetak 72 mm, 203 dpi)', 'width_mm' => 72.0, 'dpi' => 203, 'paper_mm' => 80],
    'thermal58' => ['label' => 'Thermal 58 mm (area cetak 48 mm, 203 dpi)', 'width_mm' => 48.0, 'dpi' => 203, 'paper_mm' => 58],
    'brother62' => ['label' => 'Brother DK-22205 62 mm (area cetak 58,9 mm, 300 dpi)', 'width_mm' => 58.93, 'dpi' => 300, 'paper_mm' => 62],
    'custom'    => ['label' => 'Ukuran lain (atur sendiri)', 'width_mm' => 72.0, 'dpi' => 203, 'paper_mm' => 80],
];

/** Data peserta yang bisa ditampilkan */
const TICKET_FIELDS = [
    'kursi'      => 'Nomor meja / kursi (lengkap, mis. 04E)',
    'meja'       => 'Nomor meja saja (2 digit tanpa huruf kursi, mis. 04)',
    'nama'       => 'Nama',
    'jabatan'    => 'Jabatan',
    'instansi'   => 'Instansi',
    'id'         => 'ID peserta (isi QR)',
    'event'      => 'Nama acara',
    'event_sub'  => 'Sub judul acara',
    'seat_label' => 'Label "Nomor Meja"',
    'antrian'    => 'Nomor antrian (urut)',
    'datetime'   => 'Tanggal & jam cetak',
];

/** Desain bawaan (meniru tiket sebelumnya) */
function ticket_default(): array
{
    return [
        'paper' => ['preset' => 'thermal80', 'width_mm' => 72, 'dpi' => 203, 'height_mm' => 0, 'margin_mm' => 3],
        'elements' => [
            ['type' => 'field', 'field' => 'event', 'size' => 16, 'bold' => true, 'align' => 'center'],
            ['type' => 'field', 'field' => 'event_sub', 'size' => 9, 'align' => 'center'],
            ['type' => 'line', 'style' => 'dashed', 'thick' => 0.4, 'space' => 2],
            ['type' => 'field', 'field' => 'seat_label', 'size' => 9, 'bold' => true, 'align' => 'center', 'upper' => true, 'spacing' => 2],
            ['type' => 'field', 'field' => 'meja', 'size' => 54, 'bold' => true, 'align' => 'center', 'fit' => true],
            ['type' => 'line', 'style' => 'dashed', 'thick' => 0.4, 'space' => 2],
            ['type' => 'field', 'field' => 'nama', 'size' => 13, 'bold' => true, 'align' => 'center', 'lines' => 3],
            ['type' => 'field', 'field' => 'jabatan', 'size' => 9.5, 'align' => 'center', 'lines' => 2],
            ['type' => 'field', 'field' => 'instansi', 'size' => 10, 'bold' => true, 'align' => 'center', 'lines' => 2],
            ['type' => 'counter', 'prefix' => 'No. Antrian ', 'digits' => 3, 'size' => 12, 'bold' => true, 'align' => 'center', 'hidden' => true],
            ['type' => 'spacer', 'height' => 2],
            ['type' => 'pair', 'left' => 'id', 'right' => 'datetime', 'size' => 7],
        ],
    ];
}

/** Desain aktif (tersimpan, atau bawaan) — sudah dinormalisasi */
function ticket_template(?array $cfg = null): array
{
    $saved = load_settings()['ticket'] ?? null;
    return ticket_normalize(is_array($saved) ? $saved : ticket_default());
}

/** Rapikan & batasi nilai desain (dipakai juga untuk memvalidasi kiriman dari halaman admin) */
function ticket_normalize(array $t): array
{
    $p = (array) ($t['paper'] ?? []);
    $preset = isset(TICKET_PAPERS[$p['preset'] ?? '']) ? $p['preset'] : 'thermal80';
    $base = TICKET_PAPERS[$preset];
    $paper = [
        'preset'    => $preset,
        'width_mm'  => $preset === 'custom' ? max(20.0, min(210.0, (float) ($p['width_mm'] ?? 72))) : $base['width_mm'],
        'dpi'       => $preset === 'custom' ? (int) max(100, min(600, (int) ($p['dpi'] ?? 203))) : $base['dpi'],
        'height_mm' => max(0.0, min(400.0, (float) ($p['height_mm'] ?? 0))),     // 0 = otomatis mengikuti isi
        'margin_mm' => max(0.0, min(15.0, (float) ($p['margin_mm'] ?? 3))),
    ];
    $types = ['field', 'text', 'line', 'spacer', 'counter', 'pair'];
    $els = [];
    foreach (array_slice((array) ($t['elements'] ?? []), 0, 40) as $e) {
        if (!is_array($e) || !in_array($e['type'] ?? '', $types, true)) continue;
        $n = ['type' => $e['type'], 'hidden' => !empty($e['hidden'])];
        $n['size'] = max(5.0, min(160.0, (float) ($e['size'] ?? 10)));
        $n['bold'] = !empty($e['bold']);
        $n['align'] = in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? $e['align'] : 'center';
        $n['upper'] = !empty($e['upper']);
        $n['spacing'] = max(0.0, min(20.0, (float) ($e['spacing'] ?? 0)));   // jarak antar huruf (pt)
        $n['lines'] = max(1, min(6, (int) ($e['lines'] ?? 1)));
        $n['fit'] = !empty($e['fit']);
        $n['gap'] = max(0.0, min(20.0, (float) ($e['gap'] ?? 1)));           // jarak setelah elemen (mm)
        switch ($e['type']) {
            case 'field':
                $n['field'] = isset(TICKET_FIELDS[$e['field'] ?? '']) ? $e['field'] : 'nama';
                $n['prefix'] = mb_substr((string) ($e['prefix'] ?? ''), 0, 40);
                break;
            case 'text':
                $n['text'] = mb_substr((string) ($e['text'] ?? ''), 0, 200);
                break;
            case 'line':
                $n['style'] = ($e['style'] ?? '') === 'solid' ? 'solid' : 'dashed';
                $n['thick'] = max(0.1, min(3.0, (float) ($e['thick'] ?? 0.4)));
                $n['space'] = max(0.0, min(15.0, (float) ($e['space'] ?? 2)));
                break;
            case 'spacer':
                $n['height'] = max(0.5, min(60.0, (float) ($e['height'] ?? 3)));
                break;
            case 'counter':
                $n['prefix'] = mb_substr((string) ($e['prefix'] ?? 'No. '), 0, 40);
                $n['digits'] = max(1, min(6, (int) ($e['digits'] ?? 3)));
                break;
            case 'pair':
                $n['left'] = isset(TICKET_FIELDS[$e['left'] ?? '']) ? $e['left'] : 'id';
                $n['right'] = isset(TICKET_FIELDS[$e['right'] ?? '']) ? $e['right'] : 'datetime';
                break;
        }
        $els[] = $n;
    }
    return ['paper' => $paper, 'elements' => $els];
}

/** Apakah desain menampilkan nomor antrian (agar nomor hanya dipakai bila memang dicetak) */
function ticket_uses_counter(?array $tpl = null): bool
{
    foreach (($tpl ?? ticket_template())['elements'] as $e) {
        if (!$e['hidden'] && ($e['type'] === 'counter'
            || ($e['type'] === 'field' && $e['field'] === 'antrian')
            || ($e['type'] === 'pair' && in_array('antrian', [$e['left'], $e['right']], true)))) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------
 * Nomor antrian (urut) — storage/counter.json
 * ------------------------------------------------------------------ */
function ticket_counter_file(): string
{
    return __DIR__ . '/../storage/counter.json';
}

function ticket_counter_peek(): int
{
    $c = json_decode((string) @file_get_contents(ticket_counter_file()), true);
    return (int) ($c['value'] ?? 0);
}

function ticket_counter_next(): int
{
    $f = ticket_counter_file();
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true);
    $fh = fopen($f, 'c+');
    flock($fh, LOCK_EX);
    $c = json_decode((string) stream_get_contents($fh), true);
    $v = (int) ($c['value'] ?? 0) + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(['value' => $v, 'updated' => date('c')]));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $v;
}

function ticket_counter_set(int $value): void
{
    file_put_contents(ticket_counter_file(), json_encode(['value' => max(0, $value), 'updated' => date('c')]), LOCK_EX);
}

/** Siapkan data cetak: beri nomor antrian bila desain memakainya (panggil sekali per tiket yang benar-benar dicetak) */
function ticket_prepare(array $d): array
{
    if (!isset($d['antrian']) && ticket_uses_counter()) {
        $d['antrian'] = ticket_counter_next();
    }
    return $d;
}

/* ------------------------------------------------------------------
 * Penggambar
 * ------------------------------------------------------------------ */
function ticket_font(bool $bold): string
{
    $b = app_config()['print']['brother'] ?? [];
    $bundled = __DIR__ . '/../assets/fonts/' . ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');
    foreach (array_filter([
        $bold ? ($b['font_bold'] ?? '') : ($b['font_regular'] ?? ''),
        $bold ? 'C:/Windows/Fonts/arialbd.ttf' : 'C:/Windows/Fonts/arial.ttf',
        $bold ? '/System/Library/Fonts/Supplemental/Arial Bold.ttf' : '/System/Library/Fonts/Supplemental/Arial.ttf',
        $bundled,
    ]) as $f) {
        if (@is_file($f) && @is_readable($f)) return $f;
    }
    throw new RuntimeException('Font untuk tiket tidak ditemukan (assets/fonts).');
}

/** Nomor meja tanpa huruf kursi, angka minimal 2 digit: "04E" → "04", "40D" → "40", "A1"/"A01" → "A01" */
function ticket_table_only(string $raw): string
{
    $s = strtoupper((string) preg_replace('/\s+/', '', $raw));
    if (preg_match('/^(\d+)[-._]?[A-Z]*$/', $s, $m)) return str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT);
    if (preg_match('/^([A-Z]+)[-._]?(\d+)[A-Z]?$/', $s, $m)) return $m[1] . str_pad((string) (int) $m[2], 2, '0', STR_PAD_LEFT);
    return trim($raw);
}

/** Nilai teks sebuah field */
function ticket_value(string $field, array $d, array $cfg, array $el = []): string
{
    switch ($field) {
        case 'event':      return (string) $cfg['event_name'];
        case 'event_sub':  return (string) ($cfg['event_sub'] ?? '');
        case 'seat_label': return (string) ($cfg['kiosk']['seat_label'] ?? 'Nomor Meja');
        case 'datetime':   return date('d/m/Y H:i');
        case 'meja':       return ticket_table_only((string) ($d['kursi'] ?? ''));
        case 'antrian':
            if (!isset($d['antrian'])) return '';
            return str_pad((string) $d['antrian'], (int) ($el['digits'] ?? 3), '0', STR_PAD_LEFT);
        default:           return trim((string) ($d[$field] ?? ''));
    }
}

/**
 * Gambar tiket.
 * $target: ['width_px' => …, 'dpi' => …] untuk memaksa lebar printer tertentu (Brother/ESC-POS);
 *          null = pakai ukuran kertas di desain.
 * Kembalikan ['im' => GD (putih/hitam), 'width_px', 'height_px', 'dpi', 'width_mm', 'height_mm'].
 */
function ticket_render(array $d, ?array $cfg = null, ?array $tpl = null, ?array $target = null): array
{
    $cfg = $cfg ?? app_config();
    $tpl = $tpl ? ticket_normalize($tpl) : ticket_template($cfg);
    $paper = $tpl['paper'];
    $dpi = (int) ($target['dpi'] ?? $paper['dpi']);
    $W = (int) ($target['width_px'] ?? round($paper['width_mm'] / 25.4 * $dpi));
    $mm = function (float $v) use ($dpi): int { return (int) round($v / 25.4 * $dpi); };
    $pt = function (float $v) use ($dpi): float { return $v * $dpi / 96; };      // pt → ukuran GD (96 dpi)
    $pad = max($mm(1.0), (int) round($W * 0.025));
    $inner = $W - 2 * $pad;
    $reg = ticket_font(false);
    $bold = ticket_font(true);

    $H = 6000;
    $im = imagecreatetruecolor($W, $H);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $W, $H, $white);
    $y = $mm($paper['margin_mm']);

    $width = function (string $font, float $size, string $text, float $spacing): int {
        $b = imagettfbbox($size, 0, $font, $text);
        return (int) abs($b[2] - $b[0]) + (int) round($spacing * max(0, mb_strlen($text) - 1));
    };
    // tulis teks dengan jarak antar huruf (bila ada)
    $draw = function (string $text, string $font, float $size, int $x, int $baseline, float $spacing) use ($im, $black): void {
        if ($spacing <= 0) {
            imagettftext($im, $size, 0, $x, $baseline, $black, $font, $text);
            return;
        }
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            imagettftext($im, $size, 0, $x, $baseline, $black, $font, $ch);
            $b = imagettfbbox($size, 0, $font, $ch);
            $x += (int) abs($b[2] - $b[0]) + (int) round($spacing);
        }
    };
    $lineBox = function (string $font, float $size): array {
        $b = imagettfbbox($size, 0, $font, 'ÁgjpqyX');
        return [abs($b[7]), abs($b[1])]; // ascent, descent
    };
    $wrap = function (string $font, float $size, string $text, int $maxW, float $sp) use ($width): array {
        $lines = []; $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $w) {
            $try = $line === '' ? $w : "$line $w";
            if ($line !== '' && $width($font, $size, $try, $sp) > $maxW) { $lines[] = $line; $line = $w; }
            else $line = $try;
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    };

    foreach ($tpl['elements'] as $el) {
        if ($el['hidden']) continue;
        $type = $el['type'];

        if ($type === 'spacer') { $y += $mm($el['height']); continue; }
        if ($type === 'line') {
            $y += $mm($el['space']);
            $t = max(1, $mm($el['thick']));
            if ($el['style'] === 'solid') {
                imagefilledrectangle($im, $pad, $y, $W - $pad, $y + $t - 1, $black);
            } else {
                $dash = max(4, $mm(2.2)); $gap = max(3, $mm(1.4));
                for ($x = $pad; $x < $W - $pad; $x += $dash + $gap) {
                    imagefilledrectangle($im, $x, $y, min($x + $dash, $W - $pad), $y + $t - 1, $black);
                }
            }
            $y += $t + $mm($el['space']);
            continue;
        }

        $font = $el['bold'] ? $bold : $reg;
        $size = $pt($el['size']);
        $sp = $el['spacing'] * $dpi / 72;

        if ($type === 'pair') {
            $l = ticket_value($el['left'], $d, $cfg);
            $r = ticket_value($el['right'], $d, $cfg);
            if ($el['upper']) { $l = mb_strtoupper($l); $r = mb_strtoupper($r); }
            [$asc, $desc] = $lineBox($font, $size);
            if ($l !== '') $draw($l, $font, $size, $pad, $y + $asc, $sp);
            if ($r !== '') $draw($r, $font, $size, $W - $pad - $width($font, $size, $r, $sp), $y + $asc, $sp);
            $y += $asc + $desc + $mm($el['gap']);
            continue;
        }

        if ($type === 'text') {
            $text = $el['text'];
        } elseif ($type === 'counter') {
            if (!isset($d['antrian'])) continue;
            $text = $el['prefix'] . str_pad((string) $d['antrian'], $el['digits'], '0', STR_PAD_LEFT);
        } else {
            $text = ticket_value($el['field'], $d, $cfg, $el);
            if ($text !== '' && ($el['prefix'] ?? '') !== '') $text = $el['prefix'] . $text;
        }
        if ($el['upper']) $text = mb_strtoupper($text);
        if (trim($text) === '') continue;

        // muat lebar: perkecil (fit) atau bungkus ke beberapa baris
        if ($el['fit'] || $el['lines'] === 1) {
            while ($size > $pt(5) && $width($font, $size, $text, $sp) > $inner) $size *= 0.95;
            $lines = [$text];
        } else {
            $lines = $wrap($font, $size, $text, $inner, $sp);
            while (count($lines) > $el['lines'] && $size > $pt(5)) {
                $size *= 0.92;
                $lines = $wrap($font, $size, $text, $inner, $sp);
            }
            $lines = array_slice($lines, 0, $el['lines']);
        }
        [$asc, $desc] = $lineBox($font, $size);
        foreach ($lines as $ln) {
            $w = $width($font, $size, $ln, $sp);
            $x = $el['align'] === 'left' ? $pad : ($el['align'] === 'right' ? $W - $pad - $w : (int) (($W - $w) / 2));
            $draw($ln, $font, $size, $x, $y + $asc, $sp);
            $y += (int) round(($asc + $desc) * 1.02);
        }
        $y += $mm($el['gap']);
    }
    $y += $mm($paper['margin_mm']);

    // tinggi tetap (bila diatur) atau mengikuti isi
    $Hout = $paper['height_mm'] > 0 ? max($mm($paper['height_mm']), 1) : max($y, $mm(10));
    $out = imagecreatetruecolor($W, $Hout);
    imagefilledrectangle($out, 0, 0, $W, $Hout, imagecolorallocate($out, 255, 255, 255));
    imagecopy($out, $im, 0, 0, 0, 0, $W, min($y, $Hout));
    imagedestroy($im);
    return [
        'im' => $out, 'width_px' => $W, 'height_px' => $Hout, 'dpi' => $dpi,
        'width_mm' => round($W / $dpi * 25.4, 2), 'height_mm' => round($Hout / $dpi * 25.4, 2),
        'overflow' => $paper['height_mm'] > 0 && $y > $Hout,
    ];
}

/** PNG hitam-putih (1-bit) dari hasil render — untuk pratinjau & cetak lewat browser */
function ticket_png(array $r): string
{
    $im = $r['im'];
    $w = imagesx($im); $h = imagesy($im);
    $bw = imagecreate($w, $h);
    $wh = imagecolorallocate($bw, 255, 255, 255);
    $bk = imagecolorallocate($bw, 0, 0, 0);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $lum = ((($c >> 16) & 255) * 299 + (($c >> 8) & 255) * 587 + ($c & 255) * 114) / 1000;
            if ($lum < 128) imagesetpixel($bw, $x, $y, $bk);
        }
    }
    ob_start();
    imagepng($bw, null, 9);
    imagedestroy($bw);
    return (string) ob_get_clean();
}

/** Tiket untuk dicetak lewat browser (Kabel USB): PNG base64 + ukuran halaman */
function ticket_for_browser(array $d, ?array $cfg = null): array
{
    $r = ticket_render($d, $cfg);
    $png = ticket_png($r);
    imagedestroy($r['im']);
    $cfg = $cfg ?? app_config();
    // Ukuran mengikuti printer Kabel USB (Pengaturan › Printer › Ukuran kertas Kabel USB) — sama dengan test cetak.
    // Gambar Desain Tiket diskalakan ke lebar area cetak; tinggi halaman = tinggi isi tiket (atau tetap).
    $cp = cable_paper($cfg);
    $printW = $cp['print_w'];
    $h = $r['height_mm'] * $printW / max(1, $r['width_mm']);
    $pageH = $cp['height'] === 'fixed' ? max($cp['height_mm'], ceil($h + 1)) : ceil($h + 2);
    return [
        'png'       => base64_encode($png),
        'width_mm'  => round($printW, 2),               // lebar gambar = area cetak printer
        'page_w_mm' => $cp['paper_w'],                  // lebar kertas
        'height_mm' => round($h, 2),
        'page_h_mm' => $pageH,                          // tinggi halaman
    ];
}

/* ------------------------------------------------------------------
 * ESC/POS: gambar tiket sebagai raster (GS v 0)
 * ------------------------------------------------------------------ */
function ticket_escpos_raster(array $d, ?array $cfg = null, bool $cut = true): string
{
    $cfg = $cfg ?? app_config();
    $cols = (int) ($cfg['print']['bluetooth']['chars_per_line'] ?? 48);
    $widthPx = $cols <= 32 ? 384 : 576;                       // 58 mm / 80 mm pada 203 dpi
    $r = ticket_render($d, $cfg, null, ['width_px' => $widthPx, 'dpi' => 203]);
    $im = $r['im'];
    $w = imagesx($im); $h = imagesy($im);
    $bytesPerRow = (int) ceil($w / 8);
    $out = "\x1B\x40" . "\x1B\x61\x01";                       // reset, rata tengah
    for ($y0 = 0; $y0 < $h; $y0 += 255) {                     // kirim per pita 255 baris (aman untuk buffer printer)
        $rows = min(255, $h - $y0);
        $band = '';
        for ($y = $y0; $y < $y0 + $rows; $y++) {
            $row = array_fill(0, $bytesPerRow, 0);
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                if (((($c >> 16) & 255) * 299 + (($c >> 8) & 255) * 587 + ($c & 255) * 114) / 1000 < 128) {
                    $row[$x >> 3] |= 0x80 >> ($x & 7);
                }
            }
            $band .= pack('C*', ...$row);
        }
        $out .= "\x1D\x76\x30\x00" . pack('v', $bytesPerRow) . pack('v', $rows) . $band;
    }
    imagedestroy($im);
    $out .= "\x1B\x64\x03";                                   // feed 3 baris
    if ($cut) $out .= "\x1D\x56\x42\x00";                     // potong
    return $out;
}
