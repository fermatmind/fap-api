<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Console\Command;

final class CareerBackfillCanonicalOccupationRecords extends Command
{
    protected $signature = 'career:backfill-canonical-occupation-records
        {--slugs= : Comma-separated list of canonical slugs (required)}
        {--source= : Canonical source name (default: career_jobs_zh_cn_baseline)}
        {--dry-run : Plan without writing}
        {--apply : Execute the backfill (mutates database)}
        {--json : Emit JSON output}';

    protected $description = 'Idempotently backfill missing Occupation records from canonical career metadata sources.';

    private const DEFAULT_SOURCE = 'career_jobs_zh_cn_baseline';

    private const ZH_CN_BASELINE_RELATIVE = '../content_baselines/career_jobs/career_jobs.zh-CN.json';

    private const EN_BASELINE_RELATIVE = '../content_baselines/career_jobs/career_jobs.en.json';

    private const BATCH_MANIFEST_PATHS = [
        '../docs/career/batches/batch_2_manifest.json',
        '../docs/career/batches/batch_3_manifest.json',
        '../docs/career/batches/batch_4_manifest.json',
    ];

    public function handle(): int
    {
        try {
            $slugsRaw = $this->requiredOption('slugs');
            $slugs = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $slugsRaw)),
                static fn (string $s): bool => $s !== '',
            )));
            sort($slugs);

            if ($slugs === []) {
                throw new \RuntimeException('--slugs must contain at least one canonical slug');
            }

            $dryRun = (bool) $this->option('dry-run');
            $apply = (bool) $this->option('apply');

            if ($dryRun && $apply) {
                throw new \RuntimeException('--dry-run and --apply are mutually exclusive');
            }
            if (! $dryRun && ! $apply) {
                throw new \RuntimeException('either --dry-run or --apply must be specified');
            }

            $source = $this->option('source') !== null
                ? trim((string) $this->option('source'))
                : self::DEFAULT_SOURCE;

            $zhCnBaselinePath = $this->baselineFilePath(self::ZH_CN_BASELINE_RELATIVE);
            $enBaselinePath = $this->baselineFilePath(self::EN_BASELINE_RELATIVE);

            $zhBaselineData = $this->loadBaselineData($slugs, $zhCnBaselinePath);
            $enBaselineData = $this->loadBaselineData($slugs, $enBaselinePath);
            $batchManifestTitles = $this->loadBatchManifestTitles($slugs);

            $existingOccupations = Occupation::query()
                ->whereIn('canonical_slug', $slugs)
                ->pluck('canonical_slug')
                ->map(fn (string $s): string => strtolower(trim($s)))
                ->toArray();
            $existingSet = array_flip($existingOccupations);

            $requestedCount = count($slugs);
            $existingCount = count($existingOccupations);
            $creatable = [];
            $failed = [];
            $missingRequiredMetadata = [];

            foreach ($slugs as $slug) {
                if (isset($existingSet[$slug])) {
                    continue;
                }

                $zhMeta = $zhBaselineData[$slug] ?? null;
                $enMeta = $enBaselineData[$slug] ?? null;
                $hasZhBaseline = $zhMeta !== null;
                $hasEnBaseline = $enMeta !== null;

                if ($zhMeta === null) {
                    $missingRequiredMetadata[] = [
                        'slug' => $slug,
                        'reason' => 'not_found_in_baseline',
                        'has_zh_baseline' => false,
                        'has_en_baseline' => $hasEnBaseline,
                    ];

                    continue;
                }

                $titleZh = $zhMeta['title'] ?? '';
                $titleZhSource = 'zh_cn_baseline';
                $titleEn = null;
                $titleEnSource = null;

                if ($hasEnBaseline && ($enMeta['title'] ?? null) !== null) {
                    $titleEn = $enMeta['title'];
                    $titleEnSource = 'en_baseline';
                } elseif (isset($batchManifestTitles[$slug])) {
                    $titleEn = $batchManifestTitles[$slug];
                    $titleEnSource = 'batch_manifest';
                } else {
                    $titleEn = $this->deriveEnglishTitleFromSlug($slug);
                    $titleEnSource = 'canonical_slug_derived';
                }

                if ($titleZh === '' || $titleEn === null) {
                    $missingRequiredMetadata[] = [
                        'slug' => $slug,
                        'reason' => $titleZh === '' ? 'missing_zh_title' : 'missing_english_title',
                        'has_zh_baseline' => true,
                        'has_title_zh' => $titleZh !== '',
                        'has_title_en' => $titleEn !== null,
                        'has_en_baseline' => $hasEnBaseline,
                        'has_batch_manifest' => isset($batchManifestTitles[$slug]),
                    ];

                    continue;
                }

                $creatable[] = [
                    'slug' => $slug,
                    'title_zh' => $titleZh,
                    'title_en' => $titleEn,
                    'title_zh_source' => $titleZhSource,
                    'title_en_source' => $titleEnSource,
                    'metadata_source' => 'zh_cn_baseline',
                    'job_code' => $zhMeta['job_code'] ?? null,
                    'industry_label' => $zhMeta['industry_label'] ?? null,
                ];
            }

            if ($dryRun) {
                $result = [
                    'status' => 'planned',
                    'dry_run' => true,
                    'writes_database' => false,
                    'source' => $source,
                    'requested_count' => $requestedCount,
                    'existing_count' => $existingCount,
                    'creatable_count' => count($creatable),
                    'created_count' => 0,
                    'skipped_count' => 0,
                    'failures' => [],
                    'missing_required_metadata' => $missingRequiredMetadata,
                    'occupation_slugs' => [
                        'existing' => $existingOccupations,
                        'creatable' => array_column($creatable, 'slug'),
                        'created' => [],
                        'failed' => array_column($missingRequiredMetadata, 'slug'),
                    ],
                ];

                $this->outputResult($result);

                return self::SUCCESS;
            }

            $family = null;
            $created = 0;
            $skipped = 0;
            $applyFailures = [];

            foreach ($creatable as $record) {
                if ($family === null) {
                    $family = OccupationFamily::query()->firstOrCreate(
                        ['canonical_slug' => 'canonical_rollout_batch'],
                        [
                            'title_en' => 'Canonical Rollout Batch',
                            'title_zh' => '标准发布批次',
                        ],
                    );
                }

                try {
                    Occupation::query()->create([
                        'family_id' => $family->id,
                        'parent_id' => null,
                        'canonical_slug' => $record['slug'],
                        'entity_level' => 'market_child',
                        'truth_market' => 'US',
                        'display_market' => 'US',
                        'crosswalk_mode' => 'canonical_rollout_batch',
                        'canonical_title_en' => $record['title_en'],
                        'canonical_title_zh' => $record['title_zh'],
                        'search_h1_zh' => $record['title_zh'],
                        'structural_stability' => null,
                        'task_prototype_signature' => null,
                        'market_semantics_gap' => null,
                        'regulatory_divergence' => null,
                        'toolchain_divergence' => null,
                        'skill_gap_threshold' => null,
                        'trust_inheritance_scope' => null,
                    ]);

                    $created++;
                } catch (\Throwable $e) {
                    $applyFailures[] = [
                        'slug' => $record['slug'],
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            $result = [
                'status' => count($applyFailures) === 0 ? 'applied' : 'blocked',
                'dry_run' => false,
                'writes_database' => true,
                'source' => $source,
                'requested_count' => $requestedCount,
                'existing_count' => $existingCount,
                'creatable_count' => count($creatable),
                'created_count' => $created,
                'skipped_count' => $skipped,
                'failures' => $applyFailures,
                'missing_required_metadata' => $missingRequiredMetadata,
                'final_occupation_count' => $existingCount + $created,
                'occupation_slugs' => [
                    'existing' => $existingOccupations,
                    'creatable' => array_column($creatable, 'slug'),
                    'created' => array_column(array_slice($creatable, 0, $created), 'slug'),
                    'failed' => array_column($applyFailures, 'slug'),
                ],
            ];

            $this->outputResult($result);

            return count($applyFailures) === 0 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            throw new \RuntimeException("--{$name} is required");
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function outputResult(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));
        } else {
            $this->line('status='.($result['status'] ?? 'unknown'));
            $this->line('requested_count='.($result['requested_count'] ?? 0));
            $this->line('existing_count='.($result['existing_count'] ?? 0));
            $this->line('creatable_count='.($result['creatable_count'] ?? 0));
            $this->line('created_count='.($result['created_count'] ?? 0));
            $this->line('dry_run='.($result['dry_run'] ? 'true' : 'false'));
            $this->line('writes_database='.($result['writes_database'] ? 'true' : 'false'));
        }
    }

    // ─── Path resolution ────────────────────────────────────────────────────

    private function baselineFilePath(string $relativePath): string
    {
        $candidate = base_path($relativePath);

        if (is_file($candidate)) {
            return $candidate;
        }

        $candidate = dirname(base_path()).'/'.ltrim($relativePath, '/.');

        if (is_file($candidate)) {
            return $candidate;
        }

        return $candidate;
    }

    // ─── Baseline loaders ───────────────────────────────────────────────────

    /**
     * @param  list<string>  $slugs
     * @return array<string, array{title: string|null, industry_label: string|null, job_code: string|null}>
     */
    private function loadBaselineData(array $slugs, string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return [];
        }

        $jobs = $payload['jobs'] ?? [];

        if (! is_array($jobs)) {
            return [];
        }

        $slugSet = array_flip($slugs);
        $data = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            $slug = strtolower(trim((string) ($job['slug'] ?? $job['job_code'] ?? '')));

            if ($slug === '' || ! isset($slugSet[$slug])) {
                continue;
            }

            $title = trim((string) ($job['title'] ?? ''));

            $data[$slug] = [
                'title' => $title !== '' ? $title : null,
                'job_code' => isset($job['job_code']) ? trim((string) $job['job_code']) : null,
                'industry_label' => isset($job['industry_label']) ? trim((string) $job['industry_label']) : null,
            ];
        }

        return $data;
    }

    // ─── Batch manifest title loader ────────────────────────────────────────

    /**
     * @param  list<string>  $slugs
     * @return array<string, string>
     */
    private function loadBatchManifestTitles(array $slugs): array
    {
        $titles = [];
        $slugSet = array_flip($slugs);

        foreach (self::BATCH_MANIFEST_PATHS as $relativePath) {
            $path = $this->baselineFilePath($relativePath);

            if (! is_file($path)) {
                continue;
            }

            $raw = file_get_contents($path);

            if ($raw === false) {
                continue;
            }

            $payload = json_decode($raw, true);

            if (! is_array($payload)) {
                continue;
            }

            $members = $payload['members'] ?? [];

            if (! is_array($members)) {
                continue;
            }

            foreach ($members as $member) {
                if (! is_array($member)) {
                    continue;
                }

                $slug = strtolower(trim((string) ($member['canonical_slug'] ?? $member['canonicalSlug'] ?? '')));

                if ($slug === '' || ! isset($slugSet[$slug])) {
                    continue;
                }

                $title = trim((string) ($member['canonical_title_en'] ?? $member['canonicalTitleEn'] ?? ''));

                if ($title !== '' && ! isset($titles[$slug])) {
                    $titles[$slug] = $title;
                }
            }
        }

        return $titles;
    }

    // ─── English title derivation ───────────────────────────────────────────

    private function deriveEnglishTitleFromSlug(string $slug): string
    {
        return implode(' ', array_map(
            'ucfirst',
            explode('-', $slug),
        ));
    }
}
