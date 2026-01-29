<?php

namespace App\Support;

final class OrgContext
{
    private int $orgId = 0;
    private ?int $userId = null;
    private ?string $role = null;
    private ?string $anonId = null;

    public function set(int $orgId, ?int $userId, ?string $role, ?string $anonId = null): void
    {
        $this->orgId = $orgId;
        $this->userId = $userId;
        $this->role = $role;
        $this->anonId = $anonId !== null && trim($anonId) !== '' ? trim($anonId) : null;
    }

    public function orgId(): int
    {
        return $this->orgId;
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
}
