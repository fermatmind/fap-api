<?php

declare(strict_types=1);

namespace App\DTO\Commerce;

final readonly class PurchaseActor
{
    public function __construct(
        public int $orgId,
        public ?string $userId = null,
        public ?string $anonId = null,
        public ?string $guestAnonId = null,
    ) {}
}
