<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerImportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use SplFileObject;
use Throwable;

final class CareerExportOccupationDirectoryReviewQueues extends Command
{
    protected $signature = 'career:export-occupation-directory-review-queues
        {--input= : Path to career_create_import.jsonl}
        {--alias-review= : Optional career_alias_review.csv path}
        {--child-role-review= : Optional career_child_role_review.csv path}
        {--import-run= : Optional staged CareerImportRun id}
        {--output-dir= : Directory for generated review CSV files}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Export editable review queues for staged occupation-directory candidates.';

    public function handle(): int
    {
        try {
            $inputPath = $this->requiredPath('input');
            $outputDir = trim((string) $this->option('output-dir'));
            if ($outputDir === '') {
                throw new \RuntimeException('--output-dir is required.');
            }

            File::ensureDirectoryExists($outputDir);

            $records = $this->readJsonl($inputPath);
            $aliasRows = $this->optionalCsvRows('alias-review');
            $childRows = $this->optionalCsvRows('child-role-review');
            $importRun = $this->resolveImportRun();

            $translationPath = rtrim($outputDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'translation_review_queue.csv';
            $aliasPath = rtrim($outputDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'alias_review_decisions.csv';
            $childPath = rtrim($outputDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'child_role_review_decisions.csv';
            $manifestPath = rtrim($outputDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'review_manifest.json';

            $translationCount = $this->writeTranslationQueue($translationPath, $records, $importRun);
            $aliasCount = $this->writeDecisionQueue($aliasPath, $aliasRows, [
                'review_decision',
                'approved_target_slug',
                'approved_alias_zh',
                'approved_alias_en',
                'reviewer_notes',
            ]);
            $childCount = $this->writeDecisionQueue($childPath, $childRows, [
                'review_decision',
                'approved_parent_slug',
                'approved_child_title_zh',
                'approved_child_title_en',
                'reviewer_notes',
            ]);

            $summary = [
                'import_run_id' => $importRun?->id,
                'translation_review_total' => $translationCount,
                'alias_review_total' => $aliasCount,
                'child_role_review_total' => $childCount,
                'output_dir' => $outputDir,
                'files' => [
                    'translation_review_queue' => $translationPath,
                    'alias_review_decisions' => $aliasPath,
                    'child_role_review_decisions' => $childPath,
                    'review_manifest' => $manifestPath,
                ],
            ];

            file_put_contents($manifestPath, json_encode([
                'package_kind' => 'career_occupation_directory_review_queues',
                'generated_at' => now()->toISOString(),
                ...$summary,
                'review_contract' => [
                    'translation_review_queue.review_decision' => 'approve | edit | reject | merge_alias',
                    'alias_review_decisions.review_decision' => 'alias_existing | create_separate | reject | needs_research',
                    'child_role_review_decisions.review_decision' => 'alias_parent | child_role | create_top_level | reject | needs_research',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if ((bool) $this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->line('translation_review_total='.$translationCount);
                $this->line('alias_review_total='.$aliasCount);
                $this->line('child_role_review_total='.$childCount);
                $this->line('output_dir='.$outputDir);
            }

            $this->info('review queues exported');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredPath(string $option): string
    {
        $path = trim((string) $this->option($option));
        if ($path === '') {
            throw new \RuntimeException('--'.$option.' is required.');
        }
        if (! is_file($path)) {
            throw new \RuntimeException('--'.$option.' file does not exist: '.$path);
        }

        return $path;
    }

    private function resolveImportRun(): ?CareerImportRun
    {
        $id = trim((string) ($this->option('import-run') ?? ''));
        if ($id === '') {
            return null;
        }

        $run = CareerImportRun::query()->find($id);
        if (! $run instanceof CareerImportRun) {
            throw new \RuntimeException('--import-run not found: '.$id);
        }

        return $run;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonl(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $records = [];
        $lineNumber = 0;

        while (! $file->eof()) {
            $lineNumber++;
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Invalid JSONL record at line '.$lineNumber.'.');
            }
            $records[] = $decoded;
        }

        return $records;
    }

    /**
     * @return list<array<string, string>>
     */
    private function optionalCsvRows(string $option): array
    {
        $path = trim((string) ($this->option($option) ?? ''));
        if ($path === '') {
            return [];
        }
        if (! is_file($path)) {
            throw new \RuntimeException('--'.$option.' file does not exist: '.$path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file: '.$path);
        }

        $header = null;
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($header === null) {
                if (isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                }
                $header = array_map(static fn ($value): string => trim((string) $value), $row);

                continue;
            }

            $assoc = [];
            foreach ($header as $index => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = (string) ($row[$index] ?? '');
            }
            if ($assoc !== []) {
                $rows[] = $assoc;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function writeTranslationQueue(string $path, array $records, ?CareerImportRun $importRun): int
    {
        $header = [
            'import_run_id',
            'market',
            'authority_source',
            'authority_code',
            'proposed_slug',
            'source_title_zh',
            'source_title_en',
            'suggested_title_zh',
            'suggested_title_en',
            'translation_status',
            'review_decision',
            'approved_title_zh',
            'approved_title_en',
            'reviewer_notes',
            'source_url',
            'definition',
        ];
        $handle = $this->openCsv($path);
        fputcsv($handle, $header);

        $count = 0;
        foreach ($records as $record) {
            fputcsv($handle, [
                $importRun?->id ?? '',
                (string) ($record['market'] ?? ''),
                (string) data_get($record, 'authority.source'),
                (string) data_get($record, 'authority.code'),
                (string) data_get($record, 'identity.proposed_slug'),
                (string) data_get($record, 'identity.source_title_zh'),
                (string) data_get($record, 'identity.source_title_en'),
                (string) data_get($record, 'identity.canonical_title_zh'),
                (string) data_get($record, 'identity.canonical_title_en'),
                (string) data_get($record, 'localization.translation_status'),
                '',
                '',
                '',
                '',
                (string) data_get($record, 'authority.source_url'),
                (string) data_get($record, 'content_seed.definition'),
            ]);
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $decisionColumns
     */
    private function writeDecisionQueue(string $path, array $rows, array $decisionColumns): int
    {
        $sourceHeader = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (! in_array($key, $sourceHeader, true)) {
                    $sourceHeader[] = $key;
                }
            }
        }

        $handle = $this->openCsv($path);
        fputcsv($handle, array_merge($sourceHeader, $decisionColumns));
        foreach ($rows as $row) {
            $values = [];
            foreach ($sourceHeader as $key) {
                $values[] = $row[$key] ?? '';
            }
            foreach ($decisionColumns as $column) {
                $values[] = '';
            }
            fputcsv($handle, $values);
        }
        fclose($handle);

        return count($rows);
    }

    /**
     * @return resource
     */
    private function openCsv(string $path): mixed
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to write CSV file: '.$path);
        }

        fwrite($handle, "\xEF\xBB\xBF");

        return $handle;
    }
}
