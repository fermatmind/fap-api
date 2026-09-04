<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Import\RunStatus;
use App\Domain\Career\IndexStateValue;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationTruthMetric;
use App\Models\RecommendationSnapshot;
use App\Models\TrustManifest;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\Import\CareerAuthorityMaterializer;
use App\Services\Career\Recovery\CareerRecommendationAuthorityRecovery;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerCompileRecommendationSubjects extends Command
{
    public const PUBLICATION_STATE = 'published_complete';

    private const DEFAULT_TYPES = [
        'INTJ-A', 'INTP-A', 'ENTJ-A', 'ENTP-A',
        'INFJ-A', 'INFP-A', 'ENFJ-A', 'ENFP-A',
        'ISTJ-A', 'ISFJ-A', 'ESTJ-A', 'ESFJ-A',
        'ISTP-A', 'ISFP-A', 'ESTP-A', 'ESFP-A',
    ];

    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

    private const PUBLIC_REVIEW_STATUSES = ['approved', 'reviewed'];

    private const MAX_OCCUPATIONS_PER_TYPE = 32;

    protected $signature = 'career:compile-recommendation-subjects
        {--import-run= : Completed import run UUID; defaults to the latest eligible non-dry-run import}
        {--types= : Comma-separated MBTI runtime type codes; defaults to the 16 canonical -A public routes}
        {--dry-run : Select and validate the complete publication without writing snapshots}
        {--limit= : Limit eligible occupations compiled per type; defaults to 32}';

    protected $description = 'Atomically publish complete MBTI recommendation subject snapshots from career authority.';

    public function handle(
        CareerAuthorityMaterializer $materializer,
        CareerRecommendationAuthorityRecovery $authorityRecovery,
    ): int {
        try {
            $types = $this->typeSubjects();
            $this->assertCanonicalPublicTypeSet($types);
            [$importRun, $occupationIds] = $this->selectImportRun($authorityRecovery);
            $publicationKey = $this->publicationKey($importRun, $types, $occupationIds);
            $priorRun = $this->priorPublicationRun($importRun, $publicationKey);

            if ($priorRun instanceof CareerCompileRun) {
                $this->assertCompletePublicRun($priorRun, $types);
                $this->writeSummary($priorRun, count($types), count($occupationIds), true, false);

                return self::SUCCESS;
            }

            if ((bool) $this->option('dry-run')) {
                $this->line('import_run_id='.$importRun->id);
                $this->line('compiler_version='.CareerRecommendationCompiler::COMPILER_VERSION);
                $this->line('dry_run=1');
                $this->line('types_requested='.count($types));
                $this->line('occupations_requested='.count($occupationIds));
                $this->line('subjects_seen='.(count($types) * count($occupationIds)));
                $this->line('snapshots_planned='.(count($types) * count($occupationIds)));
                $this->line('status=preflight_pass');

                return self::SUCCESS;
            }

            /** @var CareerCompileRun $compileRun */
            $compileRun = DB::transaction(function () use ($materializer, $importRun, $types, $occupationIds, $publicationKey): CareerCompileRun {
                $scopeMode = $importRun->scope_mode.':recommendation_subjects';
                $compileRun = CareerCompileRun::query()->create([
                    'import_run_id' => $importRun->id,
                    'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
                    'scope_mode' => $scopeMode,
                    'dry_run' => false,
                    'status' => RunStatus::RUNNING,
                    'started_at' => now(),
                    'meta' => [
                        'materialization_kind' => 'mbti_recommendation_subject',
                        'publication_state' => self::PUBLICATION_STATE,
                        'publication_key' => $publicationKey,
                        'type_codes' => array_column($types, 'type_code'),
                        'occupation_ids_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($occupationIds)),
                    ],
                ]);

                foreach ($types as $subject) {
                    foreach ($occupationIds as $occupationId) {
                        $occupation = Occupation::query()->find($occupationId);
                        if (! $occupation instanceof Occupation) {
                            throw new RuntimeException('recommendation_publication_occupation_missing');
                        }
                        $resolved = $this->resolvePinnedRefs($occupation, $importRun);
                        $this->assertResolvedPublicAuthority($resolved);
                        $materializer->materializeRecommendationSubjectSnapshot($occupation, $compileRun, $importRun, $resolved, $subject);
                    }
                }

                $created = count($types) * count($occupationIds);
                $compileRun->forceFill([
                    'status' => RunStatus::COMPLETED,
                    'finished_at' => now(),
                    'subjects_seen' => $created,
                    'snapshots_created' => $created,
                    'snapshots_skipped' => 0,
                    'snapshots_failed' => 0,
                    'output_counts' => [
                        'types_requested' => count($types),
                        'occupations_requested' => count($occupationIds),
                        'public_entry_count' => count($types),
                    ],
                    'error_summary' => [],
                ])->save();

                $this->assertCompletePublicRun($compileRun, $types);

                return $compileRun;
            }, 3);

            $this->writeSummary($compileRun, count($types), count($occupationIds), false, true);

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{0:CareerImportRun,1:list<string>} */
    private function selectImportRun(CareerRecommendationAuthorityRecovery $authorityRecovery): array
    {
        $requested = trim((string) $this->option('import-run'));
        $runs = $requested !== ''
            ? CareerImportRun::query()->whereKey($requested)->get()
            : CareerImportRun::query()
                ->where('status', RunStatus::COMPLETED)
                ->where('dry_run', false)
                ->where('rows_failed', 0)
                ->orderByDesc('finished_at')
                ->orderByDesc('started_at')
                ->orderByDesc('created_at')
                ->get();

        foreach ($runs as $run) {
            if (! $run instanceof CareerImportRun || $run->dry_run || $run->status !== RunStatus::COMPLETED || $run->rows_failed !== 0) {
                continue;
            }
            $occupationIds = $this->eligibleOccupationIds($run);
            if ($occupationIds !== []) {
                return [$run, $occupationIds];
            }
        }

        if ($requested === '') {
            $run = $authorityRecovery->ensure();
            $occupationIds = $this->eligibleOccupationIds($run);
            if ($occupationIds !== []) {
                return [$run, $occupationIds];
            }
        }

        throw new RuntimeException($requested !== ''
            ? 'Requested import run is not completed, non-dry-run, failure-free, and public-authority eligible.'
            : 'No completed, non-dry-run, failure-free public-authority import run is available.');
    }

    /** @return list<string> */
    private function eligibleOccupationIds(CareerImportRun $importRun): array
    {
        $limit = $this->limitValue() ?? self::MAX_OCCUPATIONS_PER_TYPE;
        $ids = OccupationTruthMetric::query()
            ->where('import_run_id', $importRun->id)
            ->pluck('occupation_id')
            ->unique()
            ->values()
            ->all();

        $eligible = [];
        foreach ($ids as $id) {
            $occupation = Occupation::query()->find($id);
            if (! $occupation instanceof Occupation || ! in_array($occupation->crosswalk_mode, self::SAFE_CROSSWALK_MODES, true)) {
                continue;
            }
            $resolved = $this->resolvePinnedRefs($occupation, $importRun);
            try {
                $this->assertResolvedPublicAuthority($resolved);
            } catch (RuntimeException) {
                continue;
            }
            $eligible[(string) $occupation->canonical_slug] = (string) $occupation->id;
        }

        ksort($eligible);

        return array_slice(array_values($eligible), 0, $limit);
    }

    /** @return list<array{type_code:string,canonical_type_code:string,display_title:string,public_route_slug:string}> */
    private function typeSubjects(): array
    {
        $raw = trim((string) $this->option('types'));
        $typeCodes = $raw === '' ? self::DEFAULT_TYPES : array_values(array_filter(array_map('trim', explode(',', $raw))));

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

    /** @param list<array<string,string>> $types */
    private function assertCanonicalPublicTypeSet(array $types): void
    {
        $actual = array_column($types, 'type_code');
        sort($actual);
        $expected = self::DEFAULT_TYPES;
        sort($expected);
        if ($actual !== $expected || count(array_unique($actual)) !== 16) {
            throw new RuntimeException('Recommendation publication requires exactly the 16 canonical MBTI -A types.');
        }
    }

    /** @return array{truth_metric_id:?string,trust_manifest_id:?string,index_state_id:?string,display_market:string,reviewer_status:?string,index_state:?string,index_eligible:bool} */
    private function resolvePinnedRefs(Occupation $occupation, CareerImportRun $importRun): array
    {
        $truthMetricId = OccupationTruthMetric::query()->where('occupation_id', $occupation->id)->where('import_run_id', $importRun->id)->latest('created_at')->value('id');
        $trust = TrustManifest::query()->where('occupation_id', $occupation->id)->where('import_run_id', $importRun->id)->latest('created_at')->first();
        $index = IndexState::query()->where('occupation_id', $occupation->id)->where('import_run_id', $importRun->id)->orderByDesc('changed_at')->orderByDesc('created_at')->first();

        return [
            'truth_metric_id' => is_string($truthMetricId) ? $truthMetricId : null,
            'trust_manifest_id' => $trust instanceof TrustManifest ? (string) $trust->id : null,
            'index_state_id' => $index instanceof IndexState ? (string) $index->id : null,
            'display_market' => (string) $occupation->display_market,
            'reviewer_status' => $trust instanceof TrustManifest ? (string) $trust->reviewer_status : null,
            'index_state' => $index instanceof IndexState ? (string) $index->index_state : null,
            'index_eligible' => $index instanceof IndexState && (bool) $index->index_eligible,
        ];
    }

    /** @param array<string,mixed> $resolved */
    private function assertResolvedPublicAuthority(array $resolved): void
    {
        if (! is_string($resolved['truth_metric_id'] ?? null)
            || ! is_string($resolved['trust_manifest_id'] ?? null)
            || ! is_string($resolved['index_state_id'] ?? null)
            || ! in_array(strtolower((string) ($resolved['reviewer_status'] ?? '')), self::PUBLIC_REVIEW_STATUSES, true)
            || ! IndexStateValue::isIndexedLike((string) ($resolved['index_state'] ?? ''), (bool) ($resolved['index_eligible'] ?? false))) {
            throw new RuntimeException('recommendation_publication_authority_ineligible');
        }
    }

    /** @param list<array<string,string>> $types @param list<string> $occupationIds */
    private function publicationKey(CareerImportRun $importRun, array $types, array $occupationIds): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'type_codes' => array_column($types, 'type_code'),
            'occupation_ids' => $occupationIds,
        ]));
    }

    private function priorPublicationRun(CareerImportRun $importRun, string $publicationKey): ?CareerCompileRun
    {
        return CareerCompileRun::query()
            ->where('import_run_id', $importRun->id)
            ->where('compiler_version', CareerRecommendationCompiler::COMPILER_VERSION)
            ->where('status', RunStatus::COMPLETED)
            ->where('dry_run', false)
            ->orderByDesc('finished_at')
            ->get()
            ->first(static fn (CareerCompileRun $run): bool => data_get($run->meta, 'publication_key') === $publicationKey);
    }

    /** @param list<array<string,string>> $types */
    private function assertCompletePublicRun(CareerCompileRun $run, array $types): void
    {
        if ($run->snapshots_skipped !== 0 || $run->snapshots_failed !== 0) {
            throw new RuntimeException('recommendation_publication_run_contains_skips_or_failures');
        }

        $expected = array_map('strtolower', array_column($types, 'canonical_type_code'));
        sort($expected);
        $actual = RecommendationSnapshot::query()
            ->where('compile_run_id', $run->id)
            ->whereNotNull('compiled_at')
            ->whereHas('occupation', static fn ($query) => $query->whereIn('crosswalk_mode', self::SAFE_CROSSWALK_MODES))
            ->whereHas('trustManifest', static fn ($query) => $query->whereIn('reviewer_status', self::PUBLIC_REVIEW_STATUSES))
            ->whereHas('indexState', static fn ($query) => $query->where('index_eligible', true)->whereIn('index_state', [IndexStateValue::INDEXABLE, IndexStateValue::INDEXED]))
            ->whereHas('contextSnapshot', static fn ($query) => $query->where('context_payload->materialization', 'career_first_wave'))
            ->whereHas('profileProjection', static fn ($query) => $query->where('projection_payload->materialization', 'career_first_wave'))
            ->with('profileProjection')
            ->get()
            ->map(static fn (RecommendationSnapshot $snapshot): string => strtolower((string) data_get($snapshot->profileProjection?->projection_payload, 'recommendation_subject_meta.public_route_slug', '')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($actual !== $expected) {
            throw new RuntimeException('recommendation_publication_requires_16_public_entries');
        }
    }

    private function writeSummary(CareerCompileRun $run, int $typeCount, int $occupationCount, bool $replay, bool $created): void
    {
        $this->line('compile_run_id='.$run->id);
        $this->line('import_run_id='.$run->import_run_id);
        $this->line('compiler_version='.$run->compiler_version);
        $this->line('dry_run=0');
        $this->line('replay='.($replay ? '1' : '0'));
        $this->line('types_requested='.$typeCount);
        $this->line('occupations_requested='.$occupationCount);
        $this->line('subjects_seen='.$run->subjects_seen);
        $this->line('snapshots_created='.($created ? $run->snapshots_created : 0));
        $this->line('snapshots_skipped='.$run->snapshots_skipped);
        $this->line('snapshots_failed='.$run->snapshots_failed);
        $this->line('public_entry_count='.$typeCount);
        $this->line('status='.$run->status);
    }

    private function limitValue(): ?int
    {
        $raw = $this->option('limit');
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $limit = (int) $raw;
        if ($limit < 1 || $limit > self::MAX_OCCUPATIONS_PER_TYPE) {
            throw new RuntimeException('Recommendation publication limit must be between 1 and 32.');
        }

        return $limit;
    }
}
