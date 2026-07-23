<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerDetailProductionCacheRepairWorkflowTest extends TestCase
{
    #[Test]
    public function repair_job_routes_to_the_required_managed_default_queue(): void
    {
        $job = (string) file_get_contents(app_path('Jobs/Career/WarmCareerJobDetailProjection.php'));
        $deployer = (string) file_get_contents(base_path('../deploy.php'));
        $runbook = (string) file_get_contents(base_path('../README_DEPLOY.md'));

        $this->assertStringContainsString("\$this->onQueue('default');", $job);
        $this->assertStringContainsString("'fap-queue-default-high'", $deployer);
        $this->assertStringContainsString('--queue=high,default', $runbook);
        $this->assertStringNotContainsString("onQueue('career-cache')", $job);
    }

    #[Test]
    public function workflow_is_exact_revision_bound_and_serialized_with_production_deploy(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('environment: production', $source);
        $this->assertStringContainsString('group: deploy-${{ github.repository }}-production', $source);
        $this->assertStringContainsString('actions: read', $source);
        $this->assertStringContainsString('test "$GITHUB_REF" = "refs/heads/main"', $source);
        $this->assertStringContainsString('ref: ${{ inputs.candidate_release_revision }}', $source);
        $this->assertStringContainsString('test "$(git rev-parse HEAD)" = "$CANDIDATE_RELEASE_REVISION"', $source);
        $this->assertStringContainsString('git merge-base --is-ancestor "$EXPECTED_ACTIVE_REVISION" "$CANDIDATE_RELEASE_REVISION"', $source);
        $this->assertStringContainsString('git merge-base --is-ancestor "$CANDIDATE_RELEASE_REVISION" origin/main', $source);
        $this->assertStringContainsString('actions/runs/${STAGING_RUN_ID}', $source);
        $this->assertStringContainsString('.head_sha == $candidate_sha', $source);
        $this->assertStringContainsString('Deploy checks (staging)', $source);
        $this->assertStringContainsString('Deploy (staging)', $source);
        $this->assertStringContainsString('test \"\$current\" != \"\$candidate\"', $source);
        $this->assertStringContainsString("test ! -e '\$DEPLOY_PATH/.dep/deploy.lock'", $source);
        $this->assertStringNotContainsString('/var/www/fap-api-staging', $source);
    }

    #[Test]
    public function workflow_keeps_verify_only_read_only_and_repair_bounded_fail_closed(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('verify_only refuses an operator approval phrase.', $source);
        $this->assertStringContainsString(
            'cache/queue-only, no CMS/DB-authority/publication/indexability/sitemap/llms/search.',
            $source,
        );
        $this->assertSame(2, substr_count($source, 'career:verify-job-detail-cache-coverage --verify-only'));
        $this->assertSame(1, substr_count($source, 'career:verify-job-detail-cache-coverage --repair-missing'));
        $this->assertStringContainsString('--batch-size=$REPAIR_BATCH_SIZE', $source);
        $this->assertStringContainsString('--confirm-production-write', $source);
        $this->assertStringContainsString("supervisorctl status 'fap-queue-default-high:*'", $source);
        $this->assertStringContainsString('for batch in $(seq 1 9)', $source);
        $this->assertStringContainsString('for _attempt in $(seq 1 90)', $source);
        $this->assertStringContainsString('.covered_target_count == $expected_targets', $source);
        $this->assertStringContainsString('and .missing_count == 0', $source);
        $this->assertStringContainsString('and .broken_count == 0', $source);
        $this->assertStringNotContainsString('--repair-missing-sync', $source);
        $this->assertStringNotContainsString('deploy:symlink', $source);
    }

    private function workflowSource(): string
    {
        return (string) file_get_contents(base_path('../.github/workflows/career-detail-production-cache-repair.yml'));
    }
}
