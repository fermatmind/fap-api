<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerValidateCnAuthorityPolicyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_cn_proxy_scope_without_public_release_or_seo_eligibility(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture(possibleUsCanonicalTargets: 12);
        $outputPath = storage_path('framework/testing/career-cn-authority-policy-dry-run.json');
        File::delete($outputPath);

        $exitCode = Artisan::call('career:validate-cn-authority-policy', [
            '--scope' => $scopePath,
            '--dry-run' => true,
            '--timestamp' => 'career-cn-authority-policy-test',
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
        $this->assertSame(1663, (int) ($payload['cn_rows_validated_for_governance_scope'] ?? 0));
        $this->assertSame(12, (int) ($payload['possible_us_canonical_targets'] ?? 0));
        $this->assertFalse((bool) ($payload['CN_as_US_canonical_job_allowed'] ?? true));
        $this->assertSame(0, (int) ($payload['public_canonical_job'] ?? -1));
        $this->assertSame(0, (int) ($payload['public_cn_proxy_page'] ?? -1));
        $this->assertFalse((bool) ($payload['public_route_allowed'] ?? true));
        $this->assertSame(0, (int) ($payload['indexable_CN_proxy_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['sitemap_eligible_CN_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_eligible_CN_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_eligible_CN_rows'] ?? -1));
        $this->assertTrue((bool) ($payload['disclaimer_required'] ?? false));
        $this->assertSame(1663, (int) ($payload['disclaimer_required_rows'] ?? 0));
        $this->assertTrue((bool) ($payload['trust_manifest_required'] ?? false));
        $this->assertSame(1663, (int) ($payload['trust_manifest_required_rows'] ?? 0));
        $this->assertFalse((bool) ($payload['mapping_evidence_alone_public_eligible'] ?? true));
        $this->assertSame(0, (int) ($payload['display_asset_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['career_job_display_assets_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['occupations_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['occupation_crosswalks_delta'] ?? -1));
        $this->assertSame(0, (int) ($payload['sitemap_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_CN_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_CN_urls'] ?? -1));
        $this->assertContains('boundary_disclaimer', (array) ($payload['required_future_fields'] ?? []));
        $this->assertContains('trust_manifest', (array) ($payload['required_future_fields'] ?? []));
        $this->assertContains('release_gate_approval', (array) ($payload['required_future_fields'] ?? []));
        $this->assertSame([], $payload['blockers'] ?? null);
        $this->assertFileExists($outputPath);
    }

    public function test_it_rejects_cn_scope_with_public_candidates_before_policy_approval(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture(publicCandidates: 1);

        $exitCode = Artisan::call('career:validate-cn-authority-policy', [
            '--scope' => $scopePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('CN_proxy_public_resolution_present_before_policy', (array) ($payload['blockers'] ?? []));
    }

    public function test_it_requires_disclaimer_and_trust_manifest_for_every_cn_proxy_row(): void
    {
        $scopePath = $this->writeCnProxyScopeFixture(missingDisclaimerRows: 1, missingTrustManifestRows: 1);

        $exitCode = Artisan::call('career:validate-cn-authority-policy', [
            '--scope' => $scopePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('CN_proxy_missing_disclaimer_requirement', (array) ($payload['blockers'] ?? []));
        $this->assertContains('CN_proxy_missing_trust_manifest_requirement', (array) ($payload['blockers'] ?? []));
    }

    private function writeCnProxyScopeFixture(
        int $possibleUsCanonicalTargets = 0,
        int $publicCandidates = 0,
        int $missingDisclaimerRows = 0,
        int $missingTrustManifestRows = 0,
    ): string {
        $path = storage_path('framework/testing/career-phase2c-cn-proxy-scope.json');
        File::ensureDirectoryExists(dirname($path));

        $rows = [];
        for ($index = 1; $index <= 1663; $index++) {
            $rows[] = [
                'row_number' => 794 + $index,
                'source_slug' => sprintf('cn-proxy-%04d', $index),
                'title' => sprintf('CN Proxy %04d', $index),
                'current_status' => 'CN_proxy_hold',
                'cn_mapping_source' => $index <= 663 ? 'CN_663_Mapping_QA' : 'CN_1000_Mapping_QA',
                'possible_us_canonical_target' => $index <= $possibleUsCanonicalTargets ? sprintf('canonical-%04d', $index) : null,
                'mapping_confidence' => $index % 5 === 0 ? 'high' : 'medium',
                'source_authority_model' => 'CN-first authority required; US SOC/O*NET is comparison proxy only',
                'recommended_resolution' => $index <= $publicCandidates ? 'public_cn_proxy_page_candidate' : 'blocked_until_CN_authority_policy',
                'disclaimer_required' => $index > $missingDisclaimerRows,
                'trust_manifest_required' => $index > $missingTrustManifestRows,
                'blockers' => $index <= $publicCandidates ? [] : [
                    'CN_public_authority_policy_not_release_proven',
                    'CN_specific_trust_manifest_evidence_owner_missing',
                ],
            ];
        }

        File::put($path, (string) json_encode([
            'scope' => 'CN_proxy_hold',
            'count' => 1663,
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
