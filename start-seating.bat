@echo off
REM ==================================================================
REM  Jalankan halaman SEATING ARRANGEMENT dalam mode KIOSK + cetak otomatis tanpa dialog
REM  --kiosk           : layar penuh, tanpa address bar
REM  --kiosk-printing  : window.print() langsung ke PRINTER DEFAULT Windows
REM  Pastikan printer tiket sudah di-set sebagai Default Printer.
REM  Tutup kiosk: Alt+F4
REM ==================================================================

set URL=http://localhost/seat-ptbi/seating.php
set PROFILE=%LOCALAPPDATA%\SeatKioskProfile

set BROWSER=
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe
if not defined BROWSER if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set BROWSER=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe
if not defined BROWSER if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" set BROWSER=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe
if not defined BROWSER if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" set BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe
if not defined BROWSER if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" set BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe

if not defined BROWSER (
  echo Chrome / Edge tidak ditemukan.
  pause
  exit /b 1
)

REM --- matikan header/footer bawaan browser saat cetak (tanggal, judul, URL, nomor halaman) ---
REM  1) pengaturan cetak tersimpan di profil kiosk (tanpa hak admin) - kode PowerShell di akhir file ini
REM  2) kebijakan PrintHeaderFooter=0: HKLM bila dijalankan sebagai Administrator, HKCU bila diizinkan
set SEAT_PROFILE=%PROFILE%
set SEAT_BAT=%~f0
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=[IO.File]::ReadAllText($env:SEAT_BAT); $m='#PS'+'-BEGIN'; iex $s.Substring($s.LastIndexOf($m))"
reg add "HKLM\SOFTWARE\Policies\Google\Chrome" /v PrintHeaderFooter /t REG_DWORD /d 0 /f >nul 2>&1
reg add "HKLM\SOFTWARE\Policies\Microsoft\Edge" /v PrintHeaderFooter /t REG_DWORD /d 0 /f >nul 2>&1
reg add "HKCU\Software\Policies\Google\Chrome" /v PrintHeaderFooter /t REG_DWORD /d 0 /f >nul 2>&1
reg add "HKCU\Software\Policies\Microsoft\Edge" /v PrintHeaderFooter /t REG_DWORD /d 0 /f >nul 2>&1

echo Membuka kiosk dengan: %BROWSER%
start "" "%BROWSER%" --kiosk --kiosk-printing --user-data-dir="%PROFILE%" ^
  --no-first-run --disable-session-crashed-bubble --disable-infobars ^
  --disable-features=Translate --autoplay-policy=no-user-gesture-required ^
  --enable-features=WebBluetoothNewPermissionsBackend ^
  --use-fake-ui-for-media-stream "%URL%"
exit /b 0
#PS-BEGIN
# --- PowerShell (dijalankan oleh baris di atas): atur pengaturan cetak tersimpan di profil kiosk ---
# Chrome --kiosk-printing mencetak memakai pengaturan print terakhir di profil:
# header/footer MATI, margin Default (ikut CSS @page margin 0), skala 100%, tujuan = Default Printer Windows.
$prof = $env:SEAT_PROFILE
if (-not $prof) { return }
$dir = Join-Path $prof 'Default'
New-Item -ItemType Directory -Force -Path $dir | Out-Null
$file = Join-Path $dir 'Preferences'
$p = $null
if (Test-Path $file) {
  try { $p = [IO.File]::ReadAllText($file) | ConvertFrom-Json } catch { Write-Host 'Preferences profil kiosk tidak terbaca, dilewati.'; return }
}
if (-not $p) { $p = New-Object PSObject }
function Get-Child($o, $n) {
  if (-not ($o.PSObject.Properties.Name -contains $n)) { $o | Add-Member -NotePropertyName $n -NotePropertyValue (New-Object PSObject) }
  return $o.$n
}
$sticky = Get-Child (Get-Child $p 'printing') 'print_preview_sticky_settings'
$app = $null
if ($sticky.PSObject.Properties.Name -contains 'appState') { try { $app = $sticky.appState | ConvertFrom-Json } catch {} }
if (-not $app) { $app = New-Object PSObject }
$set = @{ version = 2; isHeaderFooterEnabled = $false; marginsType = 0; isCssBackgroundEnabled = $true; scalingType = 0; scaling = '100' }
foreach ($k in $set.Keys) { $app | Add-Member -Force -NotePropertyName $k -NotePropertyValue $set[$k] }
if ($app.PSObject.Properties.Name -contains 'recentDestinations') { $app.PSObject.Properties.Remove('recentDestinations') }
$sticky | Add-Member -Force -NotePropertyName appState -NotePropertyValue ($app | ConvertTo-Json -Compress -Depth 20)
[IO.File]::WriteAllText($file, ($p | ConvertTo-Json -Compress -Depth 100), (New-Object Text.UTF8Encoding $false))
Write-Host 'Pengaturan cetak kiosk: header/footer mati, margin default, printer default.'
