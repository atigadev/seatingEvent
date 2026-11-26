@echo off
REM ==================================================================
REM  KIOSK ONLINE - membuka aplikasi di HOSTING dalam mode kiosk.
REM  PC kiosk TIDAK perlu XAMPP: cukup salin file ini ke PC kiosk lalu jalankan.
REM
REM  --kiosk           : layar penuh, tanpa address bar (tutup: Alt+F4)
REM  --kiosk-printing  : cetak langsung ke PRINTER DEFAULT Windows tanpa dialog
REM                      (mode printer "Kabel USB" di Pengaturan)
REM
REM  Halaman yang tampil (Kiosk Scan / Seating) diatur dari
REM  Pengaturan > Halaman Awal Kiosk - tidak perlu mengubah file ini.
REM  Nyalakan otomatis saat Windows hidup: jalankan file ini dengan  /pasang
REM ==================================================================

REM --- alamat aplikasi di hosting ---
set URL=https://seat.saturegis.com/
REM Stasiun cetak (mode Print Agent), kosongkan bila tidak dipakai. Contoh: set STATION=pintu-utara
set STATION=

if defined STATION set URL=%URL%?station=%STATION%
set PROFILE=%LOCALAPPDATA%\SeatKioskProfile

REM --- pasang ke Startup Windows ---
if /i "%~1"=="/pasang" (
  copy /y "%~f0" "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\Seat Kiosk.bat" >nul
  echo Kiosk akan terbuka otomatis setiap Windows menyala.
  echo Batalkan: hapus "Seat Kiosk.bat" dari folder shell:startup
  pause
  exit /b 0
)

set BROWSER=
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe
if not defined BROWSER if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set BROWSER=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe
if not defined BROWSER if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" set BROWSER=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe
if not defined BROWSER if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" set BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe
if not defined BROWSER if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" set BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe
if not defined BROWSER (
  echo Chrome / Edge tidak ditemukan. Pasang Google Chrome terlebih dahulu.
  pause
  exit /b 1
)

REM --- tunggu internet (maks. 60 detik) agar kiosk tidak terbuka di halaman error saat baru menyala ---
echo Menunggu koneksi ke %URL% ...
for /l %%i in (1,1,20) do (
  curl -s -o nul --max-time 3 "%URL%" >nul 2>&1 && goto online
  timeout /t 2 /nobreak >nul
)
echo Internet belum tersedia, kiosk tetap dibuka (akan memakai cache bila ada).
:online

echo Membuka kiosk: %URL%
start "" "%BROWSER%" --kiosk --kiosk-printing --user-data-dir="%PROFILE%" ^
  --no-first-run --disable-session-crashed-bubble --disable-infobars --noerrdialogs ^
  --disable-features=Translate,TouchpadOverscrollHistoryNavigation --overscroll-history-navigation=0 ^
  --autoplay-policy=no-user-gesture-required ^
  --enable-features=WebBluetoothNewPermissionsBackend ^
  --use-fake-ui-for-media-stream "%URL%"
