<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

use RuntimeException;

final class EnneagramAssetMergeResolver
{
    public function __construct(
        private readonly EnneagramAssetMergePolicyValidator $mergePolicyValidator,
    ) {}

    /**
     * @param  array{metadata?:array<string,mixed>,items?:list<array<string,mixed>>}  $batchA
     * @param  array{metadata?:array<string,mixed>,items?:list<array<string,mixed>>}  $batchB
     * @return array<string,mixed>
     */
    public function resolve(array $batchA, array $batchB): array
    {
        $errors = $this->mergePolicyValidator->validatePair($batchA, $batchB);
        if ($errors !== []) {
            throw new RuntimeException('ENNEAGRAM asset merge policy invalid: '.implode(' | ', $errors));
        }

        $items = [];
        foreach ((array) ($batchA['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = array_merge($item, ['_preview_batch' => '1R-A']);
            }
        }
        foreach ((array) ($batchB['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = array_merge($item, ['_preview_batch' => '1R-B']);
            }
        }

        return [
            'schema_version' => 'enneagram.asset_preview.merge.v1',
            'mode' => 'staging_preview_only',
            'production_import_allowed' => false,
            'full_replacement_allowed' => false,
            'source_versions' => [
                'batch_1r_a' => (string) data_get($batchA, 'metadata.version', ''),
                'batch_1r_b' => (string) data_get($batchB, 'metadata.version', ''),
            ],
            'replacement_coverage' => [
                'batch_1r_a_replaces' => EnneagramAssetMergePolicyValidator::BATCH_A_REPLACE_CATEGORIES,
                'batch_1r_a_adds' => array_values(array_diff(
                    EnneagramAssetMergePolicyValidator::BATCH_A_CATEGORIES,
                    EnneagramAssetMergePolicyValidator::BATCH_A_REPLACE_CATEGORIES
                )),
                'batch_1r_b_replaces' => EnneagramAssetMergePolicyValidator::BATCH_B_DEEP_CORE_CATEGORIES,
            ],
            'items' => $items,
        ];
    }
}
