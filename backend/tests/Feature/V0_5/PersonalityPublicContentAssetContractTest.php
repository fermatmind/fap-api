<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PersonalityPublicContentAssetContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_dry_run_validates_big_five_seed_without_writing(): void
    {
        $this->artisan('personality-public-assets:import')
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('assets_found=8')
            ->expectsOutputToContain('valid_count=8')
            ->expectsOutputToContain('errors_count=0')
            ->expectsOutputToContain('indexable_count=0')
            ->expectsOutputToContain('sitemap_eligible_count=0')
            ->expectsOutputToContain('llms_eligible_count=0')
            ->assertExitCode(0);

        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_import_keeps_seed_assets_draft_noindex_and_hidden_from_public_api(): void
    {
        $this->artisan('personality-public-assets:import', [
            '--write' => true,
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('will_create=8')
            ->expectsOutputToContain('indexable_count=0')
            ->expectsOutputToContain('sitemap_eligible_count=0')
            ->expectsOutputToContain('llms_eligible_count=0')
            ->assertExitCode(0);

        $this->assertSame(8, PersonalityPublicContentAsset::query()->count());

        $asset = PersonalityPublicContentAsset::query()
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_HUB)
            ->firstOrFail();

        $this->assertSame(PersonalityPublicContentAsset::LAUNCH_DRAFT, $asset->launch_state);
        $this->assertFalse((bool) $asset->index_eligible);
        $this->assertFalse((bool) $asset->sitemap_eligible);
        $this->assertFalse((bool) $asset->llms_eligible);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');

        $sitemapLocs = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->implode("\n");

        $this->assertStringNotContainsString('/personality/big-five', $sitemapLocs);
    }

    public function test_published_indexable_asset_can_be_read_without_sitemap_or_llms_flags(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'index_eligible' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => now()->subMinute(),
        ]));

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.framework', 'big_five')
            ->assertJsonPath('items.0.entity_type', 'hub')
            ->assertJsonPath('items.0.index_eligible', true)
            ->assertJsonPath('items.0.sitemap_eligible', false)
            ->assertJsonPath('items.0.llms_eligible', false);

        $this->getJson('/api/v0.5/personality-content-assets/big_five/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.slug', 'big-five')
            ->assertJsonPath('personality_public_content_asset_v1.launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED);

        $sitemapLocs = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->implode("\n");

        $this->assertStringNotContainsString('/personality/big-five', $sitemapLocs);
    }

    public function test_contract_rejects_disallowed_page_families_and_private_result_modules(): void
    {
        $contract = app(PersonalityPublicContentAssetContract::class);

        try {
            $contract->validateAsset($this->contractPayload([
                'entity_type' => 'polarity',
                'entity_key' => 'ocean-32-intense-profile',
                'slug' => 'big-five/ocean-32-intense-profile',
            ]));
            $this->fail('Expected 32 OCEAN validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entity_key', $exception->errors());
        }

        try {
            $contract->validateAsset($this->contractPayload([
                'content_sections' => [
                    [
                        'key' => 'overview',
                        'source' => 'private_result_module',
                        'body_md' => 'Do not reuse private result modules.',
                    ],
                ],
            ]));
            $this->fail('Expected private result module validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content_sections', $exception->errors());
        }

        try {
            $contract->validateAsset($this->contractPayload([
                'framework' => 'enneagram',
                'entity_type' => 'instinctual_subtype',
                'entity_key' => 'tritype-548',
                'slug' => 'enneagram/tritype-548',
            ]));
            $this->fail('Expected Tritype validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entity_key', $exception->errors());
        }
    }

    public function test_contract_requires_published_state_for_indexable_assets(): void
    {
        $this->expectException(ValidationException::class);

        app(PersonalityPublicContentAssetContract::class)->validateAsset($this->contractPayload([
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'index_eligible' => true,
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function assetAttributes(array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Big Five Personality',
            'summary' => 'Published API fixture.',
            'content_sections_json' => [
                [
                    'key' => 'overview',
                    'body_md' => 'Public CMS-authored body.',
                ],
            ],
            'seo_json' => [
                'title' => 'Big Five Personality',
                'description' => 'Published fixture.',
            ],
            'canonical_json' => [
                'path' => '/en/personality/big-five',
            ],
            'hreflang_json' => [],
            'faq_json' => [],
            'media_json' => [],
            'schema_json' => [
                '@type' => 'WebPage',
            ],
            'method_boundary_json' => [
                'summary' => 'Dimensional model boundary.',
            ],
            'evidence_notes_json' => [
                [
                    'source_type' => 'fixture',
                    'note' => 'Test fixture.',
                ],
            ],
            'is_public' => true,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'draft',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function contractPayload(array $overrides = []): array
    {
        return array_merge([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Big Five Personality',
            'summary' => 'Contract fixture.',
            'content_sections' => [
                [
                    'key' => 'overview',
                    'body_md' => 'CMS-authored body.',
                ],
            ],
            'seo' => [
                'title' => 'Big Five Personality',
                'description' => 'Contract fixture.',
            ],
            'canonical' => [
                'path' => '/en/personality/big-five',
            ],
            'hreflang' => [],
            'faq' => [],
            'media' => [],
            'schema' => [
                '@type' => 'WebPage',
            ],
            'method_boundary' => [
                'summary' => 'Big Five is dimensional.',
            ],
            'evidence_notes' => [
                [
                    'source_type' => 'fixture',
                    'note' => 'Contract fixture.',
                ],
            ],
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'draft',
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
        ], $overrides);
    }
}
