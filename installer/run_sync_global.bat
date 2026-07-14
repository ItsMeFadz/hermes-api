@echo off
setlocal EnableExtensions

set "PROJECT_DIR=%~dp0.."

cd /d "%PROJECT_DIR%" || goto :project_error

php artisan sync:global
exit /b %ERRORLEVEL%

:project_error
echo [ERROR] Gagal masuk ke folder project.
exit /b 1
