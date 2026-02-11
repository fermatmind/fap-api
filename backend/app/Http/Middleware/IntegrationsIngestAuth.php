<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class IntegrationsIngestAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = strtolower(trim((string) $request->route('provider', '')));
        if (! $this->isAllowedProvider($provider)) {
            return $this->unauthorized('unsupported_provider');
        }

        if ($this->hasBearerAuthorization($request)) {
            return $this->handleBearer($request, $next);
        }

        return $this->handleSignedWebhook($request, $next, $provider);
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

        if (! $passed) {
            return $this->unauthorized('token_missing_or_invalid');
        }

        return $response;
    }

    private function handleSignedWebhook(Request $request, Closure $next, string $provider): Response
    {
        $timestamp = $this->extractTimestamp($request);
        $signature = strtolower(trim((string) $request->header('X-Webhook-Signature', '')));
        $eventId = trim((string) $request->header('X-Webhook-Event-Id', ''));

        if ($timestamp === null || $signature === '' || $eventId === '') {
            return $this->unauthorized('missing_signature_headers');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return $this->unauthorized('invalid_signature_format');
        }

        $tolerance = $this->resolveWebhookTolerance($provider);
        if (abs(time() - $timestamp) > $tolerance) {
            return $this->unauthorized('timestamp_out_of_tolerance');
        }

        $secret = trim((string) config("services.integrations.providers.{$provider}.webhook_secret", ''));
        if ($secret === '') {
            return $this->unauthorized('webhook_secret_missing');
        }

        $rawBody = (string) $request->getContent();
        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->unauthorized('invalid_signature');
        }

        $externalUserId = trim((string) data_get($request->all(), 'external_user_id', ''));
        if ($externalUserId === '') {
            return $this->unauthorized('unauthorized_identity_mapping');
        }

        if (! Schema::hasTable('integrations')) {
            return $this->unauthorized('unauthorized_identity_mapping');
        }

        $resolved = DB::transaction(function () use ($provider, $externalUserId, $eventId, $timestamp): array {
            $row = DB::table('integrations')
                ->where('provider', $provider)
                ->where('external_user_id', $externalUserId)
                ->lockForUpdate()
                ->first();

            if (! $row || ! is_numeric($row->user_id ?? null)) {
                return ['ok' => false, 'reason' => 'unauthorized_identity_mapping'];
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

            $updates = [];
            if (Schema::hasColumn('integrations', 'webhook_last_event_id')) {
                $updates['webhook_last_event_id'] = $eventId;
            }
            if (Schema::hasColumn('integrations', 'webhook_last_timestamp')) {
                $updates['webhook_last_timestamp'] = $timestamp;
            }
            if (Schema::hasColumn('integrations', 'webhook_last_received_at')) {
                $updates['webhook_last_received_at'] = now();
            }
            if (Schema::hasColumn('integrations', 'updated_at')) {
                $updates['updated_at'] = now();
            }

            if ($updates !== []) {
                DB::table('integrations')
                    ->where('provider', $provider)
                    ->where('external_user_id', $externalUserId)
                    ->update($updates);
            }

            return ['ok' => true, 'user_id' => (string) ((int) $row->user_id)];
        });

        if (! (bool) ($resolved['ok'] ?? false)) {
            return $this->unauthorized((string) ($resolved['reason'] ?? 'unauthorized_identity_mapping'));
        }

        $userId = (string) ($resolved['user_id'] ?? '');
        if ($userId === '') {
            return $this->unauthorized('unauthorized_identity_mapping');
        }

        $request->attributes->set('fm_user_id', $userId);
        $request->attributes->set('user_id', $userId);
        $request->attributes->set('integration_auth_mode', 'signature');
        $request->attributes->set('integration_signature_ok', true);
        $request->attributes->set('integration_actor_user_id', null);

        return $next($request);
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

    private function resolveWebhookTolerance(string $provider): int
    {
        $global = (int) config('services.integrations.webhook_tolerance_seconds', 300);
        if ($global <= 0) {
            $global = 300;
        }

        $providerTolerance = (int) config(
            "services.integrations.providers.{$provider}.webhook_tolerance_seconds",
            $global
        );

        return $providerTolerance > 0 ? $providerTolerance : $global;
    }

    private function extractTimestamp(Request $request): ?int
    {
        $raw = trim((string) $request->header('X-Webhook-Timestamp', ''));
        if ($raw === '' || preg_match('/^\d{10,13}$/', $raw) !== 1) {
            return null;
        }

        $timestamp = (int) $raw;
        if (strlen($raw) === 13) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        return $timestamp > 0 ? $timestamp : null;
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
            static fn ($v) => strtolower(trim((string) $v)),
            $allowed
        )));

        return $provider !== '' && in_array($provider, $allowed, true);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'ok' => false,
            'error' => 'UNAUTHORIZED',
            'message' => $message,
        ], 401);
    }
}
