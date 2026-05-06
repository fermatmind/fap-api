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
}
