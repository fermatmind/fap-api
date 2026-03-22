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
}
