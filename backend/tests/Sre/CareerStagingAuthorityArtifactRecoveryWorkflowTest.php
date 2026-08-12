<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerStagingAuthorityArtifactRecoveryWorkflowTest extends TestCase
{
    #[Test]
    public function recovery_is_exact_production_receipt_bound_and_environment_isolated(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-staging-authority-artifact-recovery.yml');

        foreach ([
            'environment: production',
            'environment: staging',
            'PRODUCTION_DEPLOY_USER',
            'STAGING_DEPLOY_USER',
            "PRODUCTION_POINTER_APPLY_RUN_ID: '31593321673'",
            "PRODUCTION_POINTER_APPLY_RUN_ATTEMPT: '1'",
            "PRODUCTION_POINTER_APPLY_RECEIPT_SHA256: 'e0898f6fbb438495319cf0acc8bd6f808eba18c3f3beeb4a4e7d690312c27bc4'",
            "PRODUCTION_POINTER_APPLY_ARTIFACT_DIGEST: 'sha256:101508066a741afd44d29b4c28bd866b1fa3d4772dfda14c71da71c320c545c7'",
            '.path == ".github/workflows/career-current-generation-pointer-bootstrap-production-ops.yml"',
            'PASS_APPLY_POINTER_BOOTSTRAPPED',
            'PASS_PRODUCTION_FROZEN_AUTHORITY_READ_ONLY',
            'PASS_PREFLIGHT_APPLY_ELIGIBLE',
            'PASS_APPLY_STAGING_FROZEN_AUTHORITY_IMPORTED',
            'test "$(git rev-parse origin/main)" = "$CONTROL_PLANE_SHA"',
            'test ! -e $q_path/.dep/deploy.lock',
            'EXPECTED_PRODUCTION_ACTIVE_REVISION',
            'EXPECTED_STAGING_ACTIVE_REVISION',
        ] as $required) {
            self::assertStringContainsString($required, $workflow);
        }

        self::assertMatchesRegularExpression(
            '/production_read:.*?environment: production.*?PRODUCTION_DEPLOY_USER/s',
            $workflow,
        );
        self::assertMatchesRegularExpression(
            '/staging_restore:.*?environment: staging.*?STAGING_DEPLOY_USER/s',
            $workflow,
        );
    }

    #[Test]
    public function plaintext_is_runner_temporary_and_staging_commit_is_no_clobber(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-staging-authority-artifact-recovery.yml');
        $runner = $this->repoFile('backend/scripts/operations/career_staging_authority_artifact_recovery.php');

        foreach ([
            'umask 0077',
            'chmod 600 "$output"',
            'openssl cms -encrypt',
            'openssl cms -decrypt',
            'career-staging-authority-recovery-ciphertext-',
            'gh api --method DELETE',
            'CAREER_RECOVERY_RUNNER_BYTES',
            'declare(strict_types=1)',
            'if(!str_starts_with(\\$code,\\$prefix)){exit(92);}',
            'eval(substr(\\$code,strlen(\\$prefix)))',
            'stream_get_contents(STDIN',
            "fopen(\$candidate, 'x+b')",
            'link($candidate, $target)',
            'STAGING_EXISTING_ARTIFACT_CONFLICT',
            'STAGING_ARTIFACT_READBACK_FAILED',
            "'automatic_retry_allowed' => false",
            "'permission_write_count' => 0",
        ] as $required) {
            self::assertStringContainsString($required, $workflow.$runner);
        }

        foreach ([
            'curl -k',
            '--insecure',
            'filemtime(',
            'mtime',
            'createWorkflowDispatch',
            'gh workflow run',
            'php artisan migrate',
            'queue:restart',
            'indexnow',
            'googleapis',
            'chmod($target',
            'unlink($target',
            'rename($candidate, $target)',
            'Storage::put(',
            'File::put(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $workflow.$runner);
        }
    }

    #[Test]
    public function frozen_authority_and_zero_write_receipt_contract_are_locked(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-staging-authority-artifact-recovery.yml');
        $runner = $this->repoFile('backend/scripts/operations/career_staging_authority_artifact_recovery.php');
        $control = $workflow.$runner;

        foreach ([
            'career-current-342-30-bootstrap-v1',
            '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6',
            '975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e',
            '8b328b2e002875a9f92d4c406981f3c3724f066ee817d2d5bd1a61915e1eddf5',
            '607926991fa51c74d6d6c9606ab3b7f8f35918996006a39c68963c16765d5697',
            "'slug_count' => 342",
            "'locale_row_count' => 684",
            "'published_slug_count' => 30",
            "'published_locale_row_count' => 60",
            'pointer_write_count: 0',
            'database_write_count: 0',
            'cms_write_count: 0',
            'cache_write_count: 0',
            'publication_write_count: 0',
            'discoverability_write_count: 0',
            'migration_count: 0',
            'restart_count: 0',
            'permission_write_count: 0',
            'automatic_retry_allowed: false',
            'fallback_allowed: false',
            'overwrite_allowed: false',
        ] as $required) {
            self::assertStringContainsString($required, $control);
        }
    }

    #[Test]
    public function recovery_uses_the_frozen_authority_set_hash_contract_and_accepts_absent_staging_retired_host(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-staging-authority-artifact-recovery.yml');
        $runner = $this->repoFile('backend/scripts/operations/career_staging_authority_artifact_recovery.php');

        self::assertStringContainsString('array_unique(array_filter(array_map(', $runner);
        self::assertStringContainsString('strtolower(trim((string) $value))', $runner);
        self::assertStringContainsString('implode("\\n", $normalized)."\\n"', $runner);
        self::assertMatchesRegularExpression(
            '/production_read:.*?for name in DEPLOY_USER DEPLOY_PORT DEPLOY_HOST RETIRED_DEPLOY_HOST DEPLOY_PATH SSH_KNOWN_HOSTS/s',
            $workflow,
        );
        self::assertMatchesRegularExpression(
            '/staging_restore:.*?for name in DEPLOY_USER DEPLOY_PORT DEPLOY_HOST DEPLOY_PATH SSH_KNOWN_HOSTS.*?if \\[ -n "\\$RETIRED_DEPLOY_HOST" \\]; then/s',
            $workflow,
        );
        self::assertMatchesRegularExpression(
            '/staging_restore:.*?\\[\\[ "\\$DEPLOY_USER" =~ \\^\\[A-Za-z0-9_\\]\\[A-Za-z0-9_-\\]\\{0,31\\}\\$ \\]\\]/s',
            $workflow,
        );
        self::assertSame(2, substr_count(
            $workflow,
            'gh run download "$GITHUB_RUN_ID" --repo "$GITHUB_REPOSITORY"',
        ));
    }

    private function repoFile(string $relative): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relative);
        self::assertIsString($contents);

        return $contents;
    }
}
