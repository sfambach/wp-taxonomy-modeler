@echo off
setlocal EnableExtensions
title Install test tree
cd /d "%~dp0..\.."
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0seed-test-tree.ps1" %*
echo.
pause
