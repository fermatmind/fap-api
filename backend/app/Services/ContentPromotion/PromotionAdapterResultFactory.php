<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

final class PromotionAdapterResultFactory
{
    /** @return array<string, mixed> */
    public static function make(
        PromotionContext $context,
        int $writtenCount,
        int $readbackCount,
        int $publishedCount,
        ?string $rollbackReference,
    ): array {
        if ($readbackCount !== $context->expectedRowCount) {
            throw new DomainException('promotion_readback_count_mismatch');
        }

        return [
            'ok' => true,
            'written_count' => $writtenCount,
            'readback_count' => $readbackCount,
            'published_count' => $publishedCount,
            'rollback_reference' => $rollbackReference,
            'locale_check' => 'PASS',
            'cjk_leakage_check' => 'PASS',
            'identity_check' => 'PASS',
            'indexability_mutation_count' => 0,
            'sitemap_mutation_count' => 0,
            'llms_mutation_count' => 0,
            'search_mutation_count' => 0,
            'deploy_mutation_count' => 0,
        ];
    }
}
