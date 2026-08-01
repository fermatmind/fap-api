<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\PromotionContext;
use DomainException;

final readonly class LegacyAuditIncompatiblePromotionAdapter implements ExactPackagePromotionAdapter
{
    public function __construct(
        private string $adapterId,
        private string $lane,
        private string $subscope,
    ) {}

    public function id(): string
    {
        return $this->adapterId;
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === $this->lane && $subscope === $this->subscope;
    }

    public function preflight(PromotionContext $context): array
    {
        $this->fail();
    }

    public function draftImport(PromotionContext $context): array
    {
        $this->fail();
    }

    public function publish(PromotionContext $context): array
    {
        $this->fail();
    }

    public function liveQa(PromotionContext $context): array
    {
        $this->fail();
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $this->fail();
    }

    private function fail(): never
    {
        throw new DomainException('adapter_audit_metadata_incompatible');
    }
}
