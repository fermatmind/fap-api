<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\EnneagramRegistryActivationGateService;
use Illuminate\Console\Command;
use RuntimeException;

final class EnneagramActivateInactiveCandidateRelease extends Command
{
    protected $signature = 'enneagram:activate-inactive-candidate-release
        {--release-id= : Target inactive candidate release id}
        {--confirm-release-id= : Repeat the target release id to execute activation}
        {--candidate-manifest-sha256= : Expected candidate_manifest.json SHA256}
        {--runtime-registry-sha256= : Expected runtime registry manifest SHA256}
        {--output-dir= : Report output directory}
        {--actor=ops : Operator label recorded in release snapshots}
        {--json : Emit JSON summary}';

    protected $description = 'Activate a validated ENNEAGRAM inactive candidate release with exact hash and release-id confirmations.';

    public function __construct(
        private readonly EnneagramRegistryActivationGateService $activationGateService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $releaseId = trim((string) $this->option('release-id'));
        $confirmReleaseId = trim((string) $this->option('confirm-release-id'));
        $candidateManifestSha256 = trim((string) $this->option('candidate-manifest-sha256'));
        $runtimeRegistrySha256 = trim((string) $this->option('runtime-registry-sha256'));
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

        if ($candidateManifestSha256 === '') {
            $this->components->error('Missing required --candidate-manifest-sha256 option.');

            return self::FAILURE;
        }

        if ($runtimeRegistrySha256 === '') {
            $this->components->error('Missing required --runtime-registry-sha256 option.');

            return self::FAILURE;
        }

        if ($outputDir === '') {
            $this->components->error('Missing output directory. Use --output-dir or PHASE8D4_OUTPUT_DIR.');

            return self::FAILURE;
        }

        try {
            $summary = $this->activationGateService->activateInactiveCandidateRelease(
                $releaseId,
                $confirmReleaseId,
                $candidateManifestSha256,
                $runtimeRegistrySha256,
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
            $this->components->info('ENNEAGRAM inactive candidate activation completed.');
            $this->table(
                ['field', 'value'],
                [
                    ['verdict', (string) ($summary['verdict'] ?? '')],
                    ['release_id', (string) ($summary['release_id'] ?? '')],
                    ['rollback_target_release_id', (string) ($summary['rollback_target_release_id'] ?? '')],
                    ['candidate_manifest_hash_actual', (string) ($summary['candidate_manifest_hash_actual'] ?? '')],
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
