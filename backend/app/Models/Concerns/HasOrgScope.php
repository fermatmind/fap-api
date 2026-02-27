<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use Illuminate\Http\Request;

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

    protected static function allowOrgZeroWithResolvedContext(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        $request = request();
        if (! $request instanceof Request) {
            return false;
        }

        if (self::truthy($request->attributes->get('org_context_resolved'))) {
            return true;
        }

        return self::truthy($request->attributes->get('org_context_bypass'));
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
