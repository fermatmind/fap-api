<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\EnneagramRegistryActivationGateService;
use Illuminate\Console\Command;
use RuntimeException;

final class EnneagramActivateRegistryRelease extends Command
{
    protected $signature = 'enneagram:activate-registry-release
        {--release-id= : Target inactive release id}
        {--confirm-release-id= : Repeat the target release id to execute activation}
        {--output-dir= : Report output directory}
        {--dry-run : Validate the activation gate without writing an activation row}
        {--json : Emit JSON summary}';

    protected $description = 'Validate or simulate ENNEAGRAM registry release activation in a controlled environment.';

    public function __construct(
        private readonly EnneagramRegistryActivationGateService $activationGateService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $releaseId = trim((string) $this->option('release-id'));
        $outputDir = trim((string) $this->option('output-dir'));
        if ($outputDir === '') {
            $outputDir = $this->readOutputDirFromEnvironment();
        }

        if ($releaseId === '') {
            $this->components->error('Missing required --release-id option.');

            return self::FAILURE;
        }

        if ($outputDir === '') {
            $this->components->error('Missing output directory. Use --output-dir or PHASE8D3_OUTPUT_DIR.');

            return self::FAILURE;
        }

        try {
            $summary = $this->option('dry-run')
                ? $this->activationGateService->dryRun($releaseId, $outputDir)
                : $this->activationGateService->activateControlled(
                    $releaseId,
                    trim((string) $this->option('confirm-release-id')),
                    $outputDir
                );
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->components->info('ENNEAGRAM activation gate completed.');
            $this->table(
                ['field', 'value'],
                [
                    ['verdict', (string) ($summary['verdict'] ?? '')],
                    ['mode', (string) ($summary['mode'] ?? '')],
                    ['release_id', (string) ($summary['release_id'] ?? '')],
                    ['release_storage_path', (string) ($summary['release_storage_path'] ?? '')],
                ]
            );
        }

        return self::SUCCESS;
    }

    private function readOutputDirFromEnvironment(): string
    {
        $value = $_SERVER['PHASE8D3_OUTPUT_DIR'] ?? $_ENV['PHASE8D3_OUTPUT_DIR'] ?? getenv('PHASE8D3_OUTPUT_DIR');

        return trim(is_string($value) ? $value : '');
    }
}
