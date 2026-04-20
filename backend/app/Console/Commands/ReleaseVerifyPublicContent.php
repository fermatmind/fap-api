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
        {--expected-occupations=2787 : Required full career dataset member count}
        {--min-career-job-items=2786 : Required public career job list item count}
        {--content-source-dir=../content_baselines/content_pages : Content page baseline directory to verify against}
        {--json : Emit JSON output}';

    protected $description = 'Fail release when backend-authoritative public content required by frontend surfaces is incomplete.';

    public function handle(
        PublicCareerAuthorityResponseCache $careerAuthorityCache,
        CareerJobListBundleBuilder $careerJobListBundleBuilder,
    ): int {
        $summary = [
            'content_pages' => $this->verifyContentPages(),
            'career_dataset' => $this->verifyCareerDataset($careerAuthorityCache),
            'career_job_list' => $this->verifyCareerJobList($careerJobListBundleBuilder),
        ];

        $failures = [];
        foreach ($summary as $section => $result) {
            foreach ((array) ($result['failures'] ?? []) as $failure) {
                $failures[] = [
                    'section' => $section,
                    'failure' => $failure,
                ];
            }
        }

        $payload = [
            'ok' => $failures === [],
            'summary' => $summary,
            'failures' => $failures,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            foreach ($summary as $section => $result) {
                $this->line(sprintf('%s ok=%s', $section, ($result['ok'] ?? false) ? '1' : '0'));
                foreach ((array) ($result['metrics'] ?? []) as $metric => $value) {
                    $this->line(sprintf('%s.%s=%s', $section, $metric, is_bool($value) ? ($value ? '1' : '0') : (string) $value));
                }
            }

            foreach ($failures as $failure) {
                $this->error($failure['section'].': '.$failure['failure']);
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
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
                ->where('status', ContentPage::STATUS_PUBLISHED)
                ->where('is_public', true)
                ->exists();

            if (! $exists) {
                $failures[] = sprintf('missing_content_page:%s:%s', $row['locale'], $row['slug']);
            }
        }

        $publishedPublicCount = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', ContentPage::STATUS_PUBLISHED)
            ->where('is_public', true)
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
     * @return array{ok: bool, metrics: array<string, int|bool>, failures: list<string>}
     */
    private function verifyCareerDataset(PublicCareerAuthorityResponseCache $careerAuthorityCache): array
    {
        $expectedOccupations = max(0, (int) $this->option('expected-occupations'));
        $payload = $careerAuthorityCache->datasetHubPayload();
        $memberCount = (int) data_get($payload, 'collection_summary.member_count', 0);
        $trackedTotal = (int) data_get($payload, 'collection_summary.tracking_counts.tracked_total_occupations', 0);
        $missingOccupations = (int) data_get($payload, 'collection_summary.tracking_counts.missing_occupations', 0);
        $trackingComplete = (bool) data_get($payload, 'collection_summary.tracking_counts.tracking_complete', false);

        $failures = [];
        if ($memberCount < $expectedOccupations) {
            $failures[] = "career_dataset_member_count_below_expected:{$memberCount}<{$expectedOccupations}";
        }

        if ($expectedOccupations > 0 && ! $trackingComplete) {
            $failures[] = 'career_dataset_tracking_incomplete';
        }

        if ($expectedOccupations > 0 && $missingOccupations !== 0) {
            $failures[] = "career_dataset_missing_occupations:{$missingOccupations}";
        }

        return [
            'ok' => $failures === [],
            'metrics' => [
                'expected_occupations' => $expectedOccupations,
                'member_count' => $memberCount,
                'tracked_total_occupations' => $trackedTotal,
                'missing_occupations' => $missingOccupations,
                'tracking_complete' => $trackingComplete,
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return array{ok: bool, metrics: array<string, int>, failures: list<string>}
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
            ],
            'failures' => $failures,
        ];
    }
}
