<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Support\Facades\DB;

class TenantUser extends User implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'tenant') {
            return false;
        }

        $userId = is_numeric($this->getAuthIdentifier()) ? (int) $this->getAuthIdentifier() : 0;
        if ($userId <= 0) {
            return false;
        }

        $activeOrgCount = DB::table('organization_members')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->distinct()
            ->count('org_id');

        return $activeOrgCount === 1;
    }
}
