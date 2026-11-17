<?php
/**
 * Konfigurasi Print Agent.
 * Salin file ini menjadi agent-config.php (di folder yang sama), lalu isi.
 * Token: Pengaturan › Printer › Print Agent (Hosting) › Buat token.
 */
return [
    // Alamat aplikasi di hosting (tanpa garis miring di akhir)
    'server'  => 'https://seat.saturegis.com',

    // Token rahasia dari halaman Pengaturan
    'token'   => 'ISI-TOKEN-DARI-PENGATURAN',

    // Printer di venue:
    //   IP printer Wi-Fi/LAN  → '172.16.16.192'  (port 9100 otomatis) atau '172.16.16.192:9100'
    //   Bluetooth (Windows)   → 'COM7'           (port COM "Outgoing")
    'printer' => '172.16.16.192',

    // Nama agen yang tampil di Pengaturan (opsional)
    'name'    => 'PC Venue',

    // Biarkan true. Ubah ke false hanya bila PHP di PC venue tidak punya sertifikat CA.
    'verify_ssl' => true,
];
