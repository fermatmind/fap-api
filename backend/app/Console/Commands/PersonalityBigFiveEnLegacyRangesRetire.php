<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\RangeIa\BigFiveEnLegacyRangeRetirement;
use Illuminate\Console\Command;
use Throwable;

/** @review-surface personality_public_content_asset */
final class PersonalityBigFiveEnLegacyRangesRetire extends Command
{
    public const CONFIRMATION = 'RETIRE_BIG_FIVE_EN_LEGACY_RANGE_ALIASES';

    protected $signature = 'personality:big-five-en-legacy-ranges-retire
        {--execute : Archive the exact ten English legacy range rows}
        {--confirm= : Exact execute confirmation}
        {--operator-admin-user-id=0 : Positive operator admin user id for audit attribution}
        {--json : Emit JSON}';

    protected $description = 'Inspect or retire the exact ten English Big Five legacy range content identities while preserving redirect authority.';

    public function handle(BigFiveEnLegacyRangeRetirement $retirement): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && ! hash_equals(self::CONFIRMATION, trim((string) $this->option('confirm')))) {
            return $this->finish([
                'status' => 'BLOCKED',
                'writes_committed' => false,
                'errors' => ['Exact --confirm='.self::CONFIRMATION.' is required for --execute.'],
            ], self::FAILURE);
        }

        try {
            $summary = $retirement->run(
                execute: $execute,
                operatorAdminUserId: (int) $this->option('operator-admin-user-id'),
            );
        } catch (Throwable $throwable) {
            return $this->finish([
                'status' => 'BLOCKED',
                'writes_committed' => false,
                'errors' => [$throwable->getMessage()],
            ], self::FAILURE);
        }

        return $this->finish($summary, self::SUCCESS);
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exitCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $exitCode;
        }

        foreach ($payload as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $this->line($key.'='.$value);
        }

        return $exitCode;
    }
}
