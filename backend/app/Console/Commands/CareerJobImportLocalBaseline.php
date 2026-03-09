<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\CareerCms\Baseline\CareerJobBaselineImporter;
use App\CareerCms\Baseline\CareerJobBaselineNormalizer;
use App\CareerCms\Baseline\CareerJobBaselineReader;
use App\Models\CareerJob;
use Illuminate\Console\Command;
use RuntimeException;

final class CareerJobImportLocalBaseline extends Command
{
    protected $signature = 'career-jobs:import-local-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--locale=* : Import only specific locale(s)}
        {--job=* : Import only specific job code(s)}
        {--upsert : Update existing records instead of create-missing only}
        {--status=draft : Force imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed career jobs baseline content into CareerJob CMS tables.';

    public function handle(
        CareerJobBaselineReader $reader,
        CareerJobBaselineNormalizer $normalizer,
        CareerJobBaselineImporter $importer,
    ): int {
        try {
            $status = trim((string) $this->option('status'));
            if (! in_array($status, [CareerJob::STATUS_DRAFT, CareerJob::STATUS_PUBLISHED], true)) {
                throw new RuntimeException(sprintf(
                    'Unsupported --status value: %s',
                    $status,
                ));
            }

            $sourceDir = $reader->resolveSourceDir(
                $this->option('source-dir') !== null
                    ? (string) $this->option('source-dir')
                    : null,
            );
            $selectedLocales = array_values(array_filter(
                array_map(static fn (string $value): string => trim($value), (array) $this->option('locale')),
                static fn (string $value): bool => $value !== '',
            ));
            $selectedJobs = array_values(array_filter(
                array_map(static fn (string $value): string => trim($value), (array) $this->option('job')),
                static fn (string $value): bool => $value !== '',
            ));

            $documents = $reader->read($sourceDir, $selectedLocales);
            $jobs = $normalizer->normalizeDocuments($documents, $selectedJobs);
            $summary = $importer->import($jobs, [
                'dry_run' => (bool) $this->option('dry-run'),
                'upsert' => (bool) $this->option('upsert'),
                'status' => $status,
            ]);

            $this->line('baseline_source_dir='.$sourceDir);
            $this->line('locales_selected='.($selectedLocales === [] ? 'all' : implode(',', $selectedLocales)));
            $this->line('jobs_selected='.($selectedJobs === [] ? 'all' : implode(',', $selectedJobs)));
            $this->line('dry_run='.((bool) $this->option('dry-run') ? '1' : '0'));
            $this->line('upsert='.((bool) $this->option('upsert') ? '1' : '0'));
            $this->line('status_mode='.$status);
            $this->line('files_found='.(string) count($documents));
            $this->line('jobs_found='.(string) $summary['jobs_found']);
            $this->line('will_create='.(string) $summary['will_create']);
            $this->line('will_update='.(string) $summary['will_update']);
            $this->line('will_skip='.(string) $summary['will_skip']);
            $this->line('revisions_to_create='.(string) $summary['revisions_to_create']);
            $this->line('errors_count='.(string) $summary['errors_count']);

            $this->info((bool) $this->option('dry-run') ? 'dry-run complete' : 'import complete');

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
