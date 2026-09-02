<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TagihanKreditSyncService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Ambil data tagihan kredit dari SQL Server.
     */
    public function getTagihanKreditFromSqlServer(
        string $tgl1,
        string $tgl2,
        string $kodeljk,
        string $sandicabang = '000'
    ): array {
        $column = '*';
        $filter = '';
        $sort = ' ';

        $rows = DB::connection('sqlsrv')->select(
            'EXEC report_tagihankredit ?, ?, ?, ?, ?, ?, ?',
            [
                $tgl1,
                $tgl2,
                $kodeljk,
                $sandicabang,
                '*',
                '',
                ' ',
            ]
        );

        return array_map(function ($row)
        {
            return [
                'norekcrd' => $row->norekcrd ?? null,

                'namalengkap' => $row->namalengkap ?? null,
                'alamatktp' => $row->alamatktp ?? null,
                'alamatdomisili' => $row->alamatdomisili ?? null,
                'notelp' => $row->notelp ?? null,
                'nohp' => $row->nohp ?? null,

                'noakad' => $row->noakad ?? null,
                'bakidebet' => $row->bakidebet ?? null,

                'tgltempo' => $row->tgltempo ?? null,
                'tglefektif' => $row->tglefektip ?? null,
                'graceperiod' => $row->graceperiod ?? null,

                'statusrek' => $row->statusrek ?? null,

                'tagpokok' => $row->tagpokok ?? null,
                'tagbunga' => $row->tagbunga ?? null,
                'tagdenda' => $row->tagdenda ?? null,
                'totalangsuran' => $row->totalangsuran ?? null,
                'haritunggakkan' => $row->haritunggakkan ?? null,

                'norekpembayaran' => $row->norekpembayaran ?? null,
                'saldotab' => $row->saldotab ?? null,
                'saldotabactual' => $row->saldotabactual ?? null,

                'kodeao' => $row->kodeao ?? null,
                'ao' => $row->ao ?? null,

                'ketinstansi' => $row->ketinstansi ?? null,
            ];
        }, $rows);
    }

    /**
     * Kirim data tagihan kredit ke Athena.
     */
    public function send(array $items): array
    {
        if (empty($items))
        {
            return [
                'success' => true,
                'sent' => 0,
                'message' => 'Tidak ada data tagihan kredit untuk dikirim.',
            ];
        }

        $targetUrl = $this->syncEndpoint('sync/tagihan-kredit/receive');
        $apiKey = $this->syncKey();

        if ($apiKey === '')
        {
            throw new \RuntimeException(
                'SYNC_API_KEY belum tersedia di .env'
            );
        }

        $response = Http::timeout(60)
            ->retry(2, 1000)
            ->withHeaders([
                'X-Sync-Key' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($targetUrl, [
                'items' => $items,
            ]);

        $response->throw();

        return [
            'success' => true,
            'sent' => count($items),
            'response' => $response->json(),
        ];
    }

    public function syncKey(): string
    {
        return (string) config('services.sync.api_key');
    }

    public function syncEndpoint(string $path): ?string
    {
        $baseUrl = config('services.sync.api_url');

        if (!$baseUrl)
        {
            return null;
        }

        return rtrim((string) $baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
