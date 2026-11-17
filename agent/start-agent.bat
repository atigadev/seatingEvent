@echo off
REM ==================================================================
REM  Print Agent — ambil tiket dari server hosting & cetak ke printer venue
REM  Biarkan jendela ini terbuka selama acara. Berhenti: Ctrl+C
REM ==================================================================
title Seat Kiosk - Print Agent
cd /d "%~dp0"

set PHP=php
if exist "C:\xampp\php\php.exe" set PHP=C:\xampp\php\php.exe

if not exist "agent-config.php" (
  echo agent-config.php belum ada.
  echo Salin agent-config.example.php menjadi agent-config.php lalu isi server, token, dan printer.
  pause
  exit /b 1
)

:loop
"%PHP%" print-agent.php agent-config.php
echo.
echo Agen berhenti. Menjalankan ulang dalam 5 detik... (tutup jendela untuk berhenti)
timeout /t 5 /nobreak >nul
goto loop
