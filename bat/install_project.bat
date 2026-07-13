@echo off
setlocal EnableExtensions

set "PROJECT_DIR=%~dp0.."
set "LOG_DIR=%PROJECT_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\install_project.log"

cd /d "%PROJECT_DIR%" || goto :project_error

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1

echo ================================================== > "%LOG_FILE%"
echo Hermes API Local Installer >> "%LOG_FILE%"
echo Started: %DATE% %TIME% >> "%LOG_FILE%"
echo Project: %PROJECT_DIR% >> "%LOG_FILE%"
echo ================================================== >> "%LOG_FILE%"

echo.
echo Hermes API Local Installer
echo Project: %PROJECT_DIR%
echo Log: %LOG_FILE%
echo.

call :check_command php "PHP belum terinstall atau belum masuk PATH. Install PHP/XAMPP, lalu pastikan php.exe bisa dipanggil dari CMD."
if errorlevel 1 goto :fail

call :check_command composer "Composer belum terinstall atau belum masuk PATH. Install Composer for Windows terlebih dahulu."
if errorlevel 1 goto :fail

php -r "exit(version_compare(PHP_VERSION, '8.2.0', '>=') ? 0 : 1);" >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo [ERROR] PHP minimal versi 8.2. Versi saat ini:
    php -v
    echo [ERROR] PHP minimal versi 8.2. >> "%LOG_FILE%"
    goto :fail
)

call :check_php_ext curl
if errorlevel 1 goto :fail
call :check_php_ext mbstring
if errorlevel 1 goto :fail
call :check_php_ext openssl
if errorlevel 1 goto :fail
call :check_php_ext pdo
if errorlevel 1 goto :fail
call :check_php_ext pdo_sqlsrv
if errorlevel 1 goto :fail
call :check_php_ext sqlsrv
if errorlevel 1 goto :fail

if not exist ".env" (
    if not exist ".env.example" (
        echo [ERROR] File .env.example tidak ditemukan.
        echo [ERROR] File .env.example tidak ditemukan. >> "%LOG_FILE%"
        goto :fail
    )

    copy ".env.example" ".env" >> "%LOG_FILE%" 2>&1
    if errorlevel 1 (
        echo [ERROR] Gagal membuat .env dari .env.example.
        goto :fail
    )

    echo [OK] .env dibuat dari .env.example
) else (
    echo [OK] .env sudah ada, tidak ditimpa.
)

echo.
echo Menginstall dependency Composer...
composer install --no-dev --optimize-autoloader >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo [ERROR] composer install gagal. Lihat log: %LOG_FILE%
    goto :fail
)
echo [OK] Composer dependency berhasil.

findstr /R /C:"^APP_KEY=$" ".env" >nul 2>&1
if not errorlevel 1 (
    echo Generate APP_KEY...
    php artisan key:generate --force >> "%LOG_FILE%" 2>&1
    if errorlevel 1 (
        echo [ERROR] Gagal generate APP_KEY. Lihat log: %LOG_FILE%
        goto :fail
    )
    echo [OK] APP_KEY dibuat.
) else (
    echo [OK] APP_KEY sudah ada.
)

if not exist "storage\framework\cache" mkdir "storage\framework\cache" >nul 2>&1
if not exist "storage\framework\sessions" mkdir "storage\framework\sessions" >nul 2>&1
if not exist "storage\framework\views" mkdir "storage\framework\views" >nul 2>&1
if not exist "bootstrap\cache" mkdir "bootstrap\cache" >nul 2>&1

php artisan optimize:clear >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo [ERROR] optimize:clear gagal. Lihat log: %LOG_FILE%
    goto :fail
)

findstr /C:"LOCAL_SQLSRV_DATABASE=local_database" ".env" >nul 2>&1
if not errorlevel 1 (
    echo [WARNING] LOCAL_SQLSRV_DATABASE masih local_database. Isi dulu .env sesuai database SQL Server asli.
    echo [WARNING] Test koneksi SQL Server dilewati karena database masih placeholder. >> "%LOG_FILE%"
) else (
    echo.
    echo Mengetes koneksi SQL Server sqlsrv_local...
    php artisan db:show --database=sqlsrv_local >> "%LOG_FILE%" 2>&1
    if errorlevel 1 (
        echo [ERROR] Koneksi SQL Server gagal. Cek LOCAL_SQLSRV_* di .env, SQL Server service, port 1433, user/password, dan extension pdo_sqlsrv/sqlsrv.
        echo Detail ada di log: %LOG_FILE%
        goto :fail
    )
    echo [OK] Koneksi SQL Server berhasil.
)

echo.
echo ==================================================
echo INSTALL SELESAI
echo ==================================================
echo Isi .env yang penting:
echo - LOCAL_SQLSRV_* untuk koneksi SQL Server lokal
echo - SYNC_API_KEY harus sama dengan key di hosting
echo - SYNC_API_URL contoh: https://domain-vps.com/api
echo - SYNC_KODELJK isi bila perlu filter kodeljk
echo.
echo Jalankan sinkronisasi:
echo php artisan sync:global
echo.
pause
exit /b 0

:check_command
where %~1 >nul 2>&1
if errorlevel 1 (
    echo [ERROR] %~2
    echo [ERROR] %~2 >> "%LOG_FILE%"
    exit /b 1
)
echo [OK] %~1 ditemukan.
exit /b 0

:check_php_ext
php -m | findstr /I /X "%~1" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Extension PHP %~1 belum aktif.
    echo [ERROR] Aktifkan extension %~1 di php.ini. Untuk SQL Server gunakan DLL yang cocok dengan versi PHP, Thread Safe/ZTS, x64.
    echo [ERROR] Extension PHP %~1 belum aktif. >> "%LOG_FILE%"
    exit /b 1
)
echo [OK] Extension PHP %~1 aktif.
exit /b 0

:project_error
echo [ERROR] Gagal masuk ke folder project.
pause
exit /b 1

:fail
echo.
echo ==================================================
echo INSTALL GAGAL
echo ==================================================
echo Lihat detail error di:
echo %LOG_FILE%
echo.
pause
exit /b 1
