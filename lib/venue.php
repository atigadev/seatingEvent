<?php
declare(strict_types=1);

/**
 * Denah & wayfinding — format data sama dengan Satumap (data/map.json):
 *   map     : { width, height, image, pxPerMeter, walkSpeed }
 *   kiosks  : [ { id, name, x, y, heading? } ]          titik "Anda di sini"
 *   nodes   : { id: { x, y, name? } }                    titik lorong
 *   edges   : [ [a, b] | [a, b, 1] ]                     lorong (1 = satu arah a→b)
 *   booths  : [ { id, code, name, rect:[x1,y1,x2,y2], spot:[x,y], seats } ]
 *             = meja / area duduk; `seats` = aturan nomor kursi (tambahan Seat PTBI)
 *   meta    : { publishedAt, version }
 */

const VENUE_DIR    = __DIR__ . '/../storage/venue';
const VENUE_DRAFT  = VENUE_DIR . '/draft.json';
const VENUE_LIVE   = VENUE_DIR . '/map.json';
const VENUE_BACKUP = VENUE_DIR . '/backup';
const VENUE_KEEP   = 30;
const VENUE_UPLOAD_DIR = __DIR__ . '/../uploads/venue';
const VENUE_UPLOAD_URL = 'uploads/venue';

function venue_empty(): array
{
    return [
        'map'        => ['width' => 0, 'height' => 0, 'image' => '', 'pxPerMeter' => 10, 'walkSpeed' => 1.1],
        'categories' => new stdClass(),
        'kiosks'     => [],
        'nodes'      => new stdClass(),
        'edges'      => [],
        'booths'     => [],
        'meta'       => null,
    ];
}

function venue_read(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function venue_write(string $file, array $data): bool
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    // nodes/categories kosong harus tetap objek JSON {} (bukan array [])
    foreach (['nodes', 'categories'] as $k) {
        if (isset($data[$k]) && $data[$k] === []) {
            $data[$k] = new stdClass();
        }
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp  = $file . '.tmp';
    return $json !== false && file_put_contents($tmp, $json, LOCK_EX) !== false && rename($tmp, $file);
}

/** Validasi & normalisasi data peta. Kembalikan pesan error atau null bila valid. */
function venue_validate(&$data): ?string
{
    if (!is_array($data)) {
        return 'Data JSON tidak valid.';
    }
    foreach (['map', 'kiosks', 'nodes', 'edges', 'booths'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return "Bagian '$key' tidak ada.";
        }
    }
    if (!isset($data['categories']) || !is_array($data['categories'])) {
        $data['categories'] = [];
    }
    $m = &$data['map'];
    foreach (['width', 'height', 'pxPerMeter'] as $k) {
        if (!isset($m[$k]) || !is_numeric($m[$k]) || $m[$k] <= 0) {
            return "Nilai map.$k tidak valid.";
        }
    }
    $m['walkSpeed'] = isset($m['walkSpeed']) && is_numeric($m['walkSpeed']) && $m['walkSpeed'] > 0 ? (float) $m['walkSpeed'] : 1.1;
    if (isset($m['image']) && !preg_match('#^uploads/venue/[A-Za-z0-9._-]+$#', (string) $m['image'])) {
        return 'Path gambar denah tidak valid.';
    }

    foreach ($data['nodes'] as $id => $n) {
        if (!is_array($n) || !is_numeric($n['x'] ?? null) || !is_numeric($n['y'] ?? null)) {
            return "Titik lorong '$id' tidak valid.";
        }
    }
    foreach ($data['edges'] as $e) {
        if (!is_array($e) || count($e) < 2 || count($e) > 3 || !isset($data['nodes'][$e[0]], $data['nodes'][$e[1]])) {
            return 'Ada lorong yang terhubung ke titik yang tidak ada.';
        }
    }
    $ids = [];
    foreach ($data['kiosks'] as $k) {
        if (!is_array($k) || empty($k['id']) || !is_numeric($k['x'] ?? null) || !is_numeric($k['y'] ?? null)) {
            return 'Ada kiosk yang tidak valid.';
        }
    }
    foreach ($data['booths'] as $b) {
        if (!is_array($b) || empty($b['id'])) {
            return 'Ada meja tanpa ID.';
        }
        if (isset($ids[$b['id']])) {
            return "ID meja '{$b['id']}' ganda.";
        }
        $ids[$b['id']] = true;
        if (isset($b['rect']) && (!is_array($b['rect']) || count($b['rect']) !== 4)) {
            return "Posisi meja '{$b['id']}' tidak valid.";
        }
    }
    return null;
}

/** Peta yang dipublikasikan (dipakai kiosk) */
function venue_live(): ?array
{
    $data = venue_read(VENUE_LIVE);
    return ($data && !empty($data['map']['image'])) ? $data : null;
}

function venue_draft(): array
{
    return venue_read(VENUE_DRAFT) ?? venue_read(VENUE_LIVE) ?? venue_empty();
}

function venue_version(): ?int
{
    $data = venue_read(VENUE_LIVE);
    return $data['meta']['version'] ?? null;
}

function venue_publish(array $data): array
{
    $data['meta'] = ['publishedAt' => date('c'), 'version' => time()];
    if (!is_dir(VENUE_BACKUP)) {
        mkdir(VENUE_BACKUP, 0775, true);
    }
    if (is_file(VENUE_LIVE)) {
        copy(VENUE_LIVE, VENUE_BACKUP . '/map-' . date('Ymd-His') . '.json');
    }
    $old = glob(VENUE_BACKUP . '/map-*.json') ?: [];
    sort($old);
    while (count($old) > VENUE_KEEP) {
        unlink(array_shift($old));
    }
    if (!venue_write(VENUE_LIVE, $data) || !venue_write(VENUE_DRAFT, $data)) {
        throw new RuntimeException('Gagal menulis file peta. Periksa izin folder storage/venue.');
    }
    return $data['meta'];
}

function venue_backups(): array
{
    $out = [];
    foreach (glob(VENUE_BACKUP . '/map-*.json') ?: [] as $f) {
        $d = venue_read($f);
        $out[] = [
            'file'   => basename($f),
            'time'   => date('d/m/Y H:i:s', filemtime($f)),
            'tables' => $d ? count($d['booths'] ?? []) : 0,
        ];
    }
    return array_reverse($out);
}

/** Simpan gambar denah yang diunggah. Kembalikan [url, width, height]. */
function venue_save_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gagal (kode ' . ($file['error'] ?? '?') . '). Maksimal ' . ini_get('upload_max_filesize') . '.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('File harus gambar JPG, PNG, atau WebP (PDF diubah otomatis di browser).');
    }
    if ($info[0] < 200 || $info[1] < 200 || $info[0] > 10000 || $info[1] > 10000) {
        throw new RuntimeException('Ukuran gambar harus antara 200 dan 10.000 piksel.');
    }
    if (!is_dir(VENUE_UPLOAD_DIR)) {
        mkdir(VENUE_UPLOAD_DIR, 0775, true);
    }
    $name = 'denah-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], VENUE_UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Gagal menyimpan gambar. Periksa izin folder uploads/venue.');
    }
    return [VENUE_UPLOAD_URL . '/' . $name, (int) $info[0], (int) $info[1]];
}
