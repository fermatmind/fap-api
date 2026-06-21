<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\EnneagramRegistryActivationGateService;
use Illuminate\Console\Command;
use RuntimeException;

final class EnneagramRollbackInactiveCandidateRelease extends Command
{
    protected $signature = 'enneagram:rollback-inactive-candidate-release
        {--release-id= : Currently active inactive candidate release id}
        {--confirm-release-id= : Repeat the active release id to execute rollback}
        {--output-dir= : Report output directory}
        {--actor=ops : Operator label recorded in release snapshots}
        {--json : Emit JSON summary}';

    protected $description = 'Rollback a previously activated ENNEAGRAM inactive candidate release to its recorded rollback target.';

    public function __construct(
        private readonly EnneagramRegistryActivationGateService $activationGateService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $releaseId = trim((string) $this->option('release-id'));
        $confirmReleaseId = trim((string) $this->option('confirm-release-id'));
        $outputDir = trim((string) $this->option('output-dir'));
        if ($outputDir === '') {
            $outputDir = $this->readOutputDirFromEnvironment();
        }

        if ($releaseId === '') {
            $this->components->error('Missing required --release-id option.');

            return self::FAILURE;
        }

        if ($confirmReleaseId === '') {
            $this->components->error('Missing required --confirm-release-id option.');

            return self::FAILURE;
        }

        if ($outputDir === '') {
            $this->components->error('Missing output directory. Use --output-dir or PHASE8D4_OUTPUT_DIR.');

            return self::FAILURE;
        }

        try {
            $summary = $this->activationGateService->rollbackInactiveCandidateRelease(
                $releaseId,
                $confirmReleaseId,
                $outputDir,
                trim((string) $this->option('actor')),
            );
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->components->info('ENNEAGRAM inactive candidate rollback completed.');
            $this->table(
                ['field', 'value'],
                [
                    ['verdict', (string) ($summary['verdict'] ?? '')],
                    ['release_id', (string) ($summary['release_id'] ?? '')],
                    ['rollback_target_release_id', (string) ($summary['rollback_target_release_id'] ?? '')],
                    ['restored_repo_fallback', ($summary['restored_repo_fallback'] ?? false) ? 'true' : 'false'],
                ]
            );
        }

        return self::SUCCESS;
    }

    private function readOutputDirFromEnvironment(): string
    {
        $value = $_SERVER['PHASE8D4_OUTPUT_DIR'] ?? $_ENV['PHASE8D4_OUTPUT_DIR'] ?? getenv('PHASE8D4_OUTPUT_DIR');

        return trim(is_string($value) ? $value : '');
    }
}
