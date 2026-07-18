<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\RangeIa\BigFiveLegacyAliasHardPurge;
use Illuminate\Console\Command;
use Throwable;

/** @review-surface personality_public_content_asset */
final class PersonalityBigFiveLegacyAliasesPurge extends Command
{
    public const CONFIRMATION = 'PURGE_BIG_FIVE_LEGACY_ALIAS_ROWS';

    protected $signature = 'personality:big-five-legacy-aliases-purge
        {--execute : Physically delete the exact twenty en/zh-CN legacy alias assets and cascaded evidence}
        {--confirm= : Exact execute confirmation}
        {--operator-admin-user-id=0 : Positive operator admin user id for audit attribution}
        {--backup-manifest= : Verified production backup manifest path}
        {--backup-sha256= : SHA-256 of the exact backup manifest file}
        {--json : Emit JSON}';

    protected $description = 'Inspect or physically purge the exact twenty Big Five legacy alias CMS rows after verified backup.';

    public function handle(BigFiveLegacyAliasHardPurge $purge): int
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
            $summary = $purge->run(
                execute: $execute,
                operatorAdminUserId: (int) $this->option('operator-admin-user-id'),
                backupManifestPath: trim((string) $this->option('backup-manifest')),
                backupManifestSha256: trim((string) $this->option('backup-sha256')),
            );
        } catch (Throwable $throwable) {
            return $this->finish([
                'schema_version' => BigFiveLegacyAliasHardPurge::SCHEMA_VERSION,
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
            $this->line((string) json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE,
            ));

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
