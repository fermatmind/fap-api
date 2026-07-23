<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52ProductionEvidence;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveEn52ProductionBackup extends Command
{
    protected $signature = 'personality:big-five-en52-production-backup
        {--package=../generated/big-five-en52-release/release-package.json : Locked EN52 release package}
        {--execute : Create the private backup artifact and manifest; omission is read-only evidence}
        {--confirm= : Exact backup creation confirmation}
        {--output-dir= : Existing protected output directory}
        {--approved-sha= : Exact deployed backend SHA}
        {--release-name= : Exact deployed release directory identity}
        {--operator-admin-user-id=0 : Exact operator admin user id}
        {--allow-testing : Permit execute only in APP_ENV=testing}
        {--json : Emit sanitized JSON}';

    protected $description = 'Inspect or create the exact private EN52 production backup bound to deployment and live checksums.';

    public function handle(BigFiveEn52ProductionEvidence $evidence): int
    {
        try {
            if (! (bool) $this->option('execute')) {
                $result = $evidence->inspect((string) $this->option('package'));
            } else {
                $this->assertExecutionAllowed();
                if (! hash_equals(BigFiveEn52ProductionEvidence::BACKUP_CONFIRMATION, trim((string) $this->option('confirm')))) {
                    throw new RuntimeException('Exact EN52 production backup confirmation is required.');
                }
                $testingIdentity = app()->environment('testing') ? [
                    'sha' => (string) $this->option('approved-sha'),
                    'name' => (string) $this->option('release-name'),
                ] : null;
                $result = $evidence->createBackup(
                    (string) $this->option('package'),
                    (string) $this->option('output-dir'),
                    (int) $this->option('operator-admin-user-id'),
                    (string) $this->option('approved-sha'),
                    (string) $this->option('release-name'),
                    $testingIdentity,
                );
            }
        } catch (Throwable $throwable) {
            $result = [
                'schema_version' => BigFiveEn52ProductionEvidence::BACKUP_SCHEMA_VERSION,
                'ok' => false,
                'status' => 'FAIL_CLOSED_BIG_FIVE_EN52_PRODUCTION_BACKUP',
                'writes_committed' => false,
                'backup_files_created' => false,
                'error' => $throwable->getMessage(),
            ];
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ((bool) $this->option('json') ? JSON_PRETTY_PRINT : 0)));

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function assertExecutionAllowed(): void
    {
        if (app()->environment('production')) {
            return;
        }
        if (app()->environment('testing') && (bool) $this->option('allow-testing')) {
            return;
        }

        throw new RuntimeException('EN52 production backup execute requires production, or --allow-testing in tests.');
    }
}
