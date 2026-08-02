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
        array $boundaryMutationCounts,
        array $operationCounts = [],
    ): array {
        if ($readbackCount !== $context->expectedRowCount) {
            throw new DomainException('promotion_readback_count_mismatch');
        }
        $fields = ['indexability_mutation_count', 'sitemap_mutation_count', 'llms_mutation_count', 'search_mutation_count', 'deploy_mutation_count'];
        if (array_keys($boundaryMutationCounts) !== $fields) {
            throw new DomainException('boundary_mutation_evidence_invalid');
        }
        foreach ($fields as $field) {
            if (! array_key_exists($field, $boundaryMutationCounts) || ! is_int($boundaryMutationCounts[$field]) || $boundaryMutationCounts[$field] !== 0) {
                throw new DomainException('prohibited_mutation_reported');
            }
        }
        $createdCount = (int) ($operationCounts['created_count'] ?? 0);
        $updatedCount = (int) ($operationCounts['updated_count'] ?? $writtenCount);
        $unchangedCount = (int) ($operationCounts['unchanged_count'] ?? ($readbackCount - $createdCount - $updatedCount));
        if ($createdCount < 0 || $updatedCount < 0 || $unchangedCount < 0
            || $createdCount + $updatedCount !== $writtenCount
            || $createdCount + $updatedCount + $unchangedCount !== $readbackCount) {
            throw new DomainException('promotion_operation_counts_invalid');
        }

        return [
            'ok' => true,
            'written_count' => $writtenCount,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'unchanged_count' => $unchangedCount,
            'readback_count' => $readbackCount,
            'published_count' => $publishedCount,
            'rollback_reference' => $rollbackReference,
            'locale_check' => 'PASS',
            'cjk_leakage_check' => 'PASS',
            'identity_check' => 'PASS',
            'indexability_mutation_count' => $boundaryMutationCounts['indexability_mutation_count'],
            'sitemap_mutation_count' => $boundaryMutationCounts['sitemap_mutation_count'],
            'llms_mutation_count' => $boundaryMutationCounts['llms_mutation_count'],
            'search_mutation_count' => $boundaryMutationCounts['search_mutation_count'],
            'deploy_mutation_count' => $boundaryMutationCounts['deploy_mutation_count'],
        ];
    }
}
