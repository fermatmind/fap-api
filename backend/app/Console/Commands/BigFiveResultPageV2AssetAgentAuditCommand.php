<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\ResultPageV2\AssetAgent\BigFiveResultPageV2AssetAgent;
use Illuminate\Console\Command;
use Throwable;

final class BigFiveResultPageV2AssetAgentAuditCommand extends Command
{
    protected $signature = 'big5:result-page-v2-agent
        {action=audit : Only audit is supported in Milestone 1}
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/big5_result_page_v2_agent}
        {--strict : Return non-zero when P0 blockers, invalid inventory, or leak hits are present}
        {--run-tests : Execute targeted Big Five V2 PHPUnit filters}
        {--json : Emit machine-readable summary}';

    protected $description = 'Read-only Big Five Result Page V2 content asset audit agent.';

    public function handle(BigFiveResultPageV2AssetAgent $agent): int
    {
        try {
            if ($this->argument('action') !== 'audit') {
                $this->error('Unsupported action. Milestone 1 supports only: audit');

                return self::FAILURE;
            }

            $summary = $agent->audit([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'strict' => (bool) $this->option('strict'),
                'run_tests' => (bool) $this->option('run-tests'),
            ]);

            $this->render($summary);

            return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function render(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
        $this->line('run_id='.(string) ($summary['run_id'] ?? ''));
        $this->line('artifact_dir='.(string) ($summary['artifact_dir'] ?? ''));
        $this->line('asset_record_count='.(string) data_get($summary, 'summary.asset_record_count', 0));
        $this->line('p0_batch_count='.(string) data_get($summary, 'summary.p0_batch_count', 0));
        $this->line('p0_blocker_count='.(string) data_get($summary, 'summary.p0_blocker_count', 0));
        $this->line('leak_hit_count='.(string) data_get($summary, 'summary.leak_hit_count', 0));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', data_get($artifact, 'path', '')));
        }

        foreach ((array) ($summary['strict_failures'] ?? []) as $failure) {
            $this->line('strict_failure='.(string) $failure);
        }
    }
}
