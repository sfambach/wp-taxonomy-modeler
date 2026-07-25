@echo off
setlocal EnableExtensions
title wp-taxonomy-tree — Repo wiederherstellen

set "SOURCE_ROOT=C:\devel\wordpress\source"
set "REPO_DIR=%SOURCE_ROOT%\wp-taxonomy-tree"
set "REPO_URL=https://github.com/sfambach/wp-taxonomy-tree.git"

echo.
echo ============================================================
echo   Repo von GitHub holen / reparieren
echo   NUR starten wenn du bewusst updaten willst.
echo ============================================================
echo.

if not exist "%SOURCE_ROOT%" mkdir "%SOURCE_ROOT%"

if exist "%REPO_DIR%\.git" goto :repair
if not exist "%REPO_DIR%" goto :clone

echo.
echo WARNUNG: %REPO_DIR% existiert ohne .git
echo.
set /p CONFIRM=Ordner ersetzen und neu klonen? (J/N):
if /i not "%CONFIRM%"=="J" (
  echo Abgebrochen. Nichts geaendert.
  pause
  exit /b 1
)
echo Entferne kaputten Ordner...
rmdir /s /q "%REPO_DIR%"
goto :clone

:clone
echo Klone %REPO_URL%
git clone --branch main %REPO_URL% "%REPO_DIR%"
if errorlevel 1 goto :fail
goto :ok

:repair
echo.
echo Bestehendes Repo wird auf origin/main zurueckgesetzt.
echo Lokale Aenderungen gehen verloren.
set /p CONFIRM=Fortfahren? (J/N):
if /i not "%CONFIRM%"=="J" (
  echo Abgebrochen.
  pause
  exit /b 0
)
cd /d "%SOURCE_ROOT%"
git -C wp-taxonomy-tree fetch origin
git -C wp-taxonomy-tree checkout main
git -C wp-taxonomy-tree reset --hard origin/main
if errorlevel 1 goto :fail

:ok
echo.
echo [OK] Repo bereit:
dir "%REPO_DIR%\scripts\windows"
echo.
echo Naechster Schritt: setup-dev.bat
echo   %REPO_DIR%\scripts\windows\setup-dev.bat
echo.
pause
exit /b 0

:fail
echo [FEHLER] Siehe Meldung oben.
pause
exit /b 1
