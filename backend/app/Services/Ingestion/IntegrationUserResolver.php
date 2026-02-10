<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntegrationUserResolver
{
    public function resolveInternalUserId(string $provider, string $externalUserId): ?int
    {
        $provider = strtolower(trim($provider));
        $externalUserId = trim($externalUserId);
        if ($provider === '' || $externalUserId === '') {
            return null;
        }

        if (!Schema::hasTable('integration_user_bindings')) {
            return null;
        }

        $row = DB::table('integration_user_bindings')
            ->select(['user_id'])
            ->where('provider', $provider)
            ->where('external_user_id', $externalUserId)
            ->first();

        if (!$row || !is_numeric($row->user_id ?? null)) {
            return null;
        }

        return (int) $row->user_id;
    }
}
