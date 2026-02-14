<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Services\Ingestion\ConsentService;
use App\Services\Ingestion\IngestionService;
use App\Services\Ingestion\ReplayService;
use App\Support\Idempotency\IdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProvidersController extends Controller
{
    use RespondsWithNotFound;

    public function oauthStart(Request $request, string $provider)
    {
        $provider = strtolower(trim($provider));
        if (! $this->isAllowedProvider($provider)) {
            return $this->notFoundResponse();
        }

        $state = (string) Str::uuid();
        $userId = $this->resolveUserId($request);

        if (\App\Support\SchemaBaseline::hasTable('integrations')) {
            DB::table('integrations')->updateOrInsert([
                'user_id' => $userId !== null ? (int) $userId : null,
                'provider' => $provider,
            ], [
                'user_id' => $userId !== null ? (int) $userId : null,
                'provider' => $provider,
                'status' => 'pending',
                'scopes_json' => json_encode(['mock_scope'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'consent_version' => 'v0.1',
                'connected_at' => null,
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'provider' => $provider,
            'state' => $state,
            'mock_url' => "/api/v0.2/integrations/{$provider}/oauth/callback?state={$state}&code=mock_code",
        ]);
    }

    public function oauthCallback(Request $request, string $provider)
    {
        $provider = strtolower(trim($provider));
        if (! $this->isAllowedProvider($provider)) {
            return $this->notFoundResponse();
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $userId = $this->resolveUserId($request);
        $ingestKey = 'igk_' . Str::random(48);
        $ingestKeyHash = hash('sha256', $ingestKey);

        $externalUserId = 'mock_'.substr(sha1($provider.'|'.$state.'|'.$code), 0, 12);
        $scopes = ['mock_scope'];
        $consentVersion = 'v0.1';

        $consent = app(ConsentService::class)->recordConsent($userId, $provider, $consentVersion, $scopes);
        if (($consent['ok'] ?? false) && \App\Support\SchemaBaseline::hasTable('integrations')) {
            DB::table('integrations')
                ->where('user_id', $userId !== null ? (int) $userId : null)
                ->where('provider', $provider)
                ->update([
                    'external_user_id' => $externalUserId,
                    'status' => 'connected',
                    'ingest_key_hash' => $ingestKeyHash,
                    'connected_at' => now(),
                    'updated_at' => now(),
                ]);
        }
        if (($consent['ok'] ?? false) && \App\Support\SchemaBaseline::hasTable('integration_user_bindings') && $userId !== null) {
            DB::table('integration_user_bindings')->updateOrInsert(
                [
                    'provider' => $provider,
                    'external_user_id' => $externalUserId,
                ],
                [
                    'user_id' => (int) $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json([
            'ok' => true,
            'provider' => $provider,
            'state' => $state,
            'code' => $code,
            'external_user_id' => $externalUserId,
            'ingest_key' => $ingestKey,
        ]);
    }

    public function revoke(Request $request, string $provider)
    {
        $provider = strtolower(trim($provider));
        if (! $this->isAllowedProvider($provider)) {
            return $this->notFoundResponse();
        }

        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'missing_identity',
            ], 401);
        }

        $result = app(ConsentService::class)->revoke($userId, $provider);
        $status = (int) ($result['status'] ?? (($result['ok'] ?? false) ? 200 : 400));
        if ($status < 100 || $status > 599) {
            $status = ($result['ok'] ?? false) ? 200 : 400;
        }

        return response()->json([
            'ok' => $result['ok'] ?? false,
            'error_code' => $result['error'] ?? null,
            'message' => $result['message'] ?? null,
            'provider' => $provider,
            'user_id' => $userId,
        ], $status);
    }

    public function ingest(Request $request, string $provider)
    {
        $rawBody = (string) $request->getContent();
        if (strlen($rawBody) > 256 * 1024) {
            return response()->json([
                'ok' => false,
                'error_code' => 'payload_too_large',
                'message' => 'payload_too_large',
            ], 413);
        }

        $provider = strtolower(trim($provider));
        if (! $this->isAllowedProvider($provider)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'meta' => ['nullable', 'array'],
            'meta.range_start' => ['nullable', 'date'],
            'meta.range_end' => ['nullable', 'date'],
            'samples' => ['required', 'array', 'max:100'],
            'samples.*' => ['required', 'array'],
            'samples.*.recorded_at' => ['required', 'string', 'max:64'],
            'samples.*.domain' => ['nullable', 'string', 'max:32'],
            'samples.*.value' => ['required'],
            'samples.*.external_id' => ['nullable', 'string', 'max:128'],
            'samples.*.source' => ['nullable', 'string', 'max:64'],
            'samples.*.confidence' => ['nullable', 'numeric'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $samples = $request->input('samples', []);
            if (! is_array($samples)) {
                return;
            }

            foreach ($samples as $i => $sample) {
                if (! is_array($sample) || ! array_key_exists('value', $sample)) {
                    continue;
                }

                $value = $sample['value'];
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $bytes = is_string($encoded) ? strlen($encoded) : 0;

                if ($bytes > 8192) {
                    $validator->errors()->add("samples.{$i}.value", 'value_json_too_large');
                }

                if ($this->depth($value, 8) > 8) {
                    $validator->errors()->add("samples.{$i}.value", 'value_json_too_deep');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'validation_failed',
                'message' => 'validation_failed',
                'details' => $validator->errors()->toArray(),
            ], 422);
        }

        $payload = $validator->validated();

        $authMode = (string) $request->attributes->get('integration_auth_mode', '');
        $signatureOk = (bool) $request->attributes->get('integration_signature_ok', false);
        $userId = $this->resolveUserId($request);

        if ($userId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'missing_identity',
            ], 401);
        }

        $meta = $payload['meta'] ?? [];
        if (! is_array($meta)) {
            $meta = [];
        }

        $batchMeta = [
            'range_start' => $meta['range_start'] ?? null,
            'range_end' => $meta['range_end'] ?? null,
            'raw_payload_hash' => IdempotencyKey::hashPayload($payload),
        ];

        $samples = $payload['samples'] ?? [];
        if (! is_array($samples)) {
            $samples = [];
        }

        $result = app(IngestionService::class)->ingestSamples(
            $provider,
            $userId,
            $batchMeta,
            $samples,
            [
                'actor_user_id' => $authMode === 'sanctum'
                    ? (int) ($request->attributes->get('integration_actor_user_id') ?? $userId)
                    : null,
                'auth_mode' => $authMode,
                'signature_ok' => $signatureOk,
                'source_ip' => (string) $request->ip(),
            ]
        );

        return response()->json($result);
    }

    public function replay(Request $request, string $provider, string $batch_id)
    {
        $provider = strtolower(trim($provider));
        if (! $this->isAllowedProvider($provider)) {
            return $this->notFoundResponse();
        }

        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'missing_identity',
            ], 401);
        }

        $result = app(ReplayService::class)->replay(
            $provider,
            $batch_id,
            (int) $userId,
            $this->resolveOrgRole($request)
        );

        $status = (int) ($result['status'] ?? 200);
        if ($status < 100 || $status > 599) {
            $status = 200;
        }
        unset($result['status']);

        return response()->json($result, $status);
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

    private function depth($value, int $limit, int $level = 0): int
    {
        if (! is_array($value)) {
            return $level;
        }

        if ($level > $limit) {
            return $level;
        }

        $max = $level;
        foreach ($value as $child) {
            $max = max($max, $this->depth($child, $limit, $level + 1));
            if ($max > $limit) {
                return $max;
            }
        }

        return $max;
    }

    private function resolveUserId(Request $request): ?string
    {
        $uid = $request->attributes->get('fm_user_id');
        if (is_string($uid)) {
            $uid = trim($uid);
            if ($uid !== '' && preg_match('/^\d+$/', $uid)) {
                return $uid;
            }
        }

        $uid2 = $request->attributes->get('user_id');
        if (is_string($uid2)) {
            $uid2 = trim($uid2);
            if ($uid2 !== '' && preg_match('/^\d+$/', $uid2)) {
                return $uid2;
            }
        }

        return null;
    }

    private function resolveOrgRole(Request $request): string
    {
        $role = trim((string) $request->attributes->get('org_role', ''));

        return $role !== '' ? strtolower($role) : 'public';
    }
}
