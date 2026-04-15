<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerFirstWaveReleaseArtifactMaterializationService;
use Illuminate\Console\Command;

final class CareerExportFirstWaveReleaseArtifacts extends Command
{
    protected $signature = 'career:export-first-wave-release-artifacts
        {--timestamp= : Optional output directory timestamp segment}
        {--json : Emit JSON output}';

    protected $description = 'Materialize internal first-wave release artifacts to storage/app/private/career_release_artifacts in one atomic export.';

    public function __construct(
        private readonly CareerFirstWaveReleaseArtifactMaterializationService $materializationService,
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
        $this->line('career-launch-manifest='.(string) data_get($payload, 'artifacts.career-launch-manifest.json', ''));
        $this->line('career-smoke-matrix='.(string) data_get($payload, 'artifacts.career-smoke-matrix.json', ''));

        return self::SUCCESS;
    }
}
