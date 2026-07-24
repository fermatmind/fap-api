<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductionOpsQueueControlWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_binds_read_only_preflight_and_exact_authorized_apply(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/backend-production-ops-queue-control.yml');

        foreach ([
            'Backend Production Ops Queue Control',
            'expected_control_plane_sha',
            'expected_active_revision',
            'expected_template_sha256',
            'expected_rendered_sha256',
            'expected_current_config_sha256',
            'expected_config_path_sha256',
            'expected_runtime_cwd_current',
            'expected_runtime_config_current',
            'preflight_run_id',
            'preflight_run_attempt',
            'operator_approval_phrase',
            'backend.production_ops_queue_control.v3',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'and .production_write_execution == false',
            'and .ops_pending_total == 0',
            'and .config_path_sha256 == $config_path_sha',
            'and .current_config_sha256 == $current_config_sha',
            'and .runtime_cwd_current == $runtime_cwd_current',
            'and .runtime_config_current == $runtime_config_current',
            'and .convergence_required == true',
            '(.program_present == true',
            'and .program_state == "RUNNING"',
            'and .live_process_verified == true)',
            '(.program_present == false',
            'and .program_state == "MISSING"',
            'test "$lock_present" = false',
            'test "$process_count" = 0',
            'test "$ops_pending_total" = 0',
            'ops_pending_total="$(sudo -n -u www-data php -d display_errors=0 -r "$probe_code" "$DEPLOY_PATH/current/backend" 2>/dev/null)"',
            'test "$active_revision" = "$EXPECTED_ACTIVE_REVISION"',
            'test "$rendered_sha256" = "$EXPECTED_RENDERED_SHA256"',
            'status_lines="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"',
            'test "$current_config_sha256" != "$zero_sha256"',
            '[ "$current_config_sha256" != "$EXPECTED_RENDERED_SHA256" ] && convergence_required=true',
            'test "$(ps -o user= -p "$worker_pid" | awk \'{$1=$1; print}\')" = www-data',
            'sudo -n -u www-data readlink -f "/proc/$worker_pid/cwd"',
            'case "$worker_cwd" in',
            '"$deploy_root"/releases/*/backend)',
            'test "$(tr -d \'\r\n\' < "$stale_release/REVISION")" = "$active_revision"',
            'pid fap-queue-ops:fap-queue-ops_00',
            'actual_argv_sha256="$(sha256sum "/proc/$worker_pid/cmdline" | awk \'{print $1}\')"',
            'process_epoch=$((boot_epoch + start_ticks / clock_ticks))',
            'test "$process_epoch" -ge "$config_epoch"',
            'test "$live_process_verified" = true',
            'test "$live_process_verified" = false',
            'test "${{ steps.preflight.outputs.live_process_verified }}" = false',
            'test "${{ steps.preflight.outputs.convergence_required }}" = true',
            'test "${{ steps.preflight.outputs.config_path_sha256 }}" = "$EXPECTED_CONFIG_PATH_SHA256"',
            'test "${{ steps.preflight.outputs.runtime_cwd_current }}" = "$EXPECTED_RUNTIME_CWD_CURRENT"',
            'test "${{ steps.preflight.outputs.runtime_config_current }}" = "$EXPECTED_RUNTIME_CONFIG_CURRENT"',
            'EXPECTED_PROGRAM_PRESENT_BEFORE: ${{ steps.preflight.outputs.program_present }}',
            'test "$program_present_before" = "$EXPECTED_PROGRAM_PRESENT_BEFORE"',
            'CURRENT_CONFIG_SHA256_BEFORE: ${{ steps.preflight.outputs.current_config_sha256 }}',
            'CONFIG_PATH_SHA256: ${{ steps.preflight.outputs.config_path_sha256 }}',
            'RUNTIME_CWD_CURRENT_BEFORE: ${{ steps.preflight.outputs.runtime_cwd_current }}',
            'RUNTIME_CONFIG_CURRENT_BEFORE: ${{ steps.preflight.outputs.runtime_config_current }}',
            'CONVERGENCE_REQUIRED_BEFORE: ${{ steps.preflight.outputs.convergence_required }}',
            'LIVE_PROCESS_VERIFIED_BEFORE: ${{ steps.preflight.outputs.live_process_verified }}',
            'APPLY_BACKUP_SHA256: ${{ steps.apply.outputs.backup_sha256 }}',
            'current_config_sha256: $current_config_sha256',
            'config_path_sha256: $config_path_sha256',
            'runtime_cwd_current: $runtime_cwd_current',
            'runtime_config_current: $runtime_config_current',
            'convergence_required: $convergence_required',
            'backup_config_sha256: $backup_config_sha256',
            'live_process_verified: $live_process_verified',
            'APPLY_LIVE_PROCESS_VERIFIED: ${{ steps.apply.outputs.live_process_verified }}',
            'live_process_verified="$APPLY_LIVE_PROCESS_VERIFIED"',
            'live_process_verified=true',
            'failure_gate=CONFIG_DISCOVERY',
            'failure_gate=GREP_PATH',
            'grep_path="$(command -v grep)"',
            'sudo -n find /etc/supervisor /opt/1panel -type f -size -256k',
            '-exec "$grep_path" -lFx \'[program:fap-queue-ops]\' {} +',
            'test "${#config_candidates[@]}" -le 1',
            'failure_gate=CONFIG_SINGLE_PROGRAM_BOUNDARY',
            'test "$(sudo -n "$grep_path" -Fxc \'[program:fap-queue-ops]\' "$OPS_CONFIG_PATH")" -eq 1',
            'test "$(sudo -n "$grep_path" -Ec \'^\\[[^]]+\\][[:space:]]*$\' "$OPS_CONFIG_PATH")" -eq 1',
            'OPS_CONFIG_PATH=/etc/supervisor/conf.d/fap-queue-ops.conf',
            '[[ "$OPS_CONFIG_PATH" =~ ^/(etc/supervisor|opt/1panel)(/[A-Za-z0-9._-]+)+\.(conf|ini)$ ]]',
            'test "$(printf \'%s\' "$OPS_CONFIG_PATH" | sha256sum | awk \'{print $1}\')" = "$EXPECTED_CONFIG_PATH_SHA256"',
            'sudo -n install -o root -g root -m 0600 "$OPS_CONFIG_PATH" "$backup"',
            'sudo -n install -o root -g root -m 0644 "$candidate" "$OPS_CONFIG_PATH"',
            'sudo -n "$supervisord_path" -t',
            'sudo -n "$supervisorctl_path" update fap-queue-ops',
            'sudo -n "$supervisorctl_path" restart fap-queue-ops:fap-queue-ops_00',
            'OPS_QUEUE_REMOTE_PREFLIGHT_FAILED',
            'OPS_QUEUE_REMOTE_APPLY_FAILED',
            'OPS_QUEUE_PREFLIGHT_GATE_FAILED:[A-Z0-9_]+',
            'OPS_QUEUE_APPLY_GATE_FAILED:[A-Z0-9_]+',
            'failure_gate=PID_LOOKUP',
            'failure_gate=WORKING_DIRECTORY_SCOPE',
            'failure_gate=WORKING_DIRECTORY_REVISION',
            'failure_gate=ARGV_IDENTITY',
            'failure_gate=QUEUE_PROBE',
            'current_config_sha256="$(sudo -n sha256sum "$OPS_CONFIG_PATH" | awk \'{print $1}\')"',
            'application_deploy_count: 0',
            'symlink_write_count: 0',
            'migration_count: 0',
            'cms_or_database_authority_write_count: 0',
            'publication_or_discoverability_write_count: 0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringContainsString(
            'I explicitly approve production fap-api ops queue managed-config convergence from preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT} with control-plane SHA ${EXPECTED_CONTROL_PLANE_SHA} active SHA ${EXPECTED_ACTIVE_REVISION} template SHA256 ${EXPECTED_TEMPLATE_SHA256} config-path SHA256 ${EXPECTED_CONFIG_PATH_SHA256} current config SHA256 ${EXPECTED_CURRENT_CONFIG_SHA256} rendered SHA256 ${EXPECTED_RENDERED_SHA256} runtime-cwd-current ${EXPECTED_RUNTIME_CWD_CURRENT} runtime-config-current ${EXPECTED_RUNTIME_CONFIG_CURRENT}; converge exactly one Supervisor ops worker config and restart only that worker with zero queued jobs, no deploy/symlink/migration/CMS/database-authority/publication/sitemap/llms/search/PR23.',
            $workflow,
        );
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringNotContainsString('whoami', $workflow);
        $this->assertStringNotContainsString('hostname', $workflow);
        $this->assertStringNotContainsString('cat "$META"', $workflow);
        $this->assertStringNotContainsString(
            'secrets.PRODUCTION_OPS_SUPERVISOR_CONFIG_PATH',
            $workflow,
        );
        $this->assertStringNotContainsString(
            'cat "$RUNNER_TEMP/ops-queue-preflight-ssh.err"',
            $workflow,
        );
        $this->assertStringNotContainsString(
            'cat "$RUNNER_TEMP/ops-queue-apply-ssh.err"',
            $workflow,
        );
        $this->assertStringNotContainsString(
            'status_lines="$(sudo -n "$supervisorctl_path" status 2>/dev/null || true)"',
            $workflow,
        );
        $this->assertSame(2, substr_count($workflow, 'pid fap-queue-ops:fap-queue-ops_00'));
        $this->assertSame(
            2,
            substr_count($workflow, 'sudo -n -u www-data readlink -f "/proc/$worker_pid/cwd"'),
        );
        $this->assertSame(2, substr_count($workflow, 'failure_gate=CONFIG_DISCOVERY'));
        $this->assertSame(2, substr_count($workflow, 'failure_gate=GREP_PATH'));
        $this->assertSame(2, substr_count($workflow, 'failure_gate=CONFIG_SINGLE_PROGRAM_BOUNDARY'));
        $this->assertSame(
            2,
            substr_count($workflow, 'sudo -n "$grep_path" -Ec \'^\\[[^]]+\\][[:space:]]*$\''),
        );
        $this->assertSame(
            2,
            substr_count($workflow, '-exec "$grep_path" -lFx \'[program:fap-queue-ops]\' {} +'),
        );
        $this->assertStringNotContainsString(
            '-name fap-queue-ops.conf -print',
            $workflow,
        );
    }

    #[Test]
    public function supervisor_template_is_one_bounded_ops_worker(): void
    {
        $template = $this->readRepoFile('deploy/supervisor/fap-queue-ops.conf.template');

        $this->assertSame(1, substr_count($template, '[program:fap-queue-ops]'));
        $this->assertStringContainsString('directory=__DEPLOY_PATH__/current/backend', $template);
        $this->assertStringContainsString('--queue=ops', $template);
        $this->assertStringContainsString('numprocs=1', $template);
        $this->assertStringContainsString('user=www-data', $template);
        $this->assertStringContainsString('autostart=true', $template);
        $this->assertStringContainsString('autorestart=true', $template);
        $this->assertStringNotContainsString('--queue=default', $template);
        $this->assertStringNotContainsString('--queue=reports', $template);
    }

    private function readRepoFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Unable to read {$relativePath}");

        return (string) $contents;
    }
}
