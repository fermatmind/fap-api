<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MbtiCrossPublish51CacheCloseoutRecoveryWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_receipt_bound_and_limits_apply_to_derived_caches(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'MBTI Cross Publish 51 Cache Closeout Recovery',
            'preflight',
            'apply',
            'expected_source_cache_sha256',
            'expected_feed_absence_sha256',
            'preflight_run_id',
            'preflight_run_attempt',
            'mbti.cross.publish51.cache_closeout_recovery.v1',
            'PASS_PREFLIGHT',
            'PASS_APPLY',
            'a8933ce064815c0d7815cfc968ffd6957b310dc3bd42adfa16033e13c5b79afd',
            '06a076d1492f9a35f9c1e8f7eef94e47d573dd306c49097140f98eb324bb0659',
            '5e3b2e874c942b3021e662428eb97b3893de0153',
            '["enfp-vs-entp","estj-vs-entj","isfp-vs-infp"]',
            '"seo:sitemap-source:v1:fresh"',
            '"seo:sitemap-source:v1:stale"',
            '"seo:sitemap:xml:v6"',
            '"seo:sitemap:etag:v6"',
            '"seo:llms-txt:v1:body"',
            '"seo:llms-full-txt:v1:body"',
            'seo:warm-sitemap-source-cache --json --no-interaction --no-ansi',
            'mbti_cross_publish51_cache_closeout_recovery',
            'frontend_revalidation_path_count == 6',
            'source_found_count == 3',
            'closeout_state=recovery_ready',
            'closeout_state=already_closed',
            '.closeout_state == "recovery_ready"',
            '.closeout_state == "already_closed"',
            'public_api_readback_completed == true',
            'public_page_readback_completed == true',
            'public_feed_readback_completed == true',
            'database_write_count: 0',
            'body_mutation_count: 0',
            'publication_write_count: 0',
            'indexability_write_count: 0',
            'application_deploy_count: 0',
            'worker_restart_count: 0',
            'sitemap_submission_count: 0',
            'llms_submission_count: 0',
            'search_submission_count: 0',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertStringContainsString(
            'I explicitly approve MBTI-CROSS-PUBLISH-51 production cache-only discoverability closeout from preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT}',
            $workflow,
        );
        $this->assertStringContainsString(
            'delete exactly six bounded backend cache keys, warm sitemap source, revalidate exact six frontend paths',
            $workflow,
        );
        $this->assertStringNotContainsString('--execute', $workflow);
        $this->assertStringNotContainsString('rollback --execute', $workflow);
        $this->assertStringNotContainsString('request-indexing', $workflow);
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertSame(
            2,
            substr_count($workflow, 'curl --http1.1 -fsS --retry 3'),
            'Canonical feed reads must avoid the observed HTTP/2 truncation boundary.',
        );
    }

    #[Test]
    public function workflow_is_latest_main_production_mutex_bound_and_emits_only_a_sanitized_receipt(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'test "$(git rev-parse HEAD)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
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
            'MBTI_CROSS_PUBLISH51_CACHE_CLOSEOUT_REMOTE_OPERATION_FAILED',
            'artifacts/mbti-cross-publish51-cache-closeout-recovery.json',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        $this->assertSame(
            2,
            substr_count($workflow, 'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"'),
        );
        $this->assertSame(
            1,
            substr_count($workflow, 'path: artifacts/mbti-cross-publish51-cache-closeout-recovery.json'),
        );
        $this->assertStringNotContainsString('path: artifacts/**', $workflow);
        $this->assertStringNotContainsString('echo "$result"', $workflow);
        $this->assertStringNotContainsString(
            'cat "$RUNNER_TEMP/mbti-cross-publish51-cache-closeout.err"',
            $workflow,
        );
    }

    #[Test]
    public function every_run_block_stays_below_the_actions_expression_limit(): void
    {
        $workflow = $this->workflow();
        $lines = preg_split('/\R/', $workflow);
        $this->assertIsArray($lines);

        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)run:\s*\|\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $indent = strlen($matches[1]);
            $body = '';
            for ($cursor = $index + 1; $cursor < count($lines); $cursor++) {
                $candidate = $lines[$cursor];
                if ($candidate !== '' && strlen($candidate) - strlen(ltrim($candidate)) <= $indent) {
                    break;
                }
                $body .= $candidate."\n";
            }

            $this->assertLessThanOrEqual(
                20_000,
                strlen($body),
                'Each run block must stay below GitHub Actions’ 21,000-character expression limit.',
            );
        }
    }

    private function workflow(): string
    {
        $path = dirname(__DIR__, 3).'/.github/workflows/mbti-cross-publish51-cache-closeout-recovery.yml';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Unable to read {$path}");

        return (string) $contents;
    }
}
