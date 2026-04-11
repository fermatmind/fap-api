<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFamilyHubApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_minimal_family_hub_bundle_with_visible_children_and_counts(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $family = OccupationFamily::query()->where('canonical_slug', 'computer-and-information-technology')->firstOrFail();

        $this->getJson('/api/v0.5/career/family/computer-and-information-technology')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_family_hub')
            ->assertJsonPath('bundle_version', 'career.protocol.family_hub.v1')
            ->assertJsonPath('family.family_uuid', $family->id)
            ->assertJsonPath('family.canonical_slug', 'computer-and-information-technology')
            ->assertJsonPath('counts.visible_children_count', 1)
            ->assertJsonPath('counts.publish_ready_count', 1)
            ->assertJsonPath('counts.blocked_override_eligible_count', 0)
            ->assertJsonPath('counts.blocked_not_safely_remediable_count', 0)
            ->assertJsonPath('counts.blocked_total', 0)
            ->assertJsonPath('visible_children.0.canonical_slug', 'data-scientists')
            ->assertJsonPath('visible_children.0.seo_contract.index_eligible', true)
            ->assertJsonPath('visible_children.0.trust_summary.reviewer_status', 'approved')
            ->assertJsonMissingPath('visible_children.1')
            ->assertJsonStructure([
                'bundle_kind',
                'bundle_version',
                'family' => [
                    'family_uuid',
                    'canonical_slug',
                    'title_en',
                    'title_zh',
                ],
                'visible_children' => [[
                    'occupation_uuid',
                    'canonical_slug',
                    'canonical_title_en',
                    'canonical_title_zh',
                    'seo_contract' => ['canonical_path', 'index_state', 'index_eligible', 'reason_codes'],
                    'trust_summary' => ['reviewer_status'],
                ]],
                'counts' => [
                    'visible_children_count',
                    'publish_ready_count',
                    'blocked_override_eligible_count',
                    'blocked_not_safely_remediable_count',
                    'blocked_total',
                ],
            ]);
    }

    public function test_it_returns_not_found_for_unknown_family_slug(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $this->getJson('/api/v0.5/career/family/unknown-family')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_it_returns_an_empty_visible_children_list_for_existing_families_without_public_safe_children(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'empty-family-api',
            'title_en' => 'Empty Family',
            'title_zh' => '空家族',
        ]);

        $this->getJson('/api/v0.5/career/family/empty-family-api')
            ->assertOk()
            ->assertJsonPath('family.family_uuid', $family->id)
            ->assertJsonPath('counts.visible_children_count', 0)
            ->assertJsonPath('counts.publish_ready_count', 0)
            ->assertJsonPath('counts.blocked_override_eligible_count', 0)
            ->assertJsonPath('counts.blocked_not_safely_remediable_count', 0)
            ->assertJsonPath('counts.blocked_total', 0)
            ->assertJsonPath('visible_children', []);
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
