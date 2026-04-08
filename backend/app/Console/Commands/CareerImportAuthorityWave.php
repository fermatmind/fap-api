<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Import\ImportScopeMode;
use App\Domain\Career\Import\RunStatus;
use App\Models\CareerImportRun;
use App\Services\Career\Import\CareerAuthorityDatasetReader;
use App\Services\Career\Import\CareerAuthorityWaveImporter;
use Illuminate\Console\Command;
use Throwable;

final class CareerImportAuthorityWave extends Command
{
    protected $signature = 'career:import-authority-wave
        {--source= : Source dataset path (.xlsx or .csv)}
        {--scope=exact,trust_inheritance : Allowed first-wave mapping modes}
        {--dry-run : Parse and validate without writing authority rows}
        {--limit= : Limit source rows processed}
        {--manifest= : Optional JSON manifest with conservative overrides}';

    protected $description = 'Import first-wave Career authority rows into UUID-first foundation tables with run-ledger tracking.';

    public function handle(
        CareerAuthorityDatasetReader $reader,
        CareerAuthorityWaveImporter $importer,
    ): int {
        $run = null;

        try {
            $source = trim((string) $this->option('source'));
            if ($source === '') {
                throw new \RuntimeException('--source is required.');
            }

            $allowedModes = ImportScopeMode::parse((string) $this->option('scope'));
            $dataset = $reader->read(
                $source,
                $this->option('manifest') !== null ? (string) $this->option('manifest') : null,
                $this->limitValue(),
            );

            $run = CareerImportRun::query()->create([
                'dataset_name' => $dataset['dataset_name'],
                'dataset_version' => $dataset['dataset_version'],
                'dataset_checksum' => $dataset['dataset_checksum'],
                'source_path' => $dataset['source_path'],
                'scope_mode' => ImportScopeMode::ledgerValue($allowedModes),
                'dry_run' => (bool) $this->option('dry-run'),
                'status' => RunStatus::RUNNING,
                'started_at' => now(),
                'meta' => [
                    'allowed_modes' => $allowedModes,
                    'manifest_supplied' => $this->option('manifest') !== null,
                ],
            ]);

            $summary = $importer->import($run, $dataset, $allowedModes);

            $run->forceFill([
                'status' => RunStatus::COMPLETED,
                'finished_at' => now(),
                'rows_seen' => $summary['rows_seen'],
                'rows_accepted' => $summary['rows_accepted'],
                'rows_skipped' => $summary['rows_skipped'],
                'rows_failed' => $summary['rows_failed'],
                'output_counts' => $summary['output_counts'],
                'error_summary' => array_slice((array) $summary['errors'], 0, 50),
                'meta' => array_merge((array) ($run->meta ?? []), [
                    'dataset' => $summary['dataset'],
                ]),
            ])->save();

            $this->line('import_run_id='.$run->id);
            $this->line('dataset_name='.$run->dataset_name);
            $this->line('dataset_checksum='.$run->dataset_checksum);
            $this->line('scope_mode='.$run->scope_mode);
            $this->line('dry_run='.($run->dry_run ? '1' : '0'));
            $this->line('rows_seen='.$run->rows_seen);
            $this->line('rows_accepted='.$run->rows_accepted);
            $this->line('rows_skipped='.$run->rows_skipped);
            $this->line('rows_failed='.$run->rows_failed);
            $this->line('status='.$run->status);
            $this->line('output_counts='.json_encode($run->output_counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if ($run->rows_failed > 0) {
                $this->warn('import completed with row failures');
            } else {
                $this->info($run->dry_run ? 'dry-run complete' : 'import complete');
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            if ($run instanceof CareerImportRun) {
                $run->forceFill([
                    'status' => RunStatus::FAILED,
                    'finished_at' => now(),
                    'error_summary' => [[
                        'message' => $throwable->getMessage(),
                        'type' => 'fatal',
                    ]],
                ])->save();
            }

            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function limitValue(): ?int
    {
        $raw = $this->option('limit');
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return max(1, (int) $raw);
    }
}
