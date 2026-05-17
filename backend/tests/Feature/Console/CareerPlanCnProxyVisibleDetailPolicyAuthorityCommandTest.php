<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerPlanCnProxyVisibleDetailPolicyAuthority;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerPlanCnProxyVisibleDetailPolicyAuthorityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(CareerPlanCnProxyVisibleDetailPolicyAuthority::class),
        );
    }

    public function test_it_blocks_visible_detail_authority_without_explicit_product_decision(): void
    {
        $exitCode = Artisan::call('career:plan-cn-proxy-visible-detail-policy-authority', [
            '--scope' => $this->writeCnProxyScopeFixture(),
            '--public-owner-plan' => $this->writePublicOwnerPlanFixture(),
            '--visible-gap' => $this->writeVisibleGapFixture(),
            '--json' => true,
        ]);

        $payload = $this->payload();

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertSame('blocked', $payload['status']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['rollout_allowed']);
        $this->assertFalse($payload['candidate_prep_allowed']);
        $this->assertSame(1663, $payload['cn_proxy_rows']);
        $this->assertFalse($payload['visible_detail_publication_allowed']);
        $this->assertContains('product_policy_decision_missing', array_column($payload['blockers'], 'reason'));
    }

    public function test_it_accepts_conservative_partition_aware_decision_without_publication_apply(): void
    {
        $exitCode = Artisan::call('career:plan-cn-proxy-visible-detail-policy-authority', [
            '--scope' => $this->writeCnProxyScopeFixture(),
            '--public-owner-plan' => $this->writePublicOwnerPlanFixture(),
            '--visible-gap' => $this->writeVisibleGapFixture(),
            '--decision' => $this->writeDecisionFixture('KEEP_PARTITION_AWARE_PRODUCT_CLAIM'),
            '--output' => storage_path('framework/testing/career-cn-proxy-visible-detail-policy-authority.json'),
            '--json' => true,
        ]);

        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('partition_accounted_not_visible_detail', $payload['safe_claim_scope']);
        $this->assertTrue($payload['partition_aware_claim_selected']);
        $this->assertFalse($payload['visible_detail_publication_requested']);
        $this->assertFalse($payload['visible_detail_publication_allowed']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame('KEEP_PARTITION_AWARE_PRODUCT_CLAIM', $payload['next_required_action']);
        $this->assertFileExists(storage_path('framework/testing/career-cn-proxy-visible-detail-policy-authority.json'));
    }

    public function test_it_blocks_visible_detail_decision_until_cn_first_authority_pipeline_exists(): void
    {
        $exitCode = Artisan::call('career:plan-cn-proxy-visible-detail-policy-authority', [
            '--scope' => $this->writeCnProxyScopeFixture(),
            '--public-owner-plan' => $this->writePublicOwnerPlanFixture(),
            '--visible-gap' => $this->writeVisibleGapFixture(),
            '--decision' => $this->writeDecisionFixture('PURSUE_CN_PROXY_VISIBLE_DETAIL_PUBLICATION'),
            '--json' => true,
        ]);

        $payload = $this->payload();
        $reasons = array_column($payload['blockers'], 'reason');

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertSame('blocked', $payload['status']);
        $this->assertTrue($payload['visible_detail_publication_requested']);
        $this->assertFalse($payload['visible_detail_publication_allowed']);
        $this->assertSame('insufficient_product_policy_authority', $payload['safe_claim_scope']);
        $this->assertContains('cn_first_authority_source_evidence_missing', $reasons);
        $this->assertContains('cn_visible_detail_schema_policy_missing', $reasons);
        $this->assertContains('cn_display_asset_pipeline_missing', $reasons);
        $this->assertContains('cn_directory_inclusion_gate_missing', $reasons);
        $this->assertContains('cn_visible_live_acceptance_missing', $reasons);
        $this->assertSame('REPAIR_CN_PROXY_CN_FIRST_DISPLAY_ASSET_PIPELINE_1', $payload['next_required_action']);
    }

    public function test_it_rejects_noindex_public_owner_plan_that_attempts_indexable_cn_proxy_surface(): void
    {
        $exitCode = Artisan::call('career:plan-cn-proxy-visible-detail-policy-authority', [
            '--scope' => $this->writeCnProxyScopeFixture(),
            '--public-owner-plan' => $this->writePublicOwnerPlanFixture(indexableRows: 1),
            '--visible-gap' => $this->writeVisibleGapFixture(),
            '--decision' => $this->writeDecisionFixture('KEEP_PARTITION_AWARE_PRODUCT_CLAIM'),
            '--json' => true,
        ]);

        $payload = $this->payload();
        $reasons = array_column($payload['blockers'], 'reason');

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('reviewed_noindex_public_owner_plan_unsafe_indexable_cn_proxy_rows', $reasons);
        $this->assertContains('reviewed_noindex_public_owner_plan_unsafe_sitemap_cn_urls', $reasons);
        $this->assertContains('reviewed_noindex_public_owner_plan_unsafe_llms_cn_urls', $reasons);
        $this->assertContains('reviewed_noindex_public_owner_plan_unsafe_llms_full_cn_urls', $reasons);
        $this->assertFalse($payload['visible_detail_publication_allowed']);
    }

    public function test_it_rejects_cn_proxy_scope_count_mismatch(): void
    {
        $exitCode = Artisan::call('career:plan-cn-proxy-visible-detail-policy-authority', [
            '--scope' => $this->writeCnProxyScopeFixture(count: 1662),
            '--public-owner-plan' => $this->writePublicOwnerPlanFixture(),
            '--visible-gap' => $this->writeVisibleGapFixture(),
            '--decision' => $this->writeDecisionFixture('KEEP_PARTITION_AWARE_PRODUCT_CLAIM'),
            '--json' => true,
        ]);

        $payload = $this->payload();

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertContains('cn_proxy_row_count_mismatch', array_column($payload['blockers'], 'reason'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function writeCnProxyScopeFixture(int $count = 1663): string
    {
        $path = storage_path('framework/testing/career-cn-proxy-visible-detail-scope.json');
        File::ensureDirectoryExists(dirname($path));

        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $rows[] = [
                'slug' => sprintf('cn-proxy-%04d', $index),
                'partition' => 'cn_proxy_public_owner',
            ];
        }

        File::put($path, json_encode([
            'schema_version' => 'career_cn_proxy_scope.test.v1',
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    private function writePublicOwnerPlanFixture(int $indexableRows = 0): string
    {
        $path = storage_path('framework/testing/career-cn-proxy-visible-detail-public-owner-plan.json');
        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'schema_version' => 'career_cn_proxy_public_owner_plan.test.v1',
            'status' => 'pass',
            'public_owner_plan_ready' => true,
            'reviewed_trust_manifest_complete' => true,
            'cn_proxy_rows' => 1663,
            'public_cn_proxy_page_rows' => 1663,
            'noindex_default' => true,
            'indexable_CN_proxy_rows' => $indexableRows,
            'sitemap_CN_urls' => $indexableRows,
            'llms_CN_urls' => $indexableRows,
            'llms_full_CN_urls' => $indexableRows,
            'display_asset_delta' => 0,
            'career_job_display_assets_delta' => 0,
            'occupations_delta' => 0,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    private function writeVisibleGapFixture(): string
    {
        $path = storage_path('framework/testing/career-cn-proxy-visible-detail-gap.json');
        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'schema_version' => 'career_2786_cn_proxy_policy_scan_after_1434.summary.v1',
            'visible_counts_after_1434' => [
                'all_source_slugs' => 2786,
                'backend_public_detail_indexable_count' => 1122,
                'cn_proxy_public_owner_partition' => 1663,
                'software_manual_hold' => 1,
                'detail_indexable_gap_to_2786' => 1664,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    private function writeDecisionFixture(string $decisionId): string
    {
        $path = storage_path('framework/testing/career-cn-proxy-visible-detail-decision.json');
        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'schema_version' => 'career_product_policy_decision.test.v1',
            'decision_id' => $decisionId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }
}
