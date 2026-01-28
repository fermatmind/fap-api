<?php

namespace App\Services\Agent\Policies;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ConsentPolicy
{
    public function check(int $userId): array
    {
        if (!Schema::hasTable('integrations')) {
            return [
                'ok' => false,
                'allowed' => false,
                'reason' => 'integrations_table_missing',
            ];
        }

        $row = DB::table('integrations')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();

        if (!$row || empty($row->consent_version)) {
            return [
                'ok' => true,
                'allowed' => false,
                'reason' => 'consent_missing',
            ];
        }

        return [
            'ok' => true,
            'allowed' => true,
            'consent_version' => (string) $row->consent_version,
        ];
    }
}
