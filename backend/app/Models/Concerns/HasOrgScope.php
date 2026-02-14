<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;

trait HasOrgScope
{
    protected static function bootHasOrgScope(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public static function bypassTenantScope(): bool
    {
        return false;
    }

    public static function allowOrgZeroContext(): bool
    {
        return false;
    }

    public static function failClosedWhenOrgMissing(): bool
    {
        return true;
    }
}
