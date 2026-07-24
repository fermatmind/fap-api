<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionOpsSharedConfigMigrationWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_binds_split_preflight_and_exact_authorized_apply(): void
    {
        $workflow = $this->readRepoFile(
            '.github/workflows/backend-production-ops-shared-config-migration.yml',
        );

        foreach ([
            'Backend Production Ops Shared Config Migration',
            'backend.production_ops_shared_config_migration.v2',
            'expected_control_plane_sha',
            'expected_active_revision',
            'expected_template_sha256',
            'expected_config_path_sha256',
            'expected_current_config_sha256',
            'expected_current_ops_section_sha256',
            'expected_stripped_source_sha256',
            'expected_managed_target_path_sha256',
            'expected_managed_target_current_sha256',
            'expected_rendered_ops_config_sha256',
            'expected_foreign_runtime_fingerprint_sha256',
            'expected_config_exact_program_section_count',
            'expected_config_total_section_count',
            'expected_config_foreign_program_section_count',
            'expected_stripped_exact_program_section_count',
            'expected_stripped_total_section_count',
            'expected_stripped_program_section_count',
            'preflight_run_id',
            'preflight_run_attempt',
            'operator_approval_phrase',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'config_layout: "SHARED"',
            'migration_supported: true',
            'source_config_write_count: $source_config_write_count',
            'managed_target_write_count: $managed_target_write_count',
            'worker_restart_count: $worker_restart_count',
            'rollback_execution_count: $rollback_execution_count',
            'deploy_count: 0',
            'symlink_write_count: 0',
            'application_migration_count: 0',
            'cms_or_database_authority_write_count: 0',
            'publication_or_discoverability_write_count: 0',
            'scripts/deploy/control_ops_shared_config.sh',
            'scripts/deploy/project_ops_shared_config.php',
            'OPS_SHARED_CONFIG_(GATE|ROLLBACK)_FAILED:[A-Z0-9_]+',
            'OPS_SHARED_CONFIG_REMOTE_FAILED',
            'remove only the exact [program:fap-queue-ops] section',
            'install only the canonical dedicated target',
            'preserve both foreign programs and their runtime fingerprint',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringNotContainsString(
            'cat "$RUNNER_TEMP/ops-shared-config.err"',
            $workflow,
        );
        $this->assertStringNotContainsString('whoami', $workflow);
        $this->assertStringNotContainsString('hostname', $workflow);
    }

    #[Test]
    public function remote_control_splits_source_and_rolls_back_both_paths_exactly(): void
    {
        $script = $this->readRepoFile('scripts/deploy/control_ops_shared_config.sh');

        foreach ([
            'set -euo pipefail',
            'trap rollback_on_error ERR',
            'test "${#config_candidates[@]}" -eq 1',
            "-lFx '[program:fap-queue-ops]'",
            'test "$source_config" != "$managed_target"',
            'test "$config_total_section_count" -eq 3',
            'test "$config_foreign_program_section_count" -eq 2',
            'test "$managed_target_current_sha256" = "$zero_sha256"',
            'project_config "$source_candidate" "$target_candidate"',
            'install -o root -g root -m 0644 "$source_candidate" "$source_config"',
            'install -o root -g root -m 0644 "$target_candidate" "$managed_target"',
            'rm -f "$managed_target"',
            'install -o root -g root -m 0644 "$source_backup" "$source_config"',
            'update fap-queue-ops',
            'ROLLBACK_REMOVE_TARGET',
            'ROLLBACK_RESTORE_SOURCE',
            'ROLLBACK_SOURCE_HASH',
            'ROLLBACK_TARGET_ABSENT',
            'ROLLBACK_FOREIGN_RUNTIME',
            'test "$readback_pid" != "$worker_pid"',
            'test "$ops_pending_total" = 0',
            'write query refused',
            'production_write_execution=false',
            'production_write_execution=true',
            'source_config_write_count=1',
            'managed_target_write_count=1',
            'worker_restart_count=1',
            'rollback_execution_count=0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }

        $this->assertSame(2, substr_count($script, 'update fap-queue-ops'));
        $this->assertStringNotContainsString(
            'restart fap-queue-ops:fap-queue-ops_00',
            $script,
        );
        $this->assertStringNotContainsString('queue=default', $script);
        $this->assertStringNotContainsString('queue=reports', $script);
    }

    #[Test]
    public function projector_removes_ops_from_source_and_emits_dedicated_target(): void
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
        $currentOps = "[program:fap-queue-ops]\ncommand=/usr/bin/php old\n";
        $stripped = "[program:alpha]\ncommand=/bin/alpha\n"
            ."[program:beta]\ncommand=/bin/beta\n";
        $sourcePath = tempnam(sys_get_temp_dir(), 'ops-shared-source-');
        $strippedPath = tempnam(sys_get_temp_dir(), 'ops-stripped-output-');
        $targetPath = tempnam(sys_get_temp_dir(), 'ops-target-output-');
        $this->assertNotFalse($sourcePath);
        $this->assertNotFalse($strippedPath);
        $this->assertNotFalse($targetPath);
        file_put_contents($sourcePath, $source);

        $projector = dirname(__DIR__, 3).'/scripts/deploy/project_ops_shared_config.php';
        $command = sprintf(
            'php %s %s %s %s %s',
            escapeshellarg($projector),
            escapeshellarg($sourcePath),
            escapeshellarg(base64_encode($candidate)),
            escapeshellarg($strippedPath),
            escapeshellarg($targetPath),
        );
        exec($command, $lines, $exitCode);

        try {
            $this->assertSame(0, $exitCode);
            $this->assertCount(1, $lines);
            $this->assertSame(
                implode("\t", [
                    hash('sha256', $currentOps),
                    hash('sha256', $stripped),
                    hash('sha256', $candidate),
                ]),
                $lines[0],
            );
            $this->assertSame($stripped, file_get_contents($strippedPath));
            $this->assertSame($candidate, file_get_contents($targetPath));
        } finally {
            unlink($sourcePath);
            unlink($strippedPath);
            unlink($targetPath);
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
