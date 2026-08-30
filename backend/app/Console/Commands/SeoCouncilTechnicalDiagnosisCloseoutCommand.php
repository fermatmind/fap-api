<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisCloseoutBuilder;
use Illuminate\Console\Command;
use Throwable;

final class SeoCouncilTechnicalDiagnosisCloseoutCommand extends Command
{
    protected $signature = 'seo:technical-diagnosis-closeout {--expected-sha=} {--closeout-environment=ci_candidate} {--json}';

    protected $description = 'Verify SEO-PLATFORM-11E Technical Search Diagnosis Mode for one exact SHA';

    public function handle(TechnicalDiagnosisCloseoutBuilder $closeout): int
    {
        try {
            $sourceSha = $this->releaseSha();
            $expectedSha = strtolower(trim((string) $this->option('expected-sha')));
            if (preg_match('/^[a-f0-9]{40}$/D', $expectedSha) !== 1 || ! hash_equals($expectedSha, $sourceSha)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'RELEASE_SHA_MISMATCH'], self::FAILURE);
            }
            $environment = (string) $this->option('closeout-environment');
            $receipt = $closeout->build($sourceSha, $environment);
            $expectedState = match ($environment) {
                'production_runtime' => 'CLOSED',
                'staging_runtime' => 'STAGING_READY',
                default => 'CANDIDATE_READY',
            };

            return $this->emit($receipt, ($receipt['closeout_state'] ?? null) === $expectedState ? self::SUCCESS : self::FAILURE);
        } catch (Throwable) {
            return $this->emit(['status' => 'failed', 'safe_error_code' => 'TECHNICAL_DIAGNOSIS_CLOSEOUT_FAILED'], self::FAILURE);
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
        $process = new \Symfony\Component\Process\Process(['git', 'rev-parse', 'HEAD'], dirname(base_path()));
        $process->mustRun();

        return strtolower(trim($process->getOutput()));
    }
}
