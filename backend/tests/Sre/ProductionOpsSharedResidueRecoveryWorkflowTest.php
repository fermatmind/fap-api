<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionOpsSharedResidueRecoveryWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_binds_failed_run_v5_evidence_and_exact_recovery_receipt(): void
    {
        $workflow = $this->readRepoFile(
            '.github/workflows/backend-production-ops-shared-residue-recovery.yml',
        );

        foreach ([
            'Backend Production Ops Shared Residue Recovery',
            'backend.production_ops_queue_control.v5',
            'backend.production_ops_shared_residue_recovery.v2',
            'failed_run_id',
            'failed_run_attempt',
            'expected_failed_control_plane_sha',
            'expected_source_control_plane_sha',
            'current_evidence_run_id',
            'current_evidence_run_attempt',
            'expected_backup_sha256',
            'expected_residue_set_sha256',
            'expected_residue_file_count',
            'expected_validation_process_state',
            'expected_validation_process_fingerprint_sha256',
            'expected_migration_process_state',
            'expected_migration_process_fingerprint_sha256',
            'preflight_run_id',
            'preflight_run_attempt',
            'operator_approval_phrase',
            '.conclusion == "cancelled" or .conclusion == "failure"',
            '.config_layout == "SHARED"',
            '.config_exact_program_section_count == 1',
            '.config_total_section_count == 3',
            '.config_foreign_program_section_count == 2',
            '.managed_target_current_sha256 == ("0" * 64)',
            '.config_layout == "DEDICATED"',
            '.runtime_config_current == true',
            '.convergence_required == false',
            '.foreign_runtime_fingerprint_sha256 != ("0" * 64)',
            'foreign_runtime_fingerprint_sha256="$(jq -r \'.foreign_runtime_fingerprint_sha256\' "$current_evidence_receipt")"',
            '.production_write_execution == false',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'deleted_residue_file_count',
            'source_config_write_count: 0',
            'target_config_write_count: 0',
            'backup_write_count: 0',
            'worker_restart_count: 0',
            'validation_process_stop_count: 0',
            'timeout-minutes: 10',
            'timeout --signal=TERM --kill-after=10s 180s',
            'timeout --signal=TERM --kill-after=10s 150s bash',
            'scripts/deploy/control_ops_shared_residue_recovery.sh',
            'OPS_SHARED_RESIDUE_RECOVERY_GATE_FAILED:[A-Z0-9_]+',
            'OPS_SHARED_RESIDUE_RECOVERY_REMOTE_FAILED',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringContainsString(
            'I explicitly approve production fap-api cleanup of stale shared-config migration residue from failed run ${FAILED_RUN_ID} attempt ${FAILED_RUN_ATTEMPT} using recovery preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT}, source v5 evidence run ${EVIDENCE_RUN_ID} attempt ${EVIDENCE_RUN_ATTEMPT} at source control-plane SHA ${EXPECTED_SOURCE_CONTROL_PLANE_SHA}, and current v5 evidence run ${CURRENT_EVIDENCE_RUN_ID} attempt ${CURRENT_EVIDENCE_RUN_ATTEMPT}',
            $workflow,
        );
        $this->assertStringContainsString(
            'delete only the exact bounded /tmp residue files, preserve the stripped shared source, exact dedicated target, exact backup, main Supervisor programs/PIDs and ops worker, and stop without rollback; no deploy/symlink/application-migration/CMS/database-authority/publication/sitemap/llms/search/PR23.',
            $workflow,
        );
        $this->assertSame(
            1,
            substr_count($workflow, 'timeout --signal=TERM --kill-after=10s 150s bash'),
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
            'cat "$RUNNER_TEMP/ops-shared-residue-recovery.err"',
            $workflow,
        );
        $this->assertStringNotContainsString('whoami', $workflow);
        $this->assertStringNotContainsString('hostname', $workflow);
    }

    #[Test]
    public function remote_control_deletes_only_attested_files_and_preserves_runtime(): void
    {
        $script = $this->readRepoFile(
            'scripts/deploy/control_ops_shared_residue_recovery.sh',
        );

        foreach ([
            'set -euo pipefail',
            'trap on_error ERR',
            'target_path=/etc/supervisor/conf.d/fap-queue-ops.conf',
            'test "${#config_candidates[@]}" -eq 1',
            "-lFx '[program:fap-queue-ops]'",
            'test "$source_path_sha256" = "$EVIDENCE_SOURCE_PATH_SHA256"',
            'test "$source_current_config_sha256" = "$EVIDENCE_SOURCE_CURRENT_CONFIG_SHA256"',
            'test "$target_current_path" = "$target_path"',
            'test "$backup_sha256" = "$source_original_config_sha256"',
            'ops-supervisor-migration-backups/shared-source-${FAILED_RUN_ID}.conf',
            '/tmp/fap-ops-shared-migration-${FAILED_RUN_ID}',
            '"${residue_prefix}.source"',
            '"${residue_prefix}.target"',
            '"${residue_prefix}.supervisord.conf"',
            '"${residue_prefix}.log"',
            '"${residue_prefix}.pid"',
            'test "${#discovered_residue_paths[@]}" -ge 1',
            'test "${#discovered_residue_paths[@]}" -le 5',
            'test "$validation_process_count" = 0',
            'validation_process_state=absent',
            'migration_process_state=absent',
            'test "$residue_set_sha256" = "$EXPECTED_RESIDUE_SET_SHA256"',
            'sudo -n rm -f -- "$discovered_path"',
            'test "$post_worker_pid" = "$worker_pid"',
            'test "$foreign_runtime_fingerprint_sha256" = "$EVIDENCE_FOREIGN_RUNTIME_FINGERPRINT_SHA256"',
            'write query refused',
            'deleted_residue_file_count=0',
            'source_config_write_count=0',
            'target_config_write_count=0',
            'backup_write_count=0',
            'worker_restart_count=0',
            'validation_process_stop_count=0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }

        foreach ([
            'kill ',
            'pkill',
            'supervisorctl restart',
            'supervisorctl update',
            'deploy:publish',
            'ln -s',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
        $this->assertSame(1, substr_count($script, 'sudo -n rm -f -- "$discovered_path"'));
    }

    private function readRepoFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Unable to read {$relativePath}");

        return (string) $contents;
    }
}
