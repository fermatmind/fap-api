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

    private const BASELINE_PATH = 'content_baselines/career_jobs/career_jobs.zh-CN.json';

    private const EN_BASELINE_PATH = 'content_baselines/career_jobs/career_jobs.en.json';

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

            $baselineData = $this->loadBaselineData($slugs);
            $enBaselineData = $this->loadEnglishBaselineData($slugs);

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

                $meta = $baselineData[$slug] ?? null;

                if ($meta === null) {
                    $missingRequiredMetadata[] = [
                        'slug' => $slug,
                        'reason' => 'not_found_in_baseline',
                    ];

                    continue;
                }

                $titleZh = $meta['title'] ?? null;
                $titleEn = $enBaselineData[$slug]['title'] ?? null;

                if ($titleZh === null || $titleEn === null) {
                    $missingRequiredMetadata[] = [
                        'slug' => $slug,
                        'reason' => 'missing_title',
                        'has_title_zh' => $titleZh !== null,
                        'has_title_en' => $titleEn !== null,
                    ];

                    continue;
                }

                $creatable[] = [
                    'slug' => $slug,
                    'title_zh' => $titleZh,
                    'title_en' => $titleEn,
                    'industry_label' => $meta['industry_label'] ?? null,
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
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
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

    /**
     * @param  list<string>  $slugs
     * @return array<string, array{title: string, industry_label: string|null}>
     */
    private function loadBaselineData(array $slugs): array
    {
        $path = base_path(self::BASELINE_PATH);

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

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

            $data[$slug] = [
                'title' => trim((string) ($job['title'] ?? '')),
                'industry_label' => isset($job['industry_label']) ? trim((string) $job['industry_label']) : null,
            ];
        }

        return $data;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, array{title: string|null}>
     */
    private function loadEnglishBaselineData(array $slugs): array
    {
        $path = base_path(self::EN_BASELINE_PATH);

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

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
            ];
        }

        return $data;
    }
}
