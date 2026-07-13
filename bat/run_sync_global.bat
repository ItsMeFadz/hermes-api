@echo off
setlocal EnableExtensions

set "PROJECT_DIR=%~dp0.."
set "LOG_DIR=%PROJECT_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\sync_global.log"

cd /d "%PROJECT_DIR%" || goto :project_error

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1

echo ================================================== >> "%LOG_FILE%"
echo sync:global started: %DATE% %TIME% >> "%LOG_FILE%"
echo ================================================== >> "%LOG_FILE%"

php artisan sync:global >> "%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"

echo Finished: %DATE% %TIME% with exit code %EXIT_CODE% >> "%LOG_FILE%"
echo. >> "%LOG_FILE%"

exit /b %EXIT_CODE%

:project_error
echo [ERROR] Gagal masuk ke folder project.
exit /b 1
