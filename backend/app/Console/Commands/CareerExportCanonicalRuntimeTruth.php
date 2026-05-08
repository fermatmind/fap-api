<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportCanonicalRuntimeTruth extends Command
{
    protected $signature = 'career:export-canonical-runtime-truth
        {--timestamp= : Optional output directory timestamp segment}
        {--ledger= : Optional Career full release ledger JSON artifact}
        {--projection= : Optional Career runtime publish projection JSON artifact}
        {--json : Emit JSON output}';

    protected $description = 'Export the canonical Career runtime truth from the runtime publish projection authority.';

    public function __construct(
        private readonly CareerCanonicalRuntimeTruthExporter $exporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_canonical_runtime_truth');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = $finalDir.'.tmp';

            if (is_dir($finalDir) || is_dir($tmpDir)) {
                throw new \RuntimeException('canonical runtime truth output dir already exists: '.$finalDir);
            }

            $truth = $this->exporter->build($this->ledgerPathOption(), $this->projectionPathOption());

            File::ensureDirectoryExists($tmpDir);
            $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.CareerCanonicalRuntimeTruthExporter::TRUTH_FILENAME;
            $encoded = json_encode($truth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode canonical runtime truth payload');
            }
            File::put($tmpPath, $encoded.PHP_EOL);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize canonical runtime truth output dir: '.$finalDir);
            }

            $path = $finalDir.DIRECTORY_SEPARATOR.CareerCanonicalRuntimeTruthExporter::TRUTH_FILENAME;
            $payload = [
                'status' => 'materialized',
                'output_dir' => $finalDir,
                'artifacts' => [
                    CareerCanonicalRuntimeTruthExporter::TRUTH_FILENAME => $path,
                ],
                'truth' => $truth,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('output_dir='.$finalDir);
            $this->line('career-canonical-runtime-truth='.$path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            $normalized = now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for canonical runtime truth export');
        }

        return $normalized;
    }

    private function ledgerPathOption(): ?string
    {
        $value = $this->option('ledger');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function projectionPathOption(): ?string
    {
        $value = $this->option('projection');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
