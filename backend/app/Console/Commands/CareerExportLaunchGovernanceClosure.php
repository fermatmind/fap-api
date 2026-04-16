<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerLaunchGovernanceClosureProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExportLaunchGovernanceClosure extends Command
{
    protected $signature = 'career:export-launch-governance-closure
        {--timestamp= : Optional output directory timestamp segment}
        {--json : Emit JSON output}';

    protected $description = 'Materialize internal career launch governance closure snapshot to storage/app/private/career_launch_governance_closure.';

    public function __construct(
        private readonly CareerLaunchGovernanceClosureProjectionService $projectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_launch_governance_closure');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = $finalDir.'.tmp';

            if (is_dir($finalDir) || is_dir($tmpDir)) {
                throw new \RuntimeException('launch governance closure output dir already exists: '.$finalDir);
            }

            $projected = $this->projectionService->build();
            $snapshot = (array) ($projected[CareerLaunchGovernanceClosureProjectionService::SNAPSHOT_FILENAME] ?? []);
            if ($snapshot === []) {
                throw new \RuntimeException('empty launch governance closure payload');
            }

            File::ensureDirectoryExists($tmpDir);
            $path = $tmpDir.DIRECTORY_SEPARATOR.CareerLaunchGovernanceClosureProjectionService::SNAPSHOT_FILENAME;
            $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode launch governance closure payload');
            }
            File::put($path, $encoded.PHP_EOL);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize launch governance closure output dir: '.$finalDir);
            }

            $payload = [
                'status' => 'materialized',
                'output_dir' => $finalDir,
                'artifacts' => [
                    CareerLaunchGovernanceClosureProjectionService::SNAPSHOT_FILENAME => $finalDir.DIRECTORY_SEPARATOR.CareerLaunchGovernanceClosureProjectionService::SNAPSHOT_FILENAME,
                ],
                'snapshot' => $snapshot,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('output_dir='.$finalDir);
            $this->line('career-launch-governance-closure='.(string) data_get($payload, 'artifacts.career-launch-governance-closure.json', ''));

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
            throw new \RuntimeException('invalid timestamp segment for launch governance closure export');
        }

        return $normalized;
    }
}
