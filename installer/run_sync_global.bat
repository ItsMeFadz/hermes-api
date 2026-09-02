@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "PROJECT_DIR=%~dp0.."
set "LOG_DIR=%PROJECT_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\sync_global.log"

title Hermes - Sinkronisasi Database

echo.
echo ==========================================================
echo        HERMES - SINKRONISASI DATABASE
echo ==========================================================
echo.
echo  Sedang melakukan sinkronisasi database dengan VPS...
echo.
echo  Jangan tutup jendela ini sampai proses selesai.
echo.
echo  Waktu mulai : %DATE% %TIME%
echo ==========================================================
echo.

cd /d "%PROJECT_DIR%" || goto :project_error

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1

echo ================================================== >> "%LOG_FILE%"
echo sync:global started: %DATE% %TIME% >> "%LOG_FILE%"
echo ================================================== >> "%LOG_FILE%"

php artisan sync:global >> "%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"

echo Finished: %DATE% %TIME% with exit code %EXIT_CODE% >> "%LOG_FILE%"
echo. >> "%LOG_FILE%"

echo.

if "%EXIT_CODE%"=="0" (

    echo ==========================================================
    echo        SINKRONISASI DATABASE BERHASIL
    echo ==========================================================
    echo.
    echo  Waktu selesai : %DATE% %TIME%
    echo  Semua data berhasil disinkronkan ke VPS.
    echo.
    echo  Detail proses dapat dilihat di:
    echo  %LOG_FILE%
    echo.
    echo  Jendela ini akan ditutup dalam:

    for /L %%i in (15,-1,1) do (
    cls
    echo.
    echo ==========================================================
    echo        HERMES - SINKRONISASI DATABASE
    echo ==========================================================
    echo.
    echo        SINKRONISASI DATABASE BERHASIL
    echo.
    echo        Semua data berhasil disinkronkan ke VPS.
    echo.
    echo        Jendela akan tertutup otomatis dalam:
    echo.
    echo                  %%i DETIK
    echo.
    echo ==========================================================
    timeout /t 1 /nobreak >nul
)

    exit /b 0

) else (

    echo ==========================================================
    echo        SINKRONISASI DATABASE GAGAL
    echo ==========================================================
    echo.
    echo  Waktu gagal : %DATE% %TIME%
    echo  Exit code   : %EXIT_CODE%
    echo.
    echo  Silakan hubungi bagian IT.
    echo.
    echo  Detail error tersimpan di:
    echo  %LOG_FILE%
    echo.
    echo ==========================================================
    echo  JENDELA INI TIDAK AKAN DITUTUP OTOMATIS.
    echo  Tekan tombol apa saja untuk menutup.
    echo ==========================================================
    echo.

    pause >nul

    exit /b %EXIT_CODE%
)

:project_error

echo.
echo ==========================================================
echo        ERROR - PROJECT TIDAK DITEMUKAN
echo ==========================================================
echo.
echo  Gagal masuk ke folder:
echo  %PROJECT_DIR%
echo.
echo  Silakan hubungi bagian IT.
echo.
echo  JENDELA INI TIDAK AKAN DITUTUP OTOMATIS.
echo.
echo ==========================================================

pause >nul

exit /b 1