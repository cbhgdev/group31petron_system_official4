@echo off
title Petron Station Management - Node.js Real-time Service
color 0b
echo =====================================================================
echo  STARTING PETRON STATION REAL-TIME NODE.JS SERVICE
echo =====================================================================
echo.
set PATH=C:\xampp\nodejs;%PATH%
cd /d "%~dp0nodejs_service"
echo [1/2] Checking Node.js runtime...
node -v
echo [2/2] Launching server.js on port 3000...
echo.
node server.js
pause
