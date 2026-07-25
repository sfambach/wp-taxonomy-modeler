@echo off
setlocal EnableExtensions
title wp-taxonomy-tree - Laragon stoppen, Ordner freigeben

set "SOURCE_ROOT=C:\devel\wordpress\source"
set "REPO_DIR=%SOURCE_ROOT%\wp-taxonomy-tree"
set "PLUGIN_LINK=C:\devel\wordpress\wp-content\plugins\wp-taxonomy-tree"
set "LARAGON_WWW=C:\laragon\www\devel"
set "REPO_URL=https://github.com/sfambach/wp-taxonomy-tree.git"

echo.
echo ============================================================
echo   Ordner freigeben (Laragon / Junctions) und Repo neu holen
echo ============================================================
echo.

echo [1/5] Laragon beenden ...
taskkill /IM laragon.exe /F >nul 2>&1
taskkill /IM httpd.exe /F >nul 2>&1
taskkill /IM nginx.exe /F >nul 2>&1
taskkill /IM mysqld.exe /F >nul 2>&1
taskkill /IM php-cgi.exe /F >nul 2>&1
timeout /t 3 /nobreak >nul
echo       Fertig. Falls Laragon noch laeuft: Rechtsklick Tray-Icon -^> Exit.

echo.
echo [2/5] Plugin-Junction entfernen (sperrt oft den source-Ordner) ...
if exist "%PLUGIN_LINK%" (
  rmdir "%PLUGIN_LINK%" 2>nul
  if exist "%PLUGIN_LINK%" (
    echo       FEHLER: %PLUGIN_LINK% noch da. Explorer/Cursor schliessen.
    goto :blocked
  )
  echo       Entfernt: %PLUGIN_LINK%
) else (
  echo       Nicht vorhanden - ok.
)

echo.
echo [3/5] Laragon-www-Junction entfernen ...
if exist "%LARAGON_WWW%" (
  rmdir "%LARAGON_WWW%" 2>nul
  if exist "%LARAGON_WWW%" (
    echo       FEHLER: %LARAGON_WWW% noch da.
    goto :blocked
  )
  echo       Entfernt: %LARAGON_WWW%
) else (
  echo       Nicht vorhanden - ok.
)

echo.
echo [4/5] Altes Repo entfernen ...
if not exist "%REPO_DIR%" goto :clone
echo       WARNUNG: %REPO_DIR% wird geloescht.
set /p CONFIRM=Fortfahren? (J/N):
if /i not "%CONFIRM%"=="J" (
  echo Abgebrochen.
  pause
  exit /b 0
)
rmdir /s /q "%REPO_DIR%" 2>nul
if exist "%REPO_DIR%" (
  echo       FEHLER: Ordner noch gesperrt.
  goto :blocked
)
echo       Entfernt.

:clone
echo.
echo [5/5] Frisch klonen ...
if not exist "%SOURCE_ROOT%" mkdir "%SOURCE_ROOT%"
git clone --branch main %REPO_URL% "%REPO_DIR%"
if errorlevel 1 goto :fail

echo.
echo [OK] Repo bereit: %REPO_DIR%
echo.
echo Laragon wieder starten, dann:
echo   %REPO_DIR%\scripts\windows\setup-dev.bat
echo.
pause
exit /b 0

:blocked
echo.
echo ============================================================
echo   Ordner noch gesperrt - bitte manuell:
echo   1. Laragon komplett beenden (Tray -^> Exit, nicht nur Stop)
echo   2. Cursor/VS Code schliessen (Repo geoeffnet?)
echo   3. Explorer-Fenster unter source\wp-taxonomy-tree schliessen
echo   4. Dieses Skript erneut starten
echo.
echo Wer blockiert? In PowerShell (Admin):
echo   openfiles /query /fo table ^| findstr /i taxonomy
echo ============================================================
pause
exit /b 1

:fail
echo [FEHLER] git clone fehlgeschlagen.
pause
exit /b 1
