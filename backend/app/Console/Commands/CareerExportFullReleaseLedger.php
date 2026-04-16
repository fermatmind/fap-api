<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportFullReleaseLedger extends Command
{
    protected $signature = 'career:export-full-release-ledger
        {--timestamp= : Optional output directory timestamp segment}
        {--json : Emit JSON output}';

    protected $description = 'Materialize internal full-342 release ledger authority to storage/app/private/career_release_ledger.';

    public function __construct(
        private readonly CareerFullReleaseLedgerProjectionService $projectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_release_ledger');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = $finalDir.'.tmp';

            if (is_dir($finalDir) || is_dir($tmpDir)) {
                throw new \RuntimeException('release ledger output dir already exists: '.$finalDir);
            }

            $projected = $this->projectionService->build();
            $ledger = (array) ($projected[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? []);
            if ($ledger === []) {
                throw new \RuntimeException('empty full release ledger payload');
            }

            File::ensureDirectoryExists($tmpDir);
            $path = $tmpDir.DIRECTORY_SEPARATOR.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME;
            $encoded = json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode full release ledger payload');
            }
            File::put($path, $encoded.PHP_EOL);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize release ledger output dir: '.$finalDir);
            }

            $payload = [
                'status' => 'materialized',
                'output_dir' => $finalDir,
                'artifacts' => [
                    CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME => $finalDir.DIRECTORY_SEPARATOR.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME,
                ],
                'ledger' => $ledger,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('output_dir='.$finalDir);
            $this->line('career-full-release-ledger='.(string) data_get($payload, 'artifacts.career-full-release-ledger.json', ''));

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
            throw new \RuntimeException('invalid timestamp segment for full release ledger export');
        }

        return $normalized;
    }
}
