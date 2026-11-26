<?php
declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config.php';
        // Timpa dengan pengaturan yang disimpan dari halaman Pengaturan
        $saved = load_settings();
        if (isset($saved['cable']) && is_array($saved['cable'])) {   // ukuran kertas mode Kabel USB (Pengaturan › Printer)
            $config['print']['cable'] = $saved['cable'];
        }
        if (isset($saved['mode'])) {
            $config['print']['mode'] = $saved['mode'];
        }
        if (isset($saved['bluetooth']) && is_array($saved['bluetooth'])) {
            $config['print']['bluetooth'] = array_merge($config['print']['bluetooth'], $saved['bluetooth']);
        }
        if (isset($saved['agent']) && is_array($saved['agent'])) {
            $config['print']['agent'] = array_merge($config['print']['agent'] ?? [], $saved['agent']);
        }
        if (isset($saved['brother']) && is_array($saved['brother'])) {
            $config['print']['brother'] = array_merge($config['print']['brother'] ?? [], $saved['brother']);
        }
        if (array_key_exists('scan_active', $saved)) {
            $config['kiosk']['scan_active'] = (bool) $saved['scan_active'];
        }
        if (isset($saved['idle_video']) && is_array($saved['idle_video'])) {
            $config['kiosk']['idle_video'] = array_merge($config['kiosk']['idle_video'] ?? [], $saved['idle_video']);
        }
        if (array_key_exists('wayfinding', $saved)) {
            $config['kiosk']['wayfinding'] = (bool) $saved['wayfinding'];
        }
        if (isset($saved['timing']) && is_array($saved['timing'])) {
            foreach (TIMING_FIELDS as $k => $f) {
                if (isset($saved['timing'][$k])) $config['kiosk'][$k] = max($f[1], min($f[2], (int) $saved['timing'][$k]));
            }
        }
        if (isset($saved['start_page']) && in_array($saved['start_page'], ['index', 'seating'], true)) {
            $config['kiosk']['start_page'] = $saved['start_page'];
        }
        if (isset($saved['api']) && is_array($saved['api'])) {
            foreach (['mode', 'url', 'verify_ssl'] as $k) {
                if (array_key_exists($k, $saved['api'])) {
                    $config['api'][$k] = $saved['api'][$k];
                }
            }
        }
        if (isset($saved['standby']) && is_array($saved['standby'])) {
            $config['kiosk']['standby'] = array_merge($config['kiosk']['standby'], $saved['standby']);
        }
    }
    return $config;
}

function settings_file(): string
{
    return __DIR__ . '/../storage/settings.json';
}

function load_settings(): array
{
    $file = settings_file();
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_settings(array $settings): void
{
    $dir = dirname(settings_file());
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents(settings_file(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Gabungkan perubahan ke settings.json tanpa menghapus pengaturan lain */
function update_settings(array $patch): void
{
    save_settings(array_replace_recursive(load_settings(), $patch));
}

/** Cek password Pengaturan: pakai hash tersimpan, atau admin_pin di config.php bila belum pernah diganti */
function admin_password_ok(string $password): bool
{
    $saved = load_settings();
    if (!empty($saved['admin_pin_hash'])) {
        return password_verify($password, (string) $saved['admin_pin_hash']);
    }
    $base = require __DIR__ . '/../config.php';
    return hash_equals((string) $base['admin_pin'], $password);
}

function set_admin_password(string $password): void
{
    update_settings(['admin_pin_hash' => password_hash($password, PASSWORD_DEFAULT)]);
}

/* ---------------- Video idle (halaman seating) ---------------- */
const IDLE_VIDEO_DIR = __DIR__ . '/../uploads/videos';
const IDLE_VIDEO_URL = 'uploads/videos';

/**
 * ID video YouTube dari tautan apa pun: watch?v=, youtu.be/, /shorts/, /embed/, /live/, atau ID 11 karakter.
 * Kembalikan null bila bukan tautan YouTube yang valid.
 */
function youtube_id(string $url): ?string
{
    $url = trim($url);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) return $url;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = (string) preg_replace('/^(www\.|m\.|music\.)/', '', $host);
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $id = null;
    if ($host === 'youtu.be') {
        $id = trim($path, '/');
    } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
        if (!empty($q['v'])) $id = (string) $q['v'];
        elseif (preg_match('#^/(?:shorts|embed|live|v)/([^/?]+)#', $path, $m)) $id = $m[1];
    }
    return ($id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) ? $id : null;
}

/**
 * Konfigurasi video idle untuk kiosk. Item daftar putar:
 *   "nama-file.mp4"  → video unggahan (uploads/videos/)
 *   "yt:VIDEO_ID"    → video YouTube
 */
function idle_video_config(array $cfg): array
{
    $iv = ($cfg['kiosk']['idle_video'] ?? []) + ['enabled' => false, 'delay' => 30, 'muted' => true, 'files' => []];
    $items = [];
    foreach ((array) $iv['files'] as $f) {
        $f = (string) $f;
        if (strpos($f, 'yt:') === 0) {
            $id = substr($f, 3);
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) $items[] = ['type' => 'youtube', 'id' => $id];
            continue;
        }
        $f = basename($f);
        if ($f !== '' && is_file(IDLE_VIDEO_DIR . '/' . $f)) {
            $items[] = ['type' => 'file', 'src' => IDLE_VIDEO_URL . '/' . rawurlencode($f)];
        }
    }
    return [
        'enabled' => !empty($iv['enabled']) && $items !== [],
        'delay'   => max(5, min(3600, (int) $iv['delay'])),
        'muted'   => !empty($iv['muted']),
        'items'   => $items,
        'version' => substr(md5(json_encode([$iv['enabled'], $iv['delay'], $iv['muted'], $items])), 0, 10),
    ];
}

/** Simpan pengaturan video idle (daftar file diganti utuh, bukan digabung) */
function idle_video_save(array $patch): void
{
    $s = load_settings();
    $cur = (array) ($s['idle_video'] ?? []);
    foreach ($patch as $k => $v) {
        $cur[$k] = $v;
    }
    $s['idle_video'] = $cur;
    save_settings($s);
}

/** Status kiosk yang dipantau layar kiosk secara berkala */
function kiosk_status(array $cfg): array
{
    return [
        'idle_video'  => idle_video_config($cfg),
        'scan_active' => (bool) $cfg['kiosk']['scan_active'],
        'standby'     => [
            'title'    => (string) $cfg['kiosk']['standby']['title'],
            'subtitle' => (string) $cfg['kiosk']['standby']['subtitle'],
        ],
        'print_mode'  => $cfg['print']['mode'],
        'wayfinding'  => (bool) ($cfg['kiosk']['wayfinding'] ?? true),
        'start_page'  => start_page($cfg),
        'timing'      => timing_config($cfg),
    ];
}

/**
 * Waktu tampil kiosk yang bisa diatur dari Pengaturan.
 * kunci config => [label, min, max, default, halaman]
 */
const TIMING_FIELDS = [
    'seating_after_print_seconds' => ['Setelah tiket tercetak', 3, 600, 10, 'seating'],
    'seating_display_seconds'     => ['Data tampil bila belum menekan PRINT', 5, 600, 60, 'seating'],
    'result_display_seconds'      => ['Hasil scan tampil', 2, 120, 4, 'index'],
    'map_display_seconds'         => ['Hasil + rute denah (wayfinding) tampil', 3, 300, 20, 'index'],
    'error_display_seconds'       => ['Pesan error tampil', 2, 120, 4, 'both'],
    'duplicate_cooldown_seconds'  => ['Abaikan scan QR yang sama selama', 0, 120, 5, 'both'],
];

/** Nilai waktu tampil yang berlaku (detik) */
function timing_config(?array $cfg = null): array
{
    $cfg = $cfg ?? app_config();
    $out = [];
    foreach (TIMING_FIELDS as $k => $f) {
        $out[$k] = max($f[1], min($f[2], (int) ($cfg['kiosk'][$k] ?? $f[3])));
    }
    return $out;
}

/**
 * Ukuran halaman cetak mode Kabel USB (mm).
 * paper_w  = lebar kertas, print_w = lebar area cetak (gambar tiket diskalakan ke lebar ini),
 * height   = 'auto' (tinggi halaman = tinggi isi tiket) atau 'fixed' (height_mm, minimal setinggi isi tiket).
 * Bawaan diambil dari config print.paper_size / printable_width.
 */
function cable_paper(?array $cfg = null): array
{
    $cfg = $cfg ?? app_config();
    $size = preg_split('/\s+/', trim((string) ($cfg['print']['paper_size'] ?? '80mm 80mm')));
    $c = (array) ($cfg['print']['cable'] ?? []);
    $paperW = (float) ($c['paper_w'] ?? (float) ($size[0] ?? 80));
    $printW = (float) ($c['print_w'] ?? (float) ($cfg['print']['printable_width'] ?? '72mm'));
    if ($paperW < 20 || $paperW > 300) $paperW = 80;
    if ($printW < 10 || $printW > $paperW) $printW = max(10, $paperW - 8);
    return [
        'paper_w'   => $paperW,
        'print_w'   => $printW,
        // 'driver' = ukuran halaman mengikuti kertas yang dipilih di driver printer (Brother: ukuran label khusus);
        // 'app'    = aplikasi menetapkan ukuran halaman (@page size) — untuk printer thermal roll
        'page'      => ($c['page'] ?? 'app') === 'driver' ? 'driver' : 'app',
        'height'    => ($c['height'] ?? 'auto') === 'fixed' ? 'fixed' : 'auto',
        'height_mm' => max(20, min(1000, (float) ($c['height_mm'] ?? (float) ($size[1] ?? 80)))),
    ];
}

/** Halaman awal kiosk di alamat utama (index.php): 'index' (Kiosk Scan) atau 'seating' (Seating Arrangement) */
function start_page(?array $cfg = null): string
{
    $cfg = $cfg ?? app_config();
    return ($cfg['kiosk']['start_page'] ?? 'index') === 'seating' ? 'seating' : 'index';
}

function print_mode_label(array $cfg): string
{
    switch ($cfg['print']['mode']) {
        case 'bluetooth':
            return 'Server (' . $cfg['print']['bluetooth']['port'] . ')';
        case 'webbluetooth':
            return 'Bluetooth dari Chrome';
        case 'brother':
            return 'Brother QL (' . ($cfg['print']['brother']['port'] ?? '') . ')';
        case 'agent':
            return 'Print Agent (' . (($cfg['print']['agent']['printer_type'] ?? 'brother') === 'escpos' ? 'thermal ESC/POS' : 'Brother QL') . ')';
        default:
            return 'Kabel USB';
    }
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** URL dasar aplikasi, mis. http://localhost/seat-ptbi */
function app_base_url(): string
{
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root   = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $doc    = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $path   = ($doc !== '' && stripos($root, $doc) === 0) ? substr($root, strlen($doc)) : '/seat-ptbi';
    return $scheme . '://' . $host . rtrim($path, '/');
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Ambil nilai dari array bertingkat memakai dot notation: 'data.nama' */
function array_get(array $data, ?string $path)
{
    if ($path === null || $path === '') {
        return null;
    }
    $current = $data;
    foreach (explode('.', $path) as $key) {
        if (is_array($current) && array_key_exists($key, $current)) {
            $current = $current[$key];
        } else {
            return null;
        }
    }
    return $current;
}

/**
 * Tentukan URL API yang akan dipanggil berdasarkan isi QR.
 * @return array{0:string,1:string} [url, id]
 */
function resolve_api_target(string $qr): array
{
    $cfg = app_config()['api'];
    $qr  = trim($qr);

    if (preg_match('#^https?://#i', $qr)) {
        $host = strtolower((string) parse_url($qr, PHP_URL_HOST));
        if ($cfg['qr_is_url']) {
            $allowed = array_map('strtolower', $cfg['allowed_hosts']);
            if (!in_array($host, $allowed, true)) {
                throw new RuntimeException('Host QR tidak diizinkan: ' . $host);
            }
            return [$qr, $qr];
        }
        // Ambil ID dari ?id= atau segmen terakhir path
        parse_str((string) parse_url($qr, PHP_URL_QUERY), $query);
        $id = $query['id'] ?? basename((string) parse_url($qr, PHP_URL_PATH));
        $qr = (string) $id;
    }

    if (!preg_match(app_config()['id_pattern'], $qr)) {
        throw new RuntimeException('Format QR tidak valid.');
    }

    return [api_build_url($qr), $qr];
}

/** Sumber data aktif: 'live' (API registrasi) atau 'mock' (data uji lokal) */
function api_is_live(): bool
{
    $cfg = app_config()['api'];
    return ($cfg['mode'] ?? 'mock') === 'live' && !empty($cfg['url']);
}

/** URL API untuk sebuah ID. Template boleh memakai {id}; bila tidak ada, ID ditambahkan di akhir. */
function api_build_url(string $id): string
{
    $cfg = app_config()['api'];
    $template = api_is_live() ? (string) $cfg['url'] : app_base_url() . '/mock/api.php?id={id}';
    if (strpos($template, '{id}') === false) {
        $template = rtrim($template, '/') . '/{id}';
    }
    return str_replace('{id}', rawurlencode($id), $template);
}

/** Permintaan HTTP mentah ke API (dipakai lookup & Tes Koneksi) */
function api_request(string $url, string $id): array
{
    $cfg = app_config()['api'];
    $ch  = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => (int) $cfg['timeout'],
        CURLOPT_HTTPHEADER     => $cfg['headers'],
        CURLOPT_USERAGENT      => 'SeatKiosk/1.0',
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => (bool) $cfg['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $cfg['verify_ssl'] ? 2 : 0,
    ];
    if (strtoupper($cfg['method']) === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query([$cfg['post_field'] => $id]);
    }
    curl_setopt_array($ch, $opts);
    $body   = curl_exec($ch);
    $result = [
        'status'       => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'content_type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'time'         => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2),
        'body'         => $body === false ? null : (string) $body,
        'error'        => curl_error($ch),
    ];
    curl_close($ch);
    return $result;
}

/**
 * Ambil data peserta dari sumber data (JSON atau halaman HTML) → format standar
 * { id, nama, jabatan, instansi, kursi, unassigned }
 */
function api_fetch_participant(string $url, string $id): array
{
    $r = api_request($url, $id);
    if ($r['body'] === null) {
        $hint = stripos($r['error'], 'resolve host') !== false ? ' (alamat server tidak dikenal dari komputer ini — periksa jaringan/DNS)' : '';
        throw new RuntimeException('Gagal menghubungi server registrasi: ' . $r['error'] . $hint);
    }
    $json = json_decode($r['body'], true);
    if ($r['status'] === 404) {
        throw new RuntimeException(is_array($json) && !empty($json['message']) ? $json['message'] : 'Data peserta tidak ditemukan.');
    }
    if ($r['status'] >= 400) {
        throw new RuntimeException('Server registrasi error (HTTP ' . $r['status'] . ').');
    }
    if (is_array($json)) {
        $data = map_participant($json);
    } elseif (strpos($r['body'], '<') !== false) {
        $data = parse_html_participant($r['body']);
    } else {
        throw new RuntimeException('Respons server registrasi tidak dikenali.');
    }
    if ($data['id'] === '') {
        $data['id'] = $id;
    }
    // "Belum ditentukan" / kosong = peserta belum mendapat meja
    $data['unassigned'] = $data['kursi'] === '' || (bool) preg_match('/belum\s+(di)?tentu|^-+$/i', $data['kursi']);
    if ($data['unassigned']) {
        $data['kursi'] = '';
    }
    return $data;
}

/**
 * Baca halaman HTML registrasi, mis. https://registrasi.ptbi.co.id/web/seat/{id}:
 *   <span>NAMA</span> <span>{nama}</span> <span>{instansi}</span> <span>NO MEJA</span> <span>{meja}</span>
 * Halaman berisi satu pesan saja (mis. "Data undangan tidak ditemukan.") = data tidak ada.
 */
function parse_html_participant(string $html): array
{
    // 1) Utama: elemen ber-id, mis. <span id="nama">…</span> <span id="jabatan">…</span>
    //    <span id="instansi">…</span> <span id="nomeja">40D</span>   (format registrasi PTBI terbaru)
    $byId = parse_html_by_id($html);

    // 2) Cadangan: label teks (NAMA / JABATAN / INSTANSI / NO MEJA) — format lama
    try {
        $byLabel = parse_html_by_label($html);
    } catch (RuntimeException $e) {
        if ($byId['nama'] === '' && $byId['kursi'] === '') {
            throw $e; // tidak ada data sama sekali (mis. "Data undangan tidak ditemukan.")
        }
        $byLabel = [];
    }
    foreach ($byId as $k => $v) {
        if ($v === '' && !empty($byLabel[$k])) {
            $byId[$k] = $byLabel[$k];
        }
    }
    return $byId;
}

/** Ambil isi elemen berdasarkan atribut id (nama, jabatan, instansi, nomeja, …) */
function parse_html_by_id(string $html): array
{
    $ids = [
        'nama'     => ['nama', 'name', 'nama_lengkap', 'namapeserta', 'nama_peserta', 'fullname'],
        'jabatan'  => ['jabatan', 'position', 'title', 'posisi', 'job_title'],
        'instansi' => ['instansi', 'institution', 'company', 'organisasi', 'organization', 'perusahaan', 'lembaga'],
        'kursi'    => ['nomeja', 'no_meja', 'nomor_meja', 'meja', 'nokursi', 'no_kursi', 'nomor_kursi', 'kursi', 'seat', 'table'],
        'id'       => ['kode', 'id_peserta', 'kode_peserta', 'barcode'],
    ];
    $out = ['id' => '', 'nama' => '', 'jabatan' => '', 'instansi' => '', 'kursi' => ''];
    if (!preg_match_all('#<([a-z][a-z0-9]*)\b[^>]*?\bid\s*=\s*["\']?([\w\-]+)["\']?[^>]*>(.*?)</\1\s*>#is', $html, $m, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($m as $el) {
        $id = strtolower($el[2]);
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($el[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '') continue;
        foreach ($ids as $field => $names) {
            if ($out[$field] === '' && in_array($id, $names, true)) {
                $out[$field] = $text;
            }
        }
    }
    return $out;
}

/** Format lama: teks label diikuti nilai (NAMA → nama → instansi, NO MEJA → nomor) */
function parse_html_by_label(string $html): array
{
    // buang skrip/gaya, jadikan setiap tag blok/span/<br> sebagai pemisah baris, lalu ambil teks per baris
    $html = (string) preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $html);
    $html = (string) preg_replace('#<(br|/?(span|div|p|td|th|tr|li|h\d|table|label))\b[^>]*>#i', "\n", $html);
    $texts = [];
    foreach (explode("\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $t) {
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));
        if ($t !== '') {
            $texts[] = $t;
        }
    }
    if (!$texts) {
        throw new RuntimeException('Halaman registrasi tidak berisi data.');
    }

    $labels = [
        'NAMA' => 'nama', 'NAMA LENGKAP' => 'nama', 'NAMA PESERTA' => 'nama',
        'JABATAN' => 'jabatan',
        'INSTANSI' => 'instansi', 'ASAL INSTANSI' => 'instansi', 'LEMBAGA' => 'instansi', 'PERUSAHAAN' => 'instansi',
        'NO MEJA' => 'kursi', 'NOMOR MEJA' => 'kursi', 'MEJA' => 'kursi', 'NO. MEJA' => 'kursi',
        'NO KURSI' => 'kursi', 'NOMOR KURSI' => 'kursi', 'KURSI' => 'kursi', 'NO. KURSI' => 'kursi', 'SEAT' => 'kursi',
    ];
    $out = ['id' => '', 'nama' => '', 'jabatan' => '', 'instansi' => '', 'kursi' => ''];
    $current = null;
    $values = [];
    $flush = function () use (&$current, &$values, &$out) {
        if ($current === 'nama') {
            // pola halaman: NAMA → nama → instansi (→ jabatan)
            $out['nama'] = $values[0] ?? $out['nama'];
            if (isset($values[1]) && $out['instansi'] === '') $out['instansi'] = $values[1];
            if (isset($values[2]) && $out['jabatan'] === '') $out['jabatan'] = $values[2];
        } elseif ($current !== null && isset($values[0])) {
            $out[$current] = $values[0];
        }
        $values = [];
    };
    foreach ($texts as $t) {
        $key = strtoupper(rtrim($t, ': '));
        if (isset($labels[$key])) {
            $flush();
            $current = $labels[$key];
        } elseif ($current !== null) {
            $values[] = $t;
        }
    }
    $flush();

    if ($out['nama'] === '' && $out['kursi'] === '') {
        // tidak ada label → halaman berisi pesan (mis. "Data undangan tidak ditemukan.")
        throw new RuntimeException(mb_substr($texts[0], 0, 150));
    }
    return $out;
}

/**
 * Cari nilai field secara otomatis bila path di config tidak cocok dengan respons API.
 * Menelusuri JSON (bertingkat) dan memakai nama kunci yang umum dipakai sistem registrasi.
 */
function api_autodetect(array $json, string $field)
{
    static $names = [
        'id'       => ['id', 'kode', 'code', 'registration_id', 'reg_id', 'id_peserta', 'kode_peserta', 'qr', 'barcode'],
        'nama'     => ['nama', 'name', 'nama_lengkap', 'full_name', 'fullname', 'nama_peserta', 'participant_name'],
        'jabatan'  => ['jabatan', 'position', 'title', 'job_title', 'posisi', 'jabatan_peserta'],
        'instansi' => ['instansi', 'institution', 'company', 'organisasi', 'organization', 'organisation', 'perusahaan', 'lembaga', 'satker', 'unit_kerja', 'asal_instansi'],
        'kursi'    => ['no_kursi', 'kursi', 'nomor_kursi', 'seat', 'seat_number', 'seat_no', 'nomor_tempat_duduk', 'tempat_duduk', 'no_seat', 'seat_code'],
    ];
    $wanted = $names[$field] ?? [$field];
    $queue = [$json];
    while ($queue) {
        $node = array_shift($queue);
        $lower = array_change_key_case($node, CASE_LOWER);
        foreach ($wanted as $key) {
            if (isset($lower[$key]) && is_scalar($lower[$key]) && trim((string) $lower[$key]) !== '') {
                return $lower[$key];
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $queue[] = $child;
            }
        }
    }
    return null;
}

/** Ubah respons API ke format standar aplikasi */
function map_participant(array $json): array
{
    $cfg = app_config()['api'];

    if ($cfg['success_path'] !== null) {
        $ok = array_get($json, $cfg['success_path']);
        if ($ok === false || $ok === 0 || $ok === 'false') {
            throw new RuntimeException($json['message'] ?? 'Data peserta tidak ditemukan.');
        }
    }

    $out = [];
    foreach ($cfg['field_map'] as $field => $path) {
        $value = array_get($json, $path);
        if (!is_scalar($value) || trim((string) $value) === '') {
            $value = api_autodetect($json, $field); // path tidak cocok → cari nama field yang umum
        }
        $out[$field] = is_scalar($value) ? trim((string) $value) : '';
    }
    if ($out['nama'] === '' && $out['kursi'] === '') {
        throw new RuntimeException('Data peserta kosong / tidak ditemukan.');
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Log cetak: ringkasan, arsip & reset (Pengaturan › Log Cetak)
 * Arsip disimpan di storage/log-arsip/print-log-YYYYmmdd-His.csv
 * ------------------------------------------------------------------ */
function print_log_archive_dir(): string
{
    return dirname(app_config()['log_file']) . '/log-arsip';
}

/** Ringkasan isi log: jumlah baris, tiket tercetak, peserta unik, waktu pertama & terakhir */
function print_log_stats(?string $file = null): array
{
    $file = $file ?? app_config()['log_file'];
    clearstatcache(true, $file);
    $s = ['exists' => is_file($file), 'rows' => 0, 'ok' => 0, 'people' => 0, 'failed' => 0, 'first' => null, 'last' => null,
          'size' => is_file($file) ? (int) filesize($file) : 0];
    if (!$s['exists'] || !($fh = fopen($file, 'r'))) return $s;
    $ids = [];
    $header = true;
    while (($row = fgetcsv($fh)) !== false) {
        if ($header) { $header = false; if (($row[0] ?? '') === 'waktu') continue; }
        if (!isset($row[0]) || $row[0] === null || $row[0] === '') continue;
        $s['rows']++;
        $s['first'] = $s['first'] ?? $row[0];
        $s['last'] = $row[0];
        $st = (string) ($row[5] ?? '');
        if ($st === 'OK') { $s['ok']++; $ids[(string) ($row[1] ?? '')] = true; }
        elseif (strpos($st, 'GAGAL') === 0) $s['failed']++;
    }
    fclose($fh);
    $s['people'] = count($ids);
    return $s;
}

/** Daftar arsip log (terbaru dulu) */
function print_log_archives(): array
{
    $dir = print_log_archive_dir();
    $out = [];
    foreach (glob($dir . '/print-log-*.csv') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/**
 * Reset log cetak: pindahkan log saat ini ke arsip (tidak dihapus), log baru dimulai dari kosong.
 * Mengembalikan nama file arsip, atau null bila log kosong / belum ada.
 */
function print_log_reset(): ?string
{
    $file = app_config()['log_file'];
    if (!is_file($file)) return null;
    $dir = print_log_archive_dir();
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'print-log-' . date('Ymd-His') . '.csv';
    $fh = fopen($file, 'r+');
    if (!$fh) throw new RuntimeException('Log cetak tidak bisa dibuka.');
    flock($fh, LOCK_EX);                       // tunggu penulisan kiosk yang sedang berjalan
    $ok = copy($file, $dir . '/' . $name);
    if ($ok) {
        ftruncate($fh, 0);
        rewind($fh);
        fputcsv($fh, ['waktu', 'id', 'nama', 'instansi', 'kursi', 'status', 'keterangan']);
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!$ok) throw new RuntimeException('Gagal membuat arsip log.');
    return $name;
}

/** Kirim file CSV ke browser (dengan BOM UTF-8 agar nama berhuruf khusus tampil benar di Excel) */
function send_csv_download(string $path, string $downloadName): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    if (is_file($path)) readfile($path);
    else echo "waktu,id,nama,instansi,kursi,status,keterangan\r\n";
    exit;
}

/** Catat ke CSV & kembalikan jumlah cetak sebelumnya untuk ID ini */
function log_print(string $id, array $p, string $status, string $note = ''): int
{
    $file = app_config()['log_file'];
    $dir  = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $previous = 0;
    if ($status === 'OK' && is_file($file) && ($fh = fopen($file, 'r'))) {
        while (($row = fgetcsv($fh)) !== false) {
            if (isset($row[1], $row[5]) && $row[1] === $id && $row[5] === 'OK') {
                $previous++;
            }
        }
        fclose($fh);
    }

    $isNew = !is_file($file);
    $fh = fopen($file, 'a');
    if ($fh) {
        flock($fh, LOCK_EX);
        if ($isNew) {
            fputcsv($fh, ['waktu', 'id', 'nama', 'instansi', 'kursi', 'status', 'keterangan']);
        }
        fputcsv($fh, [date('Y-m-d H:i:s'), $id, $p['nama'] ?? '', $p['instansi'] ?? '', $p['kursi'] ?? '', $status, $note]);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $previous;
}
