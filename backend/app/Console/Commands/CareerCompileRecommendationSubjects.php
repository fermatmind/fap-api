<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Import\RunStatus;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationTruthMetric;
use App\Models\TrustManifest;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\Import\CareerAuthorityMaterializer;
use Illuminate\Console\Command;
use Throwable;

final class CareerCompileRecommendationSubjects extends Command
{
    private const DEFAULT_TYPES = [
        'INTJ-A',
        'INTP-A',
        'ENTJ-A',
        'ENTP-A',
        'INFJ-A',
        'INFP-A',
        'ENFJ-A',
        'ENFP-A',
        'ISTJ-A',
        'ISFJ-A',
        'ESTJ-A',
        'ESFJ-A',
        'ISTP-A',
        'ISFP-A',
        'ESTP-A',
        'ESFP-A',
    ];

    protected $signature = 'career:compile-recommendation-subjects
        {--import-run= : Completed import run UUID to compile recommendation subject snapshots from}
        {--types= : Comma-separated MBTI runtime type codes; defaults to the 16 canonical -A public routes}
        {--dry-run : Select and validate occupation/type pairs without writing snapshots}
        {--limit= : Limit occupations compiled per type}';

    protected $description = 'Compile first-wave occupation authority into MBTI recommendation detail subject snapshots.';

    public function handle(CareerAuthorityMaterializer $materializer): int
    {
        $compileRun = null;

        try {
            $importRunId = trim((string) $this->option('import-run'));
            if ($importRunId === '') {
                throw new \RuntimeException('--import-run is required.');
            }

            $importRun = CareerImportRun::query()->findOrFail($importRunId);
            if ($importRun->dry_run) {
                throw new \RuntimeException('Cannot compile recommendation subjects from a dry-run import ledger.');
            }
            if ($importRun->status !== RunStatus::COMPLETED) {
                throw new \RuntimeException('Import run must be completed before recommendation subject compile.');
            }

            $types = $this->typeSubjects();
            $occupationIds = OccupationTruthMetric::query()
                ->where('import_run_id', $importRun->id)
                ->orderBy('created_at')
                ->limit($this->limitValue() ?? PHP_INT_MAX)
                ->pluck('occupation_id')
                ->unique()
                ->values()
                ->all();
            $scopeMode = $importRun->scope_mode.':recommendation_subjects';
            $priorRunId = CareerCompileRun::query()
                ->where('import_run_id', $importRun->id)
                ->where('compiler_version', CareerRecommendationCompiler::COMPILER_VERSION)
                ->where('scope_mode', $scopeMode)
                ->where('status', RunStatus::COMPLETED)
                ->latest('started_at')
                ->value('id');

            $compileRun = CareerCompileRun::query()->create([
                'import_run_id' => $importRun->id,
                'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
                'scope_mode' => $scopeMode,
                'dry_run' => (bool) $this->option('dry-run'),
                'status' => RunStatus::RUNNING,
                'started_at' => now(),
                'meta' => [
                    'materialization_kind' => 'mbti_recommendation_subject',
                    'type_codes' => array_column($types, 'type_code'),
                    'replay_of_run_id' => is_string($priorRunId) ? $priorRunId : null,
                ],
            ]);

            $summary = [
                'subjects_seen' => count($occupationIds) * count($types),
                'snapshots_created' => 0,
                'snapshots_planned' => 0,
                'snapshots_skipped' => 0,
                'snapshots_failed' => 0,
                'errors' => [],
            ];

            foreach ($types as $subject) {
                foreach ($occupationIds as $occupationId) {
                    $occupation = Occupation::query()->find($occupationId);
                    if (! $occupation instanceof Occupation) {
                        $summary['snapshots_failed']++;
                        $summary['errors'][] = [
                            'type_code' => $subject['type_code'],
                            'occupation_id' => $occupationId,
                            'message' => 'occupation_missing',
                        ];

                        continue;
                    }

                    $resolved = $this->resolvePinnedRefs($occupation, $importRun);
                    if ($resolved['truth_metric_id'] === null || $resolved['trust_manifest_id'] === null || $resolved['index_state_id'] === null) {
                        $summary['snapshots_skipped']++;
                        $summary['errors'][] = [
                            'type_code' => $subject['type_code'],
                            'occupation_id' => $occupation->id,
                            'slug' => $occupation->canonical_slug,
                            'message' => 'missing_compile_inputs',
                        ];

                        continue;
                    }

                    if ($compileRun->dry_run) {
                        $summary['snapshots_planned']++;

                        continue;
                    }

                    try {
                        $materializer->materializeRecommendationSubjectSnapshot($occupation, $compileRun, $importRun, $resolved, $subject);
                        $summary['snapshots_created']++;
                    } catch (Throwable $throwable) {
                        $summary['snapshots_failed']++;
                        $summary['errors'][] = [
                            'type_code' => $subject['type_code'],
                            'occupation_id' => $occupation->id,
                            'slug' => $occupation->canonical_slug,
                            'message' => $throwable->getMessage(),
                        ];
                    }
                }
            }

            $compileRun->forceFill([
                'status' => RunStatus::COMPLETED,
                'finished_at' => now(),
                'subjects_seen' => $summary['subjects_seen'],
                'snapshots_created' => $summary['snapshots_created'],
                'snapshots_skipped' => $summary['snapshots_skipped'],
                'snapshots_failed' => $summary['snapshots_failed'],
                'output_counts' => [
                    'types_requested' => count($types),
                    'occupations_requested' => count($occupationIds),
                    'snapshots_planned' => $summary['snapshots_planned'],
                ],
                'error_summary' => array_slice($summary['errors'], 0, 50),
            ])->save();

            $this->line('compile_run_id='.$compileRun->id);
            $this->line('import_run_id='.$importRun->id);
            $this->line('compiler_version='.$compileRun->compiler_version);
            $this->line('scope_mode='.$compileRun->scope_mode);
            $this->line('dry_run='.($compileRun->dry_run ? '1' : '0'));
            $this->line('replay_of_run_id='.(is_string($priorRunId) ? $priorRunId : ''));
            $this->line('types_requested='.count($types));
            $this->line('occupations_requested='.count($occupationIds));
            $this->line('subjects_seen='.$compileRun->subjects_seen);
            $this->line('snapshots_created='.$compileRun->snapshots_created);
            $this->line('snapshots_planned='.(int) ($compileRun->output_counts['snapshots_planned'] ?? 0));
            $this->line('snapshots_skipped='.$compileRun->snapshots_skipped);
            $this->line('snapshots_failed='.$compileRun->snapshots_failed);
            $this->line('status='.$compileRun->status);

            if ($compileRun->snapshots_failed > 0) {
                $this->warn('recommendation subject compile completed with failures');
            } else {
                $this->info($compileRun->dry_run ? 'dry-run complete' : 'recommendation subject compile complete');
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            if ($compileRun instanceof CareerCompileRun) {
                $compileRun->forceFill([
                    'status' => RunStatus::FAILED,
                    'finished_at' => now(),
                    'error_summary' => [[
                        'message' => $throwable->getMessage(),
                        'type' => 'fatal',
                    ]],
                ])->save();
            }

            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return list<array{type_code: string, canonical_type_code: string, display_title: string, public_route_slug: string}>
     */
    private function typeSubjects(): array
    {
        $raw = trim((string) $this->option('types'));
        $typeCodes = $raw === ''
            ? self::DEFAULT_TYPES
            : array_values(array_filter(array_map('trim', explode(',', $raw))));

        return array_values(array_map(static function (string $typeCode): array {
            $normalized = strtoupper($typeCode);
            $canonical = strtoupper(substr($normalized, 0, 4));

            return [
                'type_code' => $normalized,
                'canonical_type_code' => $canonical,
                'display_title' => $canonical.' career recommendations',
                'public_route_slug' => strtolower($canonical),
            ];
        }, $typeCodes));
    }

    /**
     * @return array{truth_metric_id: ?string, trust_manifest_id: ?string, index_state_id: ?string, display_market: string}
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

    private function limitValue(): ?int
    {
        $raw = $this->option('limit');
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return max(1, (int) $raw);
    }
}
