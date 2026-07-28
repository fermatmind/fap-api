<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Deploy\EnneagramPr23CacheOnlyResumeRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/deploy/enneagram_pr23_cache_only_resume.php';

final class EnneagramPr23CacheOnlyResumeRunnerTest extends TestCase
{
    #[Test]
    public function preflight_receipt_binds_exact_state_and_generates_the_authorization_phrase(): void
    {
        $bindings = [
            'preflight_run_id' => 123,
            'preflight_run_attempt' => 1,
            'control_plane_sha' => str_repeat('a', 40),
            'runner_sha256' => str_repeat('b', 64),
            'backend_sha' => str_repeat('c', 40),
            'frontend_sha' => str_repeat('d', 40),
            'package_sha256' => str_repeat('e', 64),
            'release_report_sha256' => str_repeat('f', 64),
            'runtime_config_apply_run_id' => 30333691762,
            'runtime_config_apply_run_attempt' => 1,
            'runtime_config_receipt_sha256' => str_repeat('1', 64),
            'rollback_token_sha256' => str_repeat('2', 64),
        ];
        $state = [
            'published_count' => 116,
            'working_count' => 116,
            'approved_review_count' => 116,
        ];
        $snapshot = [
            'public_projection_fingerprint' => str_repeat('3', 64),
            'stable_identity_discoverability_fingerprint' => str_repeat('4', 64),
            'url_sets' => ['sitemap' => ['sha256' => str_repeat('5', 64)]],
        ];
        $stateFingerprint = str_repeat('6', 64);
        $phrase = EnneagramPr23CacheOnlyResumeRunner::authorizationPhrase(
            123,
            1,
            $bindings['control_plane_sha'],
            $bindings['runner_sha256'],
            $bindings['backend_sha'],
            $bindings['frontend_sha'],
            $bindings['package_sha256'],
            $bindings['release_report_sha256'],
            30333691762,
            1,
            $bindings['runtime_config_receipt_sha256'],
            $bindings['rollback_token_sha256'],
            $stateFingerprint,
        );

        $receipt = EnneagramPr23CacheOnlyResumeRunner::preflightReceipt(
            $bindings,
            $state,
            $snapshot,
            $stateFingerprint,
            $phrase,
        );

        $this->assertSame('enneagram.pr23.cache_only_resume.v1', $receipt['contract_version']);
        $this->assertSame(
            'enneagram.pr23.cache_only_resume.authorization.v1',
            $receipt['authorization_contract_version'],
        );
        $this->assertSame(116, $receipt['published_count']);
        $this->assertSame(116, $receipt['working_count']);
        $this->assertSame(116, $receipt['approved_review_count']);
        $this->assertSame(30333691762, $receipt['runtime_config_apply_run_id']);
        $this->assertSame($phrase, $receipt['authorization_phrase']);
        $this->assertSame(hash('sha256', $phrase), $receipt['authorization_phrase_sha256']);
        $this->assertFalse($receipt['writes_committed']);
        $this->assertFalse($receipt['frontend_revalidation_committed']);
        $this->assertFalse($receipt['import_committed']);
        $this->assertFalse($receipt['review_bind_committed']);
        $this->assertFalse($receipt['promotion_committed']);
        $this->assertFalse($receipt['rollback_committed']);
        $this->assertFalse($receipt['backend_cache_invalidation_committed']);
        $this->assertFalse($receipt['deployment_committed']);
        $this->assertFalse($receipt['pr23_rerun']);
        $this->assertFalse($receipt['automatic_rollback']);
    }

    #[Test]
    public function authorization_phrase_is_explicitly_cache_only_and_post_readback_bound(): void
    {
        $phrase = EnneagramPr23CacheOnlyResumeRunner::authorizationPhrase(
            123,
            1,
            str_repeat('a', 40),
            str_repeat('b', 64),
            str_repeat('c', 40),
            str_repeat('d', 40),
            str_repeat('e', 64),
            str_repeat('f', 64),
            30333691762,
            1,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
        );

        $this->assertStringContainsString('revalidate exactly 116 frontend paths by HMAC', $phrase);
        $this->assertStringContainsString('post-readback canary 8 plus 9x12', $phrase);
        $this->assertStringContainsString(
            'no import/review-bind/promotion/rollback/backend-cache-invalidation/deploy',
            $phrase,
        );
        $this->assertStringContainsString('PR23-rerun', $phrase);
    }

    #[Test]
    public function execute_path_can_only_use_frontend_revalidation_and_post_readback_services(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/deploy/enneagram_pr23_cache_only_resume.php',
        );

        $this->assertStringContainsString('->revalidateFrontend(', $source);
        $this->assertStringContainsString('self::$frontendRevalidationAttempted = true;', $source);
        $this->assertStringContainsString('self::$frontendRevalidationCommitted = true;', $source);
        $this->assertStringContainsString(
            "'writes_committed' => self::\$frontendRevalidationCommitted",
            $source,
        );
        $this->assertStringContainsString("->run(\n                'post',", $source);
        $this->assertStringNotContainsString('->invalidatePreservingLkg(', $source);
        $this->assertStringNotContainsString('->warmAndVerifyFresh(', $source);
        $this->assertStringNotContainsString('EnneagramPublicAuthorityV224RuntimeCloseout', $source);
        $this->assertStringNotContainsString('EnneagramPublicAuthorityV223ReviewEvidenceBinder', $source);
        $this->assertStringNotContainsString('EnneagramPublicAuthorityV206RevisionPromoter', $source);
        $this->assertStringNotContainsString('->rollback(', $source);
        $this->assertStringNotContainsString('DB::transaction', $source);
        $this->assertStringNotContainsString('->save(', $source);
        $this->assertStringNotContainsString('->update(', $source);
        $this->assertStringNotContainsString('->create(', $source);
    }
}
