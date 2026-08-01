<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Contracts;

use App\Services\ContentPromotion\PromotionContext;

interface ExactPackagePromotionAdapter
{
    public function id(): string;

    public function supports(string $lane, ?string $subscope): bool;

    /** @return array<string, mixed> */
    public function preflight(PromotionContext $context): array;

    /** @return array<string, mixed> */
    public function draftImport(PromotionContext $context): array;

    /** @return array<string, mixed> */
    public function publish(PromotionContext $context): array;

    /** @return array<string, mixed> */
    public function liveQa(PromotionContext $context): array;

    public function rollback(PromotionContext $context, string $rollbackReference): void;
}
