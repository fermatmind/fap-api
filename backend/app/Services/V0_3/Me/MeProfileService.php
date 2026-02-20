<?php

namespace App\Services\V0_3\Me;

use App\Support\OrgContext;

class MeProfileService
{
    public function __construct(private readonly OrgContext $orgContext)
    {
    }

    public function profile(): array
    {
        $role = strtolower(trim((string) ($this->orgContext->role() ?? '')));

        return [
            'org_id' => (int) $this->orgContext->orgId(),
            'anon_id' => (string) ($this->orgContext->anonId() ?? ''),
            'user_id' => $this->orgContext->userId() !== null ? (string) $this->orgContext->userId() : '',
            'is_admin' => in_array($role, ['admin', 'owner'], true),
            'roles' => $role !== '' ? [$role] : [],
        ];
    }
}
