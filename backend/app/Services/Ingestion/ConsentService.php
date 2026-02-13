<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConsentService
{
    public function recordConsent(?string $userId, string $provider, string $consentVersion, array $scopes): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('integrations')) {
            return [
                'ok' => false,
                'error' => 'MISSING_TABLE',
                'message' => 'integrations table not found',
            ];
        }

        $now = now();
        $payload = [
            'user_id' => $userId !== null ? (int) $userId : null,
            'provider' => $provider,
            'status' => 'connected',
            'scopes_json' => json_encode($scopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'consent_version' => $consentVersion,
            'connected_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];

        $existing = DB::table('integrations')
            ->where('user_id', $payload['user_id'])
            ->where('provider', $provider)
            ->first();

        if ($existing) {
            DB::table('integrations')
                ->where('user_id', $payload['user_id'])
                ->where('provider', $provider)
                ->update($payload);
        } else {
            DB::table('integrations')->insert($payload);
        }

        return [
            'ok' => true,
            'user_id' => $payload['user_id'] ?? null,
            'provider' => $provider,
            'consent_version' => $consentVersion,
        ];
    }

    public function revoke(?string $userId, string $provider): array
    {
        $userId = is_string($userId) ? trim($userId) : null;
        if ($userId === null || $userId === '') {
            return [
                'ok' => false,
                'status' => 401,
                'error' => 'UNAUTHORIZED',
                'message' => 'missing_identity',
            ];
        }

        if (!\App\Support\SchemaBaseline::hasTable('integrations')) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'MISSING_TABLE',
                'message' => 'integrations table not found',
            ];
        }

        $now = now();
        DB::table('integrations')
            ->where('user_id', $userId !== null ? (int) $userId : null)
            ->where('provider', $provider)
            ->update([
                'status' => 'revoked',
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        return [
            'ok' => true,
            'status' => 200,
            'user_id' => $userId !== null ? (int) $userId : null,
            'provider' => $provider,
        ];
    }
}
