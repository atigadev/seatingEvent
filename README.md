# Seat Kiosk PTBI

Alur: **Scan QR ID Card → ambil data dari API → tampilkan → cetak otomatis tanpa dialog → siap scan berikutnya.**

## Struktur
| File | Fungsi |
|---|---|
| `index.php` | Halaman kiosk (layar scan + template tiket cetak) |
| `assets/app.js` | Logika scan, panggil API, cetak, reset |
| `api/lookup.php` | Endpoint backend: QR → panggil API → normalisasi data → log |
| `config.php` | **Semua pengaturan** (URL API, mapping field, ukuran kertas, dll.) |
| `mock/api.php`, `mock/data.php` | API tiruan + data peserta dummy |
| `mock/qr-test.php` | Halaman QR untuk testing |
| `storage/print-log.csv` | Riwayat scan/cetak (dibuat otomatis) |
| `start-kiosk.bat` | Membuka Chrome/Edge mode kiosk + silent print |

## Testing dengan data mock
1. Jalankan Apache di XAMPP.
2. Buka `http://localhost/seat-ptbi/mock/qr-test.php` di HP/layar lain (atau cetak).
3. Set printer tiket sebagai **Default Printer** di Windows.
4. Jalankan `start-kiosk.bat`, lalu scan QR (scanner USB, atau tombol **Kamera**).
   Bisa juga ketik `PTBI-0001` di kolom bawah lalu Enter.
5. `PTBI-9999` untuk menguji tampilan error.

> Cetak tanpa dialog **hanya** terjadi jika browser dibuka dengan flag `--kiosk-printing`
> (lewat `start-kiosk.bat`). Di browser biasa akan muncul dialog print — itu normal.
> Tutup kiosk dengan `Alt+F4`.

## Sumber data: server registrasi PTBI
Default: `https://registrasi.ptbi.co.id/web/seat/{id}` (Pengaturan › **Sumber Data Peserta**, bisa diganti / dites di sana).
- Halaman itu berupa **HTML berlabel** (bukan JSON): `NAMA` → nama → instansi, lalu `NO MEJA` → nomor meja.
  Dibaca otomatis oleh `parse_html_participant()`; respons JSON tetap didukung (deteksi nama field otomatis).
- QR boleh berisi ID saja (`AA210558`) atau URL lengkap — ID diambil dari ujung URL.
- "Data undangan tidak ditemukan." → layar error. **"Belum ditentukan."** → data tampil, tiket **tidak dicetak**,
  peserta diarahkan ke petugas (log: `BELUM ADA MEJA`).
- Sebutan di layar & tiket: `config.php` → `kiosk.seat_label` (default "Nomor Meja").
- Wayfinding: beri kode meja di editor denah sama dengan nomor meja dari registrasi (mis. `12`), aturan kursi boleh kosong.
- Pilih **Data Uji (Mock)** untuk latihan dengan PTBI-0001…0010.

## Menyambungkan ke API asli
Edit `config.php` bagian `api`:
```php
'url' => 'https://api.contoh.go.id/peserta/{id}',   // {id} = isi QR
'headers' => ['Authorization: Bearer TOKEN', 'Accept: application/json'],
'field_map' => [            // sesuaikan dengan struktur JSON API asli
    'id'       => 'data.id',
    'nama'     => 'data.nama',
    'jabatan'  => 'data.jabatan',
    'instansi' => 'data.instansi',
    'kursi'    => 'data.no_kursi',
],
'success_path' => 'success', // atau null jika API tidak punya penanda sukses
```
Jika QR berisi URL lengkap (bukan ID), set `qr_is_url => true` dan isi `allowed_hosts`.

## Halaman Pengaturan (ikon ⚙ di menu bawah kiosk)
Password awal `1234` — **segera ganti** lewat bagian *Ganti Password* (disimpan ter-hash di `storage/settings.json`).
Lupa password? Hapus baris `admin_pin_hash` di `storage/settings.json` → kembali ke `admin_pin` di `config.php`.

- **Saklar Pemindaian** — Mulai / Hentikan scan. Saat dihentikan, kiosk menampilkan layar standby
  dan semua scan diabaikan (server juga menolak). Semua kiosk ikut berubah dalam ±3 detik.
- **Teks Layar Standby** — pilih kata-kata siap pakai atau tulis sendiri; ada pratinjau.
  Daftar pilihan bisa ditambah di `config.php` → `kiosk.standby_presets`.
- **Printer** — mode cetak (lihat di bawah).
- **Ganti Password**.

Kamera tidak menyala otomatis; default memakai scanner QR. Tombol *Kamera* ada di menu bawah
(`config.php` → `kiosk.camera_autostart` untuk mengubah).

## Halaman Seating Arrangement (`seating.php`)
Halaman scan alternatif dengan desain resmi PTBI 2026 (halaman `index.php` tetap seperti semula).
- Scan QR → **Nomor Meja, Nama, Jabatan, Instansi** tampil di panel kiri; meja di denah ditandai cincin pink
  **berkelap-kelip** + label "MEJA xx".
- Cetak hanya saat tombol **PRINT** ditekan (`api/print.php`, data diambil ulang dari server registrasi),
  mengikuti mode printer di Pengaturan (Kabel USB, Bluetooth, Brother QL, Print Agent).
- Nomor dari registrasi dicocokkan otomatis ke denah: `40`/`040` → 40, `23A`/`09D` → 23/9, `A01`/`A-1` → A1.
- Data kembali ke layar awal setelah 60 dtk (`kiosk.seating_display_seconds`); scan berikutnya langsung menggantikan.
- Buka Pengaturan: **ketuk logo Bank Indonesia 3×**. Mode kiosk: `start-seating.bat`.
- Background: `assets/img/seating-bg.jpg` (data contoh pada gambar asli sudah dihapus).
  Posisi 83 meja: `assets/seating-tables.json` (piksel pada gambar 1920×1200) — perbarui bila denah berubah.
- Uji tampilan tanpa scan: `seating.php?demo=40`.
- **Waktu tampil** (Pengaturan › Waktu Tampil): setelah tiket tercetak (bawaan 10 dtk), data tampil bila belum
  menekan PRINT (60 dtk), hasil/rute/error di Kiosk Scan, dan jeda scan QR yang sama. Berlaku di kiosk dalam ±5 detik.
- **Halaman awal** (Pengaturan › Halaman Awal Kiosk): pilih Kiosk Scan atau Seating Arrangement untuk alamat
  utama (`/` · `index.php`). Kiosk yang terbuka di alamat utama ikut berpindah saat idle (±5 detik).
  Tetap bisa dibuka langsung: `index.php?page=scan` (Kiosk Scan) dan `seating.php`.
- **Video idle** (Pengaturan › Video Idle): video MP4/WebM tampil memenuhi frame denah setelah N detik tanpa scan,
  diputar bergantian (1 video → diulang). Saat ada scan/sentuhan: disembunyikan & dijeda; saat idle lagi:
  dilanjutkan dari posisi terakhir. Bisa diaktifkan/dimatikan, diurutkan, dihapus, dengan/tanpa suara;
  perubahan berlaku di layar dalam ±5 detik. File di `uploads/videos/` (tidak di-commit, tidak di-cache PWA).
  - **YouTube**: tempel tautan (watch, youtu.be, shorts, embed, live) → disimpan sebagai `yt:ID` dan bisa
    dicampur dengan file unggahan dalam satu daftar putar. Diputar via YouTube IFrame API (youtube-nocookie),
    tanpa kontrol, dipotong agar judul YouTube & bilah hitam tidak terlihat. Butuh internet di kiosk;
    video yang tidak boleh di-embed / dihapus / offline dilewati otomatis.

## Kiosk di Hosting (`start-kiosk-hosting.bat`)

PC kiosk di venue **tidak perlu XAMPP** — cukup Chrome + file `start-kiosk-hosting.bat`:
1. Unduh dari **Pengaturan › Unduh Skrip Kiosk** (`kiosk-download.php`, perlu login) — alamat situs &
   stasiun cetak sudah terisi otomatis. Atau salin `start-kiosk-hosting.bat` lalu ubah `URL` / `STATION` manual.
2. Jalankan → Chrome terbuka layar penuh ke hosting, cetak tanpa dialog. Tutup: **Alt+F4**.
3. Agar menyala otomatis saat Windows hidup: jalankan `start-kiosk-hosting.bat /pasang`.
4. Halaman yang tampil (Kiosk Scan / Seating) diatur dari Pengaturan › Halaman Awal Kiosk.
5. Header/footer bawaan browser (tanggal, judul, URL, nomor halaman) dimatikan otomatis oleh skrip kiosk
   lewat kebijakan `PrintHeaderFooter=0` (HKCU, Chrome & Edge). Efek samping: Chrome menampilkan
   "Dikelola oleh organisasi Anda". Hapus: `reg delete "HKCU\Software\Policies\Google\Chrome" /v PrintHeaderFooter /f`.

Mode printer saat aplikasi di hosting:
| Mode | Di hosting | Catatan |
|---|---|---|
| Kabel USB | ✅ | Browser kiosk yang mencetak ke Default Printer PC kiosk |
| Bluetooth (Chrome) | ✅ | Browser kiosk tersambung langsung ke printer BLE |
| Print Agent | ✅ | Brother/thermal di jaringan venue; jalankan `agent/start-agent.bat` di PC venue |
| Bluetooth COM / Brother langsung | ❌ | Server hosting tidak bisa menjangkau printer di venue |

Tablet: Android — buka di Chrome › Tambahkan ke layar utama, lalu **Sematkan aplikasi** (Screen pinning);
iPad — Safari › Tambah ke Layar Utama, lalu **Akses Terpandu** (Guided Access). Cetak dari tablet: Print Agent.

## Aplikasi Petugas HP (`m/`)

PWA terpisah (scope `m/`, desain sendiri, terang/gelap) untuk petugas di lapangan:
- Scan QR ID Card dengan kamera HP (default kamera belakang, ganti kamera, senter bila didukung),
  cadangan **Foto QR** dan ketik ID manual. Kamera wajib **HTTPS**.
- Hasil: nomor meja besar (2 digit), nama, jabatan, instansi, ID, status cetak tiket (dari log kiosk),
  dan **lokasi meja** di potongan denah (`m/denah.php`, dibuat otomatis dari `assets/img/seating-bg.jpg`,
  bisa diperbesar). Riwayat 20 scan terakhir tersimpan di HP.
- Data dari `api/m-lookup.php`: **baca saja** — tidak mencetak, tidak menulis log, tidak terpengaruh saklar scan.
- PWA: banner pasang (Android/iOS), banner versi baru + tombol Update, tetap terbuka saat offline.
  Naikkan `M_VERSION` (m/index.php) & `VERSION` (m/sw.js) saat aset diubah.
- Tautan & QR untuk membuka di HP: Pengaturan › Aplikasi Petugas.

## Denah & Wayfinding (logika Satumap)
Pengaturan › **Buka Editor Denah** (`admin-denah.php`). Setelah scan, kiosk menampilkan nomor kursi
**beserta rute terdekat** dari kiosk ke meja peserta + petunjuk langkah ("Jalan ± 7 m, lalu belok kiri dekat B").

1. **Unggah denah** (JPG/PNG/PDF; PDF diubah jadi gambar di browser, maks 4000 px).
2. **📏 Skala** — klik dua ujung benda yang ukurannya diketahui → isi meter.
3. **〰 Lorong** — klik titik-titik di tengah jalur jalan (lurus otomatis; Alt = bebas; Esc = selesai).
   Pilih lorong untuk menjadikannya satu arah. Titik lorong bisa diberi nama patokan (Pintu Utama, Panggung).
4. **▭ Meja** — seret persegi; isi *kode*, *nama*, dan **aturan nomor kursi**:
   `A-*` (semua berawalan A-), `B-01..B-12` (rentang), `C-01, C-05` (daftar). Kosong = awalan kode meja.
   Titik merah = titik masuk dari lorong (otomatis, bisa digeser). "Tambah banyak" untuk input massal.
5. **📍 Kiosk** — titik "Anda di sini" + arah hadap. Tiap perangkat memilih lokasinya lewat menu bawah kiosk
   (atau `index.php?kiosk=kiosk-2`).
6. **Uji Rute** dengan nomor kursi → **Publikasikan ke Kiosk** (versi lama dicadangkan; menu *Riwayat* untuk memulihkan).

- Rute dihitung `assets/router.js` — **disalin apa adanya dari Satumap** (Dijkstra di jaringan lorong,
  titik ditempel ke lorong terdekat, belok kiri/kanan, patokan ±4 m, zig-zag < 2,5 m dianggap lurus).
- Format data sama dengan `satumap/data/map.json` → bisa **Impor JSON (Satumap)** lalu unggah gambarnya.
- Data: `storage/venue/` (draft, tayang, riwayat) · gambar: `uploads/venue/`.
- Lama tampil hasil + rute: `config.php` → `kiosk.map_display_seconds` (default 20 dtk; scan berikutnya langsung menggantikan).
- **Saklar Wayfinding** (Pengaturan › Denah & Wayfinding): tampilkan / sembunyikan peta rute di semua kiosk (±3 detik,
  tanpa muat ulang). Saat disembunyikan, kiosk hanya menampilkan nomor kursi seperti semula.

## Pilihan printer: Kabel USB / Bluetooth
Klik ikon ⚙ di pojok kanan bawah kiosk → **Pengaturan** (PIN default `1234`, ubah di `config.php` → `admin_pin`).

| Mode | Cara kerja | Syarat |
|---|---|---|
| **Kabel USB** | Browser mencetak senyap (`window.print` + `--kiosk-printing`) | Driver printer terpasang & jadi Default Printer, kiosk dibuka via `start-kiosk.bat` |
| **Bluetooth dari Chrome** | Pair & cetak langsung dari browser (Web Bluetooth), data ESC/POS disusun PHP | Printer mendukung **BLE**; Chrome/Edge via `localhost`/https. Tidak perlu pairing Windows |
| **Bluetooth via COM** | PHP mengirim ESC/POS langsung ke port COM printer | Untuk printer Bluetooth Classic; pair di Windows, pilih port COM *Outgoing* |

### Bluetooth dari Chrome
1. Pengaturan → pilih **Bluetooth dari Chrome** → Simpan.
2. Di layar kiosk klik **Hubungkan Printer** (atau chip "Printer BT" di bawah) → pilih printer di dialog Chrome.
3. Status hijau = terhubung. Jika printer mati-nyala, kiosk menyambung ulang otomatis.
4. Setelah kiosk di-restart, `start-kiosk.bat` (flag `WebBluetoothNewPermissionsBackend`) mencoba menyambung otomatis
   ke printer terakhir; bila belum tersambung, cukup klik **Hubungkan Printer** sekali.
5. Jika cetak gagal, layar tetap menampilkan nomor kursi + tombol **Hubungkan Printer & Cetak Ulang**.
6. Printer tidak muncul di dialog → kemungkinan hanya Bluetooth Classic → pakai mode **Bluetooth via COM**.

- Tombol **Simpan & Test Cetak** mencetak tiket contoh untuk memastikan printer jalan.
- Mode Bluetooth: jika printer mati/terputus, layar kiosk tetap menampilkan nomor kursi + pesan "Gagal cetak", dan tercatat `GAGAL CETAK` di log.
- Pengaturan disimpan di `storage/settings.json`.
- Mode Bluetooth mengharuskan server PHP (XAMPP) berjalan **di PC yang sama** dengan printer.

## PWA — dipakai di iPad / tablet
Aplikasi bisa di-*install* ke layar utama tablet dan berjalan layar penuh seperti aplikasi.

### 1. Aktifkan HTTPS (wajib, sekali saja)
PWA, kamera, dan Bluetooth hanya jalan lewat HTTPS bila dibuka dari perangkat lain.
1. Klik kanan `tools\setup-https.ps1` → **Run with PowerShell** → klik *Yes* saat Windows minta konfirmasi CA.
2. Restart Apache di XAMPP Control Panel.
3. Jika IP PC server berubah, jalankan ulang skrip ini.

### 2. Pasang sertifikat di tablet (sekali saja)
- **iPad/iPhone:** buka di Safari `http://<IP-PC>/seat-ptbi/cert/seat-kiosk-ca.crt` → *Allow* →
  Settings › General › VPN & Device Management → install profil →
  Settings › General › About › **Certificate Trust Settings** → aktifkan sertifikat "mkcert".
- **Android:** unduh file yang sama → Settings › Security › Encryption & credentials › Install a certificate › **CA certificate**.

### 3. Install aplikasi
- **iPad (Safari):** buka `https://<IP-PC>/seat-ptbi/` → tombol Share → **Add to Home Screen**.
- **Android (Chrome):** buka alamat yang sama → menu ⋮ → **Install app**.
- Kunci tablet di aplikasi: iPad → *Guided Access* (Settings › Accessibility); Android → *Screen pinning*.

### Fitur di tablet
- Kamera menyala otomatis (`config.php` → `kiosk.camera_facing`: `user` = kamera depan menghadap peserta, `environment` = belakang).
- Kartu yang ditahan di depan kamera tidak tercetak berulang.
- Scanner QR Bluetooth (mode keyboard) yang di-pair ke tablet juga bisa dipakai.
- Layar tidak sleep (Wake Lock), keyboard layar tidak muncul, menu bawah muncul dengan mengetuk bagian bawah layar.

### Cara cetak dari tablet
| Perangkat | Mode yang disarankan |
|---|---|
| **iPad / iPhone** | **Cetak via Server**: printer Bluetooth di-pair ke PC server (COM), atau printer LAN/WiFi `IP:9100`. Safari tidak mendukung Web Bluetooth & cetak senyap. |
| **Tablet Android** | **Bluetooth dari Chrome** (printer BLE terhubung langsung ke tablet), atau Cetak via Server. |

> Mode printer berlaku untuk semua perangkat yang memakai server ini.
> Service worker men-cache aset: setelah mengubah CSS/JS, naikkan `VERSION` di `sw.js`.

## Desain Tiket (`admin-tiket.php`)
Pengaturan › Printer › **Desain Tiket →**. Satu desain untuk semua printer — tiket digambar sesuai ukuran kertas lalu
gambar yang sama dikirim ke Kabel USB (browser), thermal ESC/POS (raster), Brother QL, dan Print Agent.
- **Ukuran kertas**: Thermal 80 mm, Thermal 58 mm, Brother DK-22205 62 mm, atau ukuran lain (lebar area cetak + dpi);
  tinggi otomatis mengikuti isi atau tetap (mm); margin atas/bawah.
- **Isi tiket** (urutan bebas): data peserta (nomor meja, nama, jabatan, instansi, ID, acara, tanggal), teks bebas,
  **nomor antrian**, baris kiri–kanan, garis, jarak. Tiap elemen: ukuran huruf (pt), tebal, rata, huruf besar,
  jarak huruf, maks. baris, perkecil agar 1 baris, awalan.
- **Pratinjau langsung** dalam ukuran asli; hasil cetak identik dengan pratinjau.
- **Nomor antrian**: bertambah 1 setiap tiket dicetak (hanya bila elemennya ditampilkan); bisa diatur ulang.
  Disimpan di `storage/counter.json`.
- Bluetooth dari Chrome (BLE) tetap memakai tiket teks (gambar terlalu besar untuk BLE).

## Print Agent — aplikasi di hosting, printer di venue
Hosting di internet tidak bisa menjangkau printer di jaringan lokal venue (mis. `172.16.16.192`).
Mode **Print Agent (Hosting)** memecahkannya tanpa membuka printer ke internet:

```
kiosk/iPad/Android ──HTTPS──▶ hosting (antrean storage/queue/)
                                   ▲  HTTPS keluar, long-poll ±15 dtk
                          PC di venue: agent/print-agent.php ──LAN 9100 / COM──▶ printer
```

1. Hosting: Pengaturan › Printer › **Print Agent (Hosting)** → pilih jenis printer (Brother QL / thermal ESC/POS)
   → **Buat token** → Simpan.
2. PC venue (satu Wi-Fi dengan printer, butuh PHP — XAMPP cukup): salin aplikasi, salin
   `agent/agent-config.example.php` → `agent/agent-config.php`, isi `server`, `token`, `printer`.
3. Jalankan `agent\start-agent.bat` (biarkan terbuka; otomatis restart bila berhenti).
   Status agen (ONLINE/OFFLINE) tampil di Pengaturan → **Simpan & Test Cetak**.

- Kiosk menampilkan "Mengirim ke printer… → Silakan ambil tiket", atau pesan gagal bila agen/printer bermasalah.

### Stasiun cetak — banyak tablet & banyak printer
- Satu agen = satu printer = satu **stasiun** (`'station' => 'pintu-utara'` di `agent-config*.php`).
  Satu PC bisa melayani beberapa printer: `agent-config.php`, `agent-config-2.php`, … lalu `agent\start-agents.bat`.
- Setiap tablet/kiosk memilih stasiun di **menu bawah layar** (disimpan di perangkat) atau lewat `?station=pintu-utara`.
  Tiket dari tablet itu hanya keluar di printer stasiunnya.
- **Pengalihan** (Pengaturan › Print Agent, default aktif): bila agen stasiun offline, tiket dicetak di stasiun lain
  yang online (antrean paling sedikit) dan layar menampilkan "Silakan ambil tiket di printer stasiun X".
- Tabel status semua agen (ONLINE/OFFLINE, printer, antrean) dan *Test Cetak* per stasiun ada di Pengaturan.
- Tablet/agen tanpa stasiun tetap bekerja seperti sebelumnya (agen tanpa stasiun mencetak semua tiket).
- Job yang tidak diambil agen dalam 90 dtk (`print.agent.job_ttl`) **dibatalkan** — tidak tercetak belakangan.
- Data cetak dihapus dari server segera setelah tercetak; riwayat job dibersihkan otomatis.
- `agent/agent-config.php` berisi token → tidak di-commit (.gitignore). Ganti token kapan saja dari Pengaturan.

## Brother QL-820NWB (label DK-22205 62 mm)
Pengaturan › Printer › **Brother QL (Label)**. Printer ini tidak memakai ESC/POS: server menggambar tiket
(GD, 300 dpi, lebar 696 titik) lalu mengirim **Brother Raster Command** (`lib/brother.php`).
- **Wi-Fi (disarankan):** sambungkan printer ke Wi-Fi yang sama dengan PC server, isi `IP-printer:9100`.
- **Bluetooth:** pair di Windows → pilih port COM *Outgoing*. Bluetooth printer ini Classic (bukan BLE),
  jadi mode "Bluetooth dari Chrome" tidak bisa dipakai untuk printer ini.
- Lampu **Editor Lite harus mati**. Potong otomatis setiap tiket (bisa dimatikan).
- Pratinjau label tampil di panel Pengaturan (`api/brother-preview.php`); tombol *Simpan & Test Cetak* mencetak contoh.
- Font: Arial Windows (atur `print.brother.font_regular/font_bold` di `config.php` bila perlu).

## Printer thermal 80mm
- `config.php` → `print.paper_size = '80mm 80mm'` (lebar × panjang tiket), `printable_width = '72mm'`.
- Install driver printer (mis. Epson TM-T82 / POS-80 generik), jadikan **Default Printer**.
- Di *Printing Preferences* driver: pilih kertas **Roll Paper 80mm**, aktifkan **auto cut** (potong setelah dokumen),
  dan bila ada opsi "Paper Reduction / Blank area reduction" aktifkan agar sisa kosong tidak ikut terpotong.
- Uji tampilan tiket tanpa scanner: buka `http://localhost/seat-ptbi/?scan=PTBI-0001`.

## Catatan
- Kode ditulis untuk PHP 8 (juga kompatibel PHP 7.4). Butuh ekstensi `curl`.
- Library JS (`html5-qrcode`, `qrcodejs`) sudah disimpan lokal di `assets/vendor` → tidak butuh internet.
- Scan QR yang sama dalam 5 detik diabaikan (mencegah double-print). Scan ulang setelahnya tetap dicetak dan ditandai "cetak ulang".
