@echo off
REM ==================================================================
REM  Jalankan Print Agent untuk SEMUA printer sekaligus:
REM  setiap file agent-config*.php (kecuali contoh) dibuka di jendelanya sendiri.
REM    agent-config.php     -> printer 1 / stasiun 1
REM    agent-config-2.php   -> printer 2 / stasiun 2   dst.
REM ==================================================================
cd /d "%~dp0"
set FOUND=0
for %%F in (agent-config*.php) do (
  if /I not "%%F"=="agent-config.example.php" (
    set FOUND=1
    echo Menjalankan agen: %%F
    start "Print Agent - %%F" cmd /c start-agent.bat "%%F"
  )
)
if "%FOUND%"=="0" (
  echo Belum ada agent-config*.php. Salin agent-config.example.php lalu isi.
  pause
)
