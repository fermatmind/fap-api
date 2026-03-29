<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use Illuminate\Http\Request;

trait HasOrgScope
{
    protected static function bootHasOrgScope(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public static function bypassTenantScope(): bool
    {
        return false;
    }

    public static function publicContextOrgId(): ?int
    {
        return null;
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
        return self::resolvedPublicContextOrgId() === 0;
    }

    protected static function resolvedPublicContextOrgId(int $orgId = 0): ?int
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();
        if (! $request instanceof Request) {
            return null;
        }

        $contextResolved = self::truthy($request->attributes->get('org_context_resolved'));
        $contextBypass = self::truthy($request->attributes->get('org_context_bypass'));
        if (! $contextResolved && ! $contextBypass) {
            return null;
        }

        $contextKind = strtolower(trim((string) $request->attributes->get('org_context_kind', '')));
        if ($contextKind !== '' && $contextKind !== 'public') {
            return null;
        }

        return $orgId;
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
