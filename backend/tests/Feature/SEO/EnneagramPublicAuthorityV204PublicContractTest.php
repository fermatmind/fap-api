<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnneagramPublicAuthorityV204PublicContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fap.testing_personality_legacy_public_db_fixture', true);
    }

    public function test_ten_entity_locale_fixtures_expose_v2_visible_evidence_and_preserve_v1(): void
    {
        $fixtures = $this->contractFixtures();

        $this->assertCount(10, $fixtures);
        $this->assertSame(
            ['center', 'core_type', 'hub', 'instinctual_subtype', 'wing'],
            collect($fixtures)->pluck('entity_type')->unique()->sort()->values()->all()
        );
        $this->assertSame(['en', 'zh-CN'], collect($fixtures)->pluck('locale')->unique()->sort()->values()->all());

        foreach ($fixtures as $fixture) {
            $sourceId = 'authority-v2-'.$fixture['entity_type'].'-'.strtolower(str_replace('-', '', $fixture['locale']));
            $claimId = 'claim.'.$fixture['entity_type'].'.visible_boundary';

            PersonalityPublicContentAsset::query()->create($this->assetAttributes($fixture, [
                'authority_json' => [
                    'sources' => [[
                        'id' => $sourceId,
                        'title' => 'Authority V2 visible evidence fixture',
                        'author_or_organization' => 'FermatMind',
                        'year' => 2026,
                        'source_type' => 'official_documentation',
                        'public_url' => 'https://fermatmind.com'.$fixture['canonical_path'],
                        'claim_ids' => [$claimId],
                        'limitation' => 'Contract fixture; not a deterministic personality judgment.',
                    ]],
                    'claim_mapping' => [[
                        'claim_id' => $claimId,
                        'source_ids' => [$sourceId],
                        'limitation' => 'Visible evidence supports page framing only.',
                    ]],
                    'limitations' => ['This public profile is structured reference, not diagnosis or prediction.'],
                    'author' => null,
                    'reviewer' => null,
                    'visible_evidence_eligible' => true,
                    'schema_eligible' => true,
                ],
            ]));

            $response = $this->getJson($fixture['endpoint']);
            $this->assertSame(200, $response->status(), $fixture['fixture_id'].': '.$response->getContent());

            $response
                ->assertJsonPath('personality_public_content_asset_v1.contract_version', PersonalityPublicContentAsset::CONTRACT_VERSION_V1)
                ->assertJsonPath('personality_public_content_asset_v1.framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
                ->assertJsonPath('personality_public_content_asset_v1.entity_type', $fixture['entity_type'])
                ->assertJsonPath('personality_public_content_asset_v1.locale', $fixture['locale'])
                ->assertJsonPath('personality_public_content_asset_v2.contract_version', PersonalityPublicContentAsset::CONTRACT_VERSION_V2)
                ->assertJsonPath('personality_public_content_asset_v2.compatible_v1_contract_version', PersonalityPublicContentAsset::CONTRACT_VERSION_V1)
                ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.eligible', true)
                ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.sources.0.id', $sourceId)
                ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.claim_mapping.0.claim_id', $claimId)
                ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.author', null)
                ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.reviewer', null)
                ->assertJsonPath('personality_public_content_asset_v2.schema_eligible', true);

            $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('working_revision', $encoded);
            $this->assertStringNotContainsString('published_revision_pointer', $encoded);
            $this->assertStringNotContainsString('package_sha', $encoded);
        }
    }

    public function test_schema_eligibility_requires_visible_evidence_and_runtime_eligibility(): void
    {
        $fixture = $this->contractFixtures()[0];

        PersonalityPublicContentAsset::query()->create($this->assetAttributes($fixture, [
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'index_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'authority_json' => [
                'sources' => [[
                    'id' => 'runtime-gate-source',
                    'title' => 'Runtime gate source',
                    'author_or_organization' => 'FermatMind',
                    'year' => 2026,
                    'source_type' => 'official_documentation',
                    'claim_ids' => ['claim.runtime_gate'],
                ]],
                'claim_mapping' => [[
                    'claim_id' => 'claim.runtime_gate',
                    'source_ids' => ['runtime-gate-source'],
                ]],
                'reviewer' => null,
                'visible_evidence_eligible' => true,
                'schema_eligible' => true,
            ],
        ]));

        $response = $this->getJson($fixture['endpoint']);

        $this->assertSame(200, $response->status());
        $this->assertTrue((bool) data_get($response->getData(true), 'personality_public_content_asset_v2.visible_evidence.eligible'));
        $this->assertNull(data_get($response->getData(true), 'personality_public_content_asset_v2.editorial_authority.reviewer'));
        $this->assertFalse((bool) data_get($response->getData(true), 'personality_public_content_asset_v2.schema_eligible'));
    }

    /** @return list<array<string,string>> */
    private function contractFixtures(): array
    {
        $path = base_path('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-public-contract-04/contract-fixtures.json');
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_values($payload['contract_fixtures']);
    }

    /**
     * @param  array<string,string>  $fixture
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function assetAttributes(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => $fixture['entity_type'],
            'entity_key' => $fixture['entity_key'],
            'slug' => $fixture['slug'],
            'locale' => $fixture['locale'],
            'title' => 'Enneagram Authority V2 contract fixture',
            'summary' => 'Visible-evidence projection fixture without public editorial body.',
            'content_sections_json' => [],
            'seo_json' => [],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_json' => ['path' => $fixture['canonical_path']],
            'hreflang_json' => [],
            'faq_json' => [],
            'media_json' => [],
            'schema_json' => ['@type' => 'WebPage'],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'authority_json' => [],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'pending_manual_review',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'published_at' => now()->subDay(),
            'last_reviewed_at' => null,
        ], $overrides);
    }
}
