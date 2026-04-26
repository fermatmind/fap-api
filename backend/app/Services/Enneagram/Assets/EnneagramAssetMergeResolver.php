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
        return $this->resolveStreams($batchA, $batchB);
    }

    /**
     * @param  array<int,array{metadata?:array<string,mixed>,items?:list<array<string,mixed>>}>  $streams
     * @return array<string,mixed>
     */
    public function resolveStreams(array ...$streams): array
    {
        $errors = $this->mergePolicyValidator->validateStreams(...$streams);
        if ($errors !== []) {
            throw new RuntimeException('ENNEAGRAM asset merge policy invalid: '.implode(' | ', $errors));
        }

        $items = [];
        $sourceVersions = [];
        foreach ($streams as $stream) {
            $batch = $this->detectBatchKey($stream);
            if ($batch === '') {
                continue;
            }
            $sourceVersions[$this->sourceVersionKey($batch)] = (string) data_get($stream, 'metadata.version', '');

            foreach ((array) ($stream['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = array_merge($item, ['_preview_batch' => $batch]);
                }
            }
        }

        ksort($sourceVersions);

        return [
            'schema_version' => 'enneagram.asset_preview.merge.v1',
            'mode' => 'staging_preview_only',
            'production_import_allowed' => false,
            'full_replacement_allowed' => false,
            'source_versions' => $sourceVersions,
            'replacement_coverage' => [
                'batch_1r_a_replaces' => EnneagramAssetMergePolicyValidator::BATCH_A_REPLACE_CATEGORIES,
                'batch_1r_a_adds' => array_values(array_diff(
                    EnneagramAssetMergePolicyValidator::BATCH_A_CATEGORIES,
                    EnneagramAssetMergePolicyValidator::BATCH_A_REPLACE_CATEGORIES
                )),
                'batch_1r_b_replaces' => EnneagramAssetMergePolicyValidator::BATCH_B_DEEP_CORE_CATEGORIES,
                'batch_1r_c_adds' => EnneagramAssetMergePolicyValidator::BATCH_C_CATEGORIES,
                'batch_1r_d_adds' => EnneagramAssetMergePolicyValidator::BATCH_D_CATEGORIES,
                'batch_1r_e_adds' => EnneagramAssetMergePolicyValidator::BATCH_E_CATEGORIES,
                'batch_1r_f_adds' => EnneagramAssetMergePolicyValidator::BATCH_F_CATEGORIES,
            ],
            'items' => $items,
        ];
    }

    /**
     * @param  array{metadata?:array<string,mixed>,items?:list<array<string,mixed>>}  $stream
     */
    private function detectBatchKey(array $stream): string
    {
        $metadata = (array) data_get($stream, 'metadata', []);
        $version = (string) ($metadata['version'] ?? '');
        $categories = array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['category'] ?? '')),
            (array) ($stream['items'] ?? [])
        ))));

        $batch = $this->mergePolicyValidator->detectBatchKey($metadata, (array) ($stream['items'] ?? []));
        if ($batch !== '') {
            return $batch;
        }

        foreach ((array) ($stream['items'] ?? []) as $item) {
            if (is_array($item) && trim((string) ($item['objection_axis'] ?? '')) !== '') {
                return '1R-C';
            }
            if (is_array($item) && trim((string) ($item['partial_axis'] ?? '')) !== '') {
                return '1R-D';
            }
            if (is_array($item) && trim((string) ($item['diffuse_axis'] ?? '')) !== '') {
                return '1R-E';
            }
            if (is_array($item) && trim((string) ($item['pair_key'] ?? '')) !== '' && trim((string) ($item['canonical_pair_key'] ?? '')) !== '') {
                return '1R-F';
            }
        }

        if ($categories === EnneagramAssetMergePolicyValidator::BATCH_C_CATEGORIES) {
            return '1R-C';
        }
        if ($categories === EnneagramAssetMergePolicyValidator::BATCH_D_CATEGORIES) {
            return '1R-D';
        }
        if ($categories === EnneagramAssetMergePolicyValidator::BATCH_E_CATEGORIES) {
            return '1R-E';
        }
        if ($categories === EnneagramAssetMergePolicyValidator::BATCH_F_CATEGORIES) {
            return '1R-F';
        }

        return '';
    }

    private function sourceVersionKey(string $batch): string
    {
        return match ($batch) {
            '1R-A' => 'batch_1r_a',
            '1R-B' => 'batch_1r_b',
            '1R-C' => 'batch_1r_c',
            '1R-D' => 'batch_1r_d',
            '1R-E' => 'batch_1r_e',
            '1R-F' => 'batch_1r_f',
            default => strtolower(str_replace('-', '_', $batch)),
        };
    }
}
