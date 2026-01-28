<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Ingestion\IngestionService;
use App\Support\Idempotency\IdempotencyKey;
use App\Support\Idempotency\IdempotencyStore;
use Illuminate\Http\Request;

class HandleProviderWebhook extends Controller
{
    public function handle(Request $request, string $provider)
    {
        $payload = $request->all();
        $eventId = (string) ($payload['event_id'] ?? '');
        $externalUserId = (string) ($payload['external_user_id'] ?? '');
        $recordedAt = (string) ($payload['recorded_at'] ?? '');
        $samples = $payload['samples'] ?? [];

        if ($eventId === '' || $recordedAt === '') {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_PAYLOAD',
                'message' => 'event_id and recorded_at are required',
            ], 422);
        }

        if (!$this->verifySignature($provider, $request)) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_SIGNATURE',
                'message' => 'signature mismatch',
            ], 401);
        }

        $idKey = IdempotencyKey::build($provider, $eventId, $recordedAt, $payload);
        $store = new IdempotencyStore();
        $existing = $store->find($idKey['provider'], $idKey['external_id'], $idKey['recorded_at'], $idKey['hash']);
        if ($existing) {
            $store->touch($idKey['provider'], $idKey['external_id'], $idKey['recorded_at'], $idKey['hash']);
            return response()->json([
                'ok' => true,
                'status' => 'duplicate',
                'event_id' => $eventId,
            ]);
        }

        $store->record([
            'provider' => $idKey['provider'],
            'external_id' => $idKey['external_id'],
            'recorded_at' => $idKey['recorded_at'],
            'hash' => $idKey['hash'],
            'ingest_batch_id' => null,
        ]);

        $batchMeta = [
            'range_start' => $payload['range_start'] ?? null,
            'range_end' => $payload['range_end'] ?? null,
            'raw_payload_hash' => IdempotencyKey::hashPayload($payload),
        ];

        $result = app(IngestionService::class)->ingestSamples($provider, null, $batchMeta, is_array($samples) ? $samples : []);

        return response()->json([
            'ok' => true,
            'event_id' => $eventId,
            'external_user_id' => $externalUserId,
            'batch_id' => $result['batch_id'] ?? null,
            'inserted' => $result['inserted'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
        ]);
    }

    private function verifySignature(string $provider, Request $request): bool
    {
        $secret = (string) config("services.integrations.{$provider}.webhook_secret", '');
        if ($secret === '') {
            return true; // dev mode
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');
        if ($signature === '') {
            return false;
        }

        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
