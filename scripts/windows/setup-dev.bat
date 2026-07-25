@echo off
setlocal
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-dev.ps1" %*
if errorlevel 1 (
  echo.
  echo Setup failed. See messages above.
  exit /b 1
)
endlocal
