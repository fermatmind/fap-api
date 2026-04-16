<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Production\CareerAssetBatchPipeline;
use Illuminate\Console\Command;
use RuntimeException;

final class CareerRunAssetBatch extends Command
{
    protected $signature = 'career:run-asset-batch
        {--manifest= : Path to batch manifest JSON}
        {--mode=full : validate|compile-trust|publish-candidate|regression|full}
        {--json : Emit JSON output}';

    protected $description = 'Run Career asset batch pipeline with staged validate/compile-trust/publish-candidate/regression modes.';

    public function handle(CareerAssetBatchPipeline $pipeline): int
    {
        $manifest = trim((string) $this->option('manifest'));
        if ($manifest === '') {
            $this->error('--manifest is required.');

            return self::FAILURE;
        }

        try {
            $result = $pipeline->run($manifest, (string) $this->option('mode'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return ($result['status'] ?? 'aborted') === 'completed' ? self::SUCCESS : self::FAILURE;
        }

        $this->line('status='.(string) ($result['status'] ?? 'aborted'));
        $this->line('mode='.(string) ($result['mode'] ?? ''));
        $this->line('batch_key='.(string) data_get($result, 'manifest.batch_key', ''));
        $this->line('member_count='.(string) data_get($result, 'manifest.member_count', 0));

        foreach ((array) ($result['stages'] ?? []) as $name => $stage) {
            $this->line(sprintf(
                'stage[%s]=%s%s',
                (string) $name,
                ((bool) data_get($stage, 'passed', false)) ? 'passed' : 'failed',
                ((bool) data_get($stage, 'skipped', false)) ? ' (skipped)' : '',
            ));
        }

        return ($result['status'] ?? 'aborted') === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
