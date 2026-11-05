@echo off
rem Windows 에 기본 내장된 .NET Framework 4 컴파일러로 Updater.exe 를 빌드합니다. (별도 설치 불필요)
chcp 65001 >nul
set CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe
if not exist "%CSC%" set CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe
if not exist "%CSC%" (
  echo csc.exe 를 찾을 수 없습니다. .NET Framework 4.x 가 필요합니다.
  pause
  exit /b 1
)
"%CSC%" /nologo /codepage:65001 /target:winexe /optimize+ /out:Updater.exe ^
  /win32icon:app.ico /resource:logo-default.png,AutoUpdater.logo.png ^
  /r:System.Windows.Forms.dll /r:System.Drawing.dll /r:System.Web.Extensions.dll ^
  Updater.cs
if errorlevel 1 ( echo 빌드 실패 & pause & exit /b 1 )
echo.
echo 빌드 완료: Updater.exe
pause
