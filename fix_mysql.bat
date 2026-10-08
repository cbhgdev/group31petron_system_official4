@echo off
REM ==========================================================
REM  Fix: XAMPP MySQL "shutdown unexpectedly" (Aria log error)
REM  1. Stops mysqld
REM  2. Backs up C:\xampp\mysql\data
REM  3. Deletes corrupted Aria log files
REM ==========================================================
set DATA=C:\xampp\mysql\data
set BACKUP=C:\xampp\mysql\data_backup_%RANDOM%

echo Stopping MySQL (if running)...
taskkill /F /IM mysqld.exe >nul 2>&1
timeout /t 2 >nul

echo Backing up %DATA% to %BACKUP% ...
robocopy "%DATA%" "%BACKUP%" /E /NFL /NDL /NJH /NJS >nul
if not exist "%BACKUP%\ibdata1" (
    echo [ERROR] Backup failed. Right-click this file and choose "Run as administrator".
    pause
    exit /b 1
)
echo Backup OK.

echo Deleting corrupted Aria log files...
del /F /Q "%DATA%\aria_log_control" >nul 2>&1
del /F /Q "%DATA%\aria_log.0*" >nul 2>&1

echo.
echo ==========================================================
echo  DONE. Now click START on MySQL in the XAMPP Control Panel.
echo  Backup saved in: %BACKUP%
echo ==========================================================
pause
