@echo off
setlocal EnableExtensions
title Dump tree snapshot
cd /d "%~dp0..\.."
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0dump-tree-snapshot.ps1" %*
echo.
pause
