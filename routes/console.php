<?php

use App\Services\LunasKreditSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sync:global', function (LunasKreditSyncService $syncService) {
    $bln = now()->month;
    $thn = now()->year;
    $kodeljk = trim((string) config('services.sync.kodeljk'));
    $sort = (string) config('services.sync.sort', 'a.tglkondisi');

    $this->info("Sinkronisasi lunas kredit {$bln}/{$thn} dimulai...");
    $this->info('Filter kodeljk: ' . ($kodeljk !== '' ? $kodeljk : 'semua'));

    try
    {
        $total = $syncService->countRows($bln, $thn, $kodeljk);
        $this->info("Data ditemukan: {$total}");

        $result = $syncService->send($bln, $thn, $kodeljk, $sort);
    }
    catch (Throwable $exception)
    {
        $this->error('Sinkronisasi gagal: ' . $exception->getMessage());

        return 1;
    }

    if ($result['skipped'])
    {
        $this->info('Tidak ada data lunas kredit untuk dikirim. Request ke VPS dilewati.');

        return 0;
    }

    $this->info("Sinkronisasi selesai. Data terkirim: {$result['sent']}");

    return 0;
})->purpose('Menjalankan sinkronisasi data lokal ke API hosting.');

Artisan::command('sync:debug', function () {
    $bln = now()->month;
    $thn = now()->year;
    $kodeljk = trim((string) config('services.sync.kodeljk'));

    $this->info('Debug koneksi dan filter sinkronisasi');
    $this->line('DB_CONNECTION: ' . config('database.default'));
    $this->line('DB_DATABASE: ' . config('database.connections.' . config('database.default') . '.database'));
    $this->line('SYNC_KODELJK: ' . ($kodeljk !== '' ? $kodeljk : 'kosong'));
    $this->line("Periode: {$bln}/{$thn}");

    $database = DB::selectOne('SELECT DB_NAME() AS db_name');
    $server = DB::selectOne('SELECT @@SERVERNAME AS server_name');

    $this->line('SQL Server DB aktif: ' . ($database->db_name ?? '-'));
    $this->line('SQL Server name: ' . ($server->server_name ?? '-'));

    $counts = [
        'all_crdmaster' => [
            'sql' => 'SELECT COUNT(*) AS total FROM crdmaster',
            'params' => [],
        ],
        'periode_saja' => [
            'sql' => 'SELECT COUNT(*) AS total FROM crdmaster a WHERE DATEPART(m, a.tglkondisi) = ? AND DATEPART(yyyy, a.tglkondisi) = ?',
            'params' => [$bln, $thn],
        ],
        'periode_kondisi_02' => [
            'sql' => "SELECT COUNT(*) AS total FROM crdmaster a WHERE a.kodekondisi = '02' AND DATEPART(m, a.tglkondisi) = ? AND DATEPART(yyyy, a.tglkondisi) = ?",
            'params' => [$bln, $thn],
        ],
        'final_filter' => [
            'sql' => "SELECT COUNT(*) AS total FROM crdmaster a WHERE (? = '' OR a.kodeljk = ?) AND a.kodekondisi = '02' AND DATEPART(m, a.tglkondisi) = ? AND DATEPART(yyyy, a.tglkondisi) = ?",
            'params' => [$kodeljk, $kodeljk, $bln, $thn],
        ],
    ];

    foreach ($counts as $label => $query) {
        $result = DB::selectOne($query['sql'], $query['params']);
        $this->line($label . ': ' . (int) ($result->total ?? 0));
    }

    $sample = DB::select(
        "SELECT TOP 5 a.kodeljk, a.sandicabang, a.norekcrd, a.kodekondisi, a.bakidebet, CONVERT(VARCHAR(10), a.tglkondisi, 23) AS tglkondisi
        FROM crdmaster a
        WHERE a.kodekondisi = '02'
        ORDER BY a.tglkondisi DESC"
    );

    $this->line('Sample kodekondisi 02:');
    foreach ($sample as $row) {
        $this->line(json_encode($row));
    }

    return 0;
})->purpose('Melihat diagnosa koneksi dan jumlah data lunas kredit.');
