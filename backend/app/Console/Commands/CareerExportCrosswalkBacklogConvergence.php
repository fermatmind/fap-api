<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Operations\CareerCrosswalkBacklogConvergenceProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportCrosswalkBacklogConvergence extends Command
{
    protected $signature = 'career:export-crosswalk-backlog-convergence
        {--timestamp= : Optional output directory timestamp segment}
        {--json : Emit JSON output}';

    protected $description = 'Materialize internal crosswalk backlog convergence snapshot to storage/app/private/career_crosswalk_backlog_convergence.';

    public function __construct(
        private readonly CareerCrosswalkBacklogConvergenceProjectionService $projectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_crosswalk_backlog_convergence');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = $finalDir.'.tmp';

            if (is_dir($finalDir) || is_dir($tmpDir)) {
                throw new \RuntimeException('crosswalk backlog convergence output dir already exists: '.$finalDir);
            }

            $projected = $this->projectionService->build();
            $snapshot = (array) ($projected[CareerCrosswalkBacklogConvergenceProjectionService::SNAPSHOT_FILENAME] ?? []);
            if ($snapshot === []) {
                throw new \RuntimeException('empty crosswalk backlog convergence snapshot payload');
            }

            File::ensureDirectoryExists($tmpDir);
            $path = $tmpDir.DIRECTORY_SEPARATOR.CareerCrosswalkBacklogConvergenceProjectionService::SNAPSHOT_FILENAME;
            $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode crosswalk backlog convergence snapshot payload');
            }
            File::put($path, $encoded.PHP_EOL);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize crosswalk backlog convergence output dir: '.$finalDir);
            }

            $payload = [
                'status' => 'materialized',
                'output_dir' => $finalDir,
                'artifacts' => [
                    CareerCrosswalkBacklogConvergenceProjectionService::SNAPSHOT_FILENAME => $finalDir.DIRECTORY_SEPARATOR.CareerCrosswalkBacklogConvergenceProjectionService::SNAPSHOT_FILENAME,
                ],
                'snapshot' => $snapshot,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('output_dir='.$finalDir);
            $this->line('career-crosswalk-backlog-convergence='.(string) data_get($payload, 'artifacts.career-crosswalk-backlog-convergence.json', ''));

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
            throw new \RuntimeException('invalid timestamp segment for crosswalk backlog convergence export');
        }

        return $normalized;
    }
}
