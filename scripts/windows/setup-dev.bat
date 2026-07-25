@echo off
setlocal EnableExtensions
title wp-taxonomy-tree - Dev Setup

rem Run from repo parent so git can update scripts\windows (not locked by cwd)
set "SCRIPT_DIR=%~dp0"
set "REPO_DIR=%SCRIPT_DIR%..\.."
cd /d "%REPO_DIR%"

echo.
echo ============================================================
echo   wp-taxonomy-tree - Laragon Dev Setup
echo   (Bitte diese .bat Datei starten, NICHT die .ps1 per Doppelklick)
echo ============================================================
echo   Repo: %REPO_DIR%
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%setup-dev.ps1" %*
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
