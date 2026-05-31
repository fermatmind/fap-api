<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\Content\Eq60ContentCompileService;
use App\Services\Content\Eq60PackLoader;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Eq60EnglishSeoGeoAuthorityPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_eq_seo_geo_authority_is_backend_owned_and_public_api_visible(): void
    {
        $this->prepareEqContent();
        (new ScaleRegistrySeeder)->run();

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        $assets = $loader->readCompiledJson('report_assets.compiled.json', 'v1');
        $this->assertIsArray($assets);

        $publicPage = (array) data_get($assets, 'assets.seo_geo_authority.public_page', []);
        $this->assertSame('/en/tests/eq-test-emotional-intelligence-assessment', (string) ($publicPage['canonical_path'] ?? ''));
        $this->assertSame('backend_content_pack', (string) ($publicPage['authority_source'] ?? ''));
        $this->assertTrue((bool) ($publicPage['sitemap_eligible'] ?? false));
        $this->assertTrue((bool) ($publicPage['llms_eligible'] ?? false));
        $this->assertTrue((bool) ($publicPage['llms_full_eligible'] ?? false));

        $seoGeoAssets = (array) data_get($assets, 'assets.seo_geo_authority.assets', []);
        $localized = is_array($seoGeoAssets['eq.seo_geo_authority.en_landing.default'] ?? null)
            ? (array) $seoGeoAssets['eq.seo_geo_authority.en_landing.default']
            : [];
        $asset = is_array($localized['en'] ?? null) ? (array) $localized['en'] : [];
        $this->assertStringContainsString('Free EQ Test', (string) ($asset['meta_title'] ?? ''));
        $this->assertStringContainsString('self-report', (string) ($asset['meta_description'] ?? ''));
        $this->assertSame('self_report_trait_mixed_ei', (string) data_get($asset, 'structured_data.assessment_mode'));
        $this->assertSame('emotional_and_relational_pattern_report', (string) data_get($asset, 'structured_data.result_scope'));
        $this->assertCount(3, (array) ($asset['faq'] ?? []));
        $this->assertCount(3, (array) ($asset['content_modules'] ?? []));

        $public = $this->getJson('/api/v0.3/scales/EQ_60/questions?locale=en&region=GLOBAL');
        $public->assertOk()
            ->assertJsonPath('meta.seo_geo_authority.schema', 'eq.seo_geo_authority.public.v1')
            ->assertJsonPath('meta.seo_geo_authority.authority_source', 'backend_content_pack')
            ->assertJsonPath('meta.seo_geo_authority.canonical_path', '/en/tests/eq-test-emotional-intelligence-assessment')
            ->assertJsonPath('meta.seo_geo_authority.structured_data.assessment_mode', 'self_report_trait_mixed_ei')
            ->assertJsonPath('meta.seo_geo_authority.structured_data.is_free', true);

        $json = json_encode($public->json('meta.seo_geo_authority'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        foreach ([
            'predicts job performance',
            'hiring suitable',
            'clinical assessment',
            'certified emotional intelligence',
            'MSCEIT-like',
            'true emotional ability',
        ] as $blockedClaim) {
            $this->assertStringNotContainsString($blockedClaim, $json);
        }

        $this->assertStringContainsString('not for clinical diagnosis', $json);
        $this->assertStringContainsString('Not for hiring selection', $json);
    }

    private function prepareEqContent(): void
    {
        /** @var Eq60ContentCompileService $compiler */
        $compiler = app(Eq60ContentCompileService::class);
        $compiled = $compiler->compile('v1');
        $this->assertTrue(
            (bool) ($compiled['ok'] ?? false),
            json_encode($compiled['errors'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );
    }
}
