<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionOpsSharedValidationProcessRecoveryWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_binds_exact_live_process_preflight_and_term_only_apply(): void
    {
        $workflow = $this->readRepoFile(
            '.github/workflows/backend-production-ops-shared-validation-process-recovery.yml',
        );

        foreach ([
            'Backend Production Ops Shared Validation Process Recovery',
            'backend.production_ops_queue_control.v5',
            'backend.production_ops_shared_validation_process_recovery.v1',
            'failed_run_id',
            'failed_run_attempt',
            'expected_failed_control_plane_sha',
            'expected_main_runtime_fingerprint_sha256',
            'expected_ops_worker_pid_sha256',
            'expected_validation_config_sha256',
            'expected_validation_pid_file_sha256',
            'expected_validation_process_fingerprint_sha256',
            'expected_validation_descendant_count',
            'expected_validation_tree_sha256',
            'expected_validation_duplicate_ops_count',
            'expected_system_ops_worker_count',
            'preflight_run_id',
            'preflight_run_attempt',
            'operator_approval_phrase',
            '.conclusion == "cancelled" or .conclusion == "failure"',
            '.config_layout == "SHARED"',
            '.config_exact_program_section_count == 1',
            '.config_total_section_count == 3',
            '.config_foreign_program_section_count == 2',
            '.managed_target_current_sha256 == ("0" * 64)',
            '.validation_process_state == "present"',
            '.validation_duplicate_ops_count == 1',
            '.system_ops_worker_count == 2',
            '.production_write_execution == false',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'validation_process_stop_count',
            'automatic_kill_count: 0',
            'automatic_rollback_count: 0',
            'timeout-minutes: 10',
            'timeout --signal=TERM --kill-after=10s 180s',
            'scripts/deploy/control_ops_shared_validation_process.sh',
            'scripts/deploy/run_remote_control_process_group.py',
            'REMOTE_CONTROL_TIMEOUT_SECONDS=150',
            'REMOTE_CONTROL_TERM_GRACE_SECONDS=10',
            '--build-privileged-launcher',
            'OPS_SHARED_VALIDATION_PROCESS_GATE_FAILED:[A-Z0-9_]+',
            'OPS_SHARED_VALIDATION_PROCESS_REMOTE_FAILED',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringContainsString(
            'I explicitly approve production fap-api stop of the stale shared-migration validation process from failed run ${FAILED_RUN_ID} attempt ${FAILED_RUN_ATTEMPT} using process recovery preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT} and v5 evidence run ${EVIDENCE_RUN_ID} attempt ${EVIDENCE_RUN_ATTEMPT}',
            $workflow,
        );
        $this->assertStringContainsString(
            'send TERM only to the exact attested temporary supervisord, require its entire attested tree to exit, preserve the main Supervisor programs/PIDs and formal configs, leave all residue files for separate recovery, and stop without automatic KILL or rollback; no deploy/symlink/application-migration/CMS/database-authority/publication/sitemap/llms/search/PR23.',
            $workflow,
        );
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringContainsString(
            'ssh-private-key: ${{ secrets.SSH_PRIVATE_KEY }}',
            $workflow,
        );
        $this->assertStringContainsString(
            'SSH_KNOWN_HOSTS: ${{ secrets.SSH_KNOWN_HOSTS }}',
            $workflow,
        );
        $this->assertStringNotContainsString(
            'cat "$RUNNER_TEMP/ops-shared-validation-process-recovery.err"',
            $workflow,
        );
    }

    #[Test]
    public function remote_control_attests_the_exact_tree_and_never_kills_or_deletes(): void
    {
        $script = $this->readRepoFile(
            'scripts/deploy/control_ops_shared_validation_process.sh',
        );

        foreach ([
            'set -euo pipefail',
            'trap on_error ERR',
            'descendant_pids()',
            'main_runtime_fingerprint_sha256',
            'foreign_runtime_fingerprint_sha256',
            'ops_worker_pid_sha256',
            '/tmp/fap-ops-shared-migration-${FAILED_RUN_ID}',
            'validation_config_sha256',
            'validation_pid_file_sha256',
            'validation_process_fingerprint_sha256',
            'validation_tree_sha256',
            'validation_descendant_count',
            'validation_duplicate_ops_count',
            'system_ops_worker_count',
            'test "$validation_duplicate_ops_count" = 1',
            'test "$system_ops_worker_count" = 2',
            'test "$descendant_pid" != "$main_pid"',
            'sudo -n kill -TERM "$validation_pid"',
            'test "$post_system_ops_worker_count" = 1',
            'test "$(sudo -n "$supervisorctl_path" pid fap-queue-ops:fap-queue-ops_00 2>/dev/null)" = "$worker_pid"',
            'write query refused',
            'validation_process_stop_count=0',
            'residue_delete_count=0',
            'source_config_write_count=0',
            'target_config_write_count=0',
            'backup_write_count=0',
            'worker_restart_count=0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }

        foreach ([
            'kill -KILL',
            'kill -9',
            'rm -f',
            'supervisorctl restart',
            'supervisorctl update',
            'deploy:publish',
            'ln -s',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
        $this->assertSame(1, substr_count($script, 'sudo -n kill -TERM "$validation_pid"'));

        $runner = $this->readRepoFile('scripts/deploy/run_remote_control_process_group.py');
        $workflow = $this->readRepoFile(
            '.github/workflows/backend-production-ops-shared-validation-process-recovery.yml',
        );
        $this->assertSame(
            1,
            preg_match(
                '/process_group_forward_env_keys="([^"]+)"/',
                $workflow,
                $forwardedKeyMatch,
            ),
        );
        foreach (explode(',', $forwardedKeyMatch[1]) as $forwardedKey) {
            $this->assertStringContainsString(
                sprintf('"%s"', $forwardedKey),
                $runner,
                "{$forwardedKey} is missing from the privileged runner allowlist.",
            );
        }

        foreach ([
            '"FAILED_RUN_ID"',
            '"EVIDENCE_SOURCE_PATH_SHA256"',
            '"EVIDENCE_SOURCE_CONFIG_SHA256"',
            '"EVIDENCE_TARGET_PATH_SHA256"',
            '"EVIDENCE_TARGET_CURRENT_SHA256"',
            '"EXPECTED_MAIN_RUNTIME_FINGERPRINT_SHA256"',
            '"EXPECTED_OPS_WORKER_PID_SHA256"',
            '"EXPECTED_VALIDATION_CONFIG_SHA256"',
            '"EXPECTED_VALIDATION_PID_FILE_SHA256"',
            '"EXPECTED_VALIDATION_PROCESS_FINGERPRINT_SHA256"',
            '"EXPECTED_VALIDATION_DESCENDANT_COUNT"',
            '"EXPECTED_VALIDATION_TREE_SHA256"',
            '"EXPECTED_VALIDATION_DUPLICATE_OPS_COUNT"',
            '"EXPECTED_SYSTEM_OPS_WORKER_COUNT"',
        ] as $forwardedKey) {
            $this->assertStringContainsString($forwardedKey, $runner);
        }
    }

    private function readRepoFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Unable to read {$relativePath}");

        return (string) $contents;
    }
}
