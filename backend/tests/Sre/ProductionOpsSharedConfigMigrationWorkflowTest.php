<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionOpsSharedConfigMigrationWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_binds_shared_section_preflight_and_exact_authorized_apply(): void
    {
        $workflow = $this->readRepoFile(
            '.github/workflows/backend-production-ops-shared-config-migration.yml',
        );

        foreach ([
            'Backend Production Ops Shared Config Migration',
            'backend.production_ops_shared_config_migration.v1',
            'expected_control_plane_sha',
            'expected_active_revision',
            'expected_template_sha256',
            'expected_config_path_sha256',
            'expected_current_config_sha256',
            'expected_current_ops_section_sha256',
            'expected_foreign_projection_sha256',
            'expected_foreign_status_sha256',
            'expected_rendered_ops_section_sha256',
            'expected_patched_config_sha256',
            'expected_config_exact_program_section_count',
            'expected_config_total_section_count',
            'expected_config_foreign_program_section_count',
            'expected_runtime_cwd_current',
            'expected_runtime_config_current',
            'preflight_run_id',
            'preflight_run_attempt',
            'operator_approval_phrase',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'config_layout: "SHARED"',
            'migration_supported: true',
            'and .ops_pending_total == 0',
            'and .live_process_verified == true',
            'and .production_write_execution == false',
            'config_write_count: $config_write_count',
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
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringContainsString(
            'I explicitly approve production fap-api shared ops-section migration from preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT} with control-plane SHA ${EXPECTED_CONTROL_PLANE_SHA} active SHA ${EXPECTED_ACTIVE_REVISION} template SHA256 ${EXPECTED_TEMPLATE_SHA256} config-path SHA256 ${EXPECTED_CONFIG_PATH_SHA256} current-config SHA256 ${EXPECTED_CURRENT_CONFIG_SHA256} current-ops-section SHA256 ${EXPECTED_CURRENT_OPS_SECTION_SHA256} foreign-projection SHA256 ${EXPECTED_FOREIGN_PROJECTION_SHA256} foreign-status SHA256 ${EXPECTED_FOREIGN_STATUS_SHA256} rendered-ops-section SHA256 ${EXPECTED_RENDERED_OPS_SECTION_SHA256} patched-config SHA256 ${EXPECTED_PATCHED_CONFIG_SHA256} section-counts ${EXPECTED_CONFIG_EXACT_PROGRAM_SECTION_COUNT}/${EXPECTED_CONFIG_TOTAL_SECTION_COUNT}/${EXPECTED_CONFIG_FOREIGN_PROGRAM_SECTION_COUNT} runtime-cwd-current ${EXPECTED_RUNTIME_CWD_CURRENT} runtime-config-current ${EXPECTED_RUNTIME_CONFIG_CURRENT}; replace only the exact [program:fap-queue-ops] section in the one attested shared Supervisor file, preserve the foreign projection and foreign program statuses, restart only fap-queue-ops, and perform exact rollback on failed readback; no deploy/symlink/migration/CMS/database-authority/publication/sitemap/llms/search/PR23.',
            $workflow,
        );
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringNotContainsString(
            'cat "$RUNNER_TEMP/ops-shared-config.err"',
            $workflow,
        );
        $this->assertStringNotContainsString('whoami', $workflow);
        $this->assertStringNotContainsString('hostname', $workflow);
    }

    #[Test]
    public function remote_control_preserves_foreign_projection_and_rolls_back_exactly(): void
    {
        $script = $this->readRepoFile('scripts/deploy/control_ops_shared_config.sh');

        foreach ([
            'set -euo pipefail',
            'trap rollback_on_error ERR',
            'test "${#config_candidates[@]}" -eq 1',
            "-lFx '[program:fap-queue-ops]'",
            'test "$config_exact_program_section_count" -eq 1',
            'test "$config_total_section_count" -ge 2',
            'test "$config_total_section_count" -eq "$config_program_section_count"',
            'test "$config_foreign_program_section_count" -ge 1',
            'OPS_PROJECTOR_B64',
            'project_config',
            'test "$readback_foreign_sha" = "$foreign_projection_sha256"',
            'test "$(awk \'$1 !~ /^fap-queue-ops(:|$)/\' <<<"$status_after" | sha256sum',
            'install -o root -g root -m 0600 "$config_path" "$backup_path"',
            'install -o root -g root -m 0644 "$candidate_path" "$config_path"',
            'update fap-queue-ops',
            'ROLLBACK_INSTALL',
            'ROLLBACK_HASH',
            'ROLLBACK_STATUS',
            'ROLLBACK_QUEUE',
            'test "$(sudo -n sha256sum "$config_path" | awk \'{print $1}\')" = "$EXPECTED_CURRENT_CONFIG_SHA256"',
            'test "$readback_pid" != "$worker_pid"',
            'test "$ops_pending_total" = 0',
            'write query refused',
            'production_write_execution=false',
            'production_write_execution=true',
            'config_write_count=1',
            'worker_restart_count=1',
            'rollback_execution_count=0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }

        $this->assertSame(
            2,
            substr_count($script, 'update fap-queue-ops'),
        );
        $this->assertStringNotContainsString(
            'restart fap-queue-ops:fap-queue-ops_00',
            $script,
        );
        $this->assertStringNotContainsString('queue=default', $script);
        $this->assertStringNotContainsString('queue=reports', $script);
    }

    #[Test]
    public function projector_replaces_only_the_exact_ops_section(): void
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
        $foreign = "[program:alpha]\ncommand=/bin/alpha\n"
            ."[program:beta]\ncommand=/bin/beta\n";
        $patched = "[program:alpha]\ncommand=/bin/alpha\n"
            .$candidate
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
                    hash('sha256', $currentOps),
                    hash('sha256', $foreign),
                    hash('sha256', $candidate),
                    hash('sha256', $patched),
                ]),
                $lines[0],
            );
            $this->assertSame($patched, file_get_contents($outputPath));
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
