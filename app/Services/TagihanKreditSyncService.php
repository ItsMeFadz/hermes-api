<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TagihanKreditSyncService
{
    /**
     * Ambil data tagihan kredit langsung dari SQL Server.
     *
     * Tidak menggunakan report_tagihankredit.
     */
    public function getTagihanKreditFromSqlServer(
        string $tgl1,
        string $tgl2,
        string $kodeljk,
        string $sandicabang = '000'
    ): array {
        $kodeljk = trim($kodeljk);
        $sandicabang = trim($sandicabang);

        $sql = <<<SQL
SELECT
    a.norekcrd,
    RTRIM(d.namalengkap) AS namalengkap,
    d.alamat AS alamatktp,
    d.alamatdomisili,
    d.notelp,
    d.nohp,

    a.noakad,
    a.bakidebet,

    DAY(b.tglangsuran) AS tgltempo,
    DAY(a.tglefektif) AS tglefektif,
    a.graceperiod,

    h.datatext1 AS statusrek,

    b.tagpokok,
    b.tagbunga,
    b.tagdenda,
    b.totalangsuran,

    a.haritunggakkan,

    a.norekpembayaran,

    ISNULL(
        CASE
            WHEN c.saldoakhir - c.saldoblokir - e.minsaldo < 0
                THEN 0
            ELSE c.saldoakhir - c.saldoblokir - e.minsaldo
        END,
        0
    ) AS saldotab,

    ISNULL(c.saldoakhir, 0) AS saldotabactual,

    a.kodeao AS kodeao,
    f.ket AS ao,

    g.ket AS ketinstansi

FROM crdmaster a

JOIN rps b
    ON a.kodeljk = b.kodeljk
    AND a.sandicabang = b.sandicabang
    AND a.norekcrd = b.norekcrd

LEFT JOIN tabmaster c
    ON a.kodeljk = c.kodeljk
    AND a.sandicabang = c.sandicabang
    AND a.norekpembayaran = c.norekening

LEFT JOIN tabungan_setup e
    ON c.kodeproduktab = e.kodeproduk

JOIN cif d
    ON a.cif = d.cif

LEFT JOIN refintern_ao f
    ON a.kodeljk = f.kodeljk
    AND a.sandicabang = f.sandicabang
    AND a.kodeao = f.kode

LEFT JOIN refintern_instansi g
    ON a.kodeljk = g.kodeljk
    AND a.sandicabang = g.sandicabang
    AND a.kodeinstansi = g.kode

LEFT JOIN reff_umum h
    ON a.kodeljk = h.kodeljk
    AND a.stsrekcrd = h.datavalue1
    AND h.kode1 = 'stsrekcrd'

WHERE
    a.kodeljk = ?
    AND a.kodekondisi = '00'
    AND a.stsrekcrd NOT IN (0, 2, 3, 4)
    AND a.stsbar = 1
    AND a.oto = 1

    AND b.tglangsuran BETWEEN ? AND ?

    AND (
        b.tagpokok - b.byrpokok > 0
        OR b.tagbunga - b.byrbunga > 0
        OR b.tagdenda - b.byrdenda > 0
    )
SQL;

        $params = [
            $kodeljk,
            $tgl1,
            $tgl2,
        ];

        /*
         * Kalau sandicabang bukan 000,
         * tambahkan filter cabang.
         */
        if ($sandicabang !== '000')
        {
            $sql = str_replace(
                'WHERE
    a.kodeljk = ?',
                'WHERE
    a.kodeljk = ?
    AND a.sandicabang = ?',
                $sql
            );

            $params = [
                $kodeljk,
                $sandicabang,
                $tgl1,
                $tgl2,
            ];
        }

        $rows = DB::connection('sqlsrv')->select(
            $sql,
            $params
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
                'tglefektif' => $row->tglefektif ?? null,
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
                'sent' => 0,
                'remote' => null,
                'skipped' => true,
            ];
        }

        $endpoint = $this->syncEndpoint(
            'sync/tagihan-kredit/receive'
        );

        if (!$endpoint)
        {
            throw new \RuntimeException(
                'Target URL belum diset. Isi SYNC_API_URL di .env.'
            );
        }

        $apiKey = $this->syncKey();

        if ($apiKey === '')
        {
            throw new \RuntimeException(
                'SYNC_API_KEY belum tersedia di .env.'
            );
        }

        $totalSent = 0;
        $lastResponse = null;

        /*
         * Kirim bertahap agar tidak membebani Athena.
         */
        foreach (array_chunk($items, 100) as $index => $chunk)
        {

            $batchNumber = $index + 1;

            $response = Http::timeout(60)
                ->retry(2, 1000)
                ->withHeaders([
                    'X-Sync-Key' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->withOptions([
                    'verify' => $this->sslVerifyOption(),
                ])
                ->post($endpoint, [
                    'items' => $chunk,
                ])
                ->throw();

            $totalSent += count($chunk);
            $lastResponse = $response->json();

            echo "Batch {$batchNumber}: "
                . count($chunk)
                . " data berhasil dikirim."
                . PHP_EOL;
        }

        return [
            'sent' => $totalSent,
            'remote' => $lastResponse,
            'skipped' => false,
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

        return rtrim((string) $baseUrl, '/')
            . '/'
            . ltrim($path, '/');
    }

    private function sslVerifyOption(): bool|string
    {
        if (!config('services.sync.verify_ssl', true))
        {
            return false;
        }

        $caBundle = config('services.sync.ca_bundle');

        if ($caBundle)
        {
            return (string) $caBundle;
        }

        return true;
    }
}