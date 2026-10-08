@echo off
REM =====================================================================
REM  FacePass - put the local XAMPP site online with a Cloudflare Tunnel
REM  Double-click this file. Keep the window open while people use the
REM  system; closing it takes the site offline.
REM =====================================================================
title FacePass - Online (Cloudflare Tunnel)

set "CF=%USERPROFILE%\cloudflared\cloudflared.exe"
if not exist "%CF%" (
    echo cloudflared.exe was not found at %CF%
    echo Download it from:
    echo   https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe
    echo and save it as %CF%
    pause
    exit /b 1
)

echo.
echo  1. Make sure Apache and MySQL are running in the XAMPP Control Panel.
echo  2. Wait for a line like:  https://something-random.trycloudflare.com
echo  3. Open that link on any phone or computer (camera works - it is HTTPS).
echo.
echo  The link changes every time this window is restarted.
echo  Press Ctrl+C or close this window to go offline.
echo.

"%CF%" tunnel --no-autoupdate --url http://localhost:80
pause
