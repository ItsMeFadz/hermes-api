<?php

namespace App\Http\Controllers;

use App\Services\LunasKreditSyncService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LunasKreditSyncController extends Controller
{
    public function send(Request $request, LunasKreditSyncService $syncService): JsonResponse
    {
        $this->validateSyncKey($request, $syncService);

        $validated = $request->validate([
            'bln' => ['required', 'integer', 'between:1,12'],
            'thn' => ['required', 'integer', 'between:2000,2100'],
            'kodeljk' => ['required', 'string', 'size:6'],
            'sandicabang' => ['nullable', 'string', 'max:3'],
            'sort' => ['nullable', 'string', 'max:50'],
            'target_url' => ['nullable', 'url'],
        ]);

        try
        {
            $result = $syncService->send(
                (int) $validated['bln'],
                (int) $validated['thn'],
                $validated['kodeljk'],
                $validated['sandicabang'] ?? '',
                $validated['sort'] ?? null,
                $validated['target_url'] ?? null,
            );
        }
        catch (RequestException $exception)
        {
            return response()->json([
                'message' => 'Gagal mengirim data ke server tujuan.',
                'status' => $exception->response?->status(),
                'response' => $exception->response?->json(),
            ], 502);
        }

        return response()->json([
            'message' => 'Sinkronisasi lunas kredit berhasil dikirim.',
            'sent' => $result['sent'],
            'remote' => $result['remote'],
        ]);
    }

    private function validateSyncKey(Request $request, LunasKreditSyncService $syncService): void
    {
        $configuredKey = $syncService->syncKey();
        $providedKey = $request->header('X-Sync-Key') ?: $request->bearerToken();

        if (!$configuredKey || !$providedKey || !hash_equals($configuredKey, $providedKey))
        {
            throw new HttpResponseException(response()->json([
                'message' => 'Sync key tidak valid.',
            ], 401));
        }
    }
}
