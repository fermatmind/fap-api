<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Career10kControlledRolloutGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerValidate10kControlledRollout extends Command
{
    protected $signature = 'career:validate-10k-controlled-rollout {--batch= : One of 100,500,1000,2500,5000,10000} {--evidence= : Read-only JSON evidence file} {--json}';

    protected $description = 'Fail-closed Career 10k batch promotion readiness validation; never applies or deploys a rollout.';

    public function handle(Career10kControlledRolloutGate $gate): int
    {
        $path = trim((string) $this->option('evidence'));
        $evidence = $path !== '' && File::isFile($path) ? json_decode((string) File::get($path), true) : null;
        if (! is_array($evidence)) {
            $evidence = [];
        }

        $batch = $this->option('batch');
        $target = match (true) {
            is_int($batch) && in_array($batch, Career10kControlledRolloutGate::BATCHES, true) => $batch,
            is_string($batch) && preg_match('/^(100|500|1000|2500|5000|10000)$/D', $batch) === 1 => (int) $batch,
            default => -1,
        };
        $report = $gate->evaluate($target, $evidence);
        $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
