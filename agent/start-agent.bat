@echo off
REM ==================================================================
REM  Print Agent untuk SATU printer.
REM    start-agent.bat                      -> memakai agent-config.php
REM    start-agent.bat agent-config-2.php   -> printer/stasiun lain
REM  Untuk menjalankan semua printer sekaligus: start-agents.bat
REM  Biarkan jendela ini terbuka selama acara. Berhenti: Ctrl+C
REM ==================================================================
cd /d "%~dp0"

set CONFIG=%~1
if "%CONFIG%"=="" set CONFIG=agent-config.php
title Print Agent - %CONFIG%

set PHP=php
if exist "C:\xampp\php\php.exe" set PHP=C:\xampp\php\php.exe

if not exist "%CONFIG%" (
  echo %CONFIG% belum ada.
  echo Salin agent-config.example.php menjadi %CONFIG% lalu isi server, token, station, dan printer.
  pause
  exit /b 1
)

:loop
"%PHP%" print-agent.php "%CONFIG%"
echo.
echo Agen berhenti. Menjalankan ulang dalam 5 detik... (tutup jendela untuk berhenti)
timeout /t 5 /nobreak >nul
goto loop
