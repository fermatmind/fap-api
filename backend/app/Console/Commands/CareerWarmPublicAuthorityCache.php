<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Console\Command;

final class CareerWarmPublicAuthorityCache extends Command
{
    protected $signature = 'career:warm-public-authority-cache
        {--job-detail-slugs= : Comma-separated career job slugs for per-locale detail cache warm}
        {--job-detail-manifest= : JSON manifest/report file used to derive per-locale career job slugs}
        {--job-detail-manifest-source=auto : Slug source in the manifest: auto, items, candidate_slugs, controlled_import_manifest.candidate_slugs, slugs}
        {--job-detail-locales=zh-CN : Comma-separated public locales for detail cache warm}
        {--forget-job-detail : Forget targeted job detail caches before warming them}
        {--job-detail-only : Warm only targeted job detail caches}
        {--json : Emit JSON output}';

    protected $description = 'Warm public Career dataset and launch-governance authority response caches outside the HTTP request path.';

    public function handle(PublicCareerAuthorityResponseCache $cache): int
    {
        try {
            $manifestPath = trim((string) $this->option('job-detail-manifest'));
            $manifestSource = trim((string) $this->option('job-detail-manifest-source'));
            $jobDetailSlugs = array_values(array_unique(array_merge(
                $this->csvOption('job-detail-slugs'),
                $manifestPath === '' ? [] : $this->slugsFromManifest($manifestPath, $manifestSource === '' ? 'auto' : $manifestSource),
            )));
            $jobDetailLocales = $this->csvOption('job-detail-locales');
            $jobDetailOnly = (bool) $this->option('job-detail-only');
            if ($jobDetailOnly && $jobDetailSlugs === []) {
                $this->error('--job-detail-only requires --job-detail-slugs or --job-detail-manifest.');

                return self::FAILURE;
            }

            $reporter = function (string $phase, string $state): void {
                if (! (bool) $this->option('json')) {
                    $this->line(sprintf('career_warm_phase=%s state=%s', $phase, $state));
                }
            };
            $summary = $jobDetailOnly ? [] : $cache->warm($reporter);
            if ($jobDetailSlugs !== []) {
                $locales = $jobDetailLocales === [] ? ['zh-CN'] : $jobDetailLocales;
                $summary = array_merge(
                    $summary,
                    $cache->warmJobDetailPayloads(
                        $jobDetailSlugs,
                        $locales,
                        (bool) $this->option('forget-job-detail'),
                        $reporter,
                    ),
                );
                $jobDetailReport = $this->jobDetailReport(
                    $summary,
                    $jobDetailSlugs,
                    $locales,
                    $manifestPath === '' ? null : $manifestPath,
                    $manifestPath === '' ? null : ($manifestSource === '' ? 'auto' : $manifestSource),
                );
            } else {
                $jobDetailReport = null;
            }

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode([
                    'status' => 'warmed',
                    'entries' => $summary,
                    'job_detail_refresh' => $jobDetailReport,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=warmed');
            if ($jobDetailReport !== null) {
                $this->line(sprintf(
                    'job_detail_refresh slug_count=%d locale_count=%d expected_cache_entries=%d',
                    (int) $jobDetailReport['slug_count'],
                    (int) $jobDetailReport['locale_count'],
                    (int) $jobDetailReport['expected_cache_entries'],
                ));
            }
            foreach ($summary as $name => $entry) {
                $this->line(sprintf(
                    '%s cache_key=%s member_count=%d',
                    $name,
                    (string) ($entry['cache_key'] ?? ''),
                    (int) ($entry['member_count'] ?? 0),
                ));
            }

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name): array
    {
        $raw = trim((string) $this->option($name));
        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw),
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<string>
     */
    private function slugsFromManifest(string $path, string $source): array
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Job detail manifest not found: %s', $path));
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Job detail manifest must decode to a JSON object or array.');
        }

        $sources = match ($source) {
            'auto' => [
                ['items'],
                ['controlled_import_manifest', 'candidate_slugs'],
                ['candidate_slugs'],
                ['slugs'],
            ],
            'items' => [['items']],
            'candidate_slugs' => [['candidate_slugs']],
            'controlled_import_manifest.candidate_slugs' => [['controlled_import_manifest', 'candidate_slugs']],
            'slugs' => [['slugs']],
            default => throw new \InvalidArgumentException(sprintf('Unsupported --job-detail-manifest-source value: %s', $source)),
        };

        foreach ($sources as $segments) {
            $value = $this->arrayPath($decoded, $segments);
            $slugs = $this->slugListFromValue($value);
            if ($slugs !== []) {
                return $slugs;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'No job detail slugs found in manifest %s using source %s.',
            $path,
            $source,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $segments
     */
    private function arrayPath(array $data, array $segments): mixed
    {
        $value = $data;
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function slugListFromValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $slugs = [];
        foreach ($value as $item) {
            $slug = is_array($item) ? ($item['slug'] ?? null) : $item;
            if (! is_string($slug)) {
                continue;
            }

            $slug = trim($slug);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  array<string, array<string, mixed>>  $summary
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function jobDetailReport(array $summary, array $slugs, array $locales, ?string $manifestPath, ?string $manifestSource): array
    {
        $entries = array_filter(
            $summary,
            static fn (array $entry): bool => ($entry['slug'] ?? null) !== null && ($entry['locale'] ?? null) !== null,
        );
        $statusCounts = [];
        foreach ($entries as $entry) {
            $status = (string) ($entry['status'] ?? 'unknown');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }
        ksort($statusCounts);

        return [
            'manifest_path' => $manifestPath,
            'manifest_source' => $manifestSource,
            'slug_count' => count($slugs),
            'locales' => $locales,
            'locale_count' => count($locales),
            'expected_cache_entries' => count($slugs) * count($locales),
            'observed_cache_entries' => count($entries),
            'status_counts' => $statusCounts,
            'forget_first' => (bool) $this->option('forget-job-detail'),
        ];
    }
}
