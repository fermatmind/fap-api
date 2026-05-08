<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Expansion\CanonicalRolloutBatchStateMachine;
use Illuminate\Console\Command;

final class CareerPlanCanonicalRolloutBatchTransition extends Command
{
    protected $signature = 'career:plan-canonical-rollout-batch-transition
        {--manifest= : Canonical expansion manifest JSON artifact}
        {--governance= : Optional canonical rollout governance validation JSON artifact}
        {--target-state= : Target rollout state}
        {--json : Emit JSON output}';

    protected $description = 'Plan a read-only canonical rollout batch state transition.';

    public function __construct(
        private readonly CanonicalRolloutBatchStateMachine $stateMachine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $manifestPath = $this->pathOption('manifest');
            if ($manifestPath === null) {
                throw new \RuntimeException('--manifest is required');
            }
            $targetState = $this->pathOption('target-state');
            if ($targetState === null) {
                throw new \RuntimeException('--target-state is required');
            }

            $result = $this->stateMachine->transition(
                manifestPayload: $this->readJsonPath($manifestPath, 'manifest'),
                targetState: $targetState,
                governanceResult: $this->pathOption('governance') !== null
                    ? $this->readJsonPath((string) $this->pathOption('governance'), '')
                    : null,
            );

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.$result['status']);
                $this->line('current_state='.$result['current_state']);
                $this->line('target_state='.$result['target_state']);
            }

            return $result['status'] === 'planned' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function pathOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonPath(string $path, string $innerKey): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('canonical rollout batch input file not found: '.$path);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('canonical rollout batch input file is not valid JSON: '.$path);
        }

        return $innerKey !== '' && is_array($payload[$innerKey] ?? null) ? $payload[$innerKey] : $payload;
    }
}
