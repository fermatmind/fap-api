<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\PersonalityCms\Baseline\PersonalityBaselineImporter;
use App\PersonalityCms\Baseline\PersonalityBaselineNormalizer;
use App\PersonalityCms\Baseline\PersonalityBaselineReader;
use Illuminate\Console\Command;
use RuntimeException;

final class PersonalityImportLocalBaseline extends Command
{
    protected $signature = 'personality:import-local-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--locale=* : Import only specific locale(s)}
        {--type=* : Import only specific MBTI type code(s)}
        {--upsert : Update existing records instead of create-missing only}
        {--status=draft : Force imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed MBTI personality baseline content into Personality CMS tables.';

    public function handle(
        PersonalityBaselineReader $reader,
        PersonalityBaselineNormalizer $normalizer,
        PersonalityBaselineImporter $importer,
    ): int {
        try {
            $status = trim((string) $this->option('status'));
            if (! in_array($status, ['draft', 'published'], true)) {
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
            $profiles = $normalizer->normalizeDocuments($documents, $selectedTypes);
            $summary = $importer->import($profiles, [
                'dry_run' => (bool) $this->option('dry-run'),
                'upsert' => (bool) $this->option('upsert'),
                'status' => $status,
            ]);

            $this->line('baseline_source_dir='.$sourceDir);
            $this->line('locales_selected='.($selectedLocales === [] ? 'all' : implode(',', $selectedLocales)));
            $this->line('types_selected='.($selectedTypes === [] ? 'all' : implode(',', $selectedTypes)));
            $this->line('dry_run='.((bool) $this->option('dry-run') ? '1' : '0'));
            $this->line('upsert='.((bool) $this->option('upsert') ? '1' : '0'));
            $this->line('status_mode='.$status);
            $this->line('profiles_found='.(string) $summary['profiles_found']);
            $this->line('will_create='.(string) $summary['will_create']);
            $this->line('will_update='.(string) $summary['will_update']);
            $this->line('will_skip='.(string) $summary['will_skip']);
            $this->line('revisions_to_create='.(string) $summary['revisions_to_create']);
            $this->line('errors_count='.(string) $summary['errors_count']);

            $this->info((bool) $this->option('dry-run') ? 'dry-run complete' : 'import complete');

            return 0;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return 1;
        }
    }
}
