<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Seo13ArticleReviewApprovalProductionOpsWorkflowTest extends TestCase
{
    public function test_workflow_requires_immutable_preflight_and_holds_every_release_surface(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/seo-13-article-review-approval-production-ops.yml');

        $this->assertStringContainsString('expected_control_plane_sha:', $workflow);
        $this->assertStringContainsString('expected_release_sha:', $workflow);
        $this->assertStringContainsString('expected_release_name:', $workflow);
        $this->assertStringContainsString('expected_state_sha256:', $workflow);
        $this->assertStringContainsString('preflight_run_id:', $workflow);
        $this->assertStringContainsString('preflight_run_attempt:', $workflow);
        $this->assertStringContainsString('actions: read', $workflow);
        $this->assertStringContainsString('contents: read', $workflow);
        $this->assertSame(1, substr_count($workflow, 'fetch-depth: 0'));
        $this->assertStringContainsString('git merge-base --is-ancestor "$EXPECTED_RELEASE_SHA" origin/main', $workflow);
        $this->assertStringContainsString('gh run download "$PREFLIGHT_RUN_ID"', $workflow);
        $this->assertStringContainsString('.status == "PASS_PREFLIGHT"', $workflow);
        $this->assertStringContainsString('.review_approval_write_count == 0', $workflow);
        $this->assertStringContainsString('.publish_count == 0', $workflow);
        $this->assertStringContainsString('.schema_write_count == 0', $workflow);
        $this->assertStringContainsString('.hreflang_write_count == 0', $workflow);
        $this->assertStringContainsString('.search_submission_count == 0', $workflow);
        $this->assertStringContainsString('.revalidation_count == 0', $workflow);
        $this->assertStringContainsString('.sitemap_write_count == 0', $workflow);
        $this->assertStringContainsString('.llms_write_count == 0', $workflow);
        $this->assertStringContainsString('.deploy_count == 0', $workflow);
        $this->assertStringContainsString('.status == "FAIL_CLOSED"', $workflow);
        $this->assertStringContainsString('if: always()', $workflow);
        $this->assertStringContainsString('vars.PRODUCTION_DEPLOY_HOST', $workflow);
        $this->assertStringContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
        $this->assertStringNotContainsString('139.224.', $workflow);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow);
        $this->assertStringNotContainsString('queue:restart', $workflow);
        $this->assertStringNotContainsString('deploy:symlink', $workflow);
    }

    public function test_streamed_runner_uses_active_release_services_and_only_approves_exact_revisions(): void
    {
        $runner = $this->readRepoFile('backend/scripts/seo/seo_13_article_review_approval_production_ops.sh');

        $this->assertStringContainsString('COHORT_LOCK_FILE_SHA256="212b4b298244ba3ed89a1a999d5ea2019332d33694e67e73093b45f275a56166"', $runner);
        $this->assertStringContainsString('TARGET_SET_SHA256="67ecf80ba9a7ec3fc730bba43242005ffd84c5cedb328b62a1aa2dde2d4f934c"', $runner);
        $this->assertStringContainsString('CONTENT_SET_SHA256="b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e"', $runner);
        $this->assertStringContainsString('REVIEWED_BY_ADMIN_USER_ID="1"', $runner);
        $this->assertStringContainsString('ArticleEditorialCompletenessGate', $runner);
        $this->assertStringContainsString('CmsEditorialReviewAttestationService', $runner);
        $this->assertStringContainsString('approveEditorialWorkingRevision', $runner);
        $this->assertStringContainsString('DB::transaction', $runner);
        $this->assertStringContainsString('STATUS_HUMAN_REVIEW', $runner);
        $this->assertStringContainsString('STATUS_APPROVED', $runner);
        $this->assertStringContainsString('! (bool) $article->is_public', $runner);
        $this->assertStringContainsString('! (bool) $article->is_indexable', $runner);
        $this->assertStringContainsString('! (bool) $article->sitemap_eligible', $runner);
        $this->assertStringContainsString('! (bool) $article->llms_eligible', $runner);
        $this->assertStringContainsString("data_get(\$import->exactness_json, 'canonical_url')", $runner);
        $this->assertStringContainsString('$canonicalPath(', $runner);
        $this->assertStringContainsString("'https://fermatmind.com/zh/articles/'.(string) \$article->slug", $runner);
        $this->assertStringNotContainsString('$article->seoMeta?->robots', $runner);
        $this->assertStringNotContainsString('$article->seoMeta?->is_indexable', $runner);
        foreach ([
            'cohort_runtime_contract',
            'reviewer_identity',
            'review_governance',
            'revision_identity',
            'public_surface',
            'import_gate',
            'editorial_completeness',
            'state_lock',
            'transaction_lock',
            'approval_readback',
            'application_runtime',
        ] as $safeFailureStage) {
            $this->assertStringContainsString($safeFailureStage, $runner);
        }
        $this->assertStringNotContainsString('$exception->getTrace', $runner);
        $this->assertStringNotContainsString('$exception->getFile', $runner);
        $this->assertStringContainsString('review_approval_write_count: (if $mode == "apply" then 13 else 0 end)', $runner);
        $this->assertStringContainsString('publish_count: 0', $runner);
        $this->assertStringContainsString('schema_write_count: 0', $runner);
        $this->assertStringContainsString('hreflang_write_count: 0', $runner);
        $this->assertStringContainsString('search_submission_count: 0', $runner);
        $this->assertStringContainsString('revalidation_count: 0', $runner);
        $this->assertStringContainsString('sitemap_write_count: 0', $runner);
        $this->assertStringContainsString('llms_write_count: 0', $runner);
        $this->assertStringContainsString('deploy_count: 0', $runner);
        $this->assertStringNotContainsString('articles:promote-existing-working-revision', $runner);
        $this->assertStringNotContainsString('articles:release-closeout', $runner);
        $this->assertStringNotContainsString('php artisan migrate', $runner);
        $this->assertStringNotContainsString('queue:restart', $runner);
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        $this->assertIsString($contents);

        return $contents;
    }
}
