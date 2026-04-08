<?php

declare(strict_types=1);

namespace App\Services\Career\Import;

use App\Domain\Career\Import\FirstWaveEligibilityPolicy;
use App\Models\CareerImportRun;
use Illuminate\Support\Arr;
use Throwable;

final class CareerAuthorityWaveImporter
{
    public function __construct(
        private readonly CareerAuthorityRowNormalizer $normalizer,
        private readonly FirstWaveEligibilityPolicy $eligibilityPolicy,
        private readonly CareerAuthorityMaterializer $materializer,
    ) {}

    /**
     * @param  array{
     *   dataset_name: string,
     *   dataset_version: ?string,
     *   dataset_checksum: string,
     *   source_path: string,
     *   rows: list<array<string, mixed>>,
     *   manifest: array<string, mixed>
     * }  $dataset
     * @param  list<string>  $allowedModes
     * @return array<string, mixed>
     */
    public function import(
        CareerImportRun $run,
        array $dataset,
        array $allowedModes,
    ): array {
        $summary = [
            'rows_seen' => 0,
            'rows_accepted' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'output_counts' => [],
            'errors' => [],
            'dataset' => Arr::except($dataset, ['rows']),
        ];

        foreach ($dataset['rows'] as $row) {
            $summary['rows_seen']++;
            $normalized = $this->normalizer->normalize($row, $dataset['manifest']);
            $eligibility = $this->eligibilityPolicy->evaluate($normalized, $allowedModes);

            if (! $eligibility['accepted']) {
                $summary['rows_skipped']++;
                $summary['errors'][] = [
                    'row' => $normalized['row_number'] ?? null,
                    'slug' => $normalized['canonical_slug'] ?? null,
                    'reasons' => $eligibility['reasons'],
                    'type' => 'skipped',
                ];

                continue;
            }

            if ($run->dry_run) {
                $summary['rows_accepted']++;

                continue;
            }

            try {
                $materialized = $this->materializer->materializeImportRow($normalized, $run);
                $summary['rows_accepted']++;
                foreach ((array) ($materialized['counts'] ?? []) as $key => $count) {
                    $summary['output_counts'][$key] = ($summary['output_counts'][$key] ?? 0) + (int) $count;
                }
            } catch (Throwable $throwable) {
                $summary['rows_failed']++;
                $summary['errors'][] = [
                    'row' => $normalized['row_number'] ?? null,
                    'slug' => $normalized['canonical_slug'] ?? null,
                    'reasons' => [$throwable->getMessage()],
                    'type' => 'failed',
                ];
            }
        }

        return $summary;
    }
}
