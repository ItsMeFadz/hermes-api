<?php

use App\Services\LunasKreditSyncService;
use App\Services\TagihanKreditSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function ()
{
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Artisan::command(
    'sync:global',
    function (LunasKreditSyncService $lunasService, TagihanKreditSyncService $tagihanService)
    {
        $hasError = false;
        $this->info('========================================');
        $this->info('      SINKRONISASI GLOBAL DIMULAI');
        $this->info('========================================');

        $kodeljk = trim(
            (string) config('services.sync.kodeljk')
        );

        $sort = (string) config(
            'services.sync.sort',
            'a.tglkondisi'
        );


        /*
        |--------------------------------------------------------------------------
        | 1. SYNC LUNAS KREDIT
        |--------------------------------------------------------------------------
        */

        $this->newLine();
        $this->info('=== 1. LUNAS KREDIT ===');

        try
        {
            $period = $lunasService->latestPeriod($kodeljk);

            if (!$period)
            {
                $this->info(
                    'Tidak ada data lunas kredit kodekondisi 02.'
                );
            }
            else
            {
                $bln = $period['bln'];
                $thn = $period['thn'];

                $this->info(
                    "Sinkronisasi lunas kredit {$bln}/{$thn} dimulai..."
                );

                $total = $lunasService->countRows(
                    $bln,
                    $thn,
                    $kodeljk
                );

                $this->info(
                    "Data lunas ditemukan: {$total}"
                );

                $result = $lunasService->send(
                    $bln,
                    $thn,
                    $kodeljk,
                    $sort
                );

                if ($result['skipped'] ?? false)
                {
                    $this->info(
                        'Tidak ada data lunas kredit untuk dikirim.'
                    );
                }
                else
                {
                    $this->info(
                        'Lunas kredit berhasil dikirim: '
                        . ($result['sent'] ?? 0)
                        . ' data.'
                    );
                }
            }
        }
        catch (\Throwable $exception)
        {
            $hasError = true;

            $this->error(
                'Sinkronisasi lunas kredit gagal: '
                . $exception->getMessage()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 2. SYNC TAGIHAN KREDIT
        |--------------------------------------------------------------------------
        */

        $this->newLine();
        $this->info('=== 2. TAGIHAN KREDIT ===');

        try
        {
            $bln = now()->month;
            $thn = now()->year;

            $tgl1 = sprintf(
                '%04d-%02d-01',
                $thn,
                $bln
            );

            $tgl2 = now()
                ->setDate($thn, $bln, 1)
                ->endOfMonth()
                ->format('Y-m-d');

            $sandicabang = '000';

            $this->info(
                "Mengambil tagihan kredit {$bln}/{$thn}..."
            );

            $items = $tagihanService
                ->getTagihanKreditFromSqlServer(
                    $tgl1,
                    $tgl2,
                    $kodeljk,
                    $sandicabang
                );

            $total = count($items);

            $this->info(
                "Data tagihan ditemukan: {$total}"
            );

            if ($total === 0)
            {
                $this->info(
                    'Tidak ada data tagihan kredit untuk dikirim.'
                );
            }
            else
            {
                $result = $tagihanService->send($items);

                $this->info(
                    'Tagihan kredit berhasil dikirim: '
                    . ($result['sent'] ?? 0)
                    . ' data.'
                );
            }
        }
        catch (\Throwable $exception)
        {
            $hasError = true;

            $this->error(
                'Sinkronisasi tagihan kredit gagal: '
                . $exception->getMessage()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SELESAI
        |--------------------------------------------------------------------------
        */

        $this->newLine();

        $this->newLine();

        if ($hasError)
        {
            $this->error('========================================');
            $this->error('      SINKRONISASI GLOBAL GAGAL');
            $this->error('========================================');

            return 1;
        }

        $this->info('========================================');
        $this->info('      SINKRONISASI GLOBAL SELESAI');
        $this->info('========================================');

        return 0;

    }
)->purpose(
        'Menjalankan seluruh sinkronisasi data lokal ke API hosting.'
    );