<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Ingestion\ConsentService;
use App\Services\Ingestion\IngestionService;
use App\Services\Ingestion\ReplayService;
use App\Support\Idempotency\IdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProvidersController extends Controller
{
    public function oauthStart(Request $request, string $provider)
    {
        $state = (string) Str::uuid();
        $userId = $this->resolveUserId($request);

        if (Schema::hasTable('integrations')) {
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
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $userId = $this->resolveUserId($request);

        $externalUserId = 'mock_' . substr(sha1($provider . '|' . $state . '|' . $code), 0, 12);
        $scopes = ['mock_scope'];
        $consentVersion = 'v0.1';

        $consent = app(ConsentService::class)->recordConsent($userId, $provider, $consentVersion, $scopes);
        if (($consent['ok'] ?? false) && Schema::hasTable('integrations')) {
            DB::table('integrations')
                ->where('user_id', $userId !== null ? (int) $userId : null)
                ->where('provider', $provider)
                ->update([
                    'external_user_id' => $externalUserId,
                    'status' => 'connected',
                    'connected_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'ok' => true,
            'provider' => $provider,
            'state' => $state,
            'code' => $code,
            'external_user_id' => $externalUserId,
        ]);
    }

    public function revoke(Request $request, string $provider)
    {
        $userId = $this->resolveUserId($request);
        $result = app(ConsentService::class)->revoke($userId, $provider);

        return response()->json([
            'ok' => $result['ok'] ?? false,
            'provider' => $provider,
            'user_id' => $userId,
        ]);
    }

    public function ingest(Request $request, string $provider)
    {
        $userId = $this->resolveUserId($request);
        $payload = $request->all();
        if ($userId === null) {
            $payloadUserId = (string) ($payload['user_id'] ?? '');
            if ($payloadUserId !== '' && preg_match('/^\d+$/', $payloadUserId)) {
                $userId = $payloadUserId;
            }
        }

        $batchMeta = [
            'range_start' => $payload['range_start'] ?? null,
            'range_end' => $payload['range_end'] ?? null,
            'raw_payload_hash' => IdempotencyKey::hashPayload($payload),
        ];

        $samples = $payload['samples'] ?? [];
        if (!is_array($samples)) {
            $samples = [];
        }

        $result = app(IngestionService::class)->ingestSamples($provider, $userId, $batchMeta, $samples);

        return response()->json($result);
    }

    public function replay(Request $request, string $provider, string $batch_id)
    {
        $result = app(ReplayService::class)->replay($provider, $batch_id);
        return response()->json($result);
    }

    private function resolveUserId(Request $request): ?string
    {
        $uid = $request->attributes->get('fm_user_id');
        if (is_string($uid)) {
            $uid = trim($uid);
            if ($uid !== '' && preg_match('/^\d+$/', $uid)) return $uid;
        }

        $uid2 = $request->attributes->get('user_id');
        if (is_string($uid2)) {
            $uid2 = trim($uid2);
            if ($uid2 !== '' && preg_match('/^\d+$/', $uid2)) return $uid2;
        }

        return null;
    }
}
