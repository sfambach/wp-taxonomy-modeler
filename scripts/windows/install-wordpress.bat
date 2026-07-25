@echo off
setlocal EnableExtensions
title wp-taxonomy-tree — WordPress Install

set "SCRIPT_DIR=%~dp0"
set "REPO_DIR=%SCRIPT_DIR%..\.."
cd /d "%REPO_DIR%"

echo.
echo ============================================================
echo   WordPress installieren (Laragon / C:\devel\wordpress)
echo   (Bitte diese .bat Datei starten, NICHT die .ps1 per Doppelklick)
echo ============================================================
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%install-wordpress.ps1" %*
set "ERR=%ERRORLEVEL%"

echo.
if not "%ERR%"=="0" (
  echo [FEHLER] WordPress-Installation fehlgeschlagen. Exit-Code: %ERR%
) else (
  echo [OK] WordPress bereit: http://devel.test/wp-admin  (admin / admin123)
)
echo.
pause
exit /b %ERR%
