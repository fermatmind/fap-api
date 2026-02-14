<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Ingestion\IngestionService;
use App\Support\Idempotency\IdempotencyKey;
use App\Support\Idempotency\IdempotencyStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HandleProviderWebhook extends Controller
{
    public function handle(Request $request, string $provider)
    {
        $provider = strtolower(trim($provider));
        if (!$this->isSupportedProvider($provider)) {
            return $this->notFoundResponse();
        }

        $raw = (string) $request->getContent();
        $len = strlen($raw);
        $max = (int) config('integrations.webhook_max_payload_bytes', 262144);
        if ($len > $max) {
            return $this->payloadTooLargeResponse($max, $len);
        }

        $payload = $request->all();
        $eventId = (string) ($payload['event_id'] ?? '');
        $externalUserId = (string) ($payload['external_user_id'] ?? '');
        $recordedAt = (string) ($payload['recorded_at'] ?? '');
        $samples = $payload['samples'] ?? [];

        if ($eventId === '' || $recordedAt === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_PAYLOAD',
                'message' => 'event_id and recorded_at are required',
            ], 422);
        }

        $verification = $this->verifySignature($provider, $request);
        if (!($verification['ok'] ?? false)) {
            return $this->notFoundResponse();
        }

        $idKey = IdempotencyKey::build($provider, $eventId, $recordedAt, $payload);
        $store = new IdempotencyStore();
        $idRecord = $store->recordFast([
            'provider' => $idKey['provider'],
            'external_id' => $idKey['external_id'],
            'recorded_at' => $idKey['recorded_at'],
            'hash' => $idKey['hash'],
            'ingest_batch_id' => null,
        ]);

        if (($idRecord['existing'] ?? false) === true) {
            if (($idRecord['hash_mismatch'] ?? false) === true) {
                return response()->json([
                    'ok' => false,
                    'error_code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'payload mismatch for existing event identity',
                ], 409);
            }

            return response()->json([
                'ok' => true,
                'status' => 'duplicate',
                'event_id' => $eventId,
            ]);
        }

        $batchMeta = [
            'range_start' => $payload['range_start'] ?? null,
            'range_end' => $payload['range_end'] ?? null,
            'raw_payload_hash' => IdempotencyKey::hashPayload($payload),
        ];

        $result = app(IngestionService::class)->ingestSamples($provider, null, $batchMeta, is_array($samples) ? $samples : []);

        $this->touchIntegrationWebhookReceipt(
            $provider,
            $externalUserId,
            $eventId,
            is_int($verification['timestamp'] ?? null) ? (int) $verification['timestamp'] : null,
        );

        return response()->json([
            'ok' => true,
            'event_id' => $eventId,
            'external_user_id' => $externalUserId,
            'batch_id' => $result['batch_id'] ?? null,
            'inserted' => $result['inserted'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
        ]);
    }

    private function verifySignature(string $provider, Request $request): array
    {
        $secret = $this->resolveWebhookSecret($provider);
        $timestamp = $this->extractWebhookTimestamp($request);

        if ($secret === '') {
            $allowUnsigned = (bool) config('services.integrations.allow_unsigned_without_secret', false);
            if ($allowUnsigned) {
                return ['ok' => true, 'timestamp' => $timestamp];
            }

            return ['ok' => false, 'timestamp' => $timestamp];
        }

        $signature = trim((string) $request->header('X-Webhook-Signature', ''));
        if ($signature === '') {
            return ['ok' => false, 'timestamp' => $timestamp];
        }
        if ($timestamp === null) {
            return ['ok' => false, 'timestamp' => null];
        }

        $toleranceSeconds = $this->resolveWebhookTolerance($provider);
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return ['ok' => false, 'timestamp' => $timestamp];
        }

        $payload = (string) $request->getContent();
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        if (hash_equals($expected, $signature)) {
            return ['ok' => true, 'timestamp' => $timestamp];
        }

        return ['ok' => false, 'timestamp' => $timestamp];
    }

    private function resolveWebhookSecret(string $provider): string
    {
        $secret = trim((string) config("services.integrations.providers.{$provider}.webhook_secret", ''));
        if ($secret !== '') {
            return $secret;
        }

        return trim((string) config("services.integrations.{$provider}.webhook_secret", ''));
    }

    private function resolveWebhookTolerance(string $provider): int
    {
        $globalTolerance = (int) config('services.integrations.webhook_tolerance_seconds', 300);
        if ($globalTolerance <= 0) {
            $globalTolerance = 300;
        }

        $providerTolerance = (int) config(
            "services.integrations.providers.{$provider}.webhook_tolerance_seconds",
            $globalTolerance,
        );

        return $providerTolerance > 0 ? $providerTolerance : $globalTolerance;
    }

    private function extractWebhookTimestamp(Request $request): ?int
    {
        foreach (['X-Webhook-Timestamp', 'X-Timestamp'] as $headerName) {
            $raw = trim((string) $request->header($headerName, ''));
            if ($raw === '') {
                continue;
            }

            if (!preg_match('/^\d{10,13}$/', $raw)) {
                return null;
            }

            $timestamp = (int) $raw;
            if (strlen($raw) === 13) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return $timestamp > 0 ? $timestamp : null;
        }

        return null;
    }

    private function isSupportedProvider(string $provider): bool
    {
        $providers = array_keys((array) config('services.integrations.providers', []));
        if (count($providers) === 0) {
            $providers = ['mock', 'apple_health', 'google_fit', 'calendar', 'screen_time'];
        }

        return in_array($provider, $providers, true);
    }

    private function touchIntegrationWebhookReceipt(
        string $provider,
        string $externalUserId,
        string $eventId,
        ?int $timestamp,
    ): void {
        if ($externalUserId === '' || !\App\Support\SchemaBaseline::hasTable('integrations')) {
            return;
        }

        $updates = [];
        if (\App\Support\SchemaBaseline::hasColumn('integrations', 'webhook_last_event_id')) {
            $updates['webhook_last_event_id'] = $eventId !== '' ? $eventId : null;
        }
        if (\App\Support\SchemaBaseline::hasColumn('integrations', 'webhook_last_timestamp')) {
            $updates['webhook_last_timestamp'] = $timestamp;
        }
        if (\App\Support\SchemaBaseline::hasColumn('integrations', 'webhook_last_received_at')) {
            $updates['webhook_last_received_at'] = now();
        }
        if (\App\Support\SchemaBaseline::hasColumn('integrations', 'updated_at')) {
            $updates['updated_at'] = now();
        }
        if (count($updates) === 0) {
            return;
        }

        DB::table('integrations')
            ->where('provider', $provider)
            ->where('external_user_id', $externalUserId)
            ->update($updates);
    }

    private function payloadTooLargeResponse(int $maxBytes, int $lenBytes)
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'PAYLOAD_TOO_LARGE',
            'message' => 'payload too large',
            'details' => [
                'max_bytes' => $maxBytes,
                'len_bytes' => $lenBytes,
            ],
        ], 413);
    }

    private function notFoundResponse()
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
            'message' => 'not found.',
        ], 404);
    }
}
