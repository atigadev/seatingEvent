<?php
/**
 * KONFIGURASI APLIKASI SEAT KIOSK
 * ------------------------------------------------------------------
 * Saat API asli sudah tersedia, cukup ubah bagian 'api' di bawah ini.
 */

return [
    // Judul acara yang tampil di layar & di cetakan
    'event_name'  => 'PTBI 2026',
    'event_sub'   => 'Pertemuan Tahunan',

    'api' => [
        // Sumber data peserta (bisa diubah di Pengaturan › Sumber Data Peserta):
        //  'live' = server registrasi (url di bawah), 'mock' = data uji lokal (mock/api.php)
        'mode'    => 'live',
        // {id} diganti isi QR. Respons boleh JSON atau halaman HTML berlabel
        // (NAMA / NO MEJA / INSTANSI / JABATAN) seperti halaman registrasi PTBI.
        'url'     => 'https://registrasi.ptbi.co.id/web/seat/{id}',

        'method'  => 'GET',          // GET atau POST
        'post_field' => 'id',        // nama field jika method POST
        'timeout' => 8,              // detik
        'verify_ssl' => true,

        // Header tambahan, misal token
        'headers' => [
            // 'Authorization: Bearer xxxxx',
            'Accept: application/json',
        ],

        // Jika QR berisi URL lengkap (bukan sekadar ID):
        //  true  = URL tsb langsung dipanggil (hanya jika host ada di allowed_hosts)
        //  false = ambil ID dari parameter ?id= atau segmen terakhir URL
        'qr_is_url'     => false,
        'allowed_hosts' => [],       // contoh: ['api.contoh.go.id']

        // Path (dot notation) di JSON respons untuk tiap field.
        // Sesuaikan dengan struktur respons API asli.
        'field_map' => [
            'id'       => 'data.id',
            'nama'     => 'data.nama',
            'jabatan'  => 'data.jabatan',
            'instansi' => 'data.instansi',
            'kursi'    => 'data.no_kursi',
        ],

        // Path penanda sukses (opsional). null = abaikan.
        'success_path' => 'success',
    ],

    // Validasi isi QR (jika qr_is_url = false)
    'id_pattern' => '/^[A-Za-z0-9_.\-]{1,64}$/',

    // PIN untuk membuka halaman Pengaturan (settings.php)
    'admin_pin' => '1234',

    'print' => [
        // Mode cetak (bisa diubah dari halaman Pengaturan, disimpan di storage/settings.json):
        //  'cable'     = Kabel USB  -> driver Windows + browser --kiosk-printing
        //  'bluetooth' = Bluetooth  -> PHP kirim ESC/POS langsung ke port COM printer
        //  'webbluetooth' = Bluetooth dari Chrome (BLE) -> pair & cetak langsung dari browser
        //  'brother'   = Brother QL (Raster) via Bluetooth COM atau Wi-Fi IP:9100
        //  'agent'     = Print Agent: hosting di internet, printer di venue (lihat agent/)
        'mode' => 'cable',

        // Print Agent — mode 'agent': server (hosting) mengantre tiket, agen di PC venue mencetak
        // ke printer lokal. Token & jenis printer diatur di Pengaturan › Printer › Print Agent.
        'agent' => [
            'token'        => '',         // dibuat dari Pengaturan (disimpan di storage/settings.json)
            'printer_type' => 'brother',  // 'brother' (QL, DK-22205) atau 'escpos' (thermal struk)
            'job_ttl'      => 90,         // detik — job yang tidak diambil agen dibatalkan
            'failover'     => true,       // agen stasiun offline → cetak di stasiun lain yang online
        ],

        // Brother QL-800/810W/820NWB (Brother Raster, bukan ESC/POS) — mode 'brother'
        'brother' => [
            'port'  => '',            // 'COM7' (Bluetooth, port Outgoing) atau '192.168.1.60:9100' (Wi-Fi/LAN)
            'media' => 'DK-22205',    // label kontinu 62 mm
            'cut'   => true,          // potong otomatis setiap tiket
            'font_regular' => '',     // kosong = Arial Windows
            'font_bold'    => '',
        ],

        'bluetooth' => [
            'port'           => 'COM5', // port COM "Outgoing" printer Bluetooth (lihat Pengaturan)
            'chars_per_line' => 48,     // 48 = kertas 80mm, 32 = kertas 58mm
            'cut'            => true,   // potong kertas otomatis (jika printer punya cutter)
        ],

        // Printer thermal 80mm: lebar kertas 80mm, area cetak efektif ~72mm.
        // Tinggi = panjang potongan tiket (roll akan dipotong setelah konten).
        'paper_size'      => '80mm 80mm',
        'printable_width' => '72mm',
        'auto_print'  => true,       // langsung cetak setelah data didapat
        'copies'      => 1,
    ],

    'kiosk' => [
        // Sebutan nomor tempat duduk di layar & tiket (data registrasi PTBI memakai "NO MEJA")
        'seat_label' => 'Nomor Meja',

        // Saklar pemindaian (bisa diubah dari halaman Pengaturan).
        // false = layar menampilkan pesan standby & scan diabaikan sampai dimulai.
        'scan_active' => false,

        // Teks layar standby (saat scan belum dimulai) — bisa diubah dari Pengaturan
        'standby' => [
            'title'    => 'Registrasi Belum Dibuka',
            'subtitle' => 'Silakan menunggu, pemindaian nomor kursi akan segera dimulai.',
        ],

        // Pilihan kata-kata siap pakai untuk layar standby
        'standby_presets' => [
            ['title' => 'Registrasi Belum Dibuka',       'subtitle' => 'Silakan menunggu, pemindaian nomor kursi akan segera dimulai.'],
            ['title' => 'Selamat Datang',                'subtitle' => 'Registrasi kursi akan dibuka sebentar lagi.'],
            ['title' => 'Registrasi Dibuka Pukul 08.00 WIB', 'subtitle' => 'Silakan menunggu di area lobi.'],
            ['title' => 'Sesi Istirahat',                'subtitle' => 'Pemindaian akan dilanjutkan kembali setelah istirahat.'],
            ['title' => 'Registrasi Telah Ditutup',      'subtitle' => 'Terima kasih atas kehadiran Anda. Silakan hubungi petugas untuk bantuan.'],
            ['title' => 'Silakan Hubungi Petugas',       'subtitle' => 'Meja registrasi berada di sebelah kanan Anda.'],
        ],

        'result_display_seconds' => 4,   // lama tampil hasil sebelum kembali ke mode scan
        'wayfinding' => true,            // tampilkan rute ke meja (denah dari menu Denah & Wayfinding)
        'map_display_seconds' => 20,     // lama tampil hasil + rute (scan berikutnya langsung menggantikan)
        'seating_display_seconds' => 60, // halaman seating.php: lama data tampil sebelum kembali ke awal
        'error_display_seconds'  => 4,
        'duplicate_cooldown_seconds' => 5, // abaikan scan QR yang sama dalam rentang ini
        'enable_camera' => true,         // izinkan scan via kamera (selain scanner USB)
        // Kamera untuk tablet/iPad: 'user' = kamera depan (menghadap peserta), 'environment' = kamera belakang
        'camera_facing' => 'user',
        // Nyalakan kamera otomatis: false = default pakai scanner QR (kamera manual via tombol Kamera),
        // 'touch' = otomatis hanya di tablet/HP, true = selalu
        'camera_autostart' => false,
    ],

    'log_file' => __DIR__ . '/storage/print-log.csv',
];
