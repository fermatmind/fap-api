<?php

namespace App\Services\Org;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MembershipService
{
    private const ALLOWED_ROLES = ['owner', 'admin', 'member', 'viewer'];

    public function getRole(int $orgId, int $userId): ?string
    {
        if (!\App\Support\SchemaBaseline::hasTable('organization_members')) {
            return null;
        }

        $role = DB::table('organization_members')
            ->where('org_id', $orgId)
            ->where('user_id', $userId)
            ->value('role');

        $role = is_string($role) ? trim($role) : '';
        return $role !== '' ? $role : null;
    }

    public function requireMember(int $orgId, int $userId): ?string
    {
        return $this->getRole($orgId, $userId);
    }

    public function addMember(int $orgId, int $userId, string $role): void
    {
        $role = trim($role);
        if ($role === '' || !in_array($role, self::ALLOWED_ROLES, true)) {
            $role = 'member';
        }

        $now = now();
        $existing = DB::table('organization_members')
            ->where('org_id', $orgId)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            DB::table('organization_members')
                ->where('org_id', $orgId)
                ->where('user_id', $userId)
                ->update([
                    'role' => $role,
                    'updated_at' => $now,
                ]);
            return;
        }

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
