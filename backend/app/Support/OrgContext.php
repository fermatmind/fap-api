<?php

namespace App\Support;

use App\Exceptions\OrgContextMissingException;

final class OrgContext
{
    public const KIND_PUBLIC = 'public';

    public const KIND_TENANT = 'tenant';

    private int $orgId = 0;

    private ?int $userId = null;

    private ?string $role = null;

    private ?string $anonId = null;

    private string $contextKind = self::KIND_PUBLIC;

    public function set(int $orgId, ?int $userId, ?string $role, ?string $anonId = null, ?string $contextKind = null): void
    {
        $this->orgId = $orgId;
        $this->userId = $userId;
        $this->role = $role;
        $this->anonId = $anonId !== null && trim($anonId) !== '' ? trim($anonId) : null;
        $this->contextKind = self::normalizeContextKind($contextKind, $orgId);
    }

    public function orgId(): int
    {
        if ($this->orgId > 0) {
            return $this->orgId;
        }

        $fromRequest = $this->resolveOrgIdFromRequest();
        if ($fromRequest !== null) {
            return $fromRequest;
        }

        return $this->orgId;
    }

    public function requirePositiveOrgId(): int
    {
        $orgId = $this->orgId();
        if ($orgId <= 0) {
            throw new OrgContextMissingException;
        }

        return $orgId;
    }

    public function scopedOrgId(): int
    {
        return $this->isTenantContext() ? $this->requirePositiveOrgId() : 0;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function role(): ?string
    {
        return $this->role;
    }

    public function anonId(): ?string
    {
        return $this->anonId;
    }

    public function contextKind(): string
    {
        return $this->contextKind;
    }

    public function isPublicContext(): bool
    {
        return $this->contextKind === self::KIND_PUBLIC;
    }

    public function isTenantContext(): bool
    {
        return $this->contextKind === self::KIND_TENANT;
    }

    public static function deriveContextKind(int $orgId, ?string $preferred = null): string
    {
        return self::normalizeContextKind($preferred, $orgId);
    }

    private function resolveOrgIdFromRequest(): ?int
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        $candidates = [
            $request->attributes->get('org_id'),
            $request->attributes->get('fm_org_id'),
            $request->header('X-FM-Org-Id'),
            $request->header('X-Org-Id'),
            $request->query('org_id'),
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->normalizeOrgId($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function normalizeOrgId(mixed $candidate): ?int
    {
        if (! is_int($candidate) && ! is_string($candidate) && ! is_numeric($candidate)) {
            return null;
        }

        $raw = trim((string) $candidate);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private static function normalizeContextKind(?string $contextKind, int $orgId): string
    {
        $normalized = strtolower(trim((string) $contextKind));
        if (in_array($normalized, [self::KIND_PUBLIC, self::KIND_TENANT], true)) {
            return $normalized;
        }

        return $orgId > 0 ? self::KIND_TENANT : self::KIND_PUBLIC;
    }
}
