<?php

namespace App\Services\Auth;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IdentityService
{
    /**
     * Bind provider_uid to user_id (no silent rebind).
     *
     * @return array {ok:bool, status?:int, error?:string, message?:string, identity?:array}
     */
    public function bind(string $userId, string $provider, string $providerUid, array $meta = []): array
    {
        $userId = trim($userId);
        $provider = strtolower(trim($provider));
        $providerUid = trim($providerUid);

        if ($userId === '' || $provider === '' || $providerUid === '') {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'INVALID_INPUT',
                'message' => 'provider/provider_uid invalid.',
            ];
        }

        try {
            $existing = DB::table('identities')
                ->where('provider', $provider)
                ->where('provider_uid', $providerUid)
                ->first();
        } catch (QueryException) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'TABLE_MISSING',
                'message' => 'identities table missing.',
            ];
        }

        if ($existing) {
            $existingUser = (string) ($existing->user_id ?? '');
            if ($existingUser !== $userId) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'IDENTITY_CONFLICT',
                    'message' => 'provider_uid already linked to another user.',
                ];
            }

            return [
                'ok' => true,
                'status' => 200,
                'identity' => $this->presentRow($existing),
            ];
        }

        $payload = null;
        if (!empty($meta)) {
            $payload = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $row = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'provider' => $provider,
            'provider_uid' => $providerUid,
            'linked_at' => now(),
            'meta_json' => $payload,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('identities')->insert($row);
        } catch (\Throwable $e) {
            $conflict = DB::table('identities')
                ->where('provider', $provider)
                ->where('provider_uid', $providerUid)
                ->first();

            if ($conflict && (string) ($conflict->user_id ?? '') !== $userId) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'IDENTITY_CONFLICT',
                    'message' => 'provider_uid already linked to another user.',
                ];
            }

            return [
                'ok' => false,
                'status' => 500,
                'error' => 'IDENTITY_BIND_FAILED',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'status' => 201,
            'identity' => $this->presentRow((object) $row),
        ];
    }

    public function resolveUserId(string $provider, string $providerUid): ?string
    {
        $provider = strtolower(trim($provider));
        $providerUid = trim($providerUid);
        if ($provider === '' || $providerUid === '') return null;

        try {
            $row = DB::table('identities')
                ->where('provider', $provider)
                ->where('provider_uid', $providerUid)
                ->first();
        } catch (QueryException) {
            return null;
        }

        if (!$row) return null;

        $uid = (string) ($row->user_id ?? '');
        return $uid !== '' ? $uid : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByUserId(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') return [];

        try {
            $rows = DB::table('identities')
                ->where('user_id', $userId)
                ->orderByDesc('linked_at')
                ->get();
        } catch (QueryException) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->presentRow($row);
        }

        return $items;
    }

    private function presentRow(object $row): array
    {
        $meta = null;
        if (property_exists($row, 'meta_json') && $row->meta_json) {
            $decoded = is_string($row->meta_json)
                ? json_decode($row->meta_json, true)
                : $row->meta_json;
            $meta = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (string) ($row->id ?? ''),
            'user_id' => (string) ($row->user_id ?? ''),
            'provider' => (string) ($row->provider ?? ''),
            'provider_uid' => (string) ($row->provider_uid ?? ''),
            'linked_at' => (string) ($row->linked_at ?? ''),
            'meta' => $meta,
        ];
    }
}
