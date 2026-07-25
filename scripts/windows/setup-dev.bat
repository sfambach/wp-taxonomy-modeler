@echo off
setlocal EnableExtensions
title wp-taxonomy-tree — Dev Setup
cd /d "%~dp0"

echo.
echo ============================================================
echo   wp-taxonomy-tree — Laragon Dev Setup
echo   (Bitte diese .bat Datei starten, NICHT die .ps1 per Doppelklick)
echo ============================================================
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-dev.ps1" %*
set "ERR=%ERRORLEVEL%"

echo.
if not "%ERR%"=="0" (
  echo [FEHLER] Setup fehlgeschlagen. Exit-Code: %ERR%
) else (
  echo [OK] Setup abgeschlossen.
)
echo.
pause
exit /b %ERR%
