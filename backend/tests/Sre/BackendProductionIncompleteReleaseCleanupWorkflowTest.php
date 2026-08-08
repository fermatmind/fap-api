<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackendProductionIncompleteReleaseCleanupWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_main_bound_production_protected_and_receipt_bound(): void
    {
        $source = $this->workflowSource();

        foreach ([
            'Backend Production Incomplete Release Cleanup',
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'contents: read',
            'actions: read',
            'test "$GITHUB_REF" = "refs/heads/main"',
            'test "$control_plane_sha" = "$(git rev-parse origin/main)"',
            '.github/workflows/deploy-production.yml',
            'backend-production-deploy-progress-${SOURCE_DEPLOY_RUN_ID}-${SOURCE_DEPLOY_RUN_ATTEMPT}',
            'deployer-task-timing-production',
            'deploy:update_code',
            'guard:expected-release-revision',
            'deploy:symlink',
            'deploy:cleanup',
            'backend.production_incomplete_release_cleanup.v1',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    #[Test]
    public function preflight_accepts_only_the_exact_empty_directory_residue(): void
    {
        $source = $this->workflowSource();
        $preflight = $this->between(
            $source,
            '      - name: Verify exact incomplete release residue without writes',
            '      - name: Remove exact empty inactive release residue',
        );

        foreach ([
            'target_is_active="$([ -n "$current_release" ] && [ "$target" = "$current_release" ] && echo true || echo false)"',
            'deploy_lock_present="$([ -e "$DEPLOY_PATH/.dep/deploy.lock" ] && echo true || echo false)"',
            "expected_inventory=\$'d backend\\nd backend/storage\\nd backend/storage/framework\\nd backend/storage/framework/cache'",
            'elif .regular_file_count != 0 then "REGULAR_FILES_PRESENT"',
            'elif .symlink_count != 0 then "SYMLINKS_PRESENT"',
            'elif .process_reference_count != 0 then "PROCESS_REFERENCE_PRESENT"',
            'directory_removal_count: 0',
            'file_delete_count: 0',
            'writes_committed: false',
            'releases_root_valid: $releases_root_valid',
            'current_release_valid: $current_release_valid',
            'target_resolved_exact: $target_resolved_exact',
            'inventory_exact: $inventory_exact',
            'INCOMPLETE_RELEASE_CLEANUP_PREFLIGHT_BLOCKED_${failure_code}',
        ] as $contract) {
            $this->assertStringContainsString($contract, $preflight);
        }

        foreach (['rmdir ', 'rm ', 'mv ', 'touch ', 'sudo ', 'php artisan', 'supervisorctl'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $preflight);
        }
    }

    #[Test]
    public function apply_is_exact_approval_bound_and_uses_only_explicit_rmdir_calls(): void
    {
        $source = $this->workflowSource();
        $apply = $this->between(
            $source,
            '      - name: Remove exact empty inactive release residue',
            '      - name: Upload cleanup receipt',
        );

        $this->assertStringContainsString(
            'I explicitly approve removal of empty inactive backend production release residue ${TARGET_RELEASE_NAME}',
            $source,
        );
        $this->assertStringContainsString('test "$OPERATOR_APPROVAL_PHRASE" = "$expected_phrase"', $source);
        $this->assertSame(5, substr_count($apply, 'rmdir -- '));

        foreach ([
            'test "$inventory_sha256" = "$EXPECTED_INVENTORY_SHA256"',
            'test "$process_reference_count" = 0',
            'test ! -e "$target"',
            'active_release_unchanged: true',
            'directory_removal_count: 5',
            'file_delete_count: 0',
            'deploy_count: 0',
            'database_write_count: 0',
            'cms_write_count: 0',
            'cache_warm_count: 0',
            'process_restart_count: 0',
            'discoverability_write_count: 0',
            'writes_committed: true',
            'INCOMPLETE_RELEASE_CLEANUP_REMOTE_FAILURE_LINE_',
            "rg -o 'INCOMPLETE_RELEASE_CLEANUP_REMOTE_FAILURE_LINE_[0-9]+'",
        ] as $contract) {
            $this->assertStringContainsString($contract, $apply);
        }

        foreach (['rm -', 'rm -r', 'rm -f', 'sudo ', 'deploy:symlink', 'php artisan', 'supervisorctl'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $apply);
        }
    }

    private function workflowSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/backend-production-incomplete-release-cleanup.yml',
        );
        $this->assertNotFalse($source);

        return (string) $source;
    }

    private function between(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $this->assertNotFalse($startPosition);
        $endPosition = strpos($source, $end, (int) $startPosition);
        $this->assertNotFalse($endPosition);

        return substr($source, (int) $startPosition, (int) $endPosition - (int) $startPosition);
    }
}
