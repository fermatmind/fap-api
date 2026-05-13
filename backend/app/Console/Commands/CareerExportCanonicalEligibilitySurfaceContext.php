<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerPublicResolutionPlanIssue;
use App\Domain\Career\Audit\CareerPublicResolutionPlanResolver;
use App\Domain\Career\Audit\CareerSurfaceContextArtifactIssue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportCanonicalEligibilitySurfaceContext extends Command
{
    protected $signature = 'career:export-canonical-eligibility-surface-context
        {--public-resolution-plan= : Required public-resolution planner JSON artifact}
        {--output= : Required output path for career_surface_context.v1 JSON}
        {--locales=en,zh : Optional locale list}
        {--mode=artifact : Surface context mode; planner-only artifact mode is the default}
        {--api-artifact= : Optional backend/API surface artifact JSON}
        {--projection= : Optional runtime projection artifact for route/indexable hints}
        {--truth= : Optional runtime truth artifact for route/indexable hints}
        {--json : Emit JSON summary output}';

    protected $description = 'Export read-only Career surface context artifacts for canonical eligibility audit reruns.';

    public function handle(): int
    {
        $planPath = $this->normalizedOption('public-resolution-plan');
        $output = $this->normalizedOption('output');

        if ($planPath === null) {
            return $this->finish([
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'live_crawl_performed' => false,
                'by_reason' => ['public_resolution_plan_missing' => 1],
                'issues' => [[
                    'reason' => 'public_resolution_plan_missing',
                    'message' => 'A --public-resolution-plan JSON artifact is required.',
                ]],
            ], self::FAILURE);
        }

        if ($output === null) {
            return $this->finish([
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'live_crawl_performed' => false,
                'by_reason' => ['output_path_missing' => 1],
                'issues' => [[
                    'reason' => 'output_path_missing',
                    'message' => 'An --output path is required.',
                ]],
            ], self::FAILURE);
        }

        $planValidation = CareerPublicResolutionPlanResolver::fromPath($planPath);
        if ($planValidation->plan === null) {
            return $this->finish([
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'live_crawl_performed' => false,
                'by_reason' => $planValidation->byReason(),
                'plan_validation' => $planValidation->toArray(),
                'issues' => array_map(
                    static fn (CareerPublicResolutionPlanIssue $issue): array => $issue->toArray(),
                    $planValidation->issues
                ),
            ], self::FAILURE);
        }

        $slugs = $this->canonicalSlugs($planValidation->rows());
        $locales = $this->locales();
        $apiRows = $this->surfaceRowsByKey($this->readArtifactRows($this->normalizedOption('api-artifact')));
        $projectionRows = $this->surfaceRowsByKey($this->readArtifactRows($this->normalizedOption('projection')));
        $truthRows = $this->surfaceRowsByKey($this->readArtifactRows($this->normalizedOption('truth')));
        $duplicateInputSlugs = $this->duplicateInputSlugs($planValidation->rows());
        $duplicateArtifactRows = $this->duplicateArtifactKeys();

        $source = [
            'type' => 'read_only_surface_artifact',
            'generated_at' => now('UTC')->toISOString(),
            'environment' => app()->environment(),
            'mode' => $this->mode(),
            'base_url' => null,
            'planner_path' => $planValidation->sourcePath,
            'planner_checksum' => $planValidation->checksum(),
            'api_artifact_path' => $this->normalizedOption('api-artifact'),
            'projection_path' => $this->normalizedOption('projection'),
            'truth_path' => $this->normalizedOption('truth'),
            'locales' => $locales,
        ];

        $rows = [];
        $issueCounts = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $row = $this->surfaceRow(
                    slug: $slug,
                    locale: $locale,
                    apiRow: $apiRows[$this->rowKey($slug, $locale)] ?? null,
                    projectionRow: $projectionRows[$this->rowKey($slug, $locale)] ?? $projectionRows[$slug] ?? null,
                    truthRow: $truthRows[$this->rowKey($slug, $locale)] ?? $truthRows[$slug] ?? null,
                );
                foreach ($row['issues'] as $issue) {
                    $issueCounts[$issue] = ($issueCounts[$issue] ?? 0) + 1;
                }
                $rows[] = $row;
            }
        }
        ksort($issueCounts);

        $artifact = [
            'schema_version' => 'career_surface_context.v1',
            'source' => $source,
            'rows' => $rows,
        ];
        $this->writeJson($output, $artifact);

        $verifiedRows = count(array_filter($rows, static fn (array $row): bool => ($row['surface_verified'] ?? false) === true));
        $summary = [
            'status' => 'materialized',
            'read_only' => true,
            'writes_database' => false,
            'live_crawl_performed' => false,
            'public_resolution_plan' => $planValidation->sourcePath,
            'output_path' => $output,
            'expected_slugs' => count($slugs),
            'expected_rows' => count($slugs) * count($locales),
            'written_rows' => count($rows),
            'verified_rows' => $verifiedRows,
            'unverified_rows' => count($rows) - $verifiedRows,
            'duplicate_input_slugs' => count($duplicateInputSlugs),
            'duplicate_input_slug_values' => array_keys($duplicateInputSlugs),
            'duplicate_artifact_rows' => count($duplicateArtifactRows),
            'duplicate_artifact_row_keys' => $duplicateArtifactRows,
            'by_reason' => $issueCounts,
            'artifacts' => [
                'surface_context' => $output,
            ],
        ];

        return $this->finish($summary, self::SUCCESS);
    }

    private function normalizedOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function mode(): string
    {
        return $this->normalizedOption('mode') ?? 'artifact';
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        $raw = $this->normalizedOption('locales') ?? 'en,zh';
        $locales = [];
        foreach (explode(',', $raw) as $locale) {
            $normalized = strtolower(trim($locale));
            if ($normalized !== '' && ! in_array($normalized, $locales, true)) {
                $locales[] = $normalized;
            }
        }

        return $locales === [] ? ['en', 'zh'] : $locales;
    }

    /**
     * @param  list<\App\Domain\Career\Audit\CareerPublicResolutionPlanRow>  $rows
     * @return list<string>
     */
    private function canonicalSlugs(array $rows): array
    {
        $slugs = [];
        foreach ($rows as $row) {
            if ($row->canonicalSlug !== null && ! in_array($row->canonicalSlug, $slugs, true)) {
                $slugs[] = $row->canonicalSlug;
            }
        }

        return $slugs;
    }

    /**
     * @param  list<\App\Domain\Career\Audit\CareerPublicResolutionPlanRow>  $rows
     * @return array<string, true>
     */
    private function duplicateInputSlugs(array $rows): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($rows as $row) {
            if ($row->canonicalSlug === null) {
                continue;
            }
            if (isset($seen[$row->canonicalSlug])) {
                $duplicates[$row->canonicalSlug] = true;

                continue;
            }

            $seen[$row->canonicalSlug] = true;
        }

        return $duplicates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readArtifactRows(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            return [];
        }

        foreach ([$payload, $payload['rows'] ?? null, $payload['items'] ?? null, $payload['api']['rows'] ?? null, $payload['api']['items'] ?? null] as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, static fn (mixed $row): bool => is_array($row) && ! array_is_list($row)));
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function surfaceRowsByKey(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $slug = $this->normalizedSlug($row['canonical_slug'] ?? $row['slug'] ?? null);
            if ($slug === null) {
                continue;
            }
            $locale = $this->normalizedLocale($row['locale'] ?? null);
            $key = $locale === null ? $slug : $this->rowKey($slug, $locale);
            if (isset($indexed[$key])) {
                continue;
            }
            $indexed[$key] = $row;
        }

        return $indexed;
    }

    /**
     * @return list<string>
     */
    private function duplicateArtifactKeys(): array
    {
        $duplicates = [];
        foreach (['api-artifact', 'projection', 'truth'] as $option) {
            $seen = [];
            foreach ($this->readArtifactRows($this->normalizedOption($option)) as $row) {
                $slug = $this->normalizedSlug($row['canonical_slug'] ?? $row['slug'] ?? null);
                if ($slug === null) {
                    continue;
                }
                $locale = $this->normalizedLocale($row['locale'] ?? null);
                $key = $option.':'.($locale === null ? $slug : $this->rowKey($slug, $locale));
                if (isset($seen[$key]) && ! in_array($key, $duplicates, true)) {
                    $duplicates[] = $key;
                }
                $seen[$key] = true;
            }
        }
        sort($duplicates);

        return $duplicates;
    }

    /**
     * @param  array<string, mixed>|null  $apiRow
     * @param  array<string, mixed>|null  $projectionRow
     * @param  array<string, mixed>|null  $truthRow
     * @return array<string, mixed>
     */
    private function surfaceRow(string $slug, string $locale, ?array $apiRow, ?array $projectionRow, ?array $truthRow): array
    {
        $sourceRow = $apiRow ?? $truthRow ?? $projectionRow;
        $hasConcreteEvidence = $sourceRow !== null;
        $canonicalPath = $this->canonicalPath($sourceRow, $slug, $locale);
        $apiIndexable = $this->indexable($sourceRow);
        $issues = $hasConcreteEvidence ? [] : [
            CareerSurfaceContextArtifactIssue::SURFACE_ARTIFACT_MISSING,
            CareerSurfaceContextArtifactIssue::SURFACE_UNVERIFIED,
        ];

        return [
            'canonical_slug' => $slug,
            'locale' => $locale,
            'api_canonical_path' => $canonicalPath,
            'api_indexable' => $apiIndexable,
            'live_canonical_path' => $this->optionalString($sourceRow['live_canonical_path'] ?? null),
            'live_robots_policy' => $this->optionalString($sourceRow['live_robots_policy'] ?? $sourceRow['robots_policy'] ?? null),
            'cta_present' => array_key_exists('cta_present', $sourceRow ?? []) && is_bool($sourceRow['cta_present']) ? $sourceRow['cta_present'] : null,
            'surface_verified' => $hasConcreteEvidence,
            'surface_mode' => $hasConcreteEvidence ? 'artifact' : 'planner_only',
            'issues' => $issues,
            'evidence' => [
                'source' => $hasConcreteEvidence ? 'artifact' : 'planner_only',
                'planner_only_unverified' => ! $hasConcreteEvidence,
                'live_crawl_performed' => false,
                'api_artifact_present' => $apiRow !== null,
                'projection_artifact_present' => $projectionRow !== null,
                'truth_artifact_present' => $truthRow !== null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function canonicalPath(?array $row, string $slug, string $locale): string
    {
        $path = $this->optionalString($row['api_canonical_path'] ?? $row['canonical_path'] ?? $row['route'] ?? null);
        if ($path !== null) {
            return $path;
        }

        $url = $this->optionalString($row['api_canonical_url'] ?? $row['canonical_url'] ?? null);
        if ($url !== null) {
            $parsed = parse_url($url, PHP_URL_PATH);
            if (is_string($parsed) && trim($parsed) !== '') {
                return $parsed;
            }
        }

        return '/'.$locale.'/career/jobs/'.$slug;
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function indexable(?array $row): bool
    {
        $indexable = $row['api_indexable'] ?? $row['indexable'] ?? $row['robots_indexable'] ?? null;
        if (is_bool($indexable)) {
            return $indexable;
        }

        $robots = $this->optionalString($row['api_robots_policy'] ?? $row['robots_policy'] ?? $row['robots'] ?? null);
        if ($robots !== null) {
            return ! str_contains(strtolower($robots), 'noindex');
        }

        return true;
    }

    private function normalizedSlug(mixed $value): ?string
    {
        $normalized = $this->optionalString($value);

        return $normalized === null ? null : strtolower($normalized);
    }

    private function normalizedLocale(mixed $value): ?string
    {
        $normalized = $this->optionalString($value);

        return $normalized === null ? null : strtolower($normalized);
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function rowKey(string $slug, string $locale): string
    {
        return strtolower($slug).'|'.strtolower($locale);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        File::put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exitCode): int
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
            if (isset($payload['output_path'])) {
                $this->line('output_path='.(string) $payload['output_path']);
            }
        }

        return $exitCode;
    }
}
