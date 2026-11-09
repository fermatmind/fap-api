<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicGatewaySurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_gateway_returns_landing_surface_with_highlighted_discoverability_items(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');

        $response = $this->getJson('/api/v0.3/public-gateways/home?locale=en');

        $response->assertOk()
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'home_gateway')
            ->assertJsonPath('landing_surface_v1.entry_type', 'public_home')
            ->assertJsonPath('landing_surface_v1.discoverability_items.0.key', 'mbti-personality-test-16-personality-types');

        $keys = $response->json('landing_surface_v1.discoverability_keys');
        $this->assertNotContains('clinical-depression-anxiety-assessment-professional-edition', $keys);
        $this->assertNotContains('depression-screening-test-standard-edition', $keys);
    }

    public function test_tests_gateway_returns_indexable_test_directory_surface(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');

        $response = $this->getJson('/api/v0.3/public-gateways/tests?locale=zh');

        $response->assertOk()
            ->assertJsonPath('landing_surface_v1.entry_surface', 'tests_index')
            ->assertJsonPath('landing_surface_v1.indexability_state', 'indexable')
            ->assertJsonCount(6, 'landing_surface_v1.discoverability_items');

        $keys = $response->json('landing_surface_v1.discoverability_keys');
        $this->assertNotContains('clinical-depression-anxiety-assessment-professional-edition', $keys);
        $this->assertNotContains('depression-screening-test-standard-edition', $keys);
    }

    public function test_help_gateway_returns_landing_surface_and_detail_answer_surface(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        $this->getJson('/api/v0.3/public-gateways/help?locale=en')
            ->assertOk()
            ->assertJsonPath('landing_surface_v1.entry_surface', 'help_hub')
            ->assertJsonPath('landing_surface_v1.discoverability_items.0.key', 'faq');

        $this->getJson('/api/v0.3/public-gateways/help/faq?locale=en')
            ->assertOk()
            ->assertJsonPath('landing_surface_v1.entry_surface', 'help_detail')
            ->assertJsonPath('answer_surface_v1.answer_contract_version', 'answer.surface.v1')
            ->assertJsonPath('answer_surface_v1.surface_type', 'help_detail')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.key', 'faq_report');
    }

    public function test_taiwan_help_gateway_keeps_original_targets_and_marks_exact_locale(): void
    {
        $url = '/api/v0.3/public-gateways/help';
        $zh = $this->getJson($url.'?locale=zh-CN')->assertOk()->assertJsonMissingPath('locale')->json('landing_surface_v1');
        $en = $this->getJson($url.'?locale=en', ['X-FAP-Locale' => 'zh-TW'])
            ->assertOk()->assertJsonMissingPath('locale')->json('landing_surface_v1');
        $response = $this->getJson($url.'?locale=zh-TW')->assertOk()
            ->assertJsonPath('locale', 'zh-TW')
            ->assertJsonPath('landing_surface_v1.summary_blocks.0.title', '支援與信任中心')
            ->assertJsonPath('landing_surface_v1.indexability_state', 'noindex')
            ->assertJsonMissingPath('answer_surface_v1');
        $taiwan = $response->json('landing_surface_v1');

        $this->assertSame('Support & Trust Center', $en['summary_blocks'][0]['title']);
        $this->assertSame('indexable', $en['indexability_state']);
        $this->assertSame('indexable', $zh['indexability_state']);
        $this->assertNotSame($zh['landing_fingerprint'], $taiwan['landing_fingerprint']);
        $this->assertSame($zh['discoverability_keys'], $taiwan['discoverability_keys']);
        foreach (['discoverability_items', 'cta_bundle'] as $section) {
            $this->assertSame(array_column($zh[$section], 'key'), array_column($taiwan[$section], 'key'));
            $this->assertSame(array_column($zh[$section], 'href'), array_column($taiwan[$section], 'href'));
        }
        $this->getJson($url, ['X-FAP-Locale' => 'zh_TW'])->assertOk()->assertJsonPath('locale', 'zh-TW');
        $this->getJson($url.'/faq?locale=zh-TW')->assertOk()
            ->assertJsonMissingPath('locale')
            ->assertJsonPath('landing_surface_v1.summary_blocks.0.title', '常见问题');
    }
}
