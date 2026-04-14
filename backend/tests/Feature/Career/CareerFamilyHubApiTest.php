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

        $response = $this->getJson('/api/v0.5/career/family/computer-and-information-technology');

        $response
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_family_hub')
            ->assertJsonPath('bundle_version', 'career.protocol.family_hub.v1')
            ->assertJsonPath('family.family_uuid', $family->id)
            ->assertJsonPath('family.canonical_slug', 'computer-and-information-technology')
            ->assertJsonPath('seo_contract.canonical_path', '/career/family/computer-and-information-technology')
            ->assertJsonPath('seo_contract.canonical_title', $family->title_en)
            ->assertJsonPath('seo_contract.index_state', 'index')
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonPath('seo_contract.reason_codes', ['visible_children_present'])
            ->assertJsonPath('seo_contract.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_contract.surface_type', 'career_family_hub_bundle')
            ->assertJsonPath('seo_contract.robots_policy', 'index,follow')
            ->assertJsonPath('seo_contract.structured_data_keys', ['BreadcrumbList', 'CollectionPage', 'ItemList'])
            ->assertJsonPath('structured_data.collection_page.@type', 'CollectionPage')
            ->assertJsonPath('structured_data.item_list.@type', 'ItemList')
            ->assertJsonPath('structured_data.breadcrumb_list.@type', 'BreadcrumbList')
            ->assertJsonPath('structured_data.collection_page.numberOfItems', 1)
            ->assertJsonPath('structured_data.item_list.numberOfItems', 1)
            ->assertJsonPath('structured_data.item_list.itemListElement.0.position', 1)
            ->assertJsonPath('structured_data.item_list.itemListElement.0.name', 'Data scientists')
            ->assertJsonPath('structured_data.item_list.itemListElement.0.url', '/career/jobs/data-scientists')
            ->assertJsonPath('counts.visible_children_count', 1)
            ->assertJsonPath('counts.publish_ready_count', 1)
            ->assertJsonPath('counts.blocked_override_eligible_count', 0)
            ->assertJsonPath('counts.blocked_not_safely_remediable_count', 0)
            ->assertJsonPath('counts.blocked_total', 0)
            ->assertJsonPath('visible_children.0.canonical_slug', 'data-scientists')
            ->assertJsonPath('visible_children.0.seo_contract.index_eligible', true)
            ->assertJsonPath('visible_children.0.trust_summary.reviewer_status', 'approved')
            ->assertJsonMissingPath('structured_data.route_kind')
            ->assertJsonMissingPath('structured_data.canonical_path')
            ->assertJsonMissingPath('structured_data.canonical_title')
            ->assertJsonMissingPath('structured_data.breadcrumb_nodes')
            ->assertJsonMissingPath('structured_data.dataset')
            ->assertJsonMissingPath('structured_data.article')
            ->assertJsonMissingPath('structured_data.defined_term_set')
            ->assertJsonMissingPath('seo_contract.canonical_target')
            ->assertJsonMissingPath('seo_contract.trust_manifest')
            ->assertJsonMissingPath('seo_contract.provenance_meta')
            ->assertJsonMissingPath('seo_contract.dataset')
            ->assertJsonMissingPath('seo_contract.article')
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
                'seo_contract' => [
                    'canonical_path',
                    'canonical_title',
                    'index_state',
                    'index_eligible',
                    'reason_codes',
                    'metadata_contract_version',
                    'surface_type',
                    'robots_policy',
                    'metadata_fingerprint',
                    'structured_data_keys',
                ],
                'structured_data' => [
                    'collection_page' => [
                        '@context',
                        '@type',
                        'name',
                        'url',
                        'mainEntityOfPage',
                        'numberOfItems',
                    ],
                    'item_list' => [
                        '@context',
                        '@type',
                        'numberOfItems',
                        'itemListElement',
                    ],
                    'breadcrumb_list' => [
                        '@context',
                        '@type',
                        'itemListElement',
                    ],
                ],
            ]);

        $this->assertIsString($response->json('seo_contract.metadata_fingerprint'));
        $this->assertNotSame('', trim((string) $response->json('seo_contract.metadata_fingerprint')));
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
            ->assertJsonPath('seo_contract.canonical_path', '/career/family/empty-family-api')
            ->assertJsonPath('seo_contract.canonical_title', 'Empty Family')
            ->assertJsonPath('seo_contract.index_state', 'noindex')
            ->assertJsonPath('seo_contract.index_eligible', false)
            ->assertJsonPath('seo_contract.reason_codes', ['excluded_zero_visible_children'])
            ->assertJsonPath('seo_contract.robots_policy', 'noindex,follow')
            ->assertJsonPath('seo_contract.structured_data_keys', ['BreadcrumbList', 'CollectionPage', 'ItemList'])
            ->assertJsonPath('structured_data.collection_page.@type', 'CollectionPage')
            ->assertJsonPath('structured_data.item_list.@type', 'ItemList')
            ->assertJsonPath('structured_data.breadcrumb_list.@type', 'BreadcrumbList')
            ->assertJsonPath('structured_data.collection_page.numberOfItems', 0)
            ->assertJsonPath('structured_data.item_list.numberOfItems', 0)
            ->assertJsonPath('structured_data.item_list.itemListElement', [])
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
