<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Import\CareerDisplayWorkbookContractRepairer;
use Illuminate\Console\Command;
use RuntimeException;

final class CareerRepairDisplayWorkbookContract extends Command
{
    protected $signature = 'career:repair-display-workbook-contract
        {--file= : Absolute path to the reviewed v4.2 career display workbook}
        {--output= : Optional repaired workbook output path; required with --execute}
        {--report-output= : Optional JSON report output path}
        {--slugs= : Optional comma-separated slug filter}
        {--execute : Write the repaired workbook artifact to --output}
        {--json : Emit JSON report}';

    protected $description = 'Repair mechanical career display workbook contract issues without importing, publishing, or mutating CMS data.';

    public function handle(CareerDisplayWorkbookContractRepairer $repairer): int
    {
        @ini_set('memory_limit', '512M');

        try {
            $report = $repairer->repair(
                sourcePath: (string) $this->option('file'),
                outputPath: $this->option('output') !== null ? (string) $this->option('output') : null,
                execute: (bool) $this->option('execute'),
                slugs: $this->slugs((string) ($this->option('slugs') ?? '')),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $reportOutput = $this->option('report-output');
        if (is_string($reportOutput) && trim($reportOutput) !== '') {
            $this->writeJsonReport($reportOutput, $report);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Career display workbook contract repair %s: %d changed rows, %d changed cells.',
            $report['decision'],
            $report['changed_rows'],
            $report['changed_cells'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function slugs(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $value),
        ), static fn (string $slug): bool => $slug !== ''));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeJsonReport(string $path, array $report): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );
    }
}
