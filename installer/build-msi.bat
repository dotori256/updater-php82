@echo off
rem 솔루션별 MSI 빌드. 예) build-msi.bat MWAGENT   /  build-msi.bat -All  /  build-msi.bat -InstallWix
rem 자세한 설명: docs\MSI_BUILD.md
chcp 65001 >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build-msi.ps1" %*
set RC=%ERRORLEVEL%
if "%NOPAUSE%"=="" pause
exit /b %RC%
