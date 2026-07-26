<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionOpsSharedConfigMigrationWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_binds_v5_evidence_and_exact_authorized_v2_migration(): void
    {
        $workflow = $this->readRepoFile(
            '.github/workflows/backend-production-ops-shared-config-migration.yml',
        );

        foreach ([
            'Backend Production Ops Shared Config Migration',
            'backend.production_ops_queue_control.v5',
            'backend.production_ops_shared_config_migration.v2',
            'evidence_run_id',
            'evidence_run_attempt',
            'expected_source_path_sha256',
            'expected_source_config_sha256',
            'expected_stripped_source_sha256',
            'expected_target_path_sha256',
            'expected_target_current_sha256',
            'expected_rendered_ops_sha256',
            'expected_foreign_runtime_fingerprint_sha256',
            'preflight_run_id',
            'preflight_run_attempt',
            'operator_approval_phrase',
            '.config_layout == "SHARED"',
            '.config_exact_program_section_count == 1',
            '.config_total_section_count == 3',
            '.config_foreign_program_section_count == 2',
            '.managed_target_current_sha256 == ("0" * 64)',
            '.stripped_exact_program_section_count == 0',
            '.stripped_total_section_count == 2',
            '.stripped_program_section_count == 2',
            '.migration_supported == true',
            '.production_write_execution == false',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'source_config_write_count: $source_config_write_count',
            'target_config_write_count: $target_config_write_count',
            'backup_write_count: $backup_write_count',
            'worker_restart_count: $worker_restart_count',
            'migration_count: $migration_count',
            'automatic_rollback_count: $automatic_rollback_count',
            'timeout-minutes: 10',
            'timeout --signal=TERM --kill-after=10s 180s',
            'application_deploy_count: 0',
            'symlink_write_count: 0',
            'application_migration_count: 0',
            'cms_or_database_authority_write_count: 0',
            'publication_or_discoverability_write_count: 0',
            'scripts/deploy/control_ops_shared_config.sh',
            'scripts/deploy/project_ops_shared_config.php',
            'OPS_SHARED_MIGRATION_GATE_FAILED:[A-Z0-9_]+',
            'OPS_SHARED_MIGRATION_REMOTE_FAILED',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringContainsString(
            'I explicitly approve production fap-api shared-to-dedicated ops config migration from preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT} and v5 evidence run ${EVIDENCE_RUN_ID} attempt ${EVIDENCE_RUN_ATTEMPT} with control-plane SHA ${EXPECTED_CONTROL_PLANE_SHA} active SHA ${EXPECTED_ACTIVE_REVISION} template SHA256 ${EXPECTED_TEMPLATE_SHA256} source-path SHA256 ${EXPECTED_SOURCE_PATH_SHA256} source-config SHA256 ${EXPECTED_SOURCE_CONFIG_SHA256} stripped-source SHA256 ${EXPECTED_STRIPPED_SOURCE_SHA256} target-path SHA256 ${EXPECTED_TARGET_PATH_SHA256} target-current SHA256 ${EXPECTED_TARGET_CURRENT_SHA256} rendered-ops SHA256 ${EXPECTED_RENDERED_OPS_SHA256} foreign-runtime SHA256 ${EXPECTED_FOREIGN_RUNTIME_FINGERPRINT_SHA256} section-counts 1/3/2 to 0/2/2; write one stripped shared source and one dedicated ops config, preserve foreign program state and PIDs, restart only fap-queue-ops, keep exact backup, and stop without automatic rollback; no deploy/symlink/application-migration/CMS/database-authority/publication/sitemap/llms/search/PR23.',
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
        $this->assertStringNotContainsString('PRODUCTION_DEPLOY_SSH_KEY', $workflow);
        $this->assertStringNotContainsString('PRODUCTION_SSH_KNOWN_HOSTS', $workflow);
        $this->assertStringNotContainsString(
            'cat "$RUNNER_TEMP/ops-shared-migration.err"',
            $workflow,
        );
        $this->assertStringNotContainsString('whoami', $workflow);
        $this->assertStringNotContainsString('hostname', $workflow);
    }

    #[Test]
    public function remote_control_splits_only_the_attested_source_and_never_auto_rolls_back(): void
    {
        $script = $this->readRepoFile('scripts/deploy/control_ops_shared_config.sh');

        foreach ([
            'set -euo pipefail',
            'trap on_error ERR',
            'test "${#config_candidates[@]}" -eq 1',
            "-lFx '[program:fap-queue-ops]'",
            'target_path=/etc/supervisor/conf.d/fap-queue-ops.conf',
            'sudo -n test ! -e "$target_path"',
            'test "$config_exact_program_section_count" -eq 1',
            'test "$config_total_section_count" -eq 3',
            'test "$config_program_section_count" -eq 3',
            'test "$config_foreign_program_section_count" -eq 2',
            'test "$stripped_source_sha256" = "$EVIDENCE_STRIPPED_SOURCE_SHA256"',
            'test "$rendered_ops_sha256" = "$EVIDENCE_RENDERED_OPS_SHA256"',
            'test "$foreign_runtime_fingerprint_sha256" = "$EVIDENCE_FOREIGN_RUNTIME_FINGERPRINT_SHA256"',
            'ops-supervisor-migration-backups',
            'install -o root -g root -m 0600 "$source_path" "$backup_path"',
            'STALE_VALIDATION_RESIDUE',
            'stale_validation_process_count',
            'stale_validation_artifact_count',
            "-name 'fap-ops-shared-migration-*'",
            'CANDIDATE_SET_VALIDATE',
            'validate_supervisor_config "$validation_root"',
            'from supervisor.options import ServerOptions',
            'ServerOptions().realize(args=["-c", sys.argv[1]])',
            'install -o root -g root -m 0644 "$stripped_candidate" "$source_path"',
            'install -o root -g root -m 0644 "$target_candidate" "$target_path"',
            'validate_supervisor_config /etc/supervisor/supervisord.conf',
            'update fap-queue-ops',
            'test "$post_foreign_runtime_fingerprint_sha256" = "$foreign_runtime_fingerprint_sha256"',
            'test "$readback_pid" != "$worker_pid"',
            'test "$ops_pending_total" = 0',
            'write query refused',
            'source_config_write_count=1',
            'target_config_write_count=1',
            'backup_write_count=1',
            'worker_restart_count=1',
            'migration_count=1',
            'automatic_rollback_count=0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }

        foreach ([
            'rollback_on_error',
            'ROLLBACK_INSTALL',
            'ROLLBACK_UPDATE',
            'restart fap-queue-ops:fap-queue-ops_00',
            'queue=default',
            'queue=reports',
            '"$supervisord_path" -t',
            'Supervisord(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
        $this->assertSame(1, substr_count($script, 'update fap-queue-ops'));
    }

    #[Test]
    public function projector_removes_only_the_exact_ops_section(): void
    {
        $source = <<<'CONF'
[program:alpha]
command=/bin/alpha
[program:fap-queue-ops]
command=/usr/bin/php old
[program:beta]
command=/bin/beta
CONF;
        $source .= "\n";
        $candidate = <<<'CONF'
[program:fap-queue-ops]
command=/usr/bin/php artisan queue:work database --queue=ops
CONF;
        $candidate .= "\n";
        $stripped = "[program:alpha]\ncommand=/bin/alpha\n"
            ."[program:beta]\ncommand=/bin/beta\n";
        $sourcePath = tempnam(sys_get_temp_dir(), 'ops-shared-source-');
        $outputPath = tempnam(sys_get_temp_dir(), 'ops-shared-output-');
        $this->assertNotFalse($sourcePath);
        $this->assertNotFalse($outputPath);
        file_put_contents($sourcePath, $source);

        $projector = dirname(__DIR__, 3).'/scripts/deploy/project_ops_shared_config.php';
        $command = sprintf(
            'php %s %s %s %s',
            escapeshellarg($projector),
            escapeshellarg($sourcePath),
            escapeshellarg(base64_encode($candidate)),
            escapeshellarg($outputPath),
        );
        exec($command, $lines, $exitCode);

        try {
            $this->assertSame(0, $exitCode);
            $this->assertCount(1, $lines);
            $this->assertSame(
                implode("\t", [
                    hash('sha256', $stripped),
                    hash('sha256', $candidate),
                ]),
                $lines[0],
            );
            $this->assertSame($stripped, file_get_contents($outputPath));
        } finally {
            unlink($sourcePath);
            unlink($outputPath);
        }
    }

    #[Test]
    public function projector_matches_the_v5_awk_line_projection(): void
    {
        $source = "[program:alpha]\r\ncommand=/bin/alpha\r\n\r\n"
            ."[program:fap-queue-ops]\r\ncommand=/usr/bin/php old\r\n"
            ."[program:beta]\r\ncommand=/bin/beta\r\n";
        $candidate = "[program:fap-queue-ops]\r\ncommand=/usr/bin/php new\r\n";
        $expected = "[program:alpha]\ncommand=/bin/alpha\n\n"
            ."[program:beta]\ncommand=/bin/beta\n";
        $sourcePath = tempnam(sys_get_temp_dir(), 'ops-shared-source-');
        $outputPath = tempnam(sys_get_temp_dir(), 'ops-shared-output-');
        $this->assertNotFalse($sourcePath);
        $this->assertNotFalse($outputPath);
        file_put_contents($sourcePath, $source);

        $projector = dirname(__DIR__, 3).'/scripts/deploy/project_ops_shared_config.php';
        $command = sprintf(
            'php %s %s %s %s',
            escapeshellarg($projector),
            escapeshellarg($sourcePath),
            escapeshellarg(base64_encode($candidate)),
            escapeshellarg($outputPath),
        );
        exec($command, $lines, $exitCode);

        try {
            $this->assertSame(0, $exitCode);
            $this->assertSame($expected, file_get_contents($outputPath));
            $this->assertSame(
                hash('sha256', $expected),
                explode("\t", $lines[0])[0],
            );
        } finally {
            unlink($sourcePath);
            unlink($outputPath);
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
