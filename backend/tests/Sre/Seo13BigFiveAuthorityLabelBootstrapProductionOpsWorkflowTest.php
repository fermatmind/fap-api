<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Seo13BigFiveAuthorityLabelBootstrapProductionOpsWorkflowTest extends TestCase
{
    public function test_workflow_requires_latest_main_active_release_and_immutable_preflight_receipt(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/seo-13-bigfive-authority-label-bootstrap-production-ops.yml');

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'expected_state_sha256:',
            'expected_target_set_sha256:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$EXPECTED_RELEASE_SHA" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'gh run download "$PREFLIGHT_RUN_ID"',
            '.contract_version == "seo13.big_five_authority_label_bootstrap.production_ops.v1"',
            '.status == "PASS_PREFLIGHT"',
            '.status == "PASS_APPLY"',
            '.article_ids == [1, 2]',
            '.desired_author_name == "FermatMind Editorial"',
            '.desired_reviewer_name == "Content Review Desk"',
            '.article_metadata_write_count == 2',
            '.audit_write_count == 1',
            '.article_body_write_count == 0',
            '.revision_write_count == 0',
            '.publication_write_count == 0',
            '.indexability_write_count == 0',
            '.schema_write_count == 0',
            '.hreflang_write_count == 0',
            '.revalidation_count == 0',
            '.sitemap_eligibility_write_count == 0',
            '.llms_eligibility_write_count == 0',
            '.search_submission_count == 0',
            '.queue_dispatch_count == 0',
            '.gsc_request_count == 0',
            '.url_inspection_count == 0',
            '.deploy_count == 0',
            'if: always()',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'group: deploy-${{ github.repository }}-production',
            'set article author and reviewer labels for exactly 2 Big Five published articles',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        $this->assertSame(1, substr_count($workflow, 'fetch-depth: 0'));
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow);
        $this->assertStringNotContainsString('queue:restart', $workflow);
        $this->assertStringNotContainsString('deploy:symlink', $workflow);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow));
        $this->assertStringNotContainsString('search-channel-queue', strtolower($workflow));
    }

    public function test_runner_uses_one_preflight_one_atomic_apply_and_sanitized_receipts(): void
    {
        $runner = $this->readRepoFile(
            'backend/scripts/seo/seo_13_bigfive_authority_label_bootstrap_production_ops.sh',
        );

        foreach ([
            'seo13.big_five_authority_label_bootstrap.production_ops.v1',
            'articles:seo13-big-five-authority-label-bootstrap',
            '--dry-run',
            '--execute',
            '--expected-state-sha256="$expected_state_sha256"',
            '--expected-target-set-sha256="$expected_target_set_sha256"',
            'article_ids: [1, 2]',
            'desired_author_name: "FermatMind Editorial"',
            'desired_reviewer_name: "Content Review Desk"',
            'article_metadata_write_count: 2',
            'audit_write_count: 1',
            'article_body_write_count: 0',
            'revision_write_count: 0',
            'publication_write_count: 0',
            'indexability_write_count: 0',
            'schema_write_count: 0',
            'hreflang_write_count: 0',
            'revalidation_count: 0',
            'sitemap_eligibility_write_count: 0',
            'llms_eligibility_write_count: 0',
            'search_submission_count: 0',
            'queue_dispatch_count: 0',
            'gsc_request_count: 0',
            'url_inspection_count: 0',
            'deploy_count: 0',
            "write_state='indeterminate'",
            "write_state='committed'",
            "stage='revalidate_active_release_before_apply'",
            'latest_current_release="$(readlink -f "$deploy_path/current")"',
            '2>/dev/null',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        $this->assertSame(2, substr_count(
            $runner,
            'php artisan articles:seo13-big-five-authority-label-bootstrap',
        ));
        $this->assertStringNotContainsString('for target in', $runner);
        $this->assertStringNotContainsString('while read', $runner);
        $this->assertStringNotContainsString('php artisan migrate', $runner);
        $this->assertStringNotContainsString('queue:restart', $runner);
        $this->assertStringNotContainsString('deploy:symlink', $runner);
        $this->assertStringNotContainsString('content_md', $runner);
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        $this->assertIsString($contents);

        return $contents;
    }
}
