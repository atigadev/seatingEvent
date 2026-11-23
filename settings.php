<?php
declare(strict_types=1);
require __DIR__ . '/lib/functions.php';
require __DIR__ . '/lib/escpos.php';

date_default_timezone_set('Asia/Jakarta');
session_start();

$base = require __DIR__ . '/config.php';

/* ---------- Pesan sekali tampil (setelah redirect) ---------- */
function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = [$msg, $type];
}
function back(string $anchor = ''): void
{
    header('Location: settings.php' . ($anchor ? '#' . $anchor : ''));
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/* ---------- Login ---------- */
if (isset($_POST['login_password'])) {
    if (admin_password_ok((string) $_POST['login_password'])) {
        session_regenerate_id(true);
        $_SESSION['seat_admin'] = true;
        if (in_array($_POST['next'] ?? '', ['admin-denah.php', 'admin-tiket.php'], true)) {
            header('Location: ' . $_POST['next']);
            exit;
        }
        back();
    }
    sleep(1); // perlambat tebak-tebakan password
    flash('Password salah.', 'err');
    back();
}
if (isset($_GET['logout'])) {
    unset($_SESSION['seat_admin']);
    header('Location: index.php');
    exit;
}
$authed = !empty($_SESSION['seat_admin']);

/* ---------- Aksi (hanya setelah login) ---------- */
$action = (string) ($_POST['action'] ?? '');
if ($authed && $action !== '') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        flash('Sesi kedaluwarsa, silakan ulangi.', 'err');
        back();
    }

    switch ($action) {
        /* --- 1. Saklar scan --- */
        case 'scan_on':
        case 'scan_off':
            update_settings(['scan_active' => $action === 'scan_on']);
            flash($action === 'scan_on'
                ? 'Pemindaian DIMULAI. Kiosk siap menerima scan.'
                : 'Pemindaian DIHENTIKAN. Kiosk menampilkan layar standby.');
            back('scan');

        /* --- Sumber data peserta --- */
        case 'save_api':
        case 'test_api':
            $apiMode = ($_POST['api_mode'] ?? '') === 'mock' ? 'mock' : 'live';
            $apiUrl  = trim((string) ($_POST['api_url'] ?? ''));
            if ($apiMode === 'live' && !preg_match('#^https?://[^\s]+$#i', $apiUrl)) {
                flash('URL server registrasi tidak valid (harus diawali http:// atau https://).', 'err');
                back('sumber');
            }
            update_settings(['api' => ['mode' => $apiMode, 'url' => $apiUrl, 'verify_ssl' => !empty($_POST['api_ssl'])]]);
            if ($action === 'save_api') {
                flash('Sumber data disimpan: ' . ($apiMode === 'live' ? 'server registrasi' : 'data uji (mock)') . '.');
                back('sumber');
            }
            // Tes koneksi dengan ID contoh
            $testId = trim((string) ($_POST['test_id'] ?? ''));
            $test = ['id' => $testId];
            try {
                [$url, $id] = resolve_api_target($testId);
                $test['url'] = $url;
                $raw = api_request($url, $id);
                $test['status'] = $raw['status'];
                $test['time'] = $raw['time'];
                $test['type'] = $raw['content_type'];
                $test['raw'] = $raw['body'] === null ? ('(tidak ada respons) ' . $raw['error']) : mb_substr($raw['body'], 0, 1500);
                $test['data'] = api_fetch_participant($url, $id);
                flash('Koneksi berhasil — data peserta terbaca.');
            } catch (Throwable $ex) {
                $test['error'] = $ex->getMessage();
                flash('Tes koneksi: ' . $ex->getMessage(), 'err');
            }
            $_SESSION['api_test'] = $test;
            back('sumber');

        /* --- Video idle (halaman seating) --- */
        case 'video_settings':
            idle_video_save([
                'enabled' => !empty($_POST['iv_enabled']),
                'delay'   => max(5, min(3600, (int) ($_POST['iv_delay'] ?? 30))),
                'muted'   => empty($_POST['iv_sound']),
            ]);
            flash('Pengaturan video idle disimpan. Halaman seating ikut berubah dalam ±5 detik.');
            back('video');

        case 'video_upload':
            $files = $_FILES['videos'] ?? null;
            $list = (array) (app_config()['kiosk']['idle_video']['files'] ?? []);
            $added = 0;
            $errors = [];
            if (!is_dir(IDLE_VIDEO_DIR)) mkdir(IDLE_VIDEO_DIR, 0775, true);
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                $orig = (string) $files['name'][$i];
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $errors[] = $orig . ': gagal diunggah (kode ' . $files['error'][$i] . ', maks. ' . ini_get('upload_max_filesize') . ')';
                    continue;
                }
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $files['tmp_name'][$i]) : '';
                if (!in_array($ext, ['mp4', 'webm', 'm4v'], true) || ($mime !== '' && strpos($mime, 'video/') !== 0)) {
                    $errors[] = $orig . ': harus video MP4 atau WebM';
                    continue;
                }
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($orig, PATHINFO_FILENAME))), '-') ?: 'video';
                $name = substr($slug, 0, 40) . '-' . bin2hex(random_bytes(3)) . '.' . ($ext === 'm4v' ? 'mp4' : $ext);
                if (move_uploaded_file($files['tmp_name'][$i], IDLE_VIDEO_DIR . '/' . $name)) {
                    $list[] = $name;
                    $added++;
                } else {
                    $errors[] = $orig . ': gagal disimpan (izin folder uploads/videos)';
                }
            }
            idle_video_save(['files' => array_values($list)]);
            flash(($added ? "$added video ditambahkan. " : '') . implode(' · ', $errors) ?: 'Pilih file video terlebih dahulu.', $errors ? 'err' : 'ok');
            back('video');

        case 'video_youtube':
            $id = youtube_id((string) ($_POST['yt_url'] ?? ''));
            if ($id === null) {
                flash('Tautan YouTube tidak dikenali. Contoh: https://www.youtube.com/watch?v=XXXXXXXXXXX atau https://youtu.be/XXXXXXXXXXX', 'err');
                back('video');
            }
            $list = array_values((array) (app_config()['kiosk']['idle_video']['files'] ?? []));
            $list[] = 'yt:' . $id;
            idle_video_save(['files' => $list]);
            flash('Video YouTube ditambahkan (' . $id . '). Kiosk perlu koneksi internet untuk memutarnya.');
            back('video');

        case 'video_move':
        case 'video_delete':
            $list = array_values((array) (app_config()['kiosk']['idle_video']['files'] ?? []));
            $raw = (string) ($_POST['file'] ?? '');
            $f = strpos($raw, 'yt:') === 0 ? $raw : basename($raw);
            $i = array_search($f, $list, true);
            if ($i !== false) {
                if ($action === 'video_delete') {
                    array_splice($list, (int) $i, 1);
                    if (strpos($f, 'yt:') !== 0) @unlink(IDLE_VIDEO_DIR . '/' . $f);   // video YouTube: hanya dihapus dari daftar
                    flash('Video dihapus dari daftar putar.');
                } else {
                    $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
                    if ($j >= 0 && $j < count($list)) {
                        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
                    }
                    flash('Urutan video diperbarui.');
                }
                idle_video_save(['files' => $list]);
            }
            back('video');

        /* --- Saklar wayfinding --- */
        case 'wf_on':
        case 'wf_off':
            update_settings(['wayfinding' => $action === 'wf_on']);
            flash($action === 'wf_on'
                ? 'Wayfinding DITAMPILKAN. Kiosk menampilkan rute ke meja setelah scan.'
                : 'Wayfinding DISEMBUNYIKAN. Kiosk hanya menampilkan nomor kursi.');
            back('denah');

        /* --- 2. Teks layar standby --- */
        case 'save_standby':
            $title = trim((string) ($_POST['sb_title'] ?? ''));
            $sub   = trim((string) ($_POST['sb_subtitle'] ?? ''));
            if ($title === '') {
                flash('Judul layar standby tidak boleh kosong.', 'err');
                back('standby');
            }
            update_settings(['standby' => [
                'title'    => mb_substr($title, 0, 120),
                'subtitle' => mb_substr($sub, 0, 250),
            ]]);
            flash('Teks layar standby disimpan.');
            back('standby');

        /* --- Token Print Agent --- */
        case 'agent_token':
            update_settings(['agent' => ['token' => bin2hex(random_bytes(20))]]);
            flash('Token agen baru dibuat. Salin ke agent-config.php di PC venue (token lama tidak berlaku lagi).');
            back('printer');

        /* --- Cek status printer Brother (tanpa mencetak) --- */
        case 'brother_status':
            require_once __DIR__ . '/lib/brother.php';
            $brPort = trim((string) ($_POST['br_port_manual'] ?? '')) ?: trim((string) ($_POST['br_port'] ?? ''));
            update_settings(['mode' => 'brother', 'brother' => ['port' => $brPort, 'media' => 'DK-22205', 'cut' => !empty($_POST['br_cut'])]]);
            try {
                $st = bql_status($brPort);
                $parts = [];
                if (!empty($st['device'])) $parts[] = 'Status: ' . $st['device'];
                if (!empty($st['media'])) $parts[] = 'Gulungan: ' . $st['media'];
                if (!empty($st['emulation'])) $parts[] = 'Mode perintah: ' . $st['emulation'];
                $msgs = array_merge($st['errors'], $st['info'] ?? []);
                flash(($st['ok'] ? '✓ Printer terhubung. ' : '⚠ ') . implode(' · ', $parts) . ($msgs ? ' — ' . implode(' ', $msgs) : ''),
                      $st['ok'] && empty($st['info']) ? 'ok' : 'err');
            } catch (Throwable $ex) {
                flash($ex->getMessage(), 'err');
            }
            back('printer');

        /* --- 3. Printer --- */
        case 'save_printer':
        case 'test_printer':
            $mode = in_array($_POST['mode'] ?? '', ['cable', 'bluetooth', 'webbluetooth', 'brother', 'agent'], true) ? $_POST['mode'] : 'cable';
            $port = trim((string) ($_POST['port_manual'] ?? '')) ?: trim((string) ($_POST['port'] ?? ''));
            $bt = [
                'port'           => $port,
                'chars_per_line' => in_array((int) ($_POST['cols'] ?? 48), [32, 42, 48], true) ? (int) $_POST['cols'] : 48,
                'cut'            => !empty($_POST['cut']),
            ];
            $brPort = trim((string) ($_POST['br_port_manual'] ?? '')) ?: trim((string) ($_POST['br_port'] ?? ''));
            $br = ['port' => $brPort, 'media' => 'DK-22205', 'cut' => !empty($_POST['br_cut'])];
            $patch = ['mode' => $mode, 'brother' => $br];
            if (isset($_POST['agent_printer_type'])) {
                $patch['agent'] = [
                    'printer_type' => $_POST['agent_printer_type'] === 'escpos' ? 'escpos' : 'brother',
                    'failover'     => !empty($_POST['agent_failover']),
                ];
            }
            if ($mode === 'agent') {
                unset($patch['brother']); // isian port Brother tidak dipakai di mode agen
            }
            if (!in_array($mode, ['brother', 'agent'], true)) {
                $patch['bluetooth'] = $bt; // jangan timpa port ESC/POS dengan isian kosong saat memakai Brother / agen
            }
            update_settings($patch);

            if ($action === 'save_printer') {
                flash('Pengaturan printer disimpan.');
                back('printer');
            }
            if ($mode === 'agent') {
                try {
                    require_once __DIR__ . '/lib/agent.php';
                    if ((string) (agent_cfg()['token'] ?? '') === '') {
                        throw new RuntimeException('Buat token agen terlebih dahulu.');
                    }
                    $testStation = station_slug((string) ($_POST['agent_test_station'] ?? ''));
                    $sample = ['id' => 'TEST-0000', 'kursi' => 'A01', 'nama' => 'Test Cetak ' . ($testStation !== '' ? 'Stasiun ' . $testStation : 'Print Agent'), 'jabatan' => '', 'instansi' => 'Instansi Contoh'];
                    $job = queue_add($sample, null, $testStation);
                    $online = $job['station'] !== '' ? agent_station_online($job['station']) : (bool) array_filter(agent_states(), function ($s) { return $s['online']; });
                    $where = $job['station'] !== '' ? 'stasiun ' . $job['station'] : 'agen mana pun';
                    flash('Test cetak masuk antrean untuk ' . $where . ($job['rerouted'] ? ' (dialihkan — stasiun ' . $testStation . ' offline)' : '') . '. '
                        . ($online ? 'Agen online — label akan keluar dalam beberapa detik.' : 'Agen BELUM online — jalankan agent\\start-agents.bat di PC venue (job dibatalkan otomatis setelah ' . (int) agent_cfg()['job_ttl'] . ' detik).'),
                        $online ? 'ok' : 'err');
                } catch (Throwable $ex) {
                    flash($ex->getMessage(), 'err');
                }
                back('printer');
            }
            if ($mode === 'brother') {
                try {
                    require_once __DIR__ . '/lib/brother.php';
                    $cfgNow = $base;
                    $cfgNow['print']['brother'] = array_merge($base['print']['brother'] ?? [], $br);
                    $cfgNow['print']['copies'] = 1;
                    $cfgNow['kiosk']['seat_label'] = app_config()['kiosk']['seat_label'] ?? 'Nomor Meja';
                    $sample = ['id' => 'TEST-0000', 'kursi' => 'A01', 'nama' => 'Test Cetak Printer', 'jabatan' => 'Jabatan Contoh', 'instansi' => 'Instansi Contoh'];
                    bql_print_ticket($sample, $cfgNow);
                    flash('Test cetak terkirim ke Brother di ' . $brPort . '.');
                } catch (Throwable $ex) {
                    flash($ex->getMessage(), 'err');
                }
                back('printer');
            }
            if ($mode !== 'bluetooth') {
                header('Location: index.php?testprint=1');
                exit;
            }
            try {
                $cfgNow = $base;
                $cfgNow['print']['bluetooth'] = array_merge($base['print']['bluetooth'], $bt);
                $sample = ['id' => 'TEST-0000', 'kursi' => 'A-01', 'nama' => 'Test Cetak Printer', 'jabatan' => 'Jabatan Contoh', 'instansi' => 'Instansi Contoh'];
                escpos_send($port, escpos_build_ticket($sample, $cfgNow));
                flash('Test cetak terkirim ke ' . $port . '.');
            } catch (Throwable $ex) {
                flash($ex->getMessage(), 'err');
            }
            back('printer');

        /* --- 4. Ganti password --- */
        case 'change_password':
            $old  = (string) ($_POST['old_password'] ?? '');
            $new  = (string) ($_POST['new_password'] ?? '');
            $conf = (string) ($_POST['confirm_password'] ?? '');
            if (!admin_password_ok($old)) {
                sleep(1);
                flash('Password lama salah.', 'err');
            } elseif (mb_strlen($new) < 4) {
                flash('Password baru minimal 4 karakter.', 'err');
            } elseif ($new !== $conf) {
                flash('Konfirmasi password baru tidak sama.', 'err');
            } else {
                set_admin_password($new);
                flash('Password berhasil diganti.');
            }
            back('password');
    }
}

[$msg, $msgType] = $_SESSION['flash'] ?? ['', 'ok'];
unset($_SESSION['flash']);
$apiTest = $_SESSION['api_test'] ?? null;
unset($_SESSION['api_test']);

$cfg     = app_config();
$bt      = $cfg['print']['bluetooth'];
$active  = (bool) $cfg['kiosk']['scan_active'];
$standby = $cfg['kiosk']['standby'];
$presets = $cfg['kiosk']['standby_presets'] ?? [];
$ports   = $authed ? escpos_list_ports() : [];
$pwDefault = $authed && empty(load_settings()['admin_pin_hash']);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b1f3a">
<title>Pengaturan Kiosk</title>
<style>
  :root { --bg:#0b1f3a; --card:#12325c; --fg:#fff; --muted:#a9bad3; --accent:#f2b33d; --ok:#2fbf71; --err:#e5484d; }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; background:var(--bg); color:var(--fg); font-family:"Segoe UI",Roboto,Arial,sans-serif; display:flex; justify-content:center; padding:32px 16px; }
  .wrap { width:100%; max-width:680px; }
  h1 { margin:0 0 4px; font-size:26px; }
  h2 { margin:0 0 4px; font-size:19px; }
  .sub { color:var(--muted); margin:0 0 24px; }
  .card { background:var(--card); border-radius:14px; padding:24px; margin-bottom:16px; scroll-margin-top:16px; }
  .card > p.desc { color:var(--muted); font-size:14px; margin:0 0 16px; }
  .modes { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; }
  .mode { position:relative; display:block; border:2px solid rgba(255,255,255,.15); border-radius:12px; padding:18px; cursor:pointer; }
  .mode input { position:absolute; opacity:0; }
  .mode:has(input:checked) { border-color:var(--accent); background:rgba(242,179,61,.08); }
  .mode .t { font-size:17px; font-weight:700; margin-top:8px; }
  .mode .d { font-size:13px; color:var(--muted); margin-top:4px; line-height:1.4; }
  .mode svg { color:var(--accent); }
  label.f { display:block; font-size:14px; color:var(--muted); margin:16px 0 6px; }
  select, input[type=text], input[type=password], textarea { width:100%; padding:12px; border-radius:8px; border:1px solid rgba(255,255,255,.2); background:rgba(0,0,0,.2); color:var(--fg); font-size:16px; font-family:inherit; }
  textarea { resize:vertical; min-height:70px; }
  select option { color:#000; }
  .row { display:flex; gap:12px; }
  .row > div { flex:1; }
  .check { display:flex; align-items:center; gap:8px; margin-top:16px; }
  .actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:16px; }
  button, .btn { padding:12px 20px; border-radius:8px; border:0; font-size:16px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; font-family:inherit; }
  .primary { background:var(--accent); color:#1a1a1a; }
  .secondary { background:transparent; color:var(--fg); border:1px solid rgba(255,255,255,.3); }
  .msg { padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  .msg.ok { background:rgba(47,191,113,.15); color:#8ff0b8; }
  .msg.err { background:rgba(229,72,77,.15); color:#ffb3b5; }
  .help { font-size:13px; color:var(--muted); line-height:1.6; }
  .help ol { padding-left:18px; margin:8px 0 0; }
  .warn { font-size:13px; color:#ffd58a; margin-top:8px; }
  [hidden] { display:none !important; }

  /* Saklar scan */
  .scan-switch { display:flex; align-items:center; gap:20px; }
  .scan-state { flex:1; }
  .scan-state .big { font-size:22px; font-weight:800; }
  .scan-state .big.on { color:var(--ok); }
  .scan-state .big.off { color:#ffb3b5; }
  .scan-state .small { font-size:14px; color:var(--muted); margin-top:2px; }
  .toggle { position:relative; width:96px; height:52px; border-radius:999px; border:0; padding:0; cursor:pointer; background:rgba(255,255,255,.2); transition:background .2s; flex-shrink:0; }
  .toggle::after { content:""; position:absolute; top:5px; left:5px; width:42px; height:42px; border-radius:50%; background:#fff; box-shadow:0 2px 6px rgba(0,0,0,.3); transition:transform .2s; }
  .toggle.on { background:var(--ok); }
  .toggle.on::after { transform:translateX(44px); }

  /* Preset teks standby */
  .presets { display:grid; gap:8px; }
  .preset { display:flex; gap:10px; align-items:flex-start; border:1px solid rgba(255,255,255,.15); border-radius:10px; padding:12px; cursor:pointer; }
  .preset:has(input:checked) { border-color:var(--accent); background:rgba(242,179,61,.08); }
  .preset input { margin-top:3px; accent-color:var(--accent); }
  .preset .pt { font-weight:700; }
  .preset .ps { font-size:13px; color:var(--muted); }
  .preview { margin-top:16px; border-radius:12px; padding:24px 16px; text-align:center; background:radial-gradient(ellipse at top, #1d4f9a, #0b1f3a 75%); border:1px dashed rgba(255,255,255,.25); }
  .preview .lbl { font-size:11px; letter-spacing:2px; color:var(--muted); text-transform:uppercase; margin-bottom:8px; }
  .preview .pv-t { font-size:24px; font-weight:700; }
  .preview .pv-s { font-size:15px; color:#dbe6f7; margin-top:6px; }

  nav.jump { display:flex; gap:8px; flex-wrap:wrap; margin:-8px 0 20px; }
  nav.jump a { color:var(--muted); font-size:14px; text-decoration:none; border:1px solid rgba(255,255,255,.2); border-radius:999px; padding:6px 12px; }
  @media (max-width:620px) { .modes, .row { grid-template-columns:1fr; flex-direction:column; } }
</style>
</head>
<body>
<div class="wrap">
  <h1>Pengaturan Kiosk</h1>
  <p class="sub">Printer: <strong><?= e(print_mode_label($cfg)) ?></strong> · Scan: <strong><?= $active ? 'Berjalan' : 'Belum dimulai' ?></strong>
    · Wayfinding: <strong><?= !empty($cfg['kiosk']['wayfinding']) ? 'Tampil' : 'Disembunyikan' ?></strong></p>

  <?php if ($msg): ?><div class="msg <?= e($msgType) ?>"><?= e($msg) ?></div><?php endif; ?>

<?php if (!$authed): ?>
  <form method="post" class="card">
    <label class="f" for="login_password">Password Pengaturan</label>
    <input type="password" id="login_password" name="login_password" autocomplete="current-password" autofocus>
    <?php $next = (string) ($_GET['next'] ?? ''); if (in_array($next, ['admin-denah.php', 'admin-tiket.php'], true)): ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <p class="small" style="color:var(--muted);margin:8px 0 0">Login untuk membuka <?= $next === 'admin-tiket.php' ? 'Desain Tiket' : 'editor Denah &amp; Wayfinding' ?>.</p>
    <?php endif; ?>
    <div class="actions">
      <button class="primary">Masuk</button>
      <a class="btn secondary" href="index.php">Kembali ke Kiosk</a>
    </div>
  </form>
<?php else: ?>

  <nav class="jump">
    <a href="#scan">Saklar Scan</a><a href="#sumber">Sumber Data</a><a href="#standby">Teks Standby</a><a href="#denah">Denah</a><a href="#video">Video Idle</a><a href="#printer">Printer</a><a href="#password">Password</a>
  </nav>

  <!-- ================= 1. SAKLAR SCAN ================= -->
  <form method="post" class="card" id="scan">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2>Saklar Pemindaian</h2>
    <p class="desc">Mulai / hentikan pencarian nomor kursi. Semua kiosk ikut berubah dalam ±3 detik.</p>
    <div class="scan-switch">
      <div class="scan-state">
        <div class="big <?= $active ? 'on' : 'off' ?>"><?= $active ? 'SCAN BERJALAN' : 'BELUM DIMULAI' ?></div>
        <div class="small"><?= $active ? 'Peserta dapat scan ID Card untuk mencetak nomor kursi.' : 'Kiosk menampilkan layar standby, scan diabaikan.' ?></div>
      </div>
      <button class="toggle <?= $active ? 'on' : '' ?>" name="action" value="<?= $active ? 'scan_off' : 'scan_on' ?>"
              role="switch" aria-checked="<?= $active ? 'true' : 'false' ?>" title="<?= $active ? 'Hentikan scan' : 'Mulai scan' ?>"></button>
    </div>
  </form>

  <!-- ================= SUMBER DATA PESERTA ================= -->
  <?php $api = $cfg['api']; $isLive = ($api['mode'] ?? 'mock') === 'live'; ?>
  <form method="post" class="card" id="sumber">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2>Sumber Data Peserta</h2>
    <p class="desc">Dari mana nama, instansi &amp; nomor meja diambil setelah QR di-scan.</p>
    <div class="modes" style="grid-template-columns:1fr 1fr">
      <label class="mode">
        <input type="radio" name="api_mode" value="live" <?= $isLive ? 'checked' : '' ?>>
        <div class="t">Server Registrasi</div>
        <div class="d">Data asli dari link registrasi. Butuh koneksi internet.</div>
      </label>
      <label class="mode">
        <input type="radio" name="api_mode" value="mock" <?= $isLive ? '' : 'checked' ?>>
        <div class="t">Data Uji (Mock)</div>
        <div class="d">Peserta contoh PTBI-0001 … PTBI-0010 untuk latihan / tes printer.</div>
      </label>
    </div>
    <label class="f" for="api_url">URL server registrasi (<code>{id}</code> = isi QR)</label>
    <input type="text" id="api_url" name="api_url" value="<?= e($api['url'] ?? '') ?>" placeholder="https://registrasi.ptbi.co.id/web/seat/{id}">
    <label class="check"><input type="checkbox" name="api_ssl" value="1" <?= !empty($api['verify_ssl']) ? 'checked' : '' ?>> Verifikasi sertifikat SSL (biarkan aktif)</label>
    <div class="row" style="margin-top:16px;align-items:flex-end">
      <div>
        <label class="f" for="test_id">ID untuk tes koneksi</label>
        <input type="text" id="test_id" name="test_id" value="<?= e($apiTest['id'] ?? ($isLive ? 'AA210558' : 'PTBI-0001')) ?>">
      </div>
      <div class="actions" style="margin:0">
        <button class="secondary" name="action" value="test_api">Simpan &amp; Tes Koneksi</button>
        <button class="primary" name="action" value="save_api">Simpan</button>
      </div>
    </div>
    <?php if ($apiTest): ?>
      <div class="help" style="margin-top:16px">
        <?php if (!empty($apiTest['url'])): ?>
          <div><strong>URL:</strong> <code><?= e($apiTest['url']) ?></code>
            <?php if (isset($apiTest['status'])): ?> · HTTP <?= (int) $apiTest['status'] ?> · <?= e((string) $apiTest['time']) ?> dtk · <?= e($apiTest['type']) ?><?php endif; ?></div>
        <?php endif; ?>
        <?php if (!empty($apiTest['data'])): $d = $apiTest['data']; ?>
          <table style="margin-top:8px;border-collapse:collapse">
            <?php foreach (['nama' => 'Nama', 'instansi' => 'Instansi', 'jabatan' => 'Jabatan', 'kursi' => $cfg['kiosk']['seat_label'] ?? 'Kursi'] as $k => $lbl): ?>
              <tr><td style="padding:2px 12px 2px 0;color:var(--muted)"><?= e($lbl) ?></td>
                  <td style="color:#fff"><strong><?= e($d[$k] !== '' ? $d[$k] : ($k === 'kursi' && !empty($d['unassigned']) ? 'Belum ditentukan (tiket tidak dicetak)' : '—')) ?></strong></td></tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
        <?php if (!empty($apiTest['raw'])): ?>
          <details style="margin-top:8px"><summary>Respons mentah dari server</summary>
            <pre style="white-space:pre-wrap;word-break:break-all;background:rgba(0,0,0,.3);padding:10px;border-radius:8px;max-height:220px;overflow:auto"><?= e($apiTest['raw']) ?></pre>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </form>

  <!-- ================= 2. TEKS LAYAR STANDBY ================= -->
  <form method="post" class="card" id="standby">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2>Teks Layar Standby</h2>
    <p class="desc">Tampil di layar utama saat scan belum dimulai. Pilih kata-kata siap pakai atau tulis sendiri.</p>

    <div class="presets">
      <?php $matched = false; foreach ($presets as $i => $p):
        $isSel = $p['title'] === $standby['title'] && $p['subtitle'] === $standby['subtitle'];
        $matched = $matched || $isSel; ?>
        <label class="preset">
          <input type="radio" name="preset" value="<?= $i ?>" data-title="<?= e($p['title']) ?>" data-sub="<?= e($p['subtitle']) ?>" <?= $isSel ? 'checked' : '' ?>>
          <span><span class="pt"><?= e($p['title']) ?></span><br><span class="ps"><?= e($p['subtitle']) ?></span></span>
        </label>
      <?php endforeach; ?>
      <label class="preset">
        <input type="radio" name="preset" value="custom" <?= $matched ? '' : 'checked' ?>>
        <span><span class="pt">Tulis sendiri…</span><br><span class="ps">Ketik judul &amp; keterangan di bawah</span></span>
      </label>
    </div>

    <label class="f" for="sb_title">Judul</label>
    <input type="text" id="sb_title" name="sb_title" maxlength="120" value="<?= e($standby['title']) ?>" required>
    <label class="f" for="sb_subtitle">Keterangan</label>
    <textarea id="sb_subtitle" name="sb_subtitle" maxlength="250"><?= e($standby['subtitle']) ?></textarea>

    <div class="preview">
      <div class="lbl">Pratinjau layar</div>
      <div class="pv-t" id="pv-t"><?= e($standby['title']) ?></div>
      <div class="pv-s" id="pv-s"><?= e($standby['subtitle']) ?></div>
    </div>

    <div class="actions">
      <button class="primary" name="action" value="save_standby">Simpan Teks</button>
    </div>
  </form>

  <!-- ================= DENAH & WAYFINDING ================= -->
  <div class="card" id="denah">
    <h2>Denah &amp; Wayfinding</h2>
    <?php
      require_once __DIR__ . '/lib/venue.php';
      $venue = venue_live();
      $placedTables = $venue ? count(array_filter($venue['booths'], function ($b) { return !empty($b['rect']); })) : 0;
      $wfOn = (bool) ($cfg['kiosk']['wayfinding'] ?? true);
    ?>
    <form method="post" class="scan-switch" style="margin:4px 0 16px">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="scan-state">
        <div class="big <?= $wfOn ? 'on' : 'off' ?>"><?= $wfOn ? 'WAYFINDING TAMPIL' : 'WAYFINDING DISEMBUNYIKAN' ?></div>
        <div class="small"><?= $wfOn
            ? 'Setelah scan, kiosk menampilkan peta rute ke meja peserta.'
            : 'Setelah scan, kiosk hanya menampilkan nomor kursi (tanpa peta).' ?></div>
      </div>
      <button class="toggle <?= $wfOn ? 'on' : '' ?>" name="action" value="<?= $wfOn ? 'wf_off' : 'wf_on' ?>"
              role="switch" aria-checked="<?= $wfOn ? 'true' : 'false' ?>" title="<?= $wfOn ? 'Sembunyikan wayfinding' : 'Tampilkan wayfinding' ?>"></button>
    </form>
    <p class="desc">
      Unggah denah seating area, gambar lorong &amp; meja, lalu kiosk menampilkan rute terdekat ke meja peserta setelah scan.
      <?php if ($venue): ?>
        <br>Tayang: <strong><?= $placedTables ?> meja</strong>, <?= count($venue['kiosks']) ?> kiosk
        · dipublikasikan <?= e(date('d/m/Y H:i', (int) $venue['meta']['version'])) ?>
      <?php else: ?>
        <br><strong>Belum ada denah yang dipublikasikan.</strong>
      <?php endif; ?>
    </p>
    <div class="actions"><a class="btn primary" href="admin-denah.php">Buka Editor Denah</a></div>
  </div>

  <!-- ================= VIDEO IDLE (halaman seating) ================= -->
  <?php
    $ivRaw = ($cfg['kiosk']['idle_video'] ?? []) + ['enabled' => false, 'delay' => 30, 'muted' => true, 'files' => []];
    $ivFiles = array_values(array_filter((array) $ivRaw['files'], function ($f) {
        $f = (string) $f;
        return strpos($f, 'yt:') === 0 || is_file(IDLE_VIDEO_DIR . '/' . basename($f));
    }));
  ?>
  <div class="card" id="video">
    <h2>Video Idle — Halaman Seating</h2>
    <p class="desc">Saat belum ada scan, video tampil di frame denah dan diputar bergantian.
      Saat peserta scan, video dijeda & disembunyikan, lalu dilanjutkan kembali setelah layar idle lagi.</p>

    <form method="post" class="scan-switch" style="margin:4px 0 14px;flex-wrap:wrap;gap:14px">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label class="check" style="margin:0"><input type="checkbox" name="iv_enabled" value="1" <?= !empty($ivRaw['enabled']) ? 'checked' : '' ?>> Aktifkan video idle</label>
      <label class="check" style="margin:0">Tampil setelah
        <input type="number" name="iv_delay" min="5" max="3600" value="<?= (int) $ivRaw['delay'] ?>" style="width:90px;padding:8px">
        detik tanpa scan</label>
      <label class="check" style="margin:0"><input type="checkbox" name="iv_sound" value="1" <?= empty($ivRaw['muted']) ? 'checked' : '' ?>> Dengan suara</label>
      <button class="primary" name="action" value="video_settings">Simpan</button>
    </form>

    <?php if (!$ivFiles): ?>
      <p class="small" style="color:#ffd58a">Belum ada video. Unggah video atau tambahkan tautan YouTube agar fitur ini berjalan.</p>
    <?php else: ?>
      <div style="display:grid;gap:8px;margin-bottom:14px">
        <?php foreach ($ivFiles as $i => $f): $f = (string) $f; $isYt = strpos($f, 'yt:') === 0; if (!$isYt) $f = basename($f); $ytId = $isYt ? substr($f, 3) : ''; ?>
          <form method="post" style="display:flex;align-items:center;gap:10px;background:rgba(0,0,0,.18);border-radius:10px;padding:8px">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="file" value="<?= e($f) ?>">
            <b style="width:22px;text-align:center;color:var(--accent)"><?= $i + 1 ?></b>
            <?php if ($isYt): ?>
              <img src="https://i.ytimg.com/vi/<?= e($ytId) ?>/mqdefault.jpg" alt="" loading="lazy" style="width:120px;height:68px;object-fit:cover;border-radius:6px;background:#000">
              <div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <span style="background:#e62117;color:#fff;font-size:11px;font-weight:800;padding:1px 6px;border-radius:4px">YouTube</span>
                <a href="https://www.youtube.com/watch?v=<?= e($ytId) ?>" target="_blank" rel="noopener" style="color:#fff"><?= e($ytId) ?></a><br>
                <span class="small" style="color:var(--muted)">Butuh internet di kiosk</span>
              </div>
            <?php else: ?>
              <video src="<?= e(IDLE_VIDEO_URL . '/' . rawurlencode($f)) ?>" muted preload="metadata" style="width:120px;height:68px;object-fit:cover;border-radius:6px;background:#000"></video>
              <div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($f) ?>">
                <?= e($f) ?><br><span class="small" style="color:var(--muted)"><?= number_format(filesize(IDLE_VIDEO_DIR . '/' . $f) / 1048576, 1, ',', '.') ?> MB</span>
              </div>
            <?php endif; ?>
            <input type="hidden" name="dir" value="">
            <button class="secondary" name="action" value="video_move" onclick="this.form.dir.value='up'" <?= $i === 0 ? 'disabled' : '' ?> title="Naik">▲</button>
            <button class="secondary" name="action" value="video_move" onclick="this.form.dir.value='down'" <?= $i === count($ivFiles) - 1 ? 'disabled' : '' ?> title="Turun">▼</button>
            <button class="secondary" name="action" value="video_delete" onclick="return confirm('Hapus video ini?')" title="Hapus" style="color:#ffb3b5">✕</button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div>
        <label class="f" for="videos">Tambah video (MP4 / WebM, bisa pilih beberapa sekaligus)</label>
        <input type="file" id="videos" name="videos[]" accept="video/mp4,video/webm,.mp4,.webm,.m4v" multiple style="width:100%;color:var(--muted)">
        <p class="small" style="color:var(--muted);margin:6px 0 0">Batas server: <?= e(ini_get('upload_max_filesize')) ?> per file,
          <?= e(ini_get('post_max_size')) ?> per unggahan. Disarankan MP4 (H.264) 1080p, ukuran kecil agar lancar di tablet.</p>
      </div>
      <div style="flex:0 0 auto"><button class="primary" name="action" value="video_upload">Unggah</button></div>
    </form>

    <form method="post" class="row" style="align-items:flex-end;margin-top:12px">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div>
        <label class="f" for="yt_url">Atau tambah video YouTube (tempel tautan)</label>
        <input type="text" id="yt_url" name="yt_url" placeholder="https://www.youtube.com/watch?v=…  atau  https://youtu.be/…">
        <p class="small" style="color:var(--muted);margin:6px 0 0">Diputar langsung dari YouTube (kiosk butuh internet). Video yang pemiliknya
          melarang pemutaran di situs lain akan dilewati otomatis.</p>
      </div>
      <div style="flex:0 0 auto"><button class="primary" name="action" value="video_youtube">Tambah</button></div>
    </form>
  </div>

  <!-- ================= 3. PRINTER ================= -->
  <form method="post" class="card" id="printer">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2>Printer</h2>
    <p class="desc">Cara tiket dicetak. Tampilan & ukuran tiket diatur di
      <a href="admin-tiket.php" style="color:var(--accent);font-weight:700">Desain Tiket →</a></p>
    <div class="modes">
      <?php $m = $cfg['print']['mode']; ?>
      <label class="mode">
        <input type="radio" name="mode" value="cable" <?= !in_array($m, ['bluetooth', 'webbluetooth'], true) ? 'checked' : '' ?>>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v10M8 6h8M9 12h6v4a3 3 0 0 1-6 0zM12 19v3"/></svg>
        <div class="t">Kabel USB</div>
        <div class="d">Via driver Windows (printer default). Cetak senyap lewat browser kiosk.</div>
      </label>
      <label class="mode">
        <input type="radio" name="mode" value="webbluetooth" <?= $m === 'webbluetooth' ? 'checked' : '' ?>>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7l10 10-5 5V2l5 5L7 17"/></svg>
        <div class="t">Bluetooth dari Chrome</div>
        <div class="d">Pair &amp; cetak langsung dari browser (printer BLE). Tanpa pairing Windows.</div>
      </label>
      <label class="mode">
        <input type="radio" name="mode" value="bluetooth" <?= $m === 'bluetooth' ? 'checked' : '' ?>>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7l10 10-5 5V2l5 5L7 17"/><path d="M19 9v6M22 11v2"/></svg>
        <div class="t">Cetak via Server</div>
        <div class="d">Server PHP yang mencetak: Bluetooth COM atau printer LAN/WiFi (IP:9100). Cocok untuk iPad/tablet.</div>
      </label>
      <label class="mode">
        <input type="radio" name="mode" value="brother" <?= $m === 'brother' ? 'checked' : '' ?>>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="7" width="18" height="10" rx="2"/><path d="M7 17v4h10v-4M7 7V3h10v4"/></svg>
        <div class="t">Brother QL (Label)</div>
        <div class="d">QL-800/810W/820NWB, label DK-22205 62 mm. Via Bluetooth (COM) atau Wi-Fi (IP:9100).</div>
      </label>
      <label class="mode">
        <input type="radio" name="mode" value="agent" <?= $m === 'agent' ? 'checked' : '' ?>>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 14a8 8 0 0 1 16 0"/><path d="M8 14a4 4 0 0 1 8 0"/><circle cx="12" cy="14" r="1.5"/><path d="M12 16v5"/></svg>
        <div class="t">Print Agent (Hosting)</div>
        <div class="d">Aplikasi di hosting internet, printer di venue. Agen di PC venue mengambil & mencetak tiket.</div>
      </label>
    </div>

    <?php
      require_once __DIR__ . '/lib/agent.php';
      $ag = agent_cfg($cfg);
      $agents = agent_states();
      $serverUrl = app_base_url();
    ?>
    <div id="agent-panel">
      <div class="row">
        <div>
          <label class="f" for="agent_printer_type">Jenis printer di venue (semua stasiun)</label>
          <select id="agent_printer_type" name="agent_printer_type">
            <option value="brother" <?= $ag['printer_type'] !== 'escpos' ? 'selected' : '' ?>>Brother QL-820NWB · DK-22205 62 mm</option>
            <option value="escpos" <?= $ag['printer_type'] === 'escpos' ? 'selected' : '' ?>>Printer thermal struk (ESC/POS)</option>
          </select>
        </div>
        <div>
          <label class="check"><input type="checkbox" name="agent_failover" value="1" <?= !empty($ag['failover']) ? 'checked' : '' ?>>
            Alihkan ke stasiun lain bila printer stasiun mati</label>
        </div>
      </div>

      <label class="f">Stasiun cetak &amp; agen</label>
      <?php if (!$agents): ?>
        <div class="small" style="padding:10px 0;color:#ffd58a">Belum ada agen yang terhubung.</div>
      <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:14px">
          <tr style="color:var(--muted);text-align:left"><th style="padding:6px 8px 6px 0">Status</th><th>Stasiun</th><th>Agen</th><th>Printer</th><th>Antre</th><th>Terakhir</th></tr>
          <?php foreach ($agents as $a): ?>
            <tr style="border-top:1px solid rgba(255,255,255,.1)">
              <td style="padding:7px 8px 7px 0"><b style="color:<?= $a['online'] ? 'var(--ok)' : 'var(--err)' ?>"><?= $a['online'] ? '● ONLINE' : '● OFFLINE' ?></b></td>
              <td><b><?= e(($a['station'] ?? '') !== '' ? $a['station'] : '(semua)') ?></b></td>
              <td><?= e($a['name'] ?? '') ?></td>
              <td><?= e($a['printer'] ?? '-') ?></td>
              <td><?= (int) $a['pending'] ?></td>
              <td style="color:var(--muted)"><?= e(date('d/m H:i:s', (int) $a['last_seen'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php $stList = agent_station_list(); ?>
      <div class="row" style="margin-top:4px">
        <div>
          <label class="f" for="agent_test_station">Test cetak ke stasiun</label>
          <select id="agent_test_station" name="agent_test_station">
            <option value="">(agen mana pun)</option>
            <?php foreach ($stList as $s): ?>
              <option value="<?= e($s['station']) ?>"><?= e($s['station']) ?><?= $s['online'] ? '' : ' (offline)' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div></div>
      </div>
      <label class="f">Token agen</label>
      <div class="row" style="align-items:center">
        <div><input type="text" readonly value="<?= e($ag['token'] ?: '(belum dibuat)') ?>" onclick="this.select()" style="font-family:Consolas,monospace;font-size:14px"></div>
        <div style="flex:0 0 auto"><button class="secondary" name="action" value="agent_token"
             onclick="return <?= $ag['token'] ? "confirm('Buat token baru? Agen yang memakai token lama akan berhenti sampai token diperbarui.')" : 'true' ?>">
             <?= $ag['token'] ? 'Buat token baru' : 'Buat token' ?></button></div>
      </div>
      <div class="help" style="margin-top:16px">
        <strong>Pasang agen di PC venue</strong> (PC yang satu Wi-Fi dengan printer, mis. PC kiosk; butuh PHP — XAMPP cukup):
        <ol>
          <li>Salin folder aplikasi ini (atau <code>git clone</code>) ke PC venue.</li>
          <li>Untuk <b>setiap printer</b>, salin <code>agent\agent-config.example.php</code> menjadi
              <code>agent\agent-config.php</code>, <code>agent\agent-config-2.php</code>, dst., lalu isi:
<pre style="white-space:pre-wrap;background:rgba(0,0,0,.3);padding:10px;border-radius:8px;margin:8px 0">'server'  => '<?= e($serverUrl) ?>',
'token'   => '<?= e($ag['token'] ?: 'BUAT-TOKEN-DULU') ?>',
'station' => 'pintu-utara',     // nama stasiun printer ini (beda per printer)
'printer' => '172.16.16.192',   // IP printer (port 9100) atau COM Bluetooth</pre></li>
          <li>Jalankan <code>agent\start-agents.bat</code> — satu jendela per printer; biarkan terbuka selama acara.
              (Satu PC bisa melayani beberapa printer.)</li>
          <li>Di setiap tablet/kiosk: buka menu bawah layar → pilih <b>Stasiun</b> yang sama dengan printer terdekat
              (atau buka alamat dengan <code>?station=pintu-utara</code>).</li>
          <li>Agen tampil <b>ONLINE</b> di tabel di atas, lalu klik <b>Simpan &amp; Test Cetak</b> per stasiun.</li>
        </ol>
        Agen hanya membuat koneksi keluar (HTTPS) ke server — printer tidak dibuka ke internet.
        Tiket yang tidak diambil agen dalam <?= (int) $ag['job_ttl'] ?> detik dibatalkan (tidak tercetak belakangan).
      </div>
    </div>

    <?php $brc = $cfg['print']['brother'] ?? []; ?>
    <div id="brother-panel">
      <div class="row">
        <div>
          <label class="f" for="br_port">Bluetooth — port COM printer</label>
          <select id="br_port" name="br_port">
            <option value="">(pilih bila memakai Bluetooth)</option>
            <?php foreach ($ports as $p => $name): ?>
              <option value="<?= e($p) ?>" <?= $p === ($brc['port'] ?? '') ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="f" for="br_port_manual">Wi-Fi — IP printer:9100 (atau isi COM manual)</label>
          <input type="text" id="br_port_manual" name="br_port_manual" placeholder="mis. 172.16.16.192 (port 9100 otomatis)"
                 value="<?= e(isset($ports[$brc['port'] ?? '']) ? '' : ($brc['port'] ?? '')) ?>">
        </div>
      </div>
      <div class="row">
        <div>
          <label class="f">Gulungan label</label>
          <select disabled><option>DK-22205 — label kontinu 62 mm</option></select>
        </div>
        <div>
          <label class="check"><input type="checkbox" name="br_cut" value="1" <?= !array_key_exists('cut', $brc) || !empty($brc['cut']) ? 'checked' : '' ?>> Potong otomatis setiap tiket</label>
        </div>
      </div>
      <div class="actions"><button class="secondary" name="action" value="brother_status">Cek Status Printer (Wi-Fi, tanpa mencetak)</button></div>
      <div class="help" style="margin-top:16px;display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
        <div style="flex:1;min-width:240px">
          <strong>Persiapan Brother QL-820NWB:</strong>
          <ol>
            <li>Pasang gulungan <b>DK-22205</b>. Pastikan lampu <b>Editor Lite mati</b> (tekan tombolnya 1 detik bila menyala).</li>
            <li>Ubah <b>mode perintah (Command Mode / Emulation) ke "Raster"</b> bila masih "P-touch Template" / "ESC/P" (cek dengan tombol <i>Cek Status Printer</i>).
                Lewat layar printer (menu pengaturan › Command Mode), atau Brother <i>Printer Setting Tool</i> di Windows.</li>
            <li><b>Wi-Fi (disarankan):</b> sambungkan printer ke Wi-Fi yang sama dengan PC server
                (menu printer › WLAN, atau aplikasi Brother iPrint&amp;Label). Lihat IP-nya di menu
                <i>Info › Network</i> / cetak laporan konfigurasi, lalu isi <code>IP:9100</code>.</li>
            <li><b>Bluetooth:</b> nyalakan Bluetooth printer, pair di Windows (<i>Settings › Bluetooth &amp; devices</i>),
                lalu pilih port COM <b>Outgoing</b> di daftar. Bluetooth printer ini <b>Classic</b> — tidak bisa lewat mode "Bluetooth dari Chrome".</li>
            <li>Klik <b>Simpan &amp; Test Cetak</b>.</li>
          </ol>
        </div>
        <figure style="margin:0;text-align:center">
          <img src="api/brother-preview.php?t=<?= time() ?>" alt="Pratinjau tiket Brother" style="width:170px;background:#fff;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.4)">
          <figcaption class="small" style="color:var(--muted);margin-top:6px">Pratinjau label 62 mm</figcaption>
        </figure>
      </div>
    </div>

    <div id="ble-panel" class="help" style="margin-top:16px">
      <strong>Mode Bluetooth dari Chrome:</strong>
      <ol>
        <li>Nyalakan printer (tidak perlu pairing di Windows). Printer harus mendukung <strong>BLE</strong>.</li>
        <li>Klik <strong>Simpan</strong>, lalu di layar kiosk tekan <strong>Hubungkan Printer</strong> → pilih printer di dialog Chrome.</li>
        <li>Koneksi otomatis tersambung ulang jika printer mati-nyala. Jika kiosk di-restart, tekan <strong>Hubungkan Printer</strong> sekali lagi bila belum tersambung.</li>
        <li>Jika printer tidak muncul di dialog → printer hanya Bluetooth Classic, gunakan mode <em>Cetak via Server</em>.</li>
        <li>Didukung di Chrome/Edge (PC &amp; Android). <strong>Tidak didukung di iPad/iPhone</strong>.</li>
      </ol>
    </div>

    <div id="esc-panel">
      <div class="row">
        <div>
          <label class="f" for="cols">Lebar kertas</label>
          <select id="cols" name="cols">
            <option value="48" <?= (int) $bt['chars_per_line'] === 48 ? 'selected' : '' ?>>80mm (48 karakter)</option>
            <option value="42" <?= (int) $bt['chars_per_line'] === 42 ? 'selected' : '' ?>>80mm (42 karakter, font besar)</option>
            <option value="32" <?= (int) $bt['chars_per_line'] === 32 ? 'selected' : '' ?>>58mm (32 karakter)</option>
          </select>
        </div>
        <div>
          <label class="check"><input type="checkbox" name="cut" value="1" <?= !empty($bt['cut']) ? 'checked' : '' ?>> Potong kertas otomatis</label>
        </div>
      </div>
    </div>

    <div id="bt-panel">
      <div class="row">
        <div>
          <label class="f" for="port">Port printer Bluetooth</label>
          <select id="port" name="port">
            <?php if (!$ports): ?><option value="<?= e($bt['port']) ?>"><?= e($bt['port']) ?> (tidak terdeteksi)</option><?php endif; ?>
            <?php foreach ($ports as $p => $name): ?>
              <option value="<?= e($p) ?>" <?= $p === $bt['port'] ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="f" for="port_manual">Atau isi manual (COM / IP printer LAN)</label>
          <input type="text" id="port_manual" name="port_manual" placeholder="mis. COM7 atau 192.168.1.50:9100">
        </div>
      </div>
      <div class="help" style="margin-top:16px">
        <strong>Cara pairing printer Bluetooth:</strong>
        <ol>
          <li>Nyalakan printer → Windows <em>Settings › Bluetooth &amp; devices › Add device</em> → pilih printer (PIN biasanya 0000 / 1234).</li>
          <li>Windows membuat port COM untuk printer. Pilih port <strong>"Outgoing"</strong> / "Standard Serial over Bluetooth link" (cek di <em>More Bluetooth settings › COM Ports</em>).</li>
          <li>Klik <strong>Simpan &amp; Test Cetak</strong>. Jika gagal, coba port COM lainnya.</li>
        </ol>
        <strong>Printer LAN / WiFi:</strong> isi kolom manual dengan <code>IP-printer:9100</code>, mis. <code>192.168.1.50:9100</code>.
        <br><strong>iPad / tablet:</strong> gunakan mode ini — tablet hanya scan, printer dikendalikan server.
      </div>
    </div>

    <div id="cable-panel" class="help" style="margin-top:16px">
      <strong>Mode Kabel USB:</strong>
      <ol>
        <li>Install driver printer thermal, jadikan <strong>Default Printer</strong> di Windows.</li>
        <li>Set kertas <em>Roll Paper 80mm</em> &amp; aktifkan auto cut di Printing Preferences.</li>
        <li>Kiosk harus dibuka via <strong>start-kiosk.bat</strong> agar cetak tanpa dialog.</li>
      </ol>
    </div>

    <div class="actions">
      <button class="primary" name="action" value="save_printer">Simpan Printer</button>
      <button class="secondary" name="action" value="test_printer">Simpan &amp; Test Cetak</button>
    </div>
  </form>

  <!-- ================= 4. GANTI PASSWORD ================= -->
  <form method="post" class="card" id="password" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <h2>Ganti Password Pengaturan</h2>
    <p class="desc">Password untuk membuka halaman ini (minimal 4 karakter, boleh angka/huruf).</p>
    <?php if ($pwDefault): ?>
      <div class="warn">⚠ Masih memakai password bawaan. Sebaiknya segera diganti.</div>
    <?php endif; ?>
    <label class="f" for="old_password">Password lama</label>
    <input type="password" id="old_password" name="old_password" autocomplete="current-password" required>
    <div class="row">
      <div>
        <label class="f" for="new_password">Password baru</label>
        <input type="password" id="new_password" name="new_password" minlength="4" autocomplete="new-password" required>
      </div>
      <div>
        <label class="f" for="confirm_password">Ulangi password baru</label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="4" autocomplete="new-password" required>
      </div>
    </div>
    <label class="check"><input type="checkbox" id="show-pw"> Tampilkan password</label>
    <div class="actions">
      <button class="primary" name="action" value="change_password">Ganti Password</button>
    </div>
  </form>

  <div class="actions" style="margin-bottom:32px">
    <a class="btn primary" href="index.php">Kembali ke Kiosk</a>
    <a class="btn secondary" href="settings.php?logout=1">Keluar</a>
  </div>

  <script>
    (function () {
      // panel printer sesuai mode
      const radios = document.querySelectorAll('input[name=mode]');
      function sync() {
        const m = document.querySelector('input[name=mode]:checked').value;
        document.getElementById('bt-panel').hidden = m !== 'bluetooth';
        document.getElementById('ble-panel').hidden = m !== 'webbluetooth';
        document.getElementById('esc-panel').hidden = m === 'cable';
        document.getElementById('cable-panel').hidden = m !== 'cable';
        document.getElementById('brother-panel').hidden = m !== 'brother';
        document.getElementById('agent-panel').hidden = m !== 'agent';
        if (m === 'brother' || m === 'agent') document.getElementById('esc-panel').hidden = true;
      }
      radios.forEach(r => r.addEventListener('change', sync));
      sync();

      // preset teks standby → isi kolom & pratinjau
      const t = document.getElementById('sb_title');
      const s = document.getElementById('sb_subtitle');
      const pvT = document.getElementById('pv-t');
      const pvS = document.getElementById('pv-s');
      function preview() { pvT.textContent = t.value; pvS.textContent = s.value; }
      document.querySelectorAll('input[name=preset]').forEach(r => r.addEventListener('change', () => {
        if (r.value === 'custom') { t.focus(); return; }
        t.value = r.dataset.title; s.value = r.dataset.sub; preview();
      }));
      [t, s].forEach(el => el.addEventListener('input', () => {
        document.querySelector('input[name=preset][value=custom]').checked = true;
        preview();
      }));

      // tampilkan password
      document.getElementById('show-pw').addEventListener('change', (e) => {
        ['old_password', 'new_password', 'confirm_password'].forEach(id => {
          document.getElementById(id).type = e.target.checked ? 'text' : 'password';
        });
      });
    })();
  </script>
<?php endif; ?>
</div>
</body>
</html>
