<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\TopicsCms\Baseline\TopicBaselineImporter;
use App\TopicsCms\Baseline\TopicBaselineNormalizer;
use App\TopicsCms\Baseline\TopicBaselineReader;
use Illuminate\Console\Command;
use RuntimeException;

final class TopicsImportLocalBaseline extends Command
{
    protected $signature = 'topics:import-local-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--locale=* : Import only specific locale(s)}
        {--topic=* : Import only specific topic_code(s)}
        {--upsert : Update existing records instead of create-missing only}
        {--status=draft : Force imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed topic baseline content into Topics CMS tables.';

    public function handle(
        TopicBaselineReader $reader,
        TopicBaselineNormalizer $normalizer,
        TopicBaselineImporter $importer,
    ): int {
        try {
            $status = trim((string) $this->option('status'));
            if (! in_array($status, [TopicBaselineImporter::STATUS_DRAFT, TopicBaselineImporter::STATUS_PUBLISHED], true)) {
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
            $selectedTopics = array_values(array_filter(
                array_map(static fn (string $value): string => trim($value), (array) $this->option('topic')),
                static fn (string $value): bool => $value !== '',
            ));

            $documents = $reader->read($sourceDir, $selectedLocales, $selectedTopics);
            $profiles = $normalizer->normalizeDocuments($documents);
            $summary = $importer->import($profiles, [
                'dry_run' => (bool) $this->option('dry-run'),
                'upsert' => (bool) $this->option('upsert'),
                'status' => $status,
            ]);

            $this->line('baseline_source_dir='.$sourceDir);
            $this->line('locales_selected='.($selectedLocales === [] ? 'all' : implode(',', $selectedLocales)));
            $this->line('topics_selected='.($selectedTopics === [] ? 'all' : implode(',', $selectedTopics)));
            $this->line('dry_run='.((bool) $this->option('dry-run') ? '1' : '0'));
            $this->line('upsert='.((bool) $this->option('upsert') ? '1' : '0'));
            $this->line('status_mode='.$status);
            $this->line('files_found='.(string) count($documents));
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
