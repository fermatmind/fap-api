<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\ScaleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IqIdentityMetadataContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_iq_public_registry_points_to_canonical_v2_demo_dir(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $row = ScaleRegistry::queryByOrgWhitelist([0])
            ->where('org_id', 0)
            ->where('code', 'IQ_RAVEN')
            ->firstOrFail();

        $this->assertSame('iq-test-intelligence-quotient-assessment', (string) $row->primary_slug);
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO', (string) $row->default_dir_version);

        $catalog = $this->getJson('/api/v0.3/scales/catalog?locale=zh');
        $catalog->assertStatus(200);

        $iq = collect($catalog->json('items'))->firstWhere('slug', 'iq-test-intelligence-quotient-assessment');
        $this->assertIsArray($iq);
        $this->assertSame(30, (int) data_get($iq, 'questions_count'));
        $this->assertSame(20, (int) data_get($iq, 'time_minutes'));
    }

    public function test_iq_questions_accept_canonical_v2_code_and_legacy_alias(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $canonical = $this->getJson('/api/v0.3/scales/IQ_INTELLIGENCE_QUOTIENT/questions?region=CN_MAINLAND&locale=zh-CN');
        $canonical->assertStatus(200);
        $canonical->assertJsonPath('ok', true);
        $canonical->assertJsonPath('scale_code_v2', 'IQ_INTELLIGENCE_QUOTIENT');

        $legacy = $this->getJson('/api/v0.3/scales/IQ_RAVEN/questions?region=CN_MAINLAND&locale=zh-CN');
        $legacy->assertStatus(200);
        $legacy->assertJsonPath('ok', true);
        $legacy->assertJsonPath('scale_code_v2', 'IQ_INTELLIGENCE_QUOTIENT');
    }

    public function test_iq_pack_metadata_exposes_canonical_v2_public_slug_and_legacy_noindex_alias(): void
    {
        $canonicalManifest = json_decode((string) file_get_contents(base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/manifest.json')), true);
        $canonicalLanding = json_decode((string) file_get_contents(base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/meta/landing.json')), true);
        $canonicalVersion = json_decode((string) file_get_contents(base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/version.json')), true);

        $legacyManifest = json_decode((string) file_get_contents(base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/manifest.json')), true);
        $legacyLanding = json_decode((string) file_get_contents(base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/meta/landing.json')), true);

        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', data_get($canonicalManifest, 'scale_code'));
        $this->assertSame('legacy_demo', data_get($canonicalManifest, 'lifecycle.status'));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', data_get($canonicalLanding, 'scale_code'));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO', data_get($canonicalLanding, 'pack_id'));
        $this->assertSame('iq-test-intelligence-quotient-assessment', data_get($canonicalLanding, 'slug'));
        $this->assertSame('/tests/iq-test-intelligence-quotient-assessment', data_get($canonicalLanding, 'landing.canonical_path'));
        $this->assertSame('iq-test-intelligence-quotient-assessment', data_get($canonicalLanding, 'landing.canonical_slug'));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO', data_get($canonicalVersion, 'dir_version'));

        $this->assertSame('IQ_RAVEN', data_get($legacyManifest, 'scale_code'));
        $this->assertSame('legacy_demo', data_get($legacyManifest, 'lifecycle.status'));
        $this->assertSame(false, data_get($legacyLanding, 'index_policy.landing.index'));
        $this->assertSame(false, data_get($legacyLanding, 'index_policy.landing.follow'));
        $this->assertSame('legacy_demo', data_get($legacyLanding, 'lifecycle.status'));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', data_get($legacyLanding, 'lifecycle.canonical_scale_code'));
        $this->assertSame('/tests/iq-test-intelligence-quotient-assessment', data_get($legacyLanding, 'lifecycle.canonical_path'));
    }
}
