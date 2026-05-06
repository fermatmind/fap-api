<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerValidateCnTrustManifestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_blocks_cn_seo_and_llms_eligibility_when_manifest_is_missing(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture();
        $outputPath = storage_path('framework/testing/career-cn-trust-manifest-dry-run.json');
        File::delete($outputPath);

        $exitCode = Artisan::call('career:validate-cn-trust-manifest', [
            '--scope' => $scopePath,
            '--dry-run' => true,
            '--timestamp' => 'career-cn-trust-manifest-test',
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
        $this->assertSame(0, (int) ($payload['manifest_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['manifest_complete_rows'] ?? -1));
        $this->assertSame(1663, (int) ($payload['missing_manifest_rows'] ?? 0));
        $this->assertSame(1663, (int) ($payload['missing_evidence_rows'] ?? 0));
        $this->assertSame(1663, (int) ($payload['missing_disclaimer_rows'] ?? 0));
        $this->assertSame(1663, (int) ($payload['missing_reviewer_reviewed_at_rows'] ?? 0));
        $this->assertSame(1663, (int) ($payload['missing_rollback_condition_rows'] ?? 0));
        $this->assertTrue((bool) ($payload['CN_trust_manifest_required'] ?? false));
        $this->assertSame(0, (int) ($payload['CN_public_indexable_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['CN_sitemap_eligible_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['CN_llms_eligible_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['CN_llms_full_eligible_rows'] ?? -1));
        $this->assertTrue((bool) ($payload['missing_evidence_blocks_CN_SEO_GEO'] ?? false));
        $this->assertTrue((bool) ($payload['missing_disclaimer_blocks_CN_public_eligibility'] ?? false));
        $this->assertTrue((bool) ($payload['missing_reviewer_reviewed_at_blocks_llms_eligibility'] ?? false));
        $this->assertTrue((bool) ($payload['missing_rollback_condition_blocks_llms_full_eligibility'] ?? false));
        $this->assertFalse((bool) ($payload['canonical_job_trust_behavior_regressed'] ?? true));
        $this->assertSame(0, (int) ($payload['career_job_display_assets_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['sitemap_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_CN_urls'] ?? -1));
        $this->assertContains('evidence_refs', (array) ($payload['required_fields'] ?? []));
        $this->assertContains('boundary_disclaimer', (array) ($payload['required_fields'] ?? []));
        $this->assertContains('rollback_condition', (array) ($payload['required_fields'] ?? []));
        $this->assertSame([], $payload['blockers'] ?? null);
        $this->assertFileExists($outputPath);
    }

    public function test_it_keeps_cn_public_eligibility_blocked_when_manifest_claim_is_incomplete(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture();
        $manifestPath = $this->writeCnTrustManifestFixture(missingFieldsForFirstClaim: true);

        $exitCode = Artisan::call('career:validate-cn-trust-manifest', [
            '--scope' => $scopePath,
            '--manifest' => $manifestPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame(1, (int) ($payload['manifest_rows'] ?? 0));
        $this->assertSame(0, (int) ($payload['manifest_complete_rows'] ?? -1));
        $this->assertSame(1662, (int) ($payload['missing_manifest_rows'] ?? 0));
        $this->assertSame(1663, (int) ($payload['missing_evidence_rows'] ?? 0));
        $this->assertSame(1663, (int) ($payload['missing_reviewer_reviewed_at_rows'] ?? 0));
        $this->assertSame(1663, (int) ($payload['missing_rollback_condition_rows'] ?? 0));
        $this->assertSame(0, (int) ($payload['CN_public_indexable_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['CN_llms_eligible_rows'] ?? -1));
        $this->assertSame([], $payload['blockers'] ?? null);
    }

    public function test_it_rejects_manifest_that_marks_cn_claim_public_before_policy_train(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture();
        $manifestPath = $this->writeCnTrustManifestFixture(publicEligible: true);

        $exitCode = Artisan::call('career:validate-cn-trust-manifest', [
            '--scope' => $scopePath,
            '--manifest' => $manifestPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('CN_manifest_public_eligibility_present_before_policy', (array) ($payload['blockers'] ?? []));
    }

    private function writeCnProxyScopeFixture(): string
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
                'recommended_resolution' => 'blocked_until_CN_authority_policy',
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

    private function writeCnTrustManifestFixture(bool $missingFieldsForFirstClaim = false, bool $publicEligible = false): string
    {
        $path = storage_path('framework/testing/career-cn-trust-manifest.json');
        File::ensureDirectoryExists(dirname($path));

        $claim = [
            'claim_id' => 'cn-proxy-0001-claim',
            'row_number' => 795,
            'slug' => 'cn-proxy-0001',
            'public_resolution_type' => 'blocked_until_governance_approval',
            'claim_text' => 'CN proxy row remains held until CN authority policy.',
            'claim_locale' => 'en-US',
            'source_authority_model' => 'CN-first authority required',
            'evidence_refs' => ['CN_663_Mapping_QA'],
            'evidence_strength' => 'governance_only',
            'reviewer' => 'career-cn-policy-reviewer',
            'reviewed_at' => '2026-05-06T00:00:00Z',
            'schema_policy' => 'CN_proxy_schema_policy_required_before_public_schema',
            'indexability' => 'not_public',
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'llms_full_eligible' => false,
            'boundary_disclaimer' => 'US SOC/O*NET mapping is comparison-only.',
            'rollback_condition' => 'remove_CN_public_resolution_before_publication',
            'last_validated_at' => '2026-05-06T00:00:00Z',
            'public_eligible' => $publicEligible,
        ];

        if ($missingFieldsForFirstClaim) {
            unset($claim['evidence_refs'], $claim['reviewer'], $claim['reviewed_at'], $claim['rollback_condition']);
        }

        File::put($path, (string) json_encode([
            'claims' => [$claim],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
