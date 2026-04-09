<?php

declare(strict_types=1);

namespace App\Services\Career\Import;

use App\Domain\Career\Import\ImportScopeMode;
use App\Domain\Career\Import\RunStatus;
use App\Domain\Career\Publish\FirstWaveManifestReader;
use App\Domain\Career\Publish\FirstWavePublishSeedMaterializer;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Models\OccupationTruthMetric;
use App\Models\TrustManifest;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Support\Str;
use Throwable;

final class FirstWaveAuthorityMaterializationService
{
    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
        private readonly CareerAuthorityDatasetReader $datasetReader,
        private readonly CareerAuthorityWaveImporter $importer,
        private readonly FirstWavePublishSeedMaterializer $publishSeedMaterializer,
        private readonly CareerAuthorityMaterializer $materializer,
        private readonly FirstWaveAuthorityRepairService $repairService,
    ) {}

    /**
     * @return array{
     *   import_run_id:?string,
     *   compile_run_id:?string,
     *   imported_slugs:list<string>,
     *   issues_by_slug:array<string,list<string>>
     * }
     */
    public function materialize(string $sourcePath, bool $compileMissing = false, bool $repairSafePartials = false): array
    {
        $manifest = $this->manifestReader->read();
        $manifestOccupations = is_array($manifest['occupations']) ? $manifest['occupations'] : [];
        $manifestBySlug = [];
        foreach ($manifestOccupations as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            $manifestBySlug[(string) $occupation['canonical_slug']] = $occupation;
        }

        $dataset = $this->datasetReader->read($sourcePath);
        $rowsBySlug = [];
        foreach ($dataset['rows'] as $row) {
            $slug = trim((string) ($row['Slug'] ?? ''));
            if ($slug === '' || ! isset($manifestBySlug[$slug]) || isset($rowsBySlug[$slug])) {
                continue;
            }

            $rowsBySlug[$slug] = $row;
        }

        $issuesBySlug = [];
        $familyMetaByUuid = $this->familyMetadata($manifestOccupations, $rowsBySlug);
        $rowsToImport = [];
        $importManifestOccupations = [];

        foreach ($manifestOccupations as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            $slug = (string) $occupation['canonical_slug'];
            $row = $rowsBySlug[$slug] ?? null;
            if (! is_array($row)) {
                $issuesBySlug[$slug][] = 'source_row_missing';

                continue;
            }

            $existingOccupation = Occupation::query()->where('canonical_slug', $slug)->first();
            if ($existingOccupation instanceof Occupation && $existingOccupation->id !== (string) $occupation['occupation_uuid']) {
                if (! $repairSafePartials) {
                    $issuesBySlug[$slug][] = 'occupation_uuid_conflict';

                    continue;
                }

                $repair = $this->repairService->repair($occupation, $row);
                foreach ($repair['issues'] as $issue) {
                    $issuesBySlug[$slug][] = $issue;
                }

                if (! $repair['repaired']) {
                    continue;
                }
            }

            $familyMeta = $familyMetaByUuid[(string) $occupation['family_uuid']] ?? null;
            if ($familyMeta !== null) {
                $existingFamily = OccupationFamily::query()
                    ->where('canonical_slug', (string) $familyMeta['family_slug'])
                    ->first();
                if ($existingFamily instanceof OccupationFamily && $existingFamily->id !== (string) $occupation['family_uuid']) {
                    $issuesBySlug[$slug][] = 'family_uuid_conflict';

                    continue;
                }
            }

            $rowsToImport[] = $row;
            $importManifestOccupations[$slug] = [
                'occupation_uuid' => (string) $occupation['occupation_uuid'],
                'family_uuid' => (string) $occupation['family_uuid'],
                'canonical_title_zh' => $occupation['canonical_title_zh'] ?? null,
                'mapping_mode' => (string) $occupation['crosswalk_mode'],
                'truth_market' => 'US',
                'display_market' => 'US',
                'family_slug' => $familyMeta['family_slug'] ?? Str::slug((string) ($row['Category'] ?? 'career-first-wave')),
                'family_title_en' => $familyMeta['family_title_en'] ?? Str::of(str_replace('-', ' ', (string) ($row['Category'] ?? 'Career First Wave')))->title()->toString(),
                'family_title_zh' => null,
            ];
        }

        if ($rowsToImport === []) {
            return [
                'import_run_id' => null,
                'compile_run_id' => null,
                'imported_slugs' => [],
                'issues_by_slug' => $issuesBySlug,
            ];
        }

        $allowedModes = array_values(array_unique(array_map(
            static fn (array $occupation): string => (string) $occupation['mapping_mode'],
            array_values($importManifestOccupations)
        )));

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => $dataset['dataset_name'],
            'dataset_version' => (string) ($manifest['selection_policy_version'] ?? $dataset['dataset_version'] ?? 'first_wave_manifest.unknown'),
            'dataset_checksum' => (string) $dataset['dataset_checksum'],
            'scope_mode' => ImportScopeMode::ledgerValue($allowedModes),
            'dry_run' => false,
            'status' => RunStatus::RUNNING,
            'started_at' => now(),
            'meta' => [
                'source_path' => $dataset['source_path'],
                'wave_name' => $manifest['wave_name'] ?? 'career_first_wave_10',
                'manifest_path' => $this->manifestReader->defaultPath(),
                'requested_rows' => count($manifestOccupations),
                'source_rows_matched' => count($rowsToImport),
            ],
        ]);

        $successfulSlugs = array_keys($importManifestOccupations);

        try {
            $summary = $this->importer->import($importRun, [
                'dataset_name' => $dataset['dataset_name'],
                'dataset_version' => (string) ($manifest['selection_policy_version'] ?? $dataset['dataset_version'] ?? 'first_wave_manifest.unknown'),
                'dataset_checksum' => $dataset['dataset_checksum'],
                'source_path' => $dataset['source_path'],
                'rows' => $rowsToImport,
                'manifest' => [
                    'dataset_version' => $manifest['selection_policy_version'] ?? null,
                    'defaults' => [
                        'truth_market' => 'US',
                        'display_market' => 'US',
                    ],
                    'occupations' => $importManifestOccupations,
                ],
            ], $allowedModes);

            foreach ($summary['errors'] as $error) {
                $slug = trim((string) ($error['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }

                $successfulSlugs = array_values(array_diff($successfulSlugs, [$slug]));

                foreach ((array) ($error['reasons'] ?? []) as $reason) {
                    $issuesBySlug[$slug][] = (string) $reason;
                }
            }

            $importRun->forceFill([
                'status' => RunStatus::COMPLETED,
                'finished_at' => now(),
                'rows_seen' => (int) $summary['rows_seen'],
                'rows_accepted' => (int) $summary['rows_accepted'],
                'rows_skipped' => (int) $summary['rows_skipped'],
                'rows_failed' => (int) $summary['rows_failed'],
                'output_counts' => (array) ($summary['output_counts'] ?? []),
                'error_summary' => array_slice((array) ($summary['errors'] ?? []), 0, 50),
            ])->save();
        } catch (Throwable $throwable) {
            $importRun->forceFill([
                'status' => RunStatus::FAILED,
                'finished_at' => now(),
                'error_summary' => [[
                    'type' => 'fatal',
                    'message' => $throwable->getMessage(),
                ]],
            ])->save();

            throw $throwable;
        }

        $seedSummary = $this->publishSeedMaterializer->apply($importRun, $manifestOccupations);
        foreach ($seedSummary['issues_by_slug'] as $slug => $slugIssues) {
            foreach ($slugIssues as $issue) {
                $issuesBySlug[$slug][] = $issue;
            }
        }

        $compileRunId = null;
        if ($compileMissing) {
            $compileSummary = $this->compileImportRun($importRun);
            $compileRunId = $compileSummary['compile_run_id'];
            foreach ($compileSummary['issues_by_slug'] as $slug => $slugIssues) {
                foreach ($slugIssues as $issue) {
                    $issuesBySlug[$slug][] = $issue;
                }
            }
        }

        return [
            'import_run_id' => $importRun->id,
            'compile_run_id' => $compileRunId,
            'imported_slugs' => array_values(array_unique($successfulSlugs)),
            'issues_by_slug' => array_map(
                static fn (array $issues): array => array_values(array_unique($issues)),
                $issuesBySlug
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $manifestOccupations
     * @param  array<string, array<string, mixed>>  $rowsBySlug
     * @return array<string, array{family_slug:string,family_title_en:string}>
     */
    private function familyMetadata(array $manifestOccupations, array $rowsBySlug): array
    {
        $metadata = [];
        $baseSlugUsage = [];

        foreach ($manifestOccupations as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            $familyUuid = (string) ($occupation['family_uuid'] ?? '');
            $slug = (string) ($occupation['canonical_slug'] ?? '');
            $row = $rowsBySlug[$slug] ?? null;
            if ($familyUuid === '' || ! is_array($row) || isset($metadata[$familyUuid])) {
                continue;
            }

            $category = trim((string) ($row['Category'] ?? ''));
            $baseSlug = Str::slug($category !== '' ? $category : 'career-first-wave');
            $metadata[$familyUuid] = [
                'family_slug' => $baseSlug,
                'family_title_en' => Str::of(str_replace('-', ' ', $category !== '' ? $category : 'Career First Wave'))->title()->toString(),
            ];
            $baseSlugUsage[$baseSlug][] = $familyUuid;
        }

        foreach ($baseSlugUsage as $baseSlug => $familyUuids) {
            $familyUuids = array_values(array_unique($familyUuids));
            if (count($familyUuids) < 2) {
                continue;
            }

            sort($familyUuids);

            foreach ($familyUuids as $familyUuid) {
                if (! isset($metadata[$familyUuid])) {
                    continue;
                }

                $metadata[$familyUuid]['family_slug'] = sprintf(
                    '%s-%s',
                    $baseSlug,
                    Str::lower(Str::substr($familyUuid, 0, 8))
                );
            }
        }

        return $metadata;
    }

    /**
     * @return array{compile_run_id:?string,issues_by_slug:array<string,list<string>>}
     */
    private function compileImportRun(CareerImportRun $importRun): array
    {
        $occupationIds = OccupationTruthMetric::query()
            ->where('import_run_id', $importRun->id)
            ->orderBy('created_at')
            ->pluck('occupation_id')
            ->unique()
            ->values()
            ->all();

        $priorRunId = CareerCompileRun::query()
            ->where('import_run_id', $importRun->id)
            ->where('compiler_version', CareerRecommendationCompiler::COMPILER_VERSION)
            ->where('scope_mode', $importRun->scope_mode)
            ->where('status', RunStatus::COMPLETED)
            ->latest('started_at')
            ->value('id');

        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'scope_mode' => $importRun->scope_mode,
            'dry_run' => false,
            'status' => RunStatus::RUNNING,
            'started_at' => now(),
            'meta' => [
                'manifest_supplied' => true,
                'replay_of_run_id' => is_string($priorRunId) ? $priorRunId : null,
            ],
        ]);

        $summary = [
            'subjects_seen' => count($occupationIds),
            'snapshots_created' => 0,
            'snapshots_skipped' => 0,
            'snapshots_failed' => 0,
            'errors' => [],
        ];

        foreach ($occupationIds as $occupationId) {
            $occupation = Occupation::query()->find($occupationId);
            if (! $occupation instanceof Occupation) {
                $summary['snapshots_failed']++;
                $summary['errors'][] = ['occupation_id' => $occupationId, 'message' => 'occupation_missing'];

                continue;
            }

            $resolved = $this->resolvePinnedRefs($occupation, $importRun);
            if ($resolved['truth_metric_id'] === null || $resolved['trust_manifest_id'] === null || $resolved['index_state_id'] === null) {
                $summary['snapshots_skipped']++;
                $summary['errors'][] = [
                    'occupation_id' => $occupation->id,
                    'slug' => $occupation->canonical_slug,
                    'message' => 'missing_compile_inputs',
                ];

                continue;
            }

            try {
                $this->materializer->materializeCompileSnapshot($occupation, $compileRun, $importRun, $resolved);
                $summary['snapshots_created']++;
            } catch (Throwable $throwable) {
                $summary['snapshots_failed']++;
                $summary['errors'][] = [
                    'occupation_id' => $occupation->id,
                    'slug' => $occupation->canonical_slug,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $compileRun->forceFill([
            'status' => RunStatus::COMPLETED,
            'finished_at' => now(),
            'subjects_seen' => $summary['subjects_seen'],
            'snapshots_created' => $summary['snapshots_created'],
            'snapshots_skipped' => $summary['snapshots_skipped'],
            'snapshots_failed' => $summary['snapshots_failed'],
            'output_counts' => [],
            'error_summary' => array_slice($summary['errors'], 0, 50),
        ])->save();

        $issuesBySlug = [];
        foreach ($summary['errors'] as $error) {
            $slug = trim((string) ($error['slug'] ?? ''));
            $message = trim((string) ($error['message'] ?? ''));
            if ($slug !== '' && $message !== '') {
                $issuesBySlug[$slug][] = $message;
            }
        }

        return [
            'compile_run_id' => $compileRun->id,
            'issues_by_slug' => $issuesBySlug,
        ];
    }

    /**
     * @return array{truth_metric_id:?string, trust_manifest_id:?string, index_state_id:?string, display_market:string}
     */
    private function resolvePinnedRefs(Occupation $occupation, CareerImportRun $importRun): array
    {
        $truthMetricId = OccupationTruthMetric::query()
            ->where('occupation_id', $occupation->id)
            ->where('import_run_id', $importRun->id)
            ->orderByDesc('created_at')
            ->value('id');

        $trustManifestId = TrustManifest::query()
            ->where('occupation_id', $occupation->id)
            ->where('import_run_id', $importRun->id)
            ->orderByDesc('created_at')
            ->value('id');

        $indexStateId = IndexState::query()
            ->where('occupation_id', $occupation->id)
            ->where('import_run_id', $importRun->id)
            ->orderByDesc('changed_at')
            ->value('id');

        return [
            'truth_metric_id' => is_string($truthMetricId) ? $truthMetricId : null,
            'trust_manifest_id' => is_string($trustManifestId) ? $trustManifestId : null,
            'index_state_id' => is_string($indexStateId) ? $indexStateId : null,
            'display_market' => (string) $occupation->display_market,
        ];
    }
}
