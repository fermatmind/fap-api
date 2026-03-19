<?php

declare(strict_types=1);

namespace App\Repositories\Report;

final readonly class ReportAccessActor
{
    public function __construct(
        public ?string $userId,
        public ?string $anonId,
        public ?string $role,
    ) {}

    public static function from(?string $userId, ?string $anonId, ?string $role): self
    {
        return new self(
            self::normalizeNumericString($userId),
            self::normalizeString($anonId),
            self::normalizeRole($role),
        );
    }

    public function isPrivilegedTenantRole(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function isMemberLikeTenantRole(): bool
    {
        return in_array($this->role, ['member', 'viewer'], true);
    }

    private static function normalizeNumericString(?string $value): ?string
    {
        $normalized = self::normalizeString($value);
        if ($normalized === null || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private static function normalizeRole(?string $value): ?string
    {
        $normalized = self::normalizeString($value);

        return $normalized !== null ? strtolower($normalized) : null;
    }

    private static function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
