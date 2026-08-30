<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Measurement\MeasurementCloseoutBuilder;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class SeoCouncilMeasurementCloseoutCommand extends Command
{
    protected $signature = 'seo:measurement-closeout {--expected-sha=} {--production-sha=} {--closeout-environment=ci_candidate} {--json}';

    protected $description = 'Verify SEO-PLATFORM-11F Search Measurement and Commercial Funnel CRO modes';

    public function handle(MeasurementCloseoutBuilder $closeout): int
    {
        try {
            $sourceSha = $this->releaseSha();
            $expectedSha = strtolower(trim((string) $this->option('expected-sha')));
            if (preg_match('/^[a-f0-9]{40}$/D', $expectedSha) !== 1 || ! hash_equals($expectedSha, $sourceSha)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'RELEASE_SHA_MISMATCH'], self::FAILURE);
            }
            $environment = (string) $this->option('closeout-environment');
            $productionSha = strtolower(trim((string) $this->option('production-sha')));
            if ($productionSha === '') {
                $productionSha = $environment === 'ci_candidate' ? $this->parentSha() : $expectedSha;
            }
            $receipt = $closeout->build($expectedSha, $environment, $productionSha);
            $expectedState = match ($environment) {
                'production_runtime' => 'CLOSED',
                'staging_runtime' => 'STAGING_READY',
                default => 'OFFLINE_EVAL_READY',
            };

            return $this->emit($receipt, ($receipt['closeout_state'] ?? null) === $expectedState ? self::SUCCESS : self::FAILURE);
        } catch (Throwable) {
            return $this->emit(['status' => 'failed', 'safe_error_code' => 'MEASUREMENT_CLOSEOUT_FAILED'], self::FAILURE);
        }
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload, int $exitCode): int
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->option('json') ? $this->line($encoded) : $this->info($encoded);

        return $exitCode;
    }

    private function releaseSha(): string
    {
        $revision = dirname(base_path()).'/REVISION';
        if (is_file($revision)) {
            $sha = strtolower(trim((string) file_get_contents($revision)));
            if (preg_match('/^[a-f0-9]{40}$/D', $sha) === 1) {
                return $sha;
            }
        }

        return $this->gitSha('HEAD');
    }

    private function parentSha(): string
    {
        return $this->gitSha('HEAD^');
    }

    private function gitSha(string $revision): string
    {
        $process = new Process(['git', 'rev-parse', $revision], dirname(base_path()));
        $process->mustRun();

        return strtolower(trim($process->getOutput()));
    }
}
