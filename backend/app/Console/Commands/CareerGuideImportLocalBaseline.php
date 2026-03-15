<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\CareerCms\Baseline\CareerGuideBaselineImporter;
use App\CareerCms\Baseline\CareerGuideBaselineNormalizer;
use App\CareerCms\Baseline\CareerGuideBaselineReader;
use App\Models\CareerGuide;
use Illuminate\Console\Command;
use RuntimeException;

final class CareerGuideImportLocalBaseline extends Command
{
    protected $signature = 'career-guides:import-local-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--locale=* : Import only specific locale(s)}
        {--guide=* : Import only specific guide_code(s)}
        {--upsert : Update existing records instead of create-missing only}
        {--status= : Override imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed career guide baseline content into CareerGuide CMS tables.';

    public function handle(
        CareerGuideBaselineReader $reader,
        CareerGuideBaselineNormalizer $normalizer,
        CareerGuideBaselineImporter $importer,
    ): int {
        try {
            $statusOption = $this->option('status');
            $status = $statusOption === null ? null : trim((string) $statusOption);
            if ($status === '') {
                $status = null;
            }

            if ($status !== null && ! in_array($status, [CareerGuide::STATUS_DRAFT, CareerGuide::STATUS_PUBLISHED], true)) {
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
            $selectedGuides = array_values(array_filter(
                array_map(static fn (string $value): string => trim($value), (array) $this->option('guide')),
                static fn (string $value): bool => $value !== '',
            ));

            $documents = $reader->read($sourceDir, $selectedLocales);
            $guides = $normalizer->normalizeDocuments($documents, $selectedGuides);
            $summary = $importer->import($guides, [
                'dry_run' => (bool) $this->option('dry-run'),
                'upsert' => (bool) $this->option('upsert'),
                'status' => $status,
            ]);

            $this->line('baseline_source_dir='.$sourceDir);
            $this->line('locales_selected='.($selectedLocales === [] ? 'all' : implode(',', $selectedLocales)));
            $this->line('guides_selected='.($selectedGuides === [] ? 'all' : implode(',', $selectedGuides)));
            $this->line('dry_run='.((bool) $this->option('dry-run') ? '1' : '0'));
            $this->line('upsert='.((bool) $this->option('upsert') ? '1' : '0'));
            $this->line('status_mode='.(string) $summary['status_mode']);
            $this->line('files_found='.(string) count($documents));
            $this->line('guides_found='.(string) $summary['guides_found']);
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
