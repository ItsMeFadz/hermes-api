<?php

use App\Services\LunasKreditSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sync:global', function (LunasKreditSyncService $syncService) {
    $bln = now()->month;
    $thn = now()->year;
    $kodeljk = (string) config('services.sync.kodeljk');
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
