<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class LunasKreditSyncService
{
    /**
     * @return array{sent: int, remote: mixed, skipped: bool}
     *
     * @throws RequestException
     */
    public function send(int $bln, int $thn, string $kodeljk, ?string $sort = null, ?string $targetUrl = null): array
    {
        $rows = $this->fetchRows($bln, $thn, trim($kodeljk), $sort);
        $endpoint = $targetUrl ?? $this->syncEndpoint('sync/lunas-kredit/receive');

        if (count($rows) === 0)
        {
            return [
                'sent' => 0,
                'remote' => null,
                'skipped' => true,
            ];
        }

        if (!$endpoint)
        {
            abort(422, 'Target URL belum diset. Isi SYNC_API_URL di .env.');
        }

        $http = Http::timeout(60)
            ->retry(2, 1000)
            ->withHeaders(['X-Sync-Key' => $this->syncKey()])
            ->withOptions(['verify' => $this->sslVerifyOption()]);

        $response = $http->post($endpoint, [
            'items' => $rows,
        ])->throw();

        return [
            'sent' => count($rows),
            'remote' => $response->json(),
            'skipped' => false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchRows(int $bln, int $thn, string $kodeljk, ?string $sort = null): array
    {
        $kodeljk = trim($kodeljk);

        $sortColumns = [
            'tglkondisi' => 'a.tglkondisi',
            'a.tglkondisi' => 'a.tglkondisi',
            'namalengkap' => 'b.namalengkap',
            'b.namalengkap' => 'b.namalengkap',
            'norekcrd' => 'a.norekcrd',
            'a.norekcrd' => 'a.norekcrd',
            'cif' => 'a.cif',
            'a.cif' => 'a.cif',
            'plafon' => 'plafon',
            'kolektibilitas' => 'a.kolektibilitas',
            'a.kolektibilitas' => 'a.kolektibilitas',
        ];

        $sortSql = $sortColumns[strtolower(trim((string) $sort))] ?? 'a.tglkondisi';

        $sql = <<<SQL
SELECT
    e.nama_bank,
    b.alamat,
    b.notelp,
    b.nohp,
    a.kodeljk,
    a.sandicabang,
    a.cif,
    a.norekcrd,
    CASE d.prk WHEN 1 THEN a.plafoninduk ELSE a.plafon END AS plafon,
    RTRIM(b.namalengkap) AS namalengkap,
    RTRIM(a.noakad) AS noakad,
    ISNULL(hist420.nominal, 0) + ISNULL(trx420.nominal, 0) AS os,
    ISNULL(hist422.nominal, 0) + ISNULL(trx422.nominal, 0) AS denda,
    ISNULL(hist424.nominal, 0) + ISNULL(trx424.nominal, 0) AS penalty,
    a.kodekondisi,
    RTRIM(c.kondisi) AS kondisi,
    CONVERT(VARCHAR(10), a.tglkondisi, 23) AS tglkondisi,
    CONVERT(VARCHAR(10), a.tglefektif, 23) AS tgleff,
    d.nama AS namaproduk,
    f.kode + ' - ' + f.ket AS ao,
    a.kolektibilitas
FROM crdmaster a
LEFT JOIN cif b ON a.cif = b.cif
LEFT JOIN refojk_kondisi c ON a.kodekondisi = c.kodekondisi
LEFT JOIN crd_setup d ON a.kodeprodukcrd = d.kodeprodukcrd
LEFT JOIN company_setup e ON a.kodeljk = e.kodeljk AND a.sandicabang = e.sandicabang
LEFT JOIN refintern_ao f ON a.kodeljk = f.kodeljk AND a.sandicabang = f.sandicabang AND a.kodeao = f.kode
OUTER APPLY (
    SELECT SUM(ISNULL(nominal, 0)) AS nominal
    FROM history_crd
    WHERE noacc = a.norekcrd AND CAST(tgltrx AS date) = CAST(a.tglkondisi AS date) AND cd_trx1 = 420
) hist420
OUTER APPLY (
    SELECT SUM(ISNULL(nominal, 0)) AS nominal
    FROM transaksi
    WHERE (dracc = a.norekcrd OR cracc = a.norekcrd) AND CAST(tgltrx AS date) = CAST(a.tglkondisi AS date) AND cd_trx1 = 420 AND ststrx = 1
) trx420
OUTER APPLY (
    SELECT SUM(ISNULL(nominal, 0)) AS nominal
    FROM history_crd
    WHERE noacc = a.norekcrd AND CAST(tgltrx AS date) = CAST(a.tglkondisi AS date) AND cd_trx1 = 422
) hist422
OUTER APPLY (
    SELECT SUM(ISNULL(nominal, 0)) AS nominal
    FROM transaksi
    WHERE (dracc = a.norekcrd OR cracc = a.norekcrd) AND CAST(tgltrx AS date) = CAST(a.tglkondisi AS date) AND cd_trx1 = 422 AND ststrx = 1
) trx422
OUTER APPLY (
    SELECT SUM(ISNULL(nominal, 0)) AS nominal
    FROM history_crd
    WHERE noacc = a.norekcrd AND CAST(tgltrx AS date) = CAST(a.tglkondisi AS date) AND cd_trx1 = 424
) hist424
OUTER APPLY (
    SELECT SUM(ISNULL(nominal, 0)) AS nominal
    FROM transaksi
    WHERE (dracc = a.norekcrd OR cracc = a.norekcrd) AND CAST(tgltrx AS date) = CAST(a.tglkondisi AS date) AND cd_trx1 = 424 AND ststrx = 1
) trx424
WHERE
    (? = '' OR a.kodeljk = ?)
    AND a.kodekondisi = '02'
    AND DATEPART(m, a.tglkondisi) = ?
    AND DATEPART(yyyy, a.tglkondisi) = ?
ORDER BY {$sortSql}
SQL;

        return array_map(
            fn(object $row) => (array) $row,
            DB::select($sql, [$kodeljk, $kodeljk, $bln, $thn]),
        );
    }

    public function countRows(int $bln, int $thn, string $kodeljk): int
    {
        $kodeljk = trim($kodeljk);

        $sql = <<<SQL
SELECT COUNT(*) AS total
FROM crdmaster a
WHERE
    (? = '' OR a.kodeljk = ?)
    AND a.kodekondisi = '02'
    AND DATEPART(m, a.tglkondisi) = ?
    AND DATEPART(yyyy, a.tglkondisi) = ?
SQL;

        $result = DB::selectOne($sql, [$kodeljk, $kodeljk, $bln, $thn]);

        return (int) ($result->total ?? 0);
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
