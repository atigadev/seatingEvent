<?php
/**
 * Konfigurasi Print Agent — SATU file per printer.
 * Salin file ini menjadi agent-config.php (printer 1), agent-config-2.php (printer 2), dst.
 * Jalankan semua sekaligus dengan start-agents.bat (satu jendela per printer).
 * Token: Pengaturan › Printer › Print Agent (Hosting) › Buat token (sama untuk semua agen).
 */
return [
    // Alamat aplikasi di hosting (tanpa garis miring di akhir)
    'server'  => 'https://seat.saturegis.com',

    // Token rahasia dari halaman Pengaturan
    'token'   => 'ISI-TOKEN-DARI-PENGATURAN',

    // Stasiun cetak yang dilayani printer ini (huruf kecil, angka, tanda minus), mis. 'pintu-utara'.
    // Tablet memilih stasiun yang sama di menu bawah layar kiosk → tiketnya keluar di printer ini.
    // Kosongkan ('') bila hanya ada satu printer: agen mencetak semua tiket.
    'station' => 'stasiun-1',

    // Printer di venue:
    //   IP printer Wi-Fi/LAN  → '172.16.16.192'  (port 9100 otomatis) atau '172.16.16.192:9100'
    //   Bluetooth (Windows)   → 'COM7'           (port COM "Outgoing")
    'printer' => '172.16.16.192',

    // Nama agen yang tampil di Pengaturan (opsional)
    'name'    => 'PC Venue',

    // Biarkan true. Ubah ke false hanya bila PHP di PC venue tidak punya sertifikat CA.
    'verify_ssl' => true,
];
