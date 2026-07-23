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
            'preflight_run_id',
            'preflight_run_attempt',
            'operator_approval_phrase',
            'backend.production_ops_queue_control.v1',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'and .production_write_execution == false',
            'and .ops_pending_total == 0',
            'and .program_present == false',
            'test "$lock_present" = false',
            'test "$process_count" = 0',
            'test "$ops_pending_total" = 0',
            'ops_pending_total="$(sudo -n -u www-data php -d display_errors=0 -r "$probe_code" "$DEPLOY_PATH/current/backend" 2>/dev/null)"',
            'test "$active_revision" = "$EXPECTED_ACTIVE_REVISION"',
            'test "$rendered_sha256" = "$EXPECTED_RENDERED_SHA256"',
            'test ! -e /etc/supervisor/conf.d/fap-queue-ops.conf',
            'sudo -n install -o root -g root -m 0644 "$candidate" /etc/supervisor/conf.d/fap-queue-ops.conf',
            'sudo -n "$supervisord_path" -t',
            'sudo -n "$supervisorctl_path" update fap-queue-ops',
            'OPS_QUEUE_REMOTE_PREFLIGHT_FAILED',
            'OPS_QUEUE_REMOTE_APPLY_FAILED',
            'application_deploy_count: 0',
            'symlink_write_count: 0',
            'migration_count: 0',
            'cms_or_database_authority_write_count: 0',
            'publication_or_discoverability_write_count: 0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringContainsString(
            'I explicitly approve production fap-api ops queue program convergence from preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT} with control-plane SHA ${EXPECTED_CONTROL_PLANE_SHA} active SHA ${EXPECTED_ACTIVE_REVISION} template SHA256 ${EXPECTED_TEMPLATE_SHA256} rendered SHA256 ${EXPECTED_RENDERED_SHA256}; install one Supervisor ops worker, zero queued jobs, no deploy/symlink/migration/CMS/database-authority/publication/sitemap/llms/search/PR23.',
            $workflow,
        );
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringNotContainsString('whoami', $workflow);
        $this->assertStringNotContainsString('hostname', $workflow);
        $this->assertStringNotContainsString('cat "$META"', $workflow);
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
