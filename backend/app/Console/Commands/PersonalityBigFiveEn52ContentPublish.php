<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52PackageCompiler;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52Publisher;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveEn52ContentPublish extends Command
{
    protected $signature = 'personality:big-five-en52-content-publish
        {--package=../generated/big-five-en52-release/release-package.json : Exact compiled 52-page release package}
        {--execute : Publish the exact 52-page cohort; omission is read-only preflight}
        {--confirm-content-sha256= : Required locked source content SHA for execute}
        {--confirm-cohort-sha256= : Required locked cohort snapshot SHA for execute}
        {--confirm-package-sha256= : Required locked compiled package file SHA for execute}
        {--operator-admin-user-id= : Required operator admin user id for execute}
        {--allow-testing : Permit execute only in APP_ENV=testing with SQLite}
        {--json : Emit full JSON output}';

    protected $description = 'Preflight or atomically publish the exact locked Big Five English EN52 52-page cohort.';

    public function handle(BigFiveEn52Publisher $publisher): int
    {
        try {
            $package = trim((string) $this->option('package'));
            if ($package === '') {
                throw new RuntimeException('--package is required.');
            }
            if ((bool) $this->option('execute')) {
                $this->assertExecuteEnvironment();
                $this->assertExactConfirmation();
                $result = $publisher->publish($package, (int) $this->option('operator-admin-user-id'));
            } else {
                $result = $publisher->preflight($package);
            }
        } catch (Throwable $throwable) {
            $result = [
                'ok' => false,
                'status' => 'FAIL_CLOSED_BIG_FIVE_EN52_52_PAGE_RELEASE',
                'writes_committed' => false,
                'error' => $throwable->getMessage(),
            ];
        }

        $this->emit($result);

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function assertExecuteEnvironment(): void
    {
        if (app()->environment('production')) {
            return;
        }
        if ((bool) $this->option('allow-testing')
            && app()->environment('testing')
            && config('database.default') === 'sqlite') {
            return;
        }

        throw new RuntimeException('--execute requires APP_ENV=production, or --allow-testing with APP_ENV=testing and SQLite.');
    }

    private function assertExactConfirmation(): void
    {
        if (! hash_equals(
            BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256,
            strtolower(trim((string) $this->option('confirm-content-sha256'))),
        )) {
            throw new RuntimeException('--confirm-content-sha256 must equal the locked source content SHA-256.');
        }
        if (! hash_equals(
            BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
            strtolower(trim((string) $this->option('confirm-package-sha256'))),
        )) {
            throw new RuntimeException('--confirm-package-sha256 must equal the locked compiled package file SHA-256.');
        }
        if (! hash_equals(
            BigFiveEn52PackageCompiler::COHORT_SNAPSHOT_SHA256,
            strtolower(trim((string) $this->option('confirm-cohort-sha256'))),
        )) {
            throw new RuntimeException('--confirm-cohort-sha256 must equal the locked cohort snapshot SHA-256.');
        }
        if ((int) $this->option('operator-admin-user-id') !== BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID) {
            throw new RuntimeException('--operator-admin-user-id must equal 1.');
        }
    }

    /** @param array<string,mixed> $result */
    private function emit(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        foreach ([
            'ok', 'status', 'mode', 'release_id', 'source_content_sha256', 'cohort_snapshot_sha256',
            'release_package_sha256', 'asset_count', 'claims_count', 'faq_count',
            'alias_expected_count', 'alias_safe_count', 'alias_database_count', 'alias_absent',
            'alias_descriptor_overlap_count',
            'alias_collision_count', 'alias_boundary_fingerprint_sha256', 'alias_boundary_unchanged',
            'created_revision_count', 'idempotent_unchanged_count', 'writes_committed',
            'cache_invalidation_ok', 'cache_invalidation_warning',
        ] as $field) {
            if (! array_key_exists($field, $result) || $result[$field] === null) {
                continue;
            }
            $value = is_bool($result[$field]) ? ($result[$field] ? '1' : '0') : (string) $result[$field];
            $this->line($field.'='.$value);
        }
        if (isset($result['error'])) {
            $this->line('error='.(string) $result['error']);
        }
    }
}
