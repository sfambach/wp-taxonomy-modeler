@echo off
setlocal
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-wordpress.ps1" %*
if errorlevel 1 (
  echo.
  echo WordPress install failed. See messages above.
  exit /b 1
)
endlocal
