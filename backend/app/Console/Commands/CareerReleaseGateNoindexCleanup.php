<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerJob;
use App\Services\Ops\SeoOperationsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class CareerReleaseGateNoindexCleanup extends Command
{
    protected $signature = 'career:release-gate-noindex-cleanup
        {--scope= : Path to /tmp/career_release_gate_noindex_scope.json}
        {--force : Persist approved indexability and robots cleanup}
        {--output= : Optional JSON report output path}';

    protected $description = 'Scoped dry-run/force cleanup for approved Career release gate noindex blockers.';

    public function __construct(private readonly SeoOperationsService $seoOperations)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $scope = $this->loadScope();
            $slugs = $this->validatedSlugs($scope);
            $records = $this->loadRecords($slugs);
            $force = (bool) $this->option('force');
            $preDisplayAssets = $this->displayAssetCount();

            $targets = $this->targetRecords($records);

            if ($force) {
                $selectionKeys = array_map(
                    static fn (CareerJob $record): string => 'job:'.(int) $record->id,
                    $targets,
                );

                foreach ([
                    SeoOperationsService::ACTION_FILL_METADATA,
                    SeoOperationsService::ACTION_SYNC_CANONICAL,
                    SeoOperationsService::ACTION_MARK_INDEXABLE,
                ] as $action) {
                    $this->seoOperations->applyBulkAction($selectionKeys, $action, [0]);
                }
            }

            $postDisplayAssets = $this->displayAssetCount();
            $report = [
                'command' => 'career:release-gate-noindex-cleanup',
                'scope' => (string) $this->option('scope'),
                'dry_run' => ! $force,
                'did_write' => $force,
                'approved_slug_count' => count($slugs),
                'approved_record_count' => count($records),
                'target_record_count' => count($targets),
                'held_rows_updated' => 0,
                'software_developers_updated' => 0,
                'career_job_display_assets' => [
                    'pre' => $preDisplayAssets,
                    'post' => $postDisplayAssets,
                    'delta' => $postDisplayAssets === null || $preDisplayAssets === null ? null : $postDisplayAssets - $preDisplayAssets,
                ],
                'records' => array_map(
                    fn (CareerJob $record): array => $this->recordReport($record),
                    $targets,
                ),
                'blockers' => [],
            ];

            return $this->finish($report, 0);
        } catch (Throwable $throwable) {
            return $this->finish([
                'command' => 'career:release-gate-noindex-cleanup',
                'dry_run' => ! (bool) $this->option('force'),
                'did_write' => false,
                'blockers' => [$throwable->getMessage()],
            ], 1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadScope(): array
    {
        $path = trim((string) $this->option('scope'));
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('--scope must point to a readable noindex scope JSON file.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Scope JSON is invalid.');
        }

        if (($decoded['scope'] ?? null) !== 'career_release_gate_noindex_cleanup') {
            throw new RuntimeException('Scope is not career_release_gate_noindex_cleanup.');
        }

        if (($decoded['safety']['all_noindex_slugs_are_imported_canonical_assets'] ?? false) !== true) {
            throw new RuntimeException('Scope safety does not prove all noindex slugs are imported canonical Career assets.');
        }

        if (($decoded['safety']['software_developers_included'] ?? false) === true) {
            throw new RuntimeException('Scope includes software-developers.');
        }

        if (($decoded['blockers'] ?? []) !== []) {
            throw new RuntimeException('Scope contains blockers.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<string>
     */
    private function validatedSlugs(array $scope): array
    {
        $slugs = $scope['noindex_blockers']['slugs'] ?? null;
        if (! is_array($slugs) || $slugs === []) {
            throw new RuntimeException('Scope does not contain noindex blocker slugs.');
        }

        $normalized = array_values(array_unique(array_map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
            $slugs,
        )));

        if (in_array('', $normalized, true)) {
            throw new RuntimeException('Scope contains an empty slug.');
        }

        if (in_array('software-developers', $normalized, true)) {
            throw new RuntimeException('Scope contains software-developers.');
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<CareerJob>
     */
    private function loadRecords(array $slugs): array
    {
        $records = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('slug', $slugs)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->whereIn('locale', CareerJob::SUPPORTED_LOCALES)
            ->with('seoMeta')
            ->orderBy('slug')
            ->orderBy('locale')
            ->get()
            ->all();

        $seen = [];
        foreach ($records as $record) {
            $seen[(string) $record->slug][(string) $record->locale] = true;
        }

        $missing = [];
        foreach ($slugs as $slug) {
            foreach (CareerJob::SUPPORTED_LOCALES as $locale) {
                if (($seen[$slug][$locale] ?? false) !== true) {
                    $missing[] = $slug.':'.$locale;
                }
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Missing published public CareerJob rows for scope: '.implode(', ', array_slice($missing, 0, 20)));
        }

        return $records;
    }

    /**
     * @param  list<CareerJob>  $records
     * @return list<CareerJob>
     */
    private function targetRecords(array $records): array
    {
        return array_values(array_filter($records, function (CareerJob $record): bool {
            $robots = strtolower(trim((string) data_get($record->seoMeta, 'robots', '')));
            $canonical = trim((string) data_get($record->seoMeta, 'canonical_url', ''));

            return ! (bool) $record->is_indexable
                || $robots === ''
                || str_contains($robots, 'noindex')
                || $canonical !== $this->seoOperations->expectedCanonical('job', $record);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function recordReport(CareerJob $record): array
    {
        return [
            'id' => (int) $record->id,
            'slug' => (string) $record->slug,
            'locale' => (string) $record->locale,
            'status' => (string) $record->status,
            'is_public' => (bool) $record->is_public,
            'is_indexable_before' => (bool) $record->is_indexable,
            'robots_before' => (string) data_get($record->seoMeta, 'robots', ''),
            'canonical_before' => (string) data_get($record->seoMeta, 'canonical_url', ''),
            'target_robots' => 'index,follow',
            'target_canonical' => $this->seoOperations->expectedCanonical('job', $record),
        ];
    }

    private function displayAssetCount(): ?int
    {
        if (! Schema::hasTable('career_job_display_assets')) {
            return null;
        }

        return (int) DB::table('career_job_display_assets')->count();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, int $exitCode): int
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode noindex cleanup report.');
        }

        $output = trim((string) ($this->option('output') ?? ''));
        if ($output !== '') {
            $written = file_put_contents($output, $json.PHP_EOL);
            if ($written === false) {
                throw new RuntimeException('Unable to write report output: '.$output);
            }
        }

        $this->output->writeln($json);

        return $exitCode;
    }
}
