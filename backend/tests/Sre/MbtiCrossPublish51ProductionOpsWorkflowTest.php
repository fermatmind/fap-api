<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MbtiCrossPublish51ProductionOpsWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_separates_content_and_indexability_preflight_from_apply(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'MBTI Cross Publish 51 Production Ops',
            'content_preflight',
            'content_apply',
            'indexability_preflight',
            'indexability_apply',
            'expected_control_plane_sha',
            'expected_active_revision',
            'expected_state_sha256',
            'preflight_run_id',
            'preflight_run_attempt',
            'operator_approval_phrase',
            'mbti.cross.publish51.production_ops.v1',
            'mbti.cross.publisher49.content.v1',
            'mbti.cross.publisher49.indexability.v1',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'personality:mbti-cross-publisher49-content',
            'personality:mbti-cross-publisher49-indexability',
            '604851b56031d22d48036e87a5358bf85c9e13268655dbe36d2ab798b3f58dae',
            'be4f17484334074cf2c90d57898ab80b6074093b2510a4b7b4b0432a164b4670',
            '["enfp-vs-entp","estj-vs-entj","isfp-vs-infp"]',
            'production_write_execution',
            'content_write_count',
            'indexability_write_count',
            'body_mutation_count: 0',
            'search_submission_count: 0',
            'application_deploy_count: 0',
            'symlink_write_count: 0',
            'worker_restart_count: 0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringContainsString(
            'I explicitly approve MBTI-CROSS-PUBLISHER-49 production content write for package SHA ${PACKAGE_SHA256} authorization SHA ${EDITORIAL_AUTHORIZATION_SHA256} current state SHA ${EXPECTED_STATE_SHA256} covering only enfp-vs-entp, estj-vs-entj, and isfp-vs-infp; keep noindex, sitemap, llms, llms-full, and search submission held.',
            $workflow,
        );
        $this->assertStringContainsString(
            'I explicitly approve MBTI-CROSS-PUBLISHER-49 production indexability release for package SHA ${PACKAGE_SHA256} authorization SHA ${EDITORIAL_AUTHORIZATION_SHA256} successful content readback SHA ${EXPECTED_STATE_SHA256} covering only enfp-vs-entp, estj-vs-entj, and isfp-vs-infp; release indexability, sitemap, llms, and llms-full without body changes or search submission.',
            $workflow,
        );
        $this->assertSame(1, substr_count(
            $workflow,
            'and .production_write_execution == false',
        ));
    }

    #[Test]
    public function workflow_is_latest_main_receipt_bound_and_uses_secrets_only_topology(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'test "$(git rev-parse HEAD)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'Revalidate latest control plane immediately before production operation',
            '.conclusion == "success"',
            '.head_branch == "main"',
            '.head_sha == $sha',
            '.run_attempt == $attempt',
            'gh run download "$PREFLIGHT_RUN_ID"',
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'ssh-private-key: ${{ secrets.SSH_PRIVATE_KEY }}',
            'SSH_KNOWN_HOSTS: ${{ secrets.SSH_KNOWN_HOSTS }}',
            'DEPLOY_USER: ${{ secrets.PRODUCTION_DEPLOY_USER }}',
            'DEPLOY_HOST: ${{ secrets.PRODUCTION_DEPLOY_HOST }}',
            'DEPLOY_PORT: ${{ secrets.PRODUCTION_DEPLOY_PORT }}',
            'DEPLOY_PATH: ${{ secrets.PRODUCTION_DEPLOY_PATH }}',
            'test ! -e "$DEPLOY_PATH/.dep/deploy.lock"',
            'test "$post_current" = "$current"',
            'test "$(tr -d \'\\r\\n\' < "$post_current/REVISION")" = "$EXPECTED_ACTIVE_REVISION"',
            'timeout --signal=TERM --kill-after=10s 300s',
            'timeout --signal=TERM --kill-after=10s 270s bash',
            'MBTI_CROSS_PUBLISH51_REMOTE_OPERATION_FAILED',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertSame(
            1,
            substr_count($workflow, 'group: deploy-${{ github.repository }}-production'),
            'The production operation must share the deployment mutex.',
        );
        $this->assertSame(
            2,
            substr_count($workflow, 'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"'),
            'Latest main must be checked during eligibility and again after environment approval.',
        );
        $this->assertStringNotContainsString(
            'cat "$RUNNER_TEMP/mbti-cross-publish51.err"',
            $workflow,
        );
        $this->assertStringNotContainsString('rollback --execute', $workflow);
        $this->assertStringNotContainsString('request-indexing', $workflow);
        $this->assertStringNotContainsString('sitemap submission', strtolower($workflow));
    }

    #[Test]
    public function workflow_validates_exact_three_held_and_released_states_without_artifact_bodies(): void
    {
        $workflow = $this->workflow();

        foreach ([
            '.publish_status == "published"',
            '.review_status == "approved"',
            '.is_public == true',
            '.is_indexable == false',
            '.sitemap_eligible == false',
            '.llms_eligible == false',
            '.search_submission_eligible == false',
            '.content_payload_json.robots == "noindex,follow"',
            '.is_indexable == true',
            '.sitemap_eligible == true',
            '.llms_eligible == true',
            '.content_payload_json.robots == "index,follow"',
            '(.content_payload_json.sections | length) == 8',
            '(.content_payload_json.faq | length) == 8',
            '(.content_payload_json.internal_links | length) == 7',
            'rollback_manifest_sha256',
            'operator_approval_phrase_sha256',
            '"seo:sitemap-source:v1:fresh"',
            '"seo:sitemap:xml:v6"',
            '"seo:sitemap:etag:v6"',
            '"seo:llms-txt:v1:body"',
            '"seo:llms-full-txt:v1:body"',
            'seo:warm-sitemap-source-cache --json --no-interaction --no-ansi',
            '"/zh/personality/enfp-vs-entp"',
            '"/zh/personality/estj-vs-entj"',
            '"/zh/personality/isfp-vs-infp"',
            '"/llms.txt"',
            '"/llms-full.txt"',
            'X-FM-Content-Release-Token',
            'https://fermatmind.com/${surface}?mbti_cross_publish51=${EXPECTED_STATE_SHA256}',
            'cache_closeout_ready',
            'cache_closeout_completed',
            'public_feed_readback_completed',
            'artifacts/mbti-cross-publish51-production-ops.json',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertSame(
            1,
            substr_count($workflow, 'path: artifacts/mbti-cross-publish51-production-ops.json'),
            'Only the single sanitized receipt may be uploaded.',
        );
        $this->assertStringNotContainsString('path: artifacts/**', $workflow);
        $this->assertStringNotContainsString('tee artifacts', $workflow);
        $this->assertStringNotContainsString('echo "$result"', $workflow);
    }

    private function workflow(): string
    {
        $path = dirname(__DIR__, 3).'/.github/workflows/mbti-cross-publish51-production-ops.yml';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Unable to read {$path}");

        return (string) $contents;
    }
}
