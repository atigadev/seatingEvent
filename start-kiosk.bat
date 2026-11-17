@echo off
REM ==================================================================
REM  Jalankan Seat Kiosk dalam mode KIOSK + cetak otomatis tanpa dialog
REM  --kiosk           : layar penuh, tanpa address bar
REM  --kiosk-printing  : window.print() langsung ke PRINTER DEFAULT Windows
REM  Pastikan printer tiket sudah di-set sebagai Default Printer.
REM  Tutup kiosk: Alt+F4
REM ==================================================================

set URL=http://localhost/seat-ptbi/
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

echo Membuka kiosk dengan: %BROWSER%
start "" "%BROWSER%" --kiosk --kiosk-printing --user-data-dir="%PROFILE%" ^
  --no-first-run --disable-session-crashed-bubble --disable-infobars ^
  --disable-features=Translate --autoplay-policy=no-user-gesture-required ^
  --enable-features=WebBluetoothNewPermissionsBackend ^
  --use-fake-ui-for-media-stream "%URL%"
