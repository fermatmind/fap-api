<?php

namespace App\Services\Org;

use Illuminate\Support\Facades\DB;

final class OrganizationService
{
    public function __construct(private MembershipService $memberships)
    {
    }

    public function createOrg(string $name, int $ownerUserId): int
    {
        $name = trim($name);

        return (int) DB::transaction(function () use ($name, $ownerUserId) {
            $now = now();
            $orgId = DB::table('organizations')->insertGetId([
                'name' => $name,
                'owner_user_id' => $ownerUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->memberships->addMember((int) $orgId, $ownerUserId, 'owner');

            return $orgId;
        });
    }
}
