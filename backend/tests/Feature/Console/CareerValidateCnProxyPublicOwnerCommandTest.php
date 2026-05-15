<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerValidateCnProxyPublicOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_cn_proxy_public_owner_as_disabled_until_policy_gates_pass(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture();
        $outputPath = storage_path('framework/testing/career-cn-proxy-public-owner-dry-run.json');
        File::delete($outputPath);

        $exitCode = Artisan::call('career:validate-cn-proxy-public-owner', [
            '--scope' => $scopePath,
            '--dry-run' => true,
            '--timestamp' => 'career-cn-proxy-public-owner-test',
            '--output' => $outputPath,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('validated', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertFalse((bool) ($payload['did_write'] ?? true));
        $this->assertSame(1663, (int) ($payload['cn_proxy_rows'] ?? 0));
        $this->assertNull($payload['manifest_path'] ?? null);
        $this->assertFalse((bool) ($payload['reviewed_trust_manifest_complete'] ?? true));
        $this->assertFalse((bool) ($payload['public_owner_plan_ready'] ?? true));
        $this->assertFalse((bool) ($payload['route_owner_enabled'] ?? true));
        $this->assertSame(0, (int) ($payload['public_pages_exposed'] ?? -1));
        $this->assertFalse((bool) ($payload['public_route_allowed'] ?? true));
        $this->assertSame('disabled_until_CN_authority_policy_trust_manifest_disclaimer_and_release_gate', $payload['guarded_public_owner_state'] ?? null);
        $this->assertTrue((bool) ($payload['ledger_decision_required'] ?? false));
        $this->assertTrue((bool) ($payload['CN_authority_policy_required'] ?? false));
        $this->assertTrue((bool) ($payload['trust_manifest_required'] ?? false));
        $this->assertTrue((bool) ($payload['disclaimer_required'] ?? false));
        $this->assertTrue((bool) ($payload['release_gate_approval_required'] ?? false));
        $this->assertTrue((bool) ($payload['rejects_rows_without_ledger_decision'] ?? false));
        $this->assertTrue((bool) ($payload['rejects_rows_without_trust_manifest'] ?? false));
        $this->assertTrue((bool) ($payload['rejects_rows_without_disclaimer'] ?? false));
        $this->assertFalse((bool) ($payload['CN_proxy_can_masquerade_as_US_canonical_job'] ?? true));
        $this->assertFalse((bool) ($payload['US_canonical_job_schema_returned'] ?? true));
        $this->assertTrue((bool) ($payload['noindex_default'] ?? false));
        $this->assertSame(0, (int) ($payload['indexable_CN_proxy_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['sitemap_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['display_asset_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['career_job_display_assets_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['occupations_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['occupation_crosswalks_delta'] ?? -1));
        $this->assertSame([], $payload['blockers'] ?? null);
        $this->assertFileExists($outputPath);
    }

    public function test_it_accepts_reviewed_noindex_trust_manifest_for_guarded_public_owner_plan(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture();
        $manifestPath = $this->writeReviewedTrustManifestFixture();

        $exitCode = Artisan::call('career:validate-cn-proxy-public-owner', [
            '--scope' => $scopePath,
            '--manifest' => $manifestPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame($manifestPath, $payload['manifest_path'] ?? null);
        $this->assertSame(1663, (int) ($payload['reviewed_trust_manifest_rows'] ?? 0));
        $this->assertSame(1663, (int) ($payload['manifest_complete_rows'] ?? 0));
        $this->assertSame(0, (int) ($payload['missing_manifest_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['missing_evidence_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['missing_disclaimer_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['missing_reviewer_reviewed_at_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['missing_rollback_condition_rows'] ?? -1));
        $this->assertTrue((bool) ($payload['reviewed_trust_manifest_complete'] ?? false));
        $this->assertTrue((bool) ($payload['public_owner_plan_ready'] ?? false));
        $this->assertSame('reviewed_noindex_public_cn_proxy_page_ready_for_separate_owner_train', $payload['guarded_public_owner_state'] ?? null);
        $this->assertSame(1663, (int) ($payload['public_cn_proxy_page_rows'] ?? 0));
        $this->assertFalse((bool) ($payload['route_owner_enabled'] ?? true));
        $this->assertSame(0, (int) ($payload['public_pages_exposed'] ?? -1));
        $this->assertFalse((bool) ($payload['public_route_allowed'] ?? true));
        $this->assertTrue((bool) ($payload['reviewed_manifest_blocks_canonical_rollout'] ?? false));
        $this->assertTrue((bool) ($payload['noindex_default'] ?? false));
        $this->assertSame(0, (int) ($payload['indexable_CN_proxy_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['sitemap_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['display_asset_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['career_job_display_assets_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['occupations_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['occupation_crosswalks_delta'] ?? -1));
        $this->assertSame([], $payload['blockers'] ?? null);
    }

    public function test_it_blocks_reviewed_manifest_missing_reviewer_fields(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture();
        $manifestPath = $this->writeReviewedTrustManifestFixture(missingReviewerRows: 1);

        $exitCode = Artisan::call('career:validate-cn-proxy-public-owner', [
            '--scope' => $scopePath,
            '--manifest' => $manifestPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertFalse((bool) ($payload['reviewed_trust_manifest_complete'] ?? true));
        $this->assertFalse((bool) ($payload['public_owner_plan_ready'] ?? true));
        $this->assertSame(1, (int) ($payload['missing_reviewer_reviewed_at_rows'] ?? 0));
        $this->assertContains('CN_proxy_public_owner_manifest_reviewer_missing', (array) ($payload['blockers'] ?? []));
    }

    public function test_it_blocks_reviewed_manifest_that_attempts_index_sitemap_or_llms_eligibility(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture();
        $manifestPath = $this->writeReviewedTrustManifestFixture(indexableRows: 1);

        $exitCode = Artisan::call('career:validate-cn-proxy-public-owner', [
            '--scope' => $scopePath,
            '--manifest' => $manifestPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertFalse((bool) ($payload['reviewed_trust_manifest_complete'] ?? true));
        $this->assertFalse((bool) ($payload['public_owner_plan_ready'] ?? true));
        $this->assertContains('CN_proxy_public_owner_manifest_indexable_rows', (array) ($payload['blockers'] ?? []));
        $this->assertContains('CN_proxy_public_owner_manifest_sitemap_rows', (array) ($payload['blockers'] ?? []));
        $this->assertContains('CN_proxy_public_owner_manifest_llms_rows', (array) ($payload['blockers'] ?? []));
        $this->assertContains('CN_proxy_public_owner_manifest_llms_full_rows', (array) ($payload['blockers'] ?? []));
    }

    public function test_it_rejects_cn_public_candidates_before_public_owner_policy(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture(publicCandidates: 1);

        $exitCode = Artisan::call('career:validate-cn-proxy-public-owner', [
            '--scope' => $scopePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('CN_proxy_public_resolution_present_before_public_owner_policy', (array) ($payload['blockers'] ?? []));
    }

    private function writeCnProxyScopeFixture(int $publicCandidates = 0): string
    {
        $path = storage_path('framework/testing/career-phase2c-cn-proxy-scope.json');
        File::ensureDirectoryExists(dirname($path));

        $rows = [];
        for ($index = 1; $index <= 1663; $index++) {
            $rows[] = [
                'row_number' => 794 + $index,
                'source_slug' => sprintf('cn-proxy-%04d', $index),
                'title' => sprintf('CN Proxy %04d', $index),
                'current_status' => 'CN_proxy_hold',
                'recommended_resolution' => $index <= $publicCandidates ? 'public_cn_proxy_page_candidate' : 'blocked_until_CN_authority_policy',
                'disclaimer_required' => true,
                'trust_manifest_required' => true,
            ];
        }

        File::put($path, (string) json_encode([
            'scope' => 'CN_proxy_hold',
            'count' => 1663,
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function writeReviewedTrustManifestFixture(int $missingReviewerRows = 0, int $indexableRows = 0): string
    {
        $path = storage_path('framework/testing/career-cn-proxy-reviewed-trust-manifest.json');
        File::ensureDirectoryExists(dirname($path));

        $claims = [];
        for ($index = 1; $index <= 1663; $index++) {
            $slug = sprintf('cn-proxy-%04d', $index);
            $claims[] = [
                'claim_id' => sprintf('cn-proxy-claim-%04d', $index),
                'row_number' => 794 + $index,
                'slug' => $slug,
                'public_resolution_type' => 'public_cn_proxy_page',
                'claim_text' => sprintf('Reviewed CN proxy public owner claim %04d', $index),
                'claim_locale' => 'zh-CN',
                'source_authority_model' => 'reviewed_cn_proxy_trust_manifest',
                'evidence_refs' => [
                    sprintf('source-plan-row:%d', 794 + $index),
                ],
                'evidence_strength' => 'reviewed_source_trace',
                'reviewer' => $index <= $missingReviewerRows ? '' : 'reviewer',
                'reviewed_at' => $index <= $missingReviewerRows ? '' : '2026-05-15T21:30:56Z',
                'schema_policy' => 'no_US_canonical_job_schema',
                'indexability' => $index <= $indexableRows ? 'indexable' : 'noindex',
                'public_eligible' => false,
                'sitemap_eligible' => $index <= $indexableRows,
                'llms_eligible' => $index <= $indexableRows,
                'llms_full_eligible' => $index <= $indexableRows,
                'boundary_disclaimer' => 'CN proxy page is not a US canonical occupation guide.',
                'rollback_condition' => 'Rollback if CN public owner policy or reviewed evidence is invalidated.',
                'last_validated_at' => '2026-05-15T21:30:56Z',
            ];
        }

        File::put($path, (string) json_encode([
            'schema_version' => 'career_2786_cn_proxy_trust_manifest_reviewed.v1',
            'claims' => $claims,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
