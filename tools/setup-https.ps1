# ==================================================================
#  Setup HTTPS untuk Seat Kiosk (wajib agar PWA, kamera & Bluetooth
#  berfungsi saat dibuka dari iPad / tablet Android lewat jaringan).
#
#  Yang dilakukan:
#   1. Unduh mkcert (alat resmi pembuat sertifikat lokal)
#   2. Buat Certificate Authority (CA) lokal & pasang di Windows
#   3. Buat sertifikat untuk localhost + IP LAN + nama komputer ini
#   4. Pasang sertifikat ke Apache XAMPP (httpd-ssl.conf di-backup dulu)
#   5. Salin sertifikat CA publik ke folder cert/ untuk dipasang di iPad/tablet
#
#  Jalankan: klik kanan file ini > "Run with PowerShell"
#  Setelah selesai: Restart Apache di XAMPP Control Panel.
#  Jika IP PC berubah, jalankan ulang skrip ini.
# ==================================================================
$ErrorActionPreference = 'Stop'

$xampp   = 'C:\xampp'
$appDir  = Split-Path -Parent $PSScriptRoot
$binDir  = Join-Path $PSScriptRoot 'bin'
$mkcert  = Join-Path $binDir 'mkcert.exe'
$sslConf = Join-Path $xampp 'apache\conf\extra\httpd-ssl.conf'
$crtFile = Join-Path $xampp 'apache\conf\ssl.crt\seat-kiosk.crt'
$keyFile = Join-Path $xampp 'apache\conf\ssl.key\seat-kiosk.key'

if (-not (Test-Path $sslConf)) { throw "Tidak menemukan $sslConf. Apakah XAMPP terpasang di $xampp ?" }

# 1. mkcert
if (-not (Test-Path $mkcert)) {
    New-Item -ItemType Directory -Force $binDir | Out-Null
    Write-Host 'Mengunduh mkcert...' -ForegroundColor Cyan
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -UseBasicParsing -OutFile $mkcert `
        'https://github.com/FiloSottile/mkcert/releases/download/v1.4.4/mkcert-v1.4.4-windows-amd64.exe'
}

# 2. CA lokal (Windows akan meminta konfirmasi - klik Yes)
Write-Host 'Memasang CA lokal ke Windows (klik YES jika diminta)...' -ForegroundColor Cyan
& $mkcert -install

# 3. Sertifikat untuk semua alamat PC ini
$ips = Get-NetIPAddress -AddressFamily IPv4 |
       Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
       Select-Object -ExpandProperty IPAddress
$names = @('localhost', '127.0.0.1', $env:COMPUTERNAME, "$($env:COMPUTERNAME).local") + $ips
Write-Host ('Membuat sertifikat untuk: ' + ($names -join ', ')) -ForegroundColor Cyan
& $mkcert -cert-file $crtFile -key-file $keyFile @names

# 4. Pasang ke Apache
$backup = "$sslConf.bak-" + (Get-Date -Format 'yyyyMMdd-HHmmss')
Copy-Item $sslConf $backup
$conf = Get-Content $sslConf -Raw
$conf = $conf -replace '(?m)^(\s*)SSLCertificateFile\s+.*$',    '$1SSLCertificateFile "conf/ssl.crt/seat-kiosk.crt"'
$conf = $conf -replace '(?m)^(\s*)SSLCertificateKeyFile\s+.*$', '$1SSLCertificateKeyFile "conf/ssl.key/seat-kiosk.key"'
[IO.File]::WriteAllText($sslConf, $conf)
Write-Host "httpd-ssl.conf diperbarui (backup: $backup)" -ForegroundColor Green

# 5. CA publik untuk perangkat lain (HANYA sertifikat publik, bukan private key)
$caRoot = (& $mkcert -CAROOT).Trim()
$certDir = Join-Path $appDir 'cert'
New-Item -ItemType Directory -Force $certDir | Out-Null
Copy-Item (Join-Path $caRoot 'rootCA.pem') (Join-Path $certDir 'seat-kiosk-ca.crt') -Force

Write-Host ''
Write-Host '================ SELESAI ================' -ForegroundColor Green
Write-Host '1. Restart Apache di XAMPP Control Panel (Stop lalu Start).'
Write-Host '2. Di iPad/tablet, buka alamat berikut lalu pasang sertifikat CA:'
foreach ($ip in $ips) { Write-Host "     http://$ip/seat-ptbi/cert/seat-kiosk-ca.crt" -ForegroundColor Yellow }
Write-Host '3. Setelah itu buka aplikasi:'
foreach ($ip in $ips) { Write-Host "     https://$ip/seat-ptbi/" -ForegroundColor Yellow }
Write-Host ''
Read-Host 'Tekan Enter untuk menutup'
