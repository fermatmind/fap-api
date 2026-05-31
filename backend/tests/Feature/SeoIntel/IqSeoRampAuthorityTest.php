<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\LandingSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IqSeoRampAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_iq_seo_ramp_authority_is_backend_cms_owned_and_claim_safe(): void
    {
        $this->artisan('landing-surfaces:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/landing_surfaces',
        ])->assertExitCode(0);

        $en = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('surface_key', 'tests')
            ->where('locale', 'en')
            ->firstOrFail();

        $zh = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('surface_key', 'tests')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $enAuthority = (array) data_get($en->payload_json, 'seo.iq_ramp_authority', []);
        $zhAuthority = (array) data_get($zh->payload_json, 'seo.iq_ramp_authority', []);

        $this->assertSame('iq.seo_ramp_authority.v1', (string) ($enAuthority['schema'] ?? ''));
        $this->assertSame('backend_cms_landing_surface', (string) ($enAuthority['authority_source'] ?? ''));
        $this->assertSame('iq-test-intelligence-quotient-assessment', (string) ($enAuthority['test_slug'] ?? ''));
        $this->assertSame('IQ_BETA_30_ORIGINAL', (string) ($enAuthority['form_code'] ?? ''));
        $this->assertSame('/en/tests/iq-test-intelligence-quotient-assessment', (string) ($enAuthority['canonical_path'] ?? ''));
        $this->assertSame('/zh/tests/iq-test-intelligence-quotient-assessment', (string) data_get($enAuthority, 'localized_paths.zh'));
        $this->assertSame('index,follow', (string) ($enAuthority['robots'] ?? ''));
        $this->assertTrue((bool) ($enAuthority['is_indexable'] ?? false));
        $this->assertTrue((bool) ($enAuthority['sitemap_eligible'] ?? false));
        $this->assertTrue((bool) ($enAuthority['llms_eligible'] ?? false));
        $this->assertTrue((bool) ($enAuthority['jsonld_eligible'] ?? false));

        $this->assertSame('/zh/tests/iq-test-intelligence-quotient-assessment', (string) ($zhAuthority['canonical_path'] ?? ''));
        $this->assertSame('zh-CN', (string) ($zhAuthority['locale'] ?? ''));
        $this->assertSame('iq-beta30-original-og', (string) data_get($zhAuthority, 'media.og_asset_key'));
        $this->assertSame('iq-full-report-cover', (string) data_get($zhAuthority, 'media.report_cover_asset_key'));

        $this->assertTrue((bool) data_get($enAuthority, 'claim_policy.norm_authority_required'));
        $this->assertSame('IQ-NORM-03', (string) data_get($enAuthority, 'claim_policy.norm_authority_pr'));
        $this->assertFalse((bool) data_get($enAuthority, 'claim_policy.public_copy_iq_estimate_claims_enabled', true));
        $this->assertFalse((bool) data_get($enAuthority, 'claim_policy.public_copy_percentile_claims_enabled', true));
        $this->assertSame('original_reasoning_practice', (string) data_get($enAuthority, 'structured_data.assessment_mode'));
        $this->assertSame('raw_score_dimension_reference_paid_report_gated', (string) data_get($enAuthority, 'structured_data.result_scope'));

        $json = json_encode([$enAuthority, $zhAuthority], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ([
            'official IQ',
            'certified IQ',
            'clinical diagnosis',
            'hiring selection',
            '人群百分位',
            '官方智商',
            '招聘筛选',
        ] as $blockedClaim) {
            $this->assertStringNotContainsString($blockedClaim, $json);
        }
    }
}
