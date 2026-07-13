# BAT Scripts

Folder ini berisi script Windows untuk server lokal.

## Install Sekali Klik

Jalankan:

```bat
bat\install_project.bat
```

Script ini akan mengecek:

- `php`
- `composer`
- versi PHP minimal 8.2
- extension PHP `curl`, `mbstring`, `openssl`, `pdo`, `pdo_sqlsrv`, `sqlsrv`
- file `.env`
- dependency Composer
- koneksi SQL Server `sqlsrv_local` bila `.env` sudah bukan placeholder

Log error tersimpan di:

```text
storage\logs\install_project.log
```

## Task Scheduler

Untuk Windows Task Scheduler, gunakan:

```text
Program/script:
C:\path\to\project\bat\run_sync_global.bat
```

Log sinkronisasi tersimpan di:

```text
storage\logs\sync_global.log
```
