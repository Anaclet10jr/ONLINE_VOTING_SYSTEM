@echo off
setlocal

echo =====================================================
 echo XAMPP MySQL permission repair for SOVMS
 echo Run this from an Administrator Command Prompt.
 echo =====================================================

takeown /f "C:\xampp\mysql\data\ibdata1" /a >nul 2>&1
if errorlevel 1 echo WARNING: Could not take ownership of ibdata1. Run as Administrator.

icacls "C:\xampp\mysql\data" /grant "%USERNAME%:(OI)(CI)F" /T >nul 2>&1
icacls "C:\xampp\mysql\data\ibdata1" /grant "%USERNAME%:F" >nul 2>&1
icacls "C:\xampp\mysql\data\ib_logfile0" /grant "%USERNAME%:F" >nul 2>&1
icacls "C:\xampp\mysql\data\ib_logfile1" /grant "%USERNAME%:F" >nul 2>&1
icacls "C:\xampp\mysql\data\ibtmp1" /grant "%USERNAME%:F" >nul 2>&1

if exist "C:\xampp\mysql\data\ib_logfile0" (
  del /f /q "C:\xampp\mysql\data\ib_logfile0" >nul 2>&1
)
if exist "C:\xampp\mysql\data\ib_logfile1" (
  del /f /q "C:\xampp\mysql\data\ib_logfile1" >nul 2>&1
)
if exist "C:\xampp\mysql\data\ibtmp1" (
  del /f /q "C:\xampp\mysql\data\ibtmp1" >nul 2>&1
)

if exist "C:\xampp\apache\bin\httpd.exe" (
  echo Starting Apache...
  "C:\xampp\apache_start.bat"
)

if exist "C:\xampp\mysql_start.bat" (
  echo Starting MySQL...
  "C:\xampp\mysql_start.bat"
)

echo.
echo Finished. Open http://localhost/VOTING_SYSTEM/ in the browser.
exit /b 0
