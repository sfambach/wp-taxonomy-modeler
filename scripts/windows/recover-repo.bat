@echo off
setlocal EnableExtensions
title wp-taxonomy-tree — Repo wiederherstellen

set "SOURCE_ROOT=C:\devel\wordpress\source"
set "REPO_DIR=%SOURCE_ROOT%\wp-taxonomy-tree"
set "REPO_URL=https://github.com/sfambach/wp-taxonomy-tree.git"

echo.
echo ============================================================
echo   Repo wiederherstellen von GitHub (main)
echo   Nichts geht verloren — alles liegt im Remote-Repo.
echo ============================================================
echo.

if not exist "%SOURCE_ROOT%" (
  mkdir "%SOURCE_ROOT%"
  echo Created %SOURCE_ROOT%
)

if not exist "%REPO_DIR%\.git" (
  echo Klone frisches Repo nach %REPO_DIR%
  if exist "%REPO_DIR%" (
    echo Alter Ordner ohne .git wird entfernt...
    rmdir /s /q "%REPO_DIR%" 2>nul
  )
  git clone --branch main %REPO_URL% "%REPO_DIR%"
  if errorlevel 1 goto :fail
  goto :ok
)

echo Repariere bestehendes Repo in %REPO_DIR%
cd /d "%SOURCE_ROOT%"
git -C wp-taxonomy-tree fetch origin
git -C wp-taxonomy-tree checkout main
git -C wp-taxonomy-tree reset --hard origin/main
if errorlevel 1 goto :fail

:ok
echo.
echo [OK] Repo wiederhergestellt.
dir "%REPO_DIR%\scripts\windows"
echo.
echo Naechster Schritt: Doppelklick auf
echo   %REPO_DIR%\scripts\windows\setup-dev.bat
echo.
pause
exit /b 0

:fail
echo.
echo [FEHLER] Wiederherstellung fehlgeschlagen.
echo Pruefe Internet und git. Manuell:
echo   mkdir %SOURCE_ROOT%
echo   git clone %REPO_URL% %REPO_DIR%
echo.
pause
exit /b 1
