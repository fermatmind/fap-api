<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class IntegrationsIngestAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = strtolower(trim((string) $request->route('provider', '')));
        if (!$this->isAllowedProvider($provider)) {
            return $this->unauthorized('unsupported_provider');
        }

        if ($this->hasBearerAuthorization($request)) {
            return $this->handleBearer($request, $next);
        }

        return $this->handleIngestKey($request, $next, $provider);
    }

    private function hasBearerAuthorization(Request $request): bool
    {
        $header = (string) $request->header('Authorization', '');

        return preg_match('/^\s*Bearer\s+.+\s*$/i', $header) === 1;
    }

    private function handleBearer(Request $request, Closure $next): Response
    {
        $passed = false;

        $response = app(FmTokenAuth::class)->handle(
            $request,
            function (Request $authedRequest) use (&$passed, $next): Response {
                $passed = true;

                $userId = $this->resolveRequestUserId($authedRequest);
                if ($userId === null) {
                    return $this->unauthorized('token_identity_missing');
                }

                $authedRequest->attributes->set('fm_user_id', $userId);
                $authedRequest->attributes->set('user_id', $userId);
                $authedRequest->attributes->set('integration_auth_mode', 'sanctum');
                $authedRequest->attributes->set('integration_signature_ok', false);
                $authedRequest->attributes->set('integration_actor_user_id', (int) $userId);

                return $next($authedRequest);
            }
        );

        if (!$passed) {
            return $this->unauthorized('token_missing_or_invalid');
        }

        return $response;
    }

    private function handleIngestKey(Request $request, Closure $next, string $provider): Response
    {
        $ingestKey = trim((string) $request->header('X-Ingest-Key', ''));
        if ($ingestKey === '') {
            return $this->unauthorized('missing_ingest_key');
        }

        $eventId = trim((string) $request->header('X-Ingest-Event-Id', ''));
        if ($eventId === '') {
            return $this->unauthorized('missing_event_id');
        }

        $timestamp = $this->resolveIngestTimestamp($request);
        $keyHash = hash('sha256', $ingestKey);

        $resolved = DB::transaction(function () use ($provider, $keyHash, $eventId, $timestamp): array {
            $row = DB::table('integrations')
                ->where('provider', $provider)
                ->where('ingest_key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            if (!$row || !is_numeric($row->user_id ?? null)) {
                return ['ok' => false, 'reason' => 'invalid_ingest_key'];
            }

            $lastEventId = trim((string) ($row->webhook_last_event_id ?? ''));
            if ($lastEventId !== '' && hash_equals($lastEventId, $eventId)) {
                return ['ok' => false, 'reason' => 'replay_detected'];
            }

            $lastTimestamp = is_numeric($row->webhook_last_timestamp ?? null)
                ? (int) $row->webhook_last_timestamp
                : null;
            if ($lastTimestamp !== null && $timestamp <= $lastTimestamp) {
                return ['ok' => false, 'reason' => 'replay_detected'];
            }

            $updateQuery = DB::table('integrations')
                ->where('provider', $provider)
                ->where('ingest_key_hash', $keyHash);

            if (is_numeric($row->user_id ?? null)) {
                $updateQuery->where('user_id', (int) $row->user_id);
            }

            $updateQuery->update([
                'webhook_last_event_id' => $eventId,
                'webhook_last_timestamp' => $timestamp,
                'webhook_last_received_at' => now(),
                'updated_at' => now(),
            ]);

            return ['ok' => true, 'user_id' => (string) ((int) $row->user_id)];
        });

        if (!($resolved['ok'] ?? false)) {
            return $this->unauthorized((string) ($resolved['reason'] ?? 'invalid_ingest_key'));
        }

        $userId = (string) ($resolved['user_id'] ?? '');
        if ($userId === '') {
            return $this->unauthorized('invalid_ingest_key');
        }

        $request->attributes->set('fm_user_id', $userId);
        $request->attributes->set('user_id', $userId);
        $request->attributes->set('integration_auth_mode', 'signature');
        $request->attributes->set('integration_signature_ok', true);
        $request->attributes->set('integration_actor_user_id', null);

        return $next($request);
    }

    private function resolveIngestTimestamp(Request $request): int
    {
        $raw = trim((string) $request->header('X-Ingest-Timestamp', ''));
        if ($raw !== '' && preg_match('/^\d{10,13}$/', $raw) === 1) {
            $timestamp = (int) $raw;
            if (strlen($raw) === 13) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        return time();
    }

    private function resolveRequestUserId(Request $request): ?string
    {
        foreach (['fm_user_id', 'user_id'] as $key) {
            $raw = trim((string) $request->attributes->get($key, ''));
            if ($raw !== '' && preg_match('/^\d+$/', $raw) === 1) {
                return $raw;
            }
        }

        return null;
    }

    private function isAllowedProvider(string $provider): bool
    {
        $allowed = (array) config('integrations.allowed_providers', [
            'mock',
            'apple_health',
            'google_fit',
            'calendar',
            'screen_time',
        ]);

        $allowed = array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $allowed
        )));

        return $provider !== '' && in_array($provider, $allowed, true);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
            'message' => $message,
        ], 401);
    }
}
