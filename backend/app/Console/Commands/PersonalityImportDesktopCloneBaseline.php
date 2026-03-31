<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineImporter;
use App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineNormalizer;
use App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineReader;
use Illuminate\Console\Command;
use RuntimeException;

final class PersonalityImportDesktopCloneBaseline extends Command
{
    protected $signature = 'personality:import-desktop-clone-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--locale=* : Import only specific locale(s)}
        {--type=* : Import only specific MBTI full code(s)}
        {--upsert : Update existing records instead of create-missing only}
        {--status=published : Force imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed MBTI desktop clone baseline content into personality variant clone content owner tables.';

    public function handle(
        PersonalityDesktopCloneBaselineReader $reader,
        PersonalityDesktopCloneBaselineNormalizer $normalizer,
        PersonalityDesktopCloneBaselineImporter $importer,
    ): int {
        try {
            $status = strtolower(trim((string) $this->option('status')));
            if (! in_array($status, [
                PersonalityProfileVariantCloneContent::STATUS_DRAFT,
                PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
            ], true)) {
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
            $selectedTypes = array_values(array_filter(
                array_map(static fn (string $value): string => trim($value), (array) $this->option('type')),
                static fn (string $value): bool => $value !== '',
            ));

            $documents = $reader->read($sourceDir, $selectedLocales);
            $rows = $normalizer->normalizeDocuments($documents, $selectedTypes);
            $summary = $importer->import($rows, [
                'dry_run' => (bool) $this->option('dry-run'),
                'upsert' => (bool) $this->option('upsert'),
                'status' => $status,
            ]);

            $this->line('baseline_source_dir='.$sourceDir);
            $this->line('locales_selected='.($selectedLocales === [] ? 'all' : implode(',', $selectedLocales)));
            $this->line('types_selected='.($selectedTypes === [] ? 'all' : implode(',', array_map('strtoupper', $selectedTypes))));
            $this->line('dry_run='.((bool) $this->option('dry-run') ? '1' : '0'));
            $this->line('upsert='.((bool) $this->option('upsert') ? '1' : '0'));
            $this->line('status_mode='.$status);
            $this->line('rows_found='.(string) $summary['rows_found']);
            $this->line('will_create='.(string) $summary['will_create']);
            $this->line('will_update='.(string) $summary['will_update']);
            $this->line('will_skip='.(string) $summary['will_skip']);
            $this->line('errors_count='.(string) $summary['errors_count']);

            $this->info((bool) $this->option('dry-run') ? 'dry-run complete' : 'import complete');

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
