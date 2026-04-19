<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SplFileObject;
use Throwable;

final class CareerImportOccupationDirectoryDryRun extends Command
{
    protected $signature = 'career:import-occupation-directory-dry-run
        {--input= : Path to career_create_import.jsonl}
        {--alias-review= : Optional career_alias_review.csv path}
        {--child-role-review= : Optional career_child_role_review.csv path}
        {--manifest= : Optional import_manifest.json path}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Validate a Career occupation-directory CMS import package without writing authority rows.';

    public function handle(): int
    {
        try {
            $inputPath = $this->requiredPath('input');
            $records = $this->readJsonl($inputPath);
            $aliasRows = $this->optionalCsvRows('alias-review');
            $childRows = $this->optionalCsvRows('child-role-review');
            $manifest = $this->optionalJson('manifest');

            $summary = $this->summarize($records, $aliasRows, $childRows, $manifest);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->line('records_seen='.$summary['records_seen']);
                $this->line('create_total='.$summary['create_total']);
                $this->line('alias_review_total='.$summary['alias_review_total']);
                $this->line('child_role_review_total='.$summary['child_role_review_total']);
                $this->line('authority_duplicate_count='.$summary['authority_duplicate_count']);
                $this->line('proposed_slug_duplicate_count='.$summary['proposed_slug_duplicate_count']);
                $this->line('gate_failure_count='.$summary['gate_failure_count']);
                $this->line('market_counts='.json_encode($summary['market_counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('translation_status_counts='.json_encode($summary['translation_status_counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if ($summary['gate_failure_count'] > 0 || $summary['authority_duplicate_count'] > 0 || $summary['proposed_slug_duplicate_count'] > 0) {
                $this->error('dry-run validation failed');

                return self::FAILURE;
            }

            $this->info('dry-run validation complete');

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
     * @return array<string, mixed>
     */
    private function optionalJson(string $option): array
    {
        $path = trim((string) ($this->option($option) ?? ''));
        if ($path === '') {
            return [];
        }
        if (! is_file($path)) {
            throw new \RuntimeException('--'.$option.' file does not exist: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON manifest: '.$path);
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, string>>  $aliasRows
     * @param  list<array<string, string>>  $childRows
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function summarize(array $records, array $aliasRows, array $childRows, array $manifest): array
    {
        $authorityKeys = [];
        $proposedSlugs = [];
        $marketCounts = [];
        $translationStatusCounts = [];
        $gateFailures = [];

        foreach ($records as $index => $record) {
            $recordNumber = $index + 1;
            $authority = is_array($record['authority'] ?? null) ? $record['authority'] : [];
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $localization = is_array($record['localization'] ?? null) ? $record['localization'] : [];
            $governance = is_array($record['governance'] ?? null) ? $record['governance'] : [];

            $market = trim((string) ($record['market'] ?? ''));
            $authoritySource = trim((string) ($authority['source'] ?? ''));
            $authorityCode = trim((string) ($authority['code'] ?? ''));
            $proposedSlug = trim((string) ($identity['proposed_slug'] ?? ''));
            $translationStatus = trim((string) ($localization['translation_status'] ?? 'unknown'));

            $marketCounts[$market] = ($marketCounts[$market] ?? 0) + 1;
            $translationStatusCounts[$translationStatus] = ($translationStatusCounts[$translationStatus] ?? 0) + 1;
            $authorityKeys[] = $market.'|'.$authoritySource.'|'.$authorityCode;
            $proposedSlugs[] = $proposedSlug;

            if (($record['import_action'] ?? null) !== 'create') {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'import_action_not_create'];
            }
            if (($record['dry_run_only'] ?? null) !== true) {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'dry_run_only_not_true'];
            }
            if (($governance['publish_state'] ?? null) !== 'draft') {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'publish_state_not_draft'];
            }
            if (($governance['requires_backend_truth_compute'] ?? null) !== true) {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'missing_backend_truth_compute_gate'];
            }
            if ($market === '' || $authoritySource === '' || $authorityCode === '' || $proposedSlug === '') {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'missing_identity_or_authority_field'];
            }
            if ($translationStatus !== 'from_existing_match' && ($localization['translation_review_required'] ?? null) !== true) {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'translation_review_gate_missing'];
            }
        }

        $authorityDuplicates = $this->duplicates($authorityKeys);
        $slugDuplicates = $this->duplicates($proposedSlugs);
        $manifestCounts = is_array($manifest['counts'] ?? null) ? $manifest['counts'] : [];
        if (isset($manifestCounts['create_total_top_level']) && (int) $manifestCounts['create_total_top_level'] !== count($records)) {
            $gateFailures[] = ['record' => 0, 'reason' => 'manifest_create_total_mismatch'];
        }

        return [
            'package_kind' => 'career_occupation_directory_import_dry_run',
            'records_seen' => count($records),
            'create_total' => count($records),
            'alias_review_total' => count($aliasRows),
            'child_role_review_total' => count($childRows),
            'market_counts' => $marketCounts,
            'translation_status_counts' => $translationStatusCounts,
            'authority_duplicate_count' => count($authorityDuplicates),
            'authority_duplicates' => array_slice($authorityDuplicates, 0, 25),
            'proposed_slug_duplicate_count' => count($slugDuplicates),
            'proposed_slug_duplicates' => array_slice($slugDuplicates, 0, 25),
            'gate_failure_count' => count($gateFailures),
            'gate_failures' => array_slice($gateFailures, 0, 50),
            'dry_run_only' => true,
            'writes_database' => false,
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function duplicates(array $values): array
    {
        $counts = array_count_values(array_filter($values, static fn (string $value): bool => trim($value) !== ''));

        return array_values(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)));
    }
}
