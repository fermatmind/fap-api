<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentPage;
use App\Services\Career\Bundles\CareerJobListBundleBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class ReleaseVerifyPublicContent extends Command
{
    protected $signature = 'release:verify-public-content
        {--expected-occupations=0 : Optional Career dataset/jobs API member count contract; 0 records current count only}
        {--min-career-job-items=0 : Optional public career jobs API item count contract; 0 records current count only}
        {--public-resolution-ledger= : Optional Career full release ledger JSON artifact for public-resolution validation}
        {--expected-terminal-resolution-rows=2786 : Expected Career workbook rows classified in the public-resolution ledger}
        {--expected-canonical-public-assets=793 : Expected canonical public Career assets in the public-resolution ledger}
        {--expected-governed-non-public-rows=1993 : Expected governed non-public Career rows in the public-resolution ledger}
        {--content-source-dir=../content_baselines/content_pages : Content page baseline directory to verify against}
        {--strict-career : Fail when Career completeness checks are below release thresholds}
        {--json : Emit JSON output}';

    protected $description = 'Fail release when backend-authoritative public content required by frontend surfaces is incomplete.';

    public function handle(
        PublicCareerAuthorityResponseCache $careerAuthorityCache,
        CareerJobListBundleBuilder $careerJobListBundleBuilder,
    ): int {
        $careerCompletenessStrict = $this->careerCompletenessStrict();
        $summary = [
            'content_pages' => $this->verifyContentPages(),
            'career_dataset' => $this->verifyCareerDataset($careerAuthorityCache),
            'career_job_list' => $this->verifyCareerJobList($careerJobListBundleBuilder),
            'career_public_resolution' => $this->verifyCareerPublicResolutionLedger(),
        ];

        $blockingFailures = [];
        $warnings = [];
        foreach ($summary as $section => $result) {
            foreach ((array) ($result['failures'] ?? []) as $failure) {
                $entry = [
                    'section' => $section,
                    'failure' => $failure,
                ];

                if (! $careerCompletenessStrict && $this->isCareerCompletenessSection($section)) {
                    $warnings[] = $entry;

                    continue;
                }

                $blockingFailures[] = $entry;
            }
        }

        $payload = [
            'ok' => $blockingFailures === [],
            'career_completeness_strict' => $careerCompletenessStrict,
            'summary' => $summary,
            'failures' => $blockingFailures,
            'warnings' => $warnings,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->line(sprintf('career_completeness_strict=%s', $careerCompletenessStrict ? '1' : '0'));

            foreach ($summary as $section => $result) {
                $this->line(sprintf('%s ok=%s', $section, ($result['ok'] ?? false) ? '1' : '0'));
                foreach ((array) ($result['metrics'] ?? []) as $metric => $value) {
                    $this->line(sprintf('%s.%s=%s', $section, $metric, is_bool($value) ? ($value ? '1' : '0') : (string) $value));
                }
            }

            foreach ($warnings as $warning) {
                $this->warn('warning '.$warning['section'].': '.$warning['failure']);
            }

            foreach ($blockingFailures as $failure) {
                $this->error($failure['section'].': '.$failure['failure']);
            }
        }

        return $blockingFailures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function careerCompletenessStrict(): bool
    {
        if ((bool) $this->option('strict-career')) {
            return true;
        }

        return filter_var((string) (getenv('DEPLOY_PUBLIC_CONTENT_STRICT_CAREER') ?: ''), FILTER_VALIDATE_BOOLEAN);
    }

    private function isCareerCompletenessSection(string $section): bool
    {
        return in_array($section, ['career_dataset', 'career_job_list'], true);
    }

    /**
     * @return array{ok: bool, metrics: array<string, int>, failures: list<string>}
     */
    private function verifyContentPages(): array
    {
        $baselineRows = $this->loadContentPageBaselineRows();
        $failures = [];

        if ($baselineRows === []) {
            $failures[] = 'content_page_baseline_empty';
        }

        foreach ($baselineRows as $row) {
            $exists = ContentPage::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('slug', $row['slug'])
                ->where('locale', $row['locale'])
                ->where('kind', $row['kind'])
                ->where('path', $row['path'])
                ->publiclyReadable()
                ->exists();

            if (! $exists) {
                $failures[] = sprintf('missing_content_page:%s:%s', $row['locale'], $row['slug']);
            }
        }

        $publishedPublicCount = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->publiclyReadable()
            ->count();

        return [
            'ok' => $failures === [],
            'metrics' => [
                'baseline_count' => count($baselineRows),
                'published_public_count' => $publishedPublicCount,
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return list<array{slug: string, locale: string, kind: string, path: string}>
     */
    private function loadContentPageBaselineRows(): array
    {
        $sourceDir = $this->resolveContentSourceDir();
        $rows = [];

        foreach (File::glob($sourceDir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $slug = trim((string) ($row['slug'] ?? ''));
                $locale = trim((string) ($row['locale'] ?? ''));
                $kind = trim((string) ($row['kind'] ?? ''));
                $path = trim((string) ($row['path'] ?? ''));

                if ($slug === '' || $locale === '' || $kind === '' || $path === '') {
                    continue;
                }

                $rows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'kind' => $kind,
                    'path' => $path,
                ];
            }
        }

        return $rows;
    }

    private function resolveContentSourceDir(): string
    {
        $sourceDir = trim((string) $this->option('content-source-dir'));
        $candidate = $sourceDir !== '' && $this->isAbsolutePath($sourceDir)
            ? $sourceDir
            : base_path($sourceDir !== '' ? $sourceDir : '../content_baselines/content_pages');

        $real = realpath($candidate);
        if (! is_string($real) || ! is_dir($real)) {
            throw new \RuntimeException("Content page baseline directory not found: {$candidate}");
        }

        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * @return array{ok: bool, metrics: array<string, int|bool|string>, failures: list<string>}
     */
    private function verifyCareerDataset(PublicCareerAuthorityResponseCache $careerAuthorityCache): array
    {
        $expectedDatasetMemberCount = max(0, (int) $this->option('expected-occupations'));
        $payload = $careerAuthorityCache->datasetHubPayload();
        $memberCount = (int) data_get($payload, 'collection_summary.member_count', 0);
        $includedCount = (int) data_get($payload, 'collection_summary.included_count', 0);
        $excludedCount = (int) data_get($payload, 'collection_summary.excluded_count', 0);
        $publicDetailIndexableCount = (int) data_get($payload, 'collection_summary.public_detail_indexable_count', 0);
        $trackedTotal = (int) data_get($payload, 'collection_summary.tracking_counts.tracked_total_occupations', 0);
        $missingOccupations = (int) data_get($payload, 'collection_summary.tracking_counts.missing_occupations', 0);
        $trackingComplete = (bool) data_get($payload, 'collection_summary.tracking_counts.tracking_complete', false);

        $failures = [];
        if ($expectedDatasetMemberCount > 0 && $memberCount < $expectedDatasetMemberCount) {
            $failures[] = "career_dataset_member_count_below_expected:{$memberCount}<{$expectedDatasetMemberCount}";
        }

        if ($expectedDatasetMemberCount > 0 && ! $trackingComplete) {
            $failures[] = 'career_dataset_tracking_incomplete';
        }

        if ($expectedDatasetMemberCount > 0 && $missingOccupations !== 0) {
            $failures[] = "career_dataset_missing_occupations:{$missingOccupations}";
        }

        return [
            'ok' => $failures === [],
            'metrics' => [
                'expected_dataset_member_count' => $expectedDatasetMemberCount,
                'member_count' => $memberCount,
                'included_count' => $includedCount,
                'excluded_count' => $excludedCount,
                'public_detail_indexable_count' => $publicDetailIndexableCount,
                'tracked_total_occupations' => $trackedTotal,
                'missing_occupations' => $missingOccupations,
                'tracking_complete' => $trackingComplete,
                'contract_scope' => 'dataset_jobs_api_count',
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return array{ok: bool, metrics: array<string, int|string>, failures: list<string>}
     */
    private function verifyCareerJobList(CareerJobListBundleBuilder $careerJobListBundleBuilder): array
    {
        $minimumItems = max(0, (int) $this->option('min-career-job-items'));
        $items = $careerJobListBundleBuilder->build();
        $itemCount = count($items);
        $failures = [];

        if ($itemCount < $minimumItems) {
            $failures[] = "career_job_list_count_below_expected:{$itemCount}<{$minimumItems}";
        }

        return [
            'ok' => $failures === [],
            'metrics' => [
                'min_career_job_items' => $minimumItems,
                'item_count' => $itemCount,
                'contract_scope' => 'career_jobs_api_count',
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return array{ok: bool, metrics: array<string, int|bool|string>, failures: list<string>}
     */
    private function verifyCareerPublicResolutionLedger(): array
    {
        $ledgerPath = trim((string) ($this->option('public-resolution-ledger') ?? ''));
        if ($ledgerPath === '') {
            return [
                'ok' => true,
                'metrics' => [
                    'enabled' => false,
                    'contract_scope' => 'career_public_resolution_ledger',
                ],
                'failures' => [],
            ];
        }

        $rows = $this->loadPublicResolutionRows($ledgerPath);
        $expectedTerminalRows = max(0, (int) $this->option('expected-terminal-resolution-rows'));
        $expectedCanonicalAssets = max(0, (int) $this->option('expected-canonical-public-assets'));
        $expectedGovernedRows = max(0, (int) $this->option('expected-governed-non-public-rows'));
        $totalRows = count($rows);
        $canonicalAssets = 0;
        $heldLeakage = 0;
        $heldPublicNoindexLeakage = 0;
        $softwareDevelopersLeakage = 0;
        $sitemapBadCount = 0;
        $llmsBadCount = 0;
        $llmsFullBadCount = 0;

        foreach ($rows as $row) {
            $type = trim((string) ($row['public_resolution_type'] ?? ''));
            $status = trim((string) ($row['current_status'] ?? ''));
            $slug = trim((string) ($row['source_slug'] ?? ''));
            $isCanonical = $type === CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB;
            $isHeld = in_array($status, ['duplicate_identity_hold', 'CN_proxy_hold', 'broad_group_hold', 'manual_hold'], true);
            $publicUrlAllowed = $this->publicResolutionTypeAllowsPublicUrl($type);
            $sitemapEligible = (bool) ($row['sitemap_eligible'] ?? false);
            $llmsEligible = (bool) ($row['llms_eligible'] ?? false);
            $llmsFullEligible = (bool) ($row['llms_full_eligible'] ?? false);
            $publicEligible = (bool) ($row['public_eligible'] ?? false);

            if ($isCanonical) {
                $canonicalAssets++;
                if (! $sitemapEligible) {
                    $sitemapBadCount++;
                }
                if (! $llmsEligible) {
                    $llmsBadCount++;
                }
                if (! $llmsFullEligible) {
                    $llmsFullBadCount++;
                }
            } else {
                if ($sitemapEligible) {
                    $sitemapBadCount++;
                }
                if ($llmsEligible) {
                    $llmsBadCount++;
                }
                if ($llmsFullEligible) {
                    $llmsFullBadCount++;
                }
            }

            if ($isHeld && $isCanonical) {
                $heldLeakage++;
            }
            if ($isHeld && $publicUrlAllowed && ! $isCanonical) {
                $heldPublicNoindexLeakage++;
            }

            if ($slug === 'software-developers' && ($publicEligible || $sitemapEligible || $llmsEligible || $llmsFullEligible || $isCanonical)) {
                $softwareDevelopersLeakage++;
            }
        }

        $governedNonPublicRows = $totalRows - $canonicalAssets;
        $failures = [];

        if ($expectedTerminalRows > 0 && $totalRows !== $expectedTerminalRows) {
            $failures[] = "career_public_resolution_terminal_rows_mismatch:{$totalRows}<>{$expectedTerminalRows}";
        }
        if ($expectedCanonicalAssets > 0 && $canonicalAssets !== $expectedCanonicalAssets) {
            $failures[] = "career_public_resolution_canonical_public_assets_mismatch:{$canonicalAssets}<>{$expectedCanonicalAssets}";
        }
        if ($expectedGovernedRows > 0 && $governedNonPublicRows !== $expectedGovernedRows) {
            $failures[] = "career_public_resolution_governed_non_public_rows_mismatch:{$governedNonPublicRows}<>{$expectedGovernedRows}";
        }
        if ($heldLeakage > 0) {
            $failures[] = "career_public_resolution_held_leakage:{$heldLeakage}";
        }
        if ($heldPublicNoindexLeakage > 0) {
            $failures[] = "career_public_resolution_held_public_noindex_leakage:{$heldPublicNoindexLeakage}";
        }
        if ($softwareDevelopersLeakage > 0) {
            $failures[] = "career_public_resolution_software_developers_leakage:{$softwareDevelopersLeakage}";
        }
        if ($sitemapBadCount > 0) {
            $failures[] = "career_public_resolution_sitemap_bad_count:{$sitemapBadCount}";
        }
        if ($llmsBadCount > 0) {
            $failures[] = "career_public_resolution_llms_bad_count:{$llmsBadCount}";
        }
        if ($llmsFullBadCount > 0) {
            $failures[] = "career_public_resolution_llms_full_bad_count:{$llmsFullBadCount}";
        }

        return [
            'ok' => $failures === [],
            'metrics' => [
                'enabled' => true,
                'contract_scope' => 'career_public_resolution_ledger',
                'ledger_path' => $ledgerPath,
                'terminal_resolution_rows' => $totalRows,
                'canonical_public_assets' => $canonicalAssets,
                'governed_non_public_rows' => $governedNonPublicRows,
                'dataset_jobs_member_count_contract_is_separate' => true,
                'held_leakage' => $heldLeakage,
                'held_public_noindex_leakage' => $heldPublicNoindexLeakage,
                'software_developers_leakage' => $softwareDevelopersLeakage,
                'sitemap_bad_count' => $sitemapBadCount,
                'llms_bad_count' => $llmsBadCount,
                'llms_full_bad_count' => $llmsFullBadCount,
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPublicResolutionRows(string $ledgerPath): array
    {
        if (! is_file($ledgerPath)) {
            throw new \RuntimeException('career public resolution ledger artifact not found: '.$ledgerPath);
        }

        $decoded = json_decode((string) file_get_contents($ledgerPath), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('career public resolution ledger artifact is not valid JSON: '.$ledgerPath);
        }

        $rows = data_get($decoded, 'public_resolution.rows');
        if (! is_array($rows)) {
            $rows = data_get($decoded, 'rows');
        }
        if (! is_array($rows)) {
            throw new \RuntimeException('career public resolution ledger artifact has no public resolution rows: '.$ledgerPath);
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
    }

    private function publicResolutionTypeAllowsPublicUrl(string $type): bool
    {
        if ($type === '') {
            return false;
        }

        try {
            return (bool) (CareerPublicResolutionTypeMatrix::policyFor($type)['public_url_allowed'] ?? false);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
