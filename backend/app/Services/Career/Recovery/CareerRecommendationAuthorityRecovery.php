<?php

declare(strict_types=1);

namespace App\Services\Career\Recovery;

use App\Domain\Career\Import\RunStatus;
use App\Domain\Career\Publish\FirstWaveManifestReader;
use App\Domain\Career\Publish\FirstWavePublishSeedMaterializer;
use App\Models\CareerImportRun;
use App\Models\CareerJob;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationSkillGraph;
use App\Models\OccupationTruthMetric;
use App\Models\SourceTrace;
use App\Models\TrustManifest;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CareerRecommendationAuthorityRecovery
{
    public const OPERATION_VERSION = 'career_recommendation_authority_recovery.v1';

    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

    private const SOURCE_CONTENT_VERSION = 'docx_342_career_batch';

    private const SOURCE_BASELINE_SHA256 = '89be50935eb2c1433c41fbbb3d6aca0d2687ece864208cb24e859f37a875c658';

    public function __construct(
        private readonly FirstWaveManifestReader $firstWaveManifestReader,
        private readonly FirstWavePublishSeedMaterializer $publishSeedMaterializer,
    ) {}

    public function ensure(): CareerImportRun
    {
        [$targets, $baselineBySlug, $firstWaveBySlug] = $this->loadBoundAuthority();
        $datasetChecksum = hash('sha256', PromotionContextFactory::canonicalJson(array_values($baselineBySlug)));

        $prior = CareerImportRun::query()
            ->where('dataset_name', self::SOURCE_CONTENT_VERSION)
            ->where('dataset_version', self::OPERATION_VERSION)
            ->where('dataset_checksum', $datasetChecksum)
            ->where('status', RunStatus::COMPLETED)
            ->where('dry_run', false)
            ->where('rows_failed', 0)
            ->orderByDesc('finished_at')
            ->get()
            ->first(fn (CareerImportRun $run): bool => data_get($run->meta, 'operation_version') === self::OPERATION_VERSION);

        if ($prior instanceof CareerImportRun) {
            $this->assertReadback($prior, $targets);

            return $prior;
        }

        return DB::transaction(function () use ($targets, $baselineBySlug, $firstWaveBySlug, $datasetChecksum): CareerImportRun {
            $jobs = CareerJob::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('locale', 'zh-CN')
                ->whereIn('slug', array_keys($targets))
                ->lockForUpdate()
                ->get()
                ->keyBy('slug');
            $occupations = Occupation::query()
                ->whereIn('canonical_slug', array_keys($targets))
                ->lockForUpdate()
                ->get()
                ->keyBy('canonical_slug');

            if ($jobs->count() !== count($targets) || $occupations->count() !== count($targets)) {
                throw new RuntimeException('career_recommendation_authority_recovery_target_count_invalid');
            }

            /** @var CareerImportRun $run */
            $run = CareerImportRun::query()->create([
                'dataset_name' => self::SOURCE_CONTENT_VERSION,
                'dataset_version' => self::OPERATION_VERSION,
                'dataset_checksum' => $datasetChecksum,
                'source_path' => 'content_baselines/career_jobs/career_jobs.zh-CN.json',
                'scope_mode' => 'exact',
                'dry_run' => false,
                'status' => RunStatus::RUNNING,
                'started_at' => now(),
                'meta' => [
                    'operation_version' => self::OPERATION_VERSION,
                    'source_content_version' => self::SOURCE_CONTENT_VERSION,
                    'source_baseline_sha256' => self::SOURCE_BASELINE_SHA256,
                    'target_slugs_sha256' => hash('sha256', PromotionContextFactory::canonicalJson(array_keys($targets))),
                ],
            ]);

            foreach ($targets as $slug => $target) {
                $job = $jobs->get($slug);
                $occupation = $occupations->get($slug);
                if (! $job instanceof CareerJob || ! $occupation instanceof Occupation) {
                    throw new RuntimeException('career_recommendation_authority_recovery_target_missing:'.$slug);
                }

                $this->assertTargetState($job, $occupation, $target, $baselineBySlug[$slug]);
                $crosswalk = $this->safeCrosswalk($occupation);
                $this->createRunAuthority($run, $job, $occupation, $crosswalk, $baselineBySlug[$slug]);
            }

            $seed = $this->publishSeedMaterializer->apply($run, array_values($firstWaveBySlug));
            if ($seed['applied'] !== count($targets) || $seed['skipped'] !== 0 || $seed['issues_by_slug'] !== []) {
                throw new RuntimeException('career_recommendation_authority_recovery_publish_seed_failed');
            }

            $run->forceFill([
                'status' => RunStatus::COMPLETED,
                'finished_at' => now(),
                'rows_seen' => count($targets),
                'rows_accepted' => count($targets),
                'rows_skipped' => 0,
                'rows_failed' => 0,
                'output_counts' => [
                    'source_traces_created' => count($targets),
                    'truth_metrics_created' => count($targets),
                    'crosswalks_created' => count($targets),
                    'skill_graphs_created' => count($targets),
                    'trust_manifests_created' => count($targets),
                    'index_states_created' => count($targets),
                ],
                'error_summary' => [],
            ])->save();

            $this->assertReadback($run, $targets);

            return $run;
        }, 3);
    }

    /** @return array{0:array<string,array<string,string>>,1:array<string,array<string,mixed>>,2:array<string,array<string,mixed>>} */
    private function loadBoundAuthority(): array
    {
        $recoveryPath = base_path('content_assets/career/career_data_recovery.v1.json');
        $recovery = is_file($recoveryPath) ? json_decode((string) file_get_contents($recoveryPath), true) : null;
        $authority = data_get($recovery, 'recommendation_publication.authority_recovery');
        $targets = [];
        foreach ((array) data_get($authority, 'targets', []) as $target) {
            if (! is_array($target)) {
                continue;
            }
            $slug = trim((string) ($target['canonical_slug'] ?? ''));
            if ($slug === '' || isset($targets[$slug])) {
                throw new RuntimeException('career_recommendation_authority_recovery_manifest_target_invalid');
            }
            $targets[$slug] = [
                'occupation_uuid' => (string) ($target['occupation_uuid'] ?? ''),
                'family_uuid' => (string) ($target['family_uuid'] ?? ''),
            ];
        }
        ksort($targets);

        if (($authority['operation_version'] ?? null) !== self::OPERATION_VERSION
            || ($authority['source_content_version'] ?? null) !== self::SOURCE_CONTENT_VERSION
            || ($authority['source_baseline_sha256'] ?? null) !== self::SOURCE_BASELINE_SHA256
            || ($authority['source_baseline_path'] ?? null) !== 'content_baselines/career_jobs/career_jobs.zh-CN.json'
            || (int) ($authority['target_count'] ?? 0) !== count($targets)
            || count($targets) < 1) {
            throw new RuntimeException('career_recommendation_authority_recovery_manifest_invalid');
        }

        $baselinePath = base_path('../'.(string) $authority['source_baseline_path']);
        $actualBaselineHash = is_file($baselinePath) ? hash_file('sha256', $baselinePath) : false;
        if (! is_string($actualBaselineHash) || ! hash_equals(self::SOURCE_BASELINE_SHA256, $actualBaselineHash)) {
            throw new RuntimeException('career_recommendation_authority_recovery_baseline_hash_invalid');
        }
        $baseline = json_decode((string) file_get_contents($baselinePath), true);
        $baselineBySlug = [];
        foreach ((array) ($baseline['jobs'] ?? []) as $job) {
            if (! is_array($job) || ! isset($targets[(string) ($job['slug'] ?? '')])) {
                continue;
            }
            $baselineBySlug[(string) $job['slug']] = $this->sourceState($job);
        }
        ksort($baselineBySlug);
        if (array_keys($baselineBySlug) !== array_keys($targets)) {
            throw new RuntimeException('career_recommendation_authority_recovery_baseline_targets_invalid');
        }

        $firstWave = $this->firstWaveManifestReader->read();
        $firstWaveBySlug = [];
        foreach ((array) ($firstWave['occupations'] ?? []) as $occupation) {
            if (is_array($occupation) && isset($targets[(string) ($occupation['canonical_slug'] ?? '')])) {
                $firstWaveBySlug[(string) $occupation['canonical_slug']] = $occupation;
            }
        }
        ksort($firstWaveBySlug);
        if (array_keys($firstWaveBySlug) !== array_keys($targets)) {
            throw new RuntimeException('career_recommendation_authority_recovery_first_wave_targets_invalid');
        }

        foreach ($targets as $slug => $target) {
            if (($firstWaveBySlug[$slug]['occupation_uuid'] ?? null) !== $target['occupation_uuid']
                || ($firstWaveBySlug[$slug]['family_uuid'] ?? null) !== $target['family_uuid']
                || data_get($firstWaveBySlug[$slug], 'reviewer_seed.status') !== 'approved'
                || data_get($firstWaveBySlug[$slug], 'index_seed.index_eligible') !== true) {
                throw new RuntimeException('career_recommendation_authority_recovery_first_wave_authority_invalid:'.$slug);
            }
        }

        return [$targets, $baselineBySlug, $firstWaveBySlug];
    }

    /** @param array<string,string> $target @param array<string,mixed> $baseline */
    private function assertTargetState(CareerJob $job, Occupation $occupation, array $target, array $baseline): void
    {
        if ($occupation->id !== $target['occupation_uuid']
            || $occupation->family_id !== $target['family_uuid']
            || ! in_array((string) $occupation->crosswalk_mode, self::SAFE_CROSSWALK_MODES, true)) {
            throw new RuntimeException('career_recommendation_authority_recovery_occupation_conflict:'.$occupation->canonical_slug);
        }
        if ($this->sourceState($job->toArray()) !== $baseline) {
            throw new RuntimeException('career_recommendation_authority_recovery_cms_baseline_conflict:'.$occupation->canonical_slug);
        }
    }

    private function safeCrosswalk(Occupation $occupation): OccupationCrosswalk
    {
        $crosswalk = $occupation->crosswalks()
            ->whereIn('mapping_type', self::SAFE_CROSSWALK_MODES)
            ->orderByDesc('confidence_score')
            ->orderByDesc('created_at')
            ->first();
        if (! $crosswalk instanceof OccupationCrosswalk || (float) $crosswalk->confidence_score < 0.8) {
            throw new RuntimeException('career_recommendation_authority_recovery_crosswalk_missing:'.$occupation->canonical_slug);
        }

        return $crosswalk;
    }

    /** @param array<string,mixed> $baseline */
    private function createRunAuthority(CareerImportRun $run, CareerJob $job, Occupation $occupation, OccupationCrosswalk $crosswalk, array $baseline): void
    {
        $fingerprint = fn (array $payload): string => hash('sha256', PromotionContextFactory::canonicalJson($payload));
        $sourceRefs = (array) data_get($baseline, 'market_demand_json.source_refs', []);
        $primaryUrl = collect($sourceRefs)->pluck('url')->filter()->first();
        $effectiveAt = $job->updated_at ?? $run->started_at;

        $sourceTrace = SourceTrace::query()->create([
            'source_id' => 'career_job:'.$job->id,
            'source_type' => 'career_cms_docx_baseline',
            'title' => $job->title.' authority baseline',
            'url' => is_string($primaryUrl) ? $primaryUrl : null,
            'fields_used' => ['median_pay_usd_annual', 'jobs_2024', 'projected_jobs_2034', 'employment_change', 'outlook_pct_2024_2034', 'ai_exposure'],
            'retrieved_at' => $effectiveAt,
            'evidence_strength' => 0.92,
            'import_run_id' => $run->id,
            'row_fingerprint' => $fingerprint(['slug' => $job->slug, 'source_refs' => $sourceRefs]),
        ]);

        OccupationTruthMetric::query()->create([
            'occupation_id' => $occupation->id,
            'source_trace_id' => $sourceTrace->id,
            'median_pay_usd_annual' => data_get($baseline, 'salary_json.annual_median_usd'),
            'jobs_2024' => data_get($baseline, 'outlook_json.jobs_2024'),
            'projected_jobs_2034' => data_get($baseline, 'outlook_json.projected_jobs_2034'),
            'employment_change' => data_get($baseline, 'outlook_json.employment_change'),
            'outlook_pct_2024_2034' => data_get($baseline, 'outlook_json.outlook_pct_2024_2034'),
            'outlook_description' => data_get($baseline, 'outlook_json.outlook_raw'),
            'entry_education' => null,
            'work_experience' => null,
            'on_the_job_training' => null,
            'ai_exposure' => data_get($baseline, 'market_demand_json.ai_exposure_score_10'),
            'ai_rationale' => data_get($baseline, 'market_demand_json.ai_exposure_raw'),
            'truth_market' => 'US',
            'effective_at' => $effectiveAt,
            'reviewed_at' => $effectiveAt,
            'import_run_id' => $run->id,
            'row_fingerprint' => $fingerprint(['slug' => $job->slug, 'truth' => $baseline]),
        ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => $crosswalk->source_system,
            'source_code' => $crosswalk->source_code,
            'source_title' => $crosswalk->source_title,
            'mapping_type' => $crosswalk->mapping_type,
            'confidence_score' => $crosswalk->confidence_score,
            'notes' => self::OPERATION_VERSION,
            'import_run_id' => $run->id,
            'row_fingerprint' => $fingerprint(['slug' => $job->slug, 'crosswalk_id' => $crosswalk->id]),
        ]);

        OccupationSkillGraph::query()->create([
            'occupation_id' => $occupation->id,
            'stack_key' => 'authority_self_baseline',
            'skill_overlap_graph' => ['self_baseline' => 1.0],
            'task_overlap_graph' => ['self_baseline' => 1.0],
            'tool_overlap_graph' => ['self_baseline' => 1.0],
            'import_run_id' => $run->id,
            'row_fingerprint' => $fingerprint(['slug' => $job->slug, 'stack_key' => 'authority_self_baseline']),
        ]);
    }

    /** @param array<string,array<string,string>> $targets */
    private function assertReadback(CareerImportRun $run, array $targets): void
    {
        $expected = count($targets);
        $occupationIds = array_column(array_values($targets), 'occupation_uuid');
        $hasExactTargets = static fn ($query): bool => $query
            ->where('import_run_id', $run->id)
            ->whereIn('occupation_id', $occupationIds)
            ->distinct('occupation_id')
            ->count('occupation_id') === $expected;

        if ($run->status !== RunStatus::COMPLETED
            || $run->dry_run
            || $run->rows_seen !== $expected
            || $run->rows_accepted !== $expected
            || $run->rows_skipped !== 0
            || $run->rows_failed !== 0
            || SourceTrace::query()->where('import_run_id', $run->id)->count() !== $expected
            || ! $hasExactTargets(OccupationTruthMetric::query())
            || ! $hasExactTargets(OccupationCrosswalk::query())
            || ! $hasExactTargets(OccupationSkillGraph::query())
            || ! $hasExactTargets(TrustManifest::query()->whereIn('reviewer_status', ['approved', 'reviewed']))
            || ! $hasExactTargets(IndexState::query()->where('index_eligible', true)->whereIn('index_state', ['indexable', 'indexed']))) {
            throw new RuntimeException('career_recommendation_authority_recovery_readback_failed');
        }
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function sourceState(array $job): array
    {
        return [
            'job_code' => $job['job_code'] ?? null,
            'slug' => $job['slug'] ?? null,
            'locale' => $job['locale'] ?? null,
            'title' => $job['title'] ?? null,
            'subtitle' => $job['subtitle'] ?? null,
            'salary_json' => $job['salary_json'] ?? null,
            'outlook_json' => $job['outlook_json'] ?? null,
            'market_demand_json' => $job['market_demand_json'] ?? null,
            'status' => $job['status'] ?? null,
            'is_public' => (bool) ($job['is_public'] ?? false),
            'is_indexable' => (bool) ($job['is_indexable'] ?? false),
        ];
    }
}
