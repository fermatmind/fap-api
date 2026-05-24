<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\MbtiUrlTruthCleanupService;
use Illuminate\Console\Command;

final class SeoIntelMbtiUrlTruthCleanupCommand extends Command
{
    protected $signature = 'seo-intel:mbti-url-truth-cleanup
        {--preset= : Exact bounded cleanup preset}
        {--dry-run : Preview without writes}
        {--no-write : Prevent writes even when --execute is present}
        {--execute : Explicitly request the bounded cleanup/write}
        {--json : Output safe machine-readable JSON}';

    protected $description = 'Safely plan or execute the MBTI FIX-02 URL Truth canonical cleanup.';

    public function handle(MbtiUrlTruthCleanupService $service): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = $execute ? (bool) $this->option('dry-run') : true;
        $noWrite = $execute ? (bool) $this->option('no-write') : true;

        $result = $service->run(
            preset: $this->nullableOption('preset'),
            execute: $execute,
            dryRun: $dryRun,
            noWrite: $noWrite,
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            foreach (['status', 'dry_run', 'no_write', 'execute_attempted', 'writes_committed'] as $key) {
                $this->line($key.'='.$this->stringValue($result[$key] ?? null));
            }
        }

        return ($execute && ($result['status'] ?? null) === 'blocked') ? self::FAILURE : self::SUCCESS;
    }

    private function nullableOption(string $key): ?string
    {
        $value = $this->option($key);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
