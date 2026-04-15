<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerFirstWaveRolloutWavePlanArtifactMaterializationService;
use Illuminate\Console\Command;

final class CareerExportFirstWaveRolloutWavePlanArtifact extends Command
{
    protected $signature = 'career:export-first-wave-rollout-wave-plan-artifact
        {--timestamp= : Optional output directory timestamp segment}
        {--json : Emit JSON output}';

    protected $description = 'Materialize internal first-wave rollout wave-plan artifact to storage/app/private/career_rollout_wave_plan_artifacts with atomic finalize.';

    public function __construct(
        private readonly CareerFirstWaveRolloutWavePlanArtifactMaterializationService $materializationService,
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
        $this->line('career-rollout-wave-plan='.(string) data_get($payload, 'artifacts.career-rollout-wave-plan.json', ''));

        return self::SUCCESS;
    }
}
