<?php

namespace App\Support;

final class OrgContext
{
    private int $orgId = 0;
    private ?int $userId = null;
    private ?string $role = null;

    public function set(int $orgId, ?int $userId, ?string $role): void
    {
        $this->orgId = $orgId;
        $this->userId = $userId;
        $this->role = $role;
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
}
