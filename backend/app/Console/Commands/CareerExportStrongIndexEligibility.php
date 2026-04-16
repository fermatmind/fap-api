<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerStrongIndexEligibilityProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportStrongIndexEligibility extends Command
{
    protected $signature = 'career:export-strong-index-eligibility
        {--timestamp= : Optional output directory timestamp segment}
        {--json : Emit JSON output}';

    protected $description = 'Materialize internal full-342 strong-index eligibility snapshot to storage/app/private/career_strong_index_eligibility.';

    public function __construct(
        private readonly CareerStrongIndexEligibilityProjectionService $projectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_strong_index_eligibility');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = $finalDir.'.tmp';

            if (is_dir($finalDir) || is_dir($tmpDir)) {
                throw new \RuntimeException('strong-index snapshot output dir already exists: '.$finalDir);
            }

            $projected = $this->projectionService->build();
            $snapshot = (array) ($projected[CareerStrongIndexEligibilityProjectionService::SNAPSHOT_FILENAME] ?? []);
            if ($snapshot === []) {
                throw new \RuntimeException('empty strong-index snapshot payload');
            }

            File::ensureDirectoryExists($tmpDir);
            $path = $tmpDir.DIRECTORY_SEPARATOR.CareerStrongIndexEligibilityProjectionService::SNAPSHOT_FILENAME;
            $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode strong-index snapshot payload');
            }
            File::put($path, $encoded.PHP_EOL);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize strong-index snapshot output dir: '.$finalDir);
            }

            $payload = [
                'status' => 'materialized',
                'output_dir' => $finalDir,
                'artifacts' => [
                    CareerStrongIndexEligibilityProjectionService::SNAPSHOT_FILENAME => $finalDir.DIRECTORY_SEPARATOR.CareerStrongIndexEligibilityProjectionService::SNAPSHOT_FILENAME,
                ],
                'snapshot' => $snapshot,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('output_dir='.$finalDir);
            $this->line('career-strong-index-eligibility='.(string) data_get($payload, 'artifacts.career-strong-index-eligibility.json', ''));

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
            $normalized = now('UTC')->format('Ymd\\THis\\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for strong-index snapshot export');
        }

        return $normalized;
    }
}
