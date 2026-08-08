<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackendProductionFailedReleaseQuarantineWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_main_bound_production_protected_and_exact_evidence_bound(): void
    {
        $source = $this->workflowSource();

        foreach ([
            'Backend Production Failed Release Quarantine',
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'contents: read',
            'actions: read',
            'test "$GITHUB_REF" = "refs/heads/main"',
            'test "$control_plane_sha" = "$(git rev-parse origin/main)"',
            '.github/workflows/deploy-production.yml',
            'backend-production-deploy-progress-${SOURCE_DEPLOY_RUN_ID}-${SOURCE_DEPLOY_RUN_ATTEMPT}',
            'deployer-task-timing-production',
            '.task == "deploy:release" and .result == "success"',
            '.task == "deploy:update_code" and .result == "failure" and .exit_code == 128',
            '.task == "deploy:shared" and .result == "skipped"',
            '.task == "artisan:migrate" and .result == "skipped"',
            '.task == "deploy:symlink" and .result == "skipped"',
            '.task == "deploy:cleanup" and .result == "skipped"',
            'backend-production-release-discovery-${DISCOVERY_RUN_ID}',
            '.reason == "revision_missing"',
            'backend.production_failed_release_quarantine.v1',
            'PASS_INVENTORY',
            'PASS_QUARANTINE',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    #[Test]
    public function inventory_builds_a_bounded_deterministic_tree_without_remote_writes(): void
    {
        $source = $this->workflowSource();
        $inventory = $this->between(
            $source,
            '      - name: Capture deterministic failed release inventory without writes',
            '      - name: Atomically quarantine exact failed inactive release',
        );

        foreach ([
            'find "$target" -xdev -mindepth 1 -print0',
            'jq -c \'select(.record_kind == "tree")\'',
            'grep -q \'[[:cntrl:]]\'',
            'sha256sum -- "$path"',
            'link_target_sha256',
            'link_absolute',
            'link_escape',
            'mountpoint_count',
            '"$process_root"/fd/*',
            'test "$entry_count" -le 250000',
            'test "$total_size_bytes" -le 5368709120',
            'test "$special_file_count" = 0',
            'test "$absolute_symlink_count" = 0',
            'test "$escaping_symlink_count" = 0',
            'test "$(jq -r \'.process_reference_count\' artifacts/remote-control.json)" = 0',
            'test "$(jq -r \'.target_device\' artifacts/remote-control.json)" = "$(jq -r \'.deploy_device\' artifacts/remote-control.json)"',
            'destination_absent: true',
            'quarantine_eligible: true',
            'remote_file_write: false',
            'writes_committed: false',
        ] as $contract) {
            $this->assertStringContainsString($contract, $inventory);
        }

        foreach (['rm -', 'mv -T', 'touch ', 'sudo ', 'php artisan', 'supervisorctl', 'chmod ', 'chown '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $inventory);
        }

        $this->assertStringNotContainsString(
            'jq -e -c \'select(.record_kind == "tree")\'',
            $inventory,
            'An empty failed-release directory is a valid deterministic inventory, not a jq no-output failure.',
        );
    }

    #[Test]
    public function apply_is_inventory_receipt_bound_and_performs_one_atomic_move_without_deletion(): void
    {
        $source = $this->workflowSource();
        $apply = $this->between(
            $source,
            '      - name: Atomically quarantine exact failed inactive release',
            '      - name: Upload failed release inventory receipt',
        );

        $this->assertStringContainsString(
            'I explicitly approve atomic quarantine of failed inactive backend production release ${TARGET_RELEASE_NAME}',
            $source,
        );
        $this->assertStringContainsString('test "$OPERATOR_APPROVAL_PHRASE" = "$expected_phrase"', $source);
        $this->assertStringContainsString('test "$tree_sha256" = "$EXPECTED_TREE_SHA256"', $source);
        $this->assertStringContainsString('test "$destination_name" = "$INVENTORY_DESTINATION_NAME"', $source);
        $this->assertSame(1, substr_count($apply, 'mv -T -- '));

        foreach ([
            'test "$CURRENT_TREE_SHA256" = "$EXPECTED_TREE_SHA256"',
            'test "$current_tree_sha256" = "$EXPECTED_TREE_SHA256"',
            'test ! -e "$DEPLOY_PATH/.dep/deploy.lock"',
            'test "$process_reference_count" = 0',
            'test ! -e "$target"',
            'test -d "$destination"',
            'active_release_unchanged: true',
            'rename_count: 1',
            'file_delete_count: 0',
            'deploy_count: 0',
            'database_write_count: 0',
            'cms_write_count: 0',
            'cache_warm_count: 0',
            'process_restart_count: 0',
            'discoverability_write_count: 0',
            'writes_committed: true',
        ] as $contract) {
            $this->assertStringContainsString($contract, $apply);
        }

        foreach (['rm -', 'rm -r', 'rm -f', 'rmdir ', 'sudo ', 'php artisan', 'supervisorctl'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $apply);
        }
    }

    #[Test]
    public function inventory_artifact_contains_the_receipt_and_exact_tree_manifest(): void
    {
        $source = $this->workflowSource();

        foreach ([
            'backend-production-failed-release-inventory-${{ github.run_id }}-${{ github.run_attempt }}',
            'artifacts/backend-production-failed-release-quarantine.json',
            'artifacts/tree-manifest.jsonl',
            'if-no-files-found: error',
            'retention-days: 30',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    private function workflowSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/backend-production-failed-release-quarantine.yml',
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
