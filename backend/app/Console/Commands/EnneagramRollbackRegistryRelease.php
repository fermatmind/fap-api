<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\EnneagramRegistryActivationGateService;
use Illuminate\Console\Command;
use RuntimeException;

final class EnneagramRollbackRegistryRelease extends Command
{
    protected $signature = 'enneagram:rollback-registry-release
        {--scale=ENNEAGRAM : Scale code, must remain ENNEAGRAM}
        {--pack-version=v2 : Registry version, must remain v2}
        {--output-dir= : Report output directory}
        {--dry-run : Inspect rollback readiness without changing activation rows}
        {--json : Emit JSON summary}';

    protected $description = 'Simulate ENNEAGRAM registry rollback in a controlled environment.';

    public function __construct(
        private readonly EnneagramRegistryActivationGateService $activationGateService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $scale = trim((string) $this->option('scale'));
        $version = trim((string) $this->option('pack-version'));
        $outputDir = trim((string) $this->option('output-dir'));
        if ($outputDir === '') {
            $outputDir = $this->readOutputDirFromEnvironment();
        }

        if ($outputDir === '') {
            $this->components->error('Missing output directory. Use --output-dir or PHASE8D3_OUTPUT_DIR.');

            return self::FAILURE;
        }

        try {
            $summary = $this->option('dry-run')
                ? [
                    'verdict' => 'PASS_FOR_MANUAL_ACTIVATION_DECISION',
                    'mode' => 'rollback_dry_run',
                    'scale' => $scale,
                    'version' => $version,
                ]
                : $this->activationGateService->rollbackControlled($scale, $version, $outputDir);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->components->info('ENNEAGRAM rollback gate completed.');
            $this->table(
                ['field', 'value'],
                [
                    ['verdict', (string) ($summary['verdict'] ?? '')],
                    ['mode', (string) ($summary['mode'] ?? '')],
                    ['scale', $scale],
                    ['version', $version],
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
