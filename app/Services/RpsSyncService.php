<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RpsSyncService
{
    private const ACCOUNT_QUERY_CHUNK_SIZE = 1000;

    private const SEND_BATCH_SIZE = 100;

    /**
     * @param array<int, string> $accounts
     * @return array{accounts: int, sent: int, batches: int, remote: mixed, skipped: bool}
     */
    public function sync(array $accounts): array
    {
        $accounts = array_values(array_unique(array_filter(
            array_map(
                fn($account) => trim((string) $account),
                $accounts
            ),
            fn($account) => $account !== ''
        )));

        if (empty($accounts))
        {
            return [
                'accounts' => 0,
                'sent' => 0,
                'batches' => 0,
                'remote' => null,
                'skipped' => true,
            ];
        }

        $endpoint = $this->syncEndpoint();
        $apiKey = $this->syncKey();

        if (!$endpoint)
        {
            throw new \RuntimeException(
                'Target URL belum diset. Isi SYNC_API_URL di .env.'
            );
        }

        if ($apiKey === '')
        {
            throw new \RuntimeException(
                'SYNC_API_KEY belum tersedia di .env.'
            );
        }

        $totalSent = 0;
        $batchNumber = 0;
        $lastResponse = null;
        $items = [];

        foreach (array_chunk($accounts, self::ACCOUNT_QUERY_CHUNK_SIZE) as $accountChunk)
        {
            $placeholders = implode(',', array_fill(0, count($accountChunk), '?'));
            $sql = <<<SQL
        SELECT
            kodeljk,
            sandicabang,
            cif,
            norekcrd,
            periode,
            tglangsuran,
            saldoawal,
            saldoakhir,
            tagpokok,
            tagbunga,
            totalangsuran,
            tagdenda,
            byrpokok,
            byrbunga,
            byrdenda,
            tglbyr,
            sukubunga,
            noakad,
            jmlharidenda,
            tglbyrdenda,
            tglbyrbunga
        FROM rps
        WHERE norekcrd IN ({$placeholders})
        SQL;

            foreach (DB::connection('sqlsrv')->cursor($sql, $accountChunk) as $row)
            {
                $items[] = (array) $row;

                if (count($items) === self::SEND_BATCH_SIZE)
                {
                    $batchNumber++;
                    $lastResponse = $this->sendBatch(
                        $items,
                        $endpoint,
                        $apiKey,
                        $batchNumber
                    );
                    $totalSent += count($items);
                    $items = [];
                }
            }
        }

        if (!empty($items))
        {
            $batchNumber++;
            $lastResponse = $this->sendBatch(
                $items,
                $endpoint,
                $apiKey,
                $batchNumber
            );
            $totalSent += count($items);
        }

        return [
            'accounts' => count($accounts),
            'sent' => $totalSent,
            'batches' => $batchNumber,
            'remote' => $lastResponse,
            'skipped' => $totalSent === 0,
        ];
    }

    private function sendBatch(
        array $items,
        string $endpoint,
        string $apiKey,
        int $batchNumber
    ): mixed {
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
                'items' => $items,
            ])
            ->throw();

        echo "Batch {$batchNumber}: "
            . count($items)
            . " data RPS berhasil dikirim."
            . PHP_EOL;

        return $response->json();
    }

    private function syncKey(): string
    {
        return (string) config('services.sync.api_key');
    }

    private function syncEndpoint(): ?string
    {
        $baseUrl = config('services.sync.api_url');

        if (!$baseUrl)
        {
            return null;
        }

        return rtrim((string) $baseUrl, '/')
            . '/sync/rps/receive';
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
