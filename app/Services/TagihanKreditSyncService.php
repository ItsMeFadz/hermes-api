<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TagihanKreditSyncService
{
    /**
     * Compatibility wrapper. Date parameters are ignored; tagihan sync does not use RPS.
     */
    public function getTagihanKreditFromSqlServer(
        string $tgl1,
        string $tgl2,
        string $kodeljk,
        string $sandicabang = '000'
    ): array {
        return $this->getAllActiveCreditItems($kodeljk, $sandicabang);
    }

    /**
     * Ambil semua data rekening kredit aktif tanpa bergantung pada jadwal RPS.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllActiveCreditItems(
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
    a.tglefektif AS tglefektif,
    a.tgljthtempo AS tgljthtempo,
    a.graceperiod,
    h.datatext1 AS statusrek,
    a.plafon,
    a.jangkawaktu,
    a.haritunggakkan,
    a.norekpembayaran,
    a.tungpokok,
    a.tungbunga,
    a.kolektibilitas,
    a.kodekondisi,
    ISNULL(
        CASE
            WHEN c.saldoakhir - c.saldoblokir - e.minsaldo < 0 THEN 0
            ELSE c.saldoakhir - c.saldoblokir - e.minsaldo
        END,
        0
    ) AS saldotab,
    ISNULL(c.saldoakhir, 0) AS saldotabactual,
    a.kodeao AS kodeao,
    f.ket AS ao,
    g.ket AS ketinstansi
FROM crdmaster a
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
    AND a.stsrekcrd = '1'
SQL;

        $params = [$kodeljk];

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
            $params[] = $sandicabang;
        }

        $rows = DB::connection('sqlsrv')->select($sql, $params);
        $items = array_map(function ($row)
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
                'tglefektif' => $row->tglefektif ?? null,
                'tgljthtempo' => $row->tgljthtempo ?? null,
                'graceperiod' => $row->graceperiod ?? null,
                'statusrek' => $row->statusrek ?? null,
                'plafon' => $row->plafon ?? null,
                'jangkawaktu' => $row->jangkawaktu ?? null,
                'haritunggakkan' => $row->haritunggakkan ?? null,
                'tungpokok' => $row->tungpokok ?? null,
                'tungbunga' => $row->tungbunga ?? null,
                'kodekondisi' => $row->kodekondisi ?? null,
                'kolektibilitas' => $row->kolektibilitas ?? null,
                'norekpembayaran' => $row->norekpembayaran ?? null,
                'saldotab' => $row->saldotab ?? null,
                'saldotabactual' => $row->saldotabactual ?? null,
                'kodeao' => $row->kodeao ?? null,
                'ao' => $row->ao ?? null,
                'ketinstansi' => $row->ketinstansi ?? null,
            ];
        }, $rows);

        $uniqueItems = [];
        $seenAccounts = [];

        foreach ($items as $item)
        {
            $account = (string) ($item['norekcrd'] ?? '');

            if (isset($seenAccounts[$account]))
            {
                continue;
            }

            $seenAccounts[$account] = true;
            $uniqueItems[] = $item;
        }

        return $uniqueItems;
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

    /**
     * Lokasi file snapshot rekening kredit aktif.
     */
    private function snapshotPath(): string
    {
        return storage_path(
            'app/sync/tagihan-kredit-snapshot.json'
        );
    }

    private function snapshotExists(): bool
    {
        return is_file($this->snapshotPath());
    }

    /**
     * Baca snapshot rekening aktif sebelumnya.
     */
    private function readSnapshot(): array
    {
        $path = $this->snapshotPath();

        if (!is_file($path))
        {
            return [];
        }

        $content = file_get_contents($path);

        if ($content === false || trim($content) === '')
        {
            return [];
        }

        $data = json_decode($content, true);

        if (!is_array($data))
        {
            return [];
        }

        $accounts = $data['accounts'] ?? [];

        if (!is_array($accounts))
        {
            return [];
        }

        return array_values(
            array_unique(
                array_map(
                    fn($account) => trim((string) $account),
                    $accounts
                )
            )
        );
    }

    public function getSnapshotAccounts(): array
    {
        return $this->readSnapshot();
    }

    /**
     * Timpa snapshot dengan daftar rekening aktif terbaru.
     */
    private function writeSnapshot(array $accounts): void
    {
        $path = $this->snapshotPath();
        $directory = dirname($path);

        if (!is_dir($directory))
        {
            mkdir($directory, 0755, true);
        }

        $accounts = array_values(
            array_unique(
                array_map(
                    fn($account) => trim((string) $account),
                    $accounts
                )
            )
        );

        sort($accounts);

        $data = [
            'updated_at' => now()->format('Y-m-d H:i:s'),
            'accounts' => $accounts,
        ];

        $result = file_put_contents(
            $path,
            json_encode(
                $data,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
            ),
            LOCK_EX
        );

        if ($result === false)
        {
            throw new \RuntimeException(
                'Gagal menyimpan snapshot tagihan kredit.'
            );
        }
    }

    public function getActiveCreditAccounts(
        string $kodeljk,
        string $sandicabang = '000'
    ): array {
        $kodeljk = trim($kodeljk);
        $sandicabang = trim($sandicabang);

        $sql = <<<SQL
        SELECT
            a.norekcrd
        FROM crdmaster a
        WHERE
            a.kodeljk = ?
            AND a.stsrekcrd = '1'
        SQL;

        $params = [
            $kodeljk,
        ];

        if ($sandicabang !== '000')
        {
            $sql .= <<<SQL

            AND a.sandicabang = ?
        SQL;

            $params[] = $sandicabang;
        }

        $rows = DB::connection('sqlsrv')->select(
            $sql,
            $params
        );

        return array_values(
            array_unique(
                array_map(
                    fn($row) => trim((string) $row->norekcrd),
                    $rows
                )
            )
        );
    }

    public function getDisappearedAccounts(
        string $kodeljk,
        string $sandicabang = '000'
    ): array {
        $previousAccounts = $this->readSnapshot();

        $currentAccounts = $this->getActiveCreditAccounts(
            $kodeljk,
            $sandicabang
        );

        if (empty($previousAccounts))
        {
            /*
             * Snapshot pertama kali dibuat.
             *
             * Jangan anggap semua rekening sebagai
             * rekening lunas karena belum ada data
             * pembanding dari malam sebelumnya.
             */
            return [];
        }

        return array_values(
            array_diff(
                $previousAccounts,
                $currentAccounts
            )
        );
    }

    public function getInactiveCreditAccounts(
        array $accounts,
        string $kodeljk,
        string $sandicabang = '000'
    ): array {
        if (empty($accounts))
        {
            return [];
        }

        $kodeljk = trim($kodeljk);
        $sandicabang = trim($sandicabang);

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($accounts),
                '?'
            )
        );

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
            a.plafon,
            a.jangkawaktu,

            a.tglefektif,
            a.tgljthtempo,
            a.graceperiod,

            h.datatext1 AS statusrek,

            a.haritunggakkan,

            a.norekpembayaran,
            a.tungpokok,
            a.tungbunga,
            a.kolektibilitas,
            a.kodekondisi,

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

        JOIN cif d
            ON a.cif = d.cif

        LEFT JOIN tabmaster c
            ON a.kodeljk = c.kodeljk
            AND a.sandicabang = c.sandicabang
            AND a.norekpembayaran = c.norekening

        LEFT JOIN tabungan_setup e
            ON c.kodeproduktab = e.kodeproduk

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
            AND a.norekcrd IN ({$placeholders})
        SQL;

        $params = [
            $kodeljk,
            ...$accounts,
        ];

        if ($sandicabang !== '000')
        {
            $sql .= <<<SQL

            AND a.sandicabang = ?
        SQL;

            $params[] = $sandicabang;
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

                'tglefektif' => $row->tglefektif ?? null,
                'tgljthtempo' => $row->tgljthtempo ?? null,
                'graceperiod' => $row->graceperiod ?? null,

                'statusrek' => $row->statusrek ?? null,

                'plafon' => $row->plafon,
                'jangkawaktu' => $row->jangkawaktu,
                'haritunggakkan' => $row->haritunggakkan ?? null,

                'tungpokok' => $row->tungpokok ?? null,
                'tungbunga' => $row->tungbunga ?? null,

                'kolektibilitas' => $row->kolektibilitas ?? null,
                'kodekondisi' => $row->kodekondisi ?? null,

                'norekpembayaran' => $row->norekpembayaran ?? null,

                'saldotab' => $row->saldotab ?? null,
                'saldotabactual' => $row->saldotabactual ?? null,

                'kodeao' => $row->kodeao ?? null,
                'ao' => $row->ao ?? null,

                'ketinstansi' => $row->ketinstansi ?? null,
            ];
        }, $rows);
    }

    public function syncWithSnapshot(
        string $kodeljk,
        string $sandicabang = '000',
        ?string $tgl1 = null,
        ?string $tgl2 = null
    ): array {
        /*
         * =====================================================
         * 1. Baca snapshot lama
         * =====================================================
         */
        $previousAccounts = $this->readSnapshot();

        /*
         * =====================================================
         * 2. Ambil rekening aktif saat ini
         * =====================================================
         */
        $currentAccounts = $this->getActiveCreditAccounts(
            $kodeljk,
            $sandicabang
        );

        /*
         * =====================================================
         * 3. Cari rekening yang sebelumnya aktif,
         *    tetapi sekarang sudah tidak aktif
         * =====================================================
         */
        $disappearedAccounts = [];

        if (!empty($previousAccounts))
        {
            $disappearedAccounts = array_values(
                array_diff(
                    $previousAccounts,
                    $currentAccounts
                )
            );
        }

        echo 'Rekening aktif sekarang: '
            . count($currentAccounts)
            . PHP_EOL;

        echo 'Rekening hilang dari snapshot: '
            . count($disappearedAccounts)
            . PHP_EOL;

        /*
         * =====================================================
         * 4. Ambil data aktif
         * =====================================================
         *
         * Kalau snapshot belum ada:
         *     INITIAL SYNC
         *     -> kirim semua rekening aktif
         *
         * Kalau snapshot sudah ada:
         *     NORMAL SYNC
         *     -> tagihan sesuai rentang tgl1/tgl2
         *
         * Data full aktif dikirim saat snapshot belum ada.
         */
        $isInitialSync = !$this->snapshotExists();

        if ($isInitialSync)
        {
            echo 'Snapshot belum ada. Menjalankan initial sync...'
                . PHP_EOL;
        }
        else
        {
            echo 'Snapshot ditemukan. Menjalankan sync normal...'
                . PHP_EOL;
        }

        $activeItems = $this->getAllActiveCreditItems(
            $kodeljk,
            $sandicabang
        );

        echo "Total active credit accounts (stsrekcrd='1'): "
            . count($activeItems)
            . PHP_EOL;

        if (!empty($activeItems))
        {
            echo 'First active credit account: '
                . ($activeItems[0]['norekcrd'] ?? '')
                . PHP_EOL;
        }

        /*
         * =====================================================
         * 5. Ambil data rekening yang sudah tidak aktif
         * =====================================================
         */
        $inactiveItems = [];

        if (!empty($disappearedAccounts))
        {
            $inactiveItems = $this->getInactiveCreditAccounts(
                $disappearedAccounts,
                $kodeljk,
                $sandicabang
            );
        }

        /*
         * =====================================================
         * 6. Gabungkan data aktif + rekening tidak aktif
         * =====================================================
         */
        $items = array_merge(
            $activeItems,
            $inactiveItems
        );

        /*
         * =====================================================
         * 7. Kirim ke Athena
         * =====================================================
         *
         * Kalau send() gagal, exception akan keluar.
         *
         * Artinya snapshot BELUM ditimpa.
         */
        $sendResult = $this->send($items);

        /*
         * =====================================================
         * 8. Setelah pengiriman berhasil,
         *    baru timpa snapshot.
         * =====================================================
         */
        $this->writeSnapshot(
            $currentAccounts
        );

        echo 'Snapshot berhasil diperbarui.'
            . PHP_EOL;

        return [
            'active_accounts' => count($currentAccounts),
            'disappeared_accounts' => count($disappearedAccounts),
            'active_items_sent' => count($activeItems),
            'inactive_items_sent' => count($inactiveItems),
            'total_sent' => $sendResult['sent'] ?? 0,
            'snapshot_updated' => true,
        ];
    }

    public function getInitialActiveCreditAccounts(
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
            a.plafon,
            a.jangkawaktu,

            a.tglefektif,
            a.tgljthtempo,
            a.graceperiod,

            h.datatext1 AS statusrek,

            a.haritunggakkan,

            a.norekpembayaran,
            a.tungpokok,
            a.tungbunga,
            a.kolektibilitas,
            a.kodekondisi,

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

        JOIN cif d
            ON a.cif = d.cif

        LEFT JOIN tabmaster c
            ON a.kodeljk = c.kodeljk
            AND a.sandicabang = c.sandicabang
            AND a.norekpembayaran = c.norekening

        LEFT JOIN tabungan_setup e
            ON c.kodeproduktab = e.kodeproduk

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
            AND a.stsrekcrd = '1'
        SQL;

                $params = [
                    $kodeljk,
                ];

                if ($sandicabang !== '000')
                {
                    $sql .= <<<SQL

            AND a.sandicabang = ?
        SQL;

            $params[] = $sandicabang;
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
                'plafon' => $row->plafon,
                'jangkawaktu' => $row->jangkawaktu,

                'tgltempo' => null,
                'tglefektif' => $row->tglefektif ?? null,
                'tgljthtempo' => $row->tgljthtempo ?? null,
                'graceperiod' => $row->graceperiod ?? null,

                'statusrek' => $row->statusrek ?? null,

                'tagpokok' => null,
                'tagbunga' => null,
                'tagdenda' => null,
                'totalangsuran' => null,

                'haritunggakkan' => $row->haritunggakkan ?? null,

                'tungpokok' => $row->tungpokok ?? null,
                'tungbunga' => $row->tungbunga ?? null,

                'kolektibilitas' => $row->kolektibilitas ?? null,
                'kodekondisi' => $row->kodekondisi ?? null,

                'norekpembayaran' => $row->norekpembayaran ?? null,

                'saldotab' => $row->saldotab ?? null,
                'saldotabactual' => $row->saldotabactual ?? null,

                'kodeao' => $row->kodeao ?? null,
                'ao' => $row->ao ?? null,

                'ketinstansi' => $row->ketinstansi ?? null,
            ];
        }, $rows);
    }
}