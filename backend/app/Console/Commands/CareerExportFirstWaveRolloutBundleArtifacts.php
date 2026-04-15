<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerFirstWaveRolloutBundleArtifactMaterializationService;
use Illuminate\Console\Command;

final class CareerExportFirstWaveRolloutBundleArtifacts extends Command
{
    protected $signature = 'career:export-first-wave-rollout-bundle-artifacts
        {--timestamp= : Optional output directory timestamp segment}
        {--json : Emit JSON output}';

    protected $description = 'Materialize internal first-wave rollout bundle/list artifacts to storage/app/private/career_rollout_bundle_artifacts in one atomic export.';

    public function __construct(
        private readonly CareerFirstWaveRolloutBundleArtifactMaterializationService $materializationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $payload = $this->materializationService->materialize(
                $this->option('timestamp') !== null ? (string) $this->option('timestamp') : null,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                $this->error('failed to encode export payload json');

                return self::FAILURE;
            }

            $this->line($encoded);

            return self::SUCCESS;
        }

        $this->line('status='.(string) ($payload['status'] ?? ''));
        $this->line('output_dir='.(string) ($payload['output_dir'] ?? ''));
        $this->line('career-rollout-bundle='.(string) data_get($payload, 'artifacts.career-rollout-bundle.json', ''));
        $this->line('career-stable-whitelist='.(string) data_get($payload, 'artifacts.career-stable-whitelist.json', ''));
        $this->line('career-candidate-whitelist='.(string) data_get($payload, 'artifacts.career-candidate-whitelist.json', ''));
        $this->line('career-hold-list='.(string) data_get($payload, 'artifacts.career-hold-list.json', ''));
        $this->line('career-blocked-list='.(string) data_get($payload, 'artifacts.career-blocked-list.json', ''));

        return self::SUCCESS;
    }
}
