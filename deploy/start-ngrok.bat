@echo off
REM =====================================================================
REM  FacePass - put the local XAMPP site online with ngrok
REM  Double-click this file. Keep the window open while people use the
REM  system; closing it takes the site offline.
REM
REM  One-time setup (free):
REM    1. Create an account at https://dashboard.ngrok.com/signup
REM    2. Copy your authtoken from https://dashboard.ngrok.com/get-started/your-authtoken
REM    3. Run:  %USERPROFILE%\ngrok\ngrok.exe config add-authtoken YOUR_TOKEN
REM
REM  Optional - same link every time: claim your free static domain at
REM  https://dashboard.ngrok.com/domains and put it on the next line,
REM  e.g.  set "NGROK_DOMAIN=your-name.ngrok-free.app"
REM =====================================================================
title FacePass - Online (ngrok)

set "NGROK_DOMAIN="

set "NGROK=%USERPROFILE%\ngrok\ngrok.exe"
if not exist "%NGROK%" (
    echo ngrok.exe was not found at %NGROK%
    echo Download it from https://ngrok.com/download and unzip it into %USERPROFILE%\ngrok
    pause
    exit /b 1
)

echo.
echo  1. Make sure Apache and MySQL are running in the XAMPP Control Panel.
echo  2. The public link is shown next to "Forwarding" (https://....ngrok-free.app).
echo  3. Open it on any phone or computer - the camera works because it is HTTPS.
echo     First-time visitors see an ngrok notice page: click "Visit Site".
echo.
echo  Press Ctrl+C or close this window to go offline.
echo.

if "%NGROK_DOMAIN%"=="" (
    "%NGROK%" http 80
) else (
    "%NGROK%" http --url=%NGROK_DOMAIN% 80
)
pause
