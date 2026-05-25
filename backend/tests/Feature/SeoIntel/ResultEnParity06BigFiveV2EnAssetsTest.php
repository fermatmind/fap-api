<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class ResultEnParity06BigFiveV2EnAssetsTest extends TestCase
{
    public function test_bigfive_v2_generated_inventory_accounts_for_all_required_english_asset_groups(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('result-en-parity-06-bigfive-v2-en-assets.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('RESULT-EN-PARITY-06', $artifact['pr_id'] ?? null);
        $this->assertSame('BIG5_OCEAN', $artifact['family'] ?? null);
        $this->assertSame(
            'bigfive_result_page_v2_en_parity_draft_ready_for_human_review',
            $artifact['decision'] ?? null
        );

        $this->assertTrue((bool) ($artifact['authority']['backend_content_pack_is_authority'] ?? false));
        $this->assertFalse((bool) ($artifact['authority']['frontend_fallback_is_authority'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['scoring_change'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['selector_change'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['production_mutation'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['cms_mutation'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['deploy'] ?? true));
        $this->assertFalse((bool) ($artifact['authority']['search_channel_action'] ?? true));

        $assetKeys = array_column($artifact['asset_groups'] ?? [], 'key');

        foreach ($this->requiredAssetKeys() as $key) {
            $this->assertContains($key, $assetKeys);
        }

        $this->assertSame([], $artifact['remaining_missing_en_asset_keys'] ?? null);
        $this->assertEqualsCanonicalizing($this->requiredAssetKeys(), $artifact['remaining_unreviewed_en_asset_keys'] ?? []);
    }

    public function test_bigfive_v2_english_draft_catalog_is_non_runtime_and_fail_closed(): void
    {
        $draft = $this->draftCatalog();

        $this->assertSame('fap.big5.result_page_v2.en_parity_draft.v1', $draft['schema'] ?? null);
        $this->assertSame('BIG5_OCEAN', $draft['scale_code'] ?? null);
        $this->assertSame('en', $draft['locale'] ?? null);
        $this->assertSame('draft_review_only', $draft['runtime_use'] ?? null);
        $this->assertFalse((bool) ($draft['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($draft['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($draft['production_use_allowed'] ?? true));
        $this->assertTrue((bool) ($draft['human_review_required'] ?? false));

        $selectorPolicy = (string) data_get($draft, 'draft_asset_groups.selector_ready_assets.selector_policy');

        $this->assertStringContainsString('must not select zh-CN interpretation copy', $selectorPolicy);
        $this->assertStringContainsString('fail closed', $selectorPolicy);

        foreach ($draft['draft_asset_groups'] ?? [] as $group => $payload) {
            $this->assertSame('draft_skeleton', $payload['status'] ?? null, (string) $group);
            $this->assertFalse((bool) ($payload['ready_for_runtime'] ?? true), (string) $group);
            $this->assertNotEmpty($payload['keys'] ?? [], (string) $group);
            $this->assertNotEmpty($payload['deferred'] ?? [], (string) $group);
        }
    }

    public function test_bigfive_v2_draft_preserves_trait_vector_workstyle_semantics(): void
    {
        $artifact = $this->artifact();
        $draft = $this->draftCatalog();

        $this->assertSame(
            'trait_vector_workstyle_behavioral_explanation_only',
            $artifact['claim_boundary']['semantics'] ?? null
        );

        $serialized = strtolower(json_encode([$artifact, $draft], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        foreach ([
            'precise career matching',
            'hiring fit',
            'career success prediction',
            'salary prediction',
            'turnover prediction',
            'clinical diagnosis',
            'treatment advice',
        ] as $forbidden) {
            $this->assertContains($forbidden, $artifact['claim_boundary']['forbidden'] ?? []);
        }

        foreach ([
            'best career for you',
            'job fit guarantee',
            'diagnose you',
            'treatment plan for you',
        ] as $unsupportedClaim) {
            $this->assertStringNotContainsString($unsupportedClaim, $serialized);
        }
    }

    public function test_bigfive_v2_draft_seed_coverage_includes_traits_facets_sections_and_scenarios(): void
    {
        $artifact = $this->artifact();
        $draft = $this->draftCatalog();

        $this->assertSame(['O', 'C', 'E', 'A', 'N'], $artifact['draft_seed_coverage']['trait_labels'] ?? null);
        $this->assertSame(30, $artifact['draft_seed_coverage']['facet_labels'] ?? null);
        $this->assertSame([
            'hero_summary',
            'domains_overview',
            'domain_deep_dive',
            'facet_details',
            'core_portrait',
            'norms_comparison',
            'action_plan',
            'methodology_and_access',
        ], $artifact['draft_seed_coverage']['section_headlines'] ?? null);
        $this->assertSame([
            'workplace',
            'relationships',
            'stress_recovery',
            'personal_growth',
        ], $artifact['draft_seed_coverage']['scenario_labels'] ?? null);

        $this->assertCount(5, data_get($draft, 'draft_asset_groups.canonical_profiles.trait_label_seed'));
        $this->assertCount(30, data_get($draft, 'draft_asset_groups.facet_assets.facet_label_seed'));
        $this->assertCount(8, data_get($draft, 'draft_asset_groups.core_body.section_headlines'));
        $this->assertCount(4, data_get($draft, 'draft_asset_groups.scenario_action_assets.scenario_labels'));
    }

    public function test_validation_contract_records_no_scoring_or_selector_change(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue((bool) ($artifact['validation_contract']['generated_json_parses'] ?? false));
        $this->assertTrue((bool) ($artifact['validation_contract']['draft_catalog_parses'] ?? false));
        $this->assertTrue((bool) ($artifact['validation_contract']['all_required_v2_groups_accounted_for'] ?? false));
        $this->assertTrue((bool) ($artifact['validation_contract']['no_zh_fallback_policy_recorded'] ?? false));
        $this->assertTrue((bool) ($artifact['validation_contract']['selector_source_authority_unchanged'] ?? false));
        $this->assertTrue((bool) ($artifact['validation_contract']['trait_vector_semantics_preserved'] ?? false));
        $this->assertTrue((bool) ($artifact['validation_contract']['no_scoring_change'] ?? false));
    }

    /**
     * @return list<string>
     */
    private function requiredAssetKeys(): array
    {
        return [
            'big5.result_page_v2.route_matrix.en',
            'big5.result_page_v2.coupling_assets.en',
            'big5.result_page_v2.scenario_action_assets.en',
            'big5.result_page_v2.facet_assets.en',
            'big5.result_page_v2.canonical_profiles.en',
            'big5.result_page_v2.core_body.en',
            'big5.result_page_v2.selector_ready_assets.en',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/result-en-parity-06-bigfive-v2-en-assets.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftCatalog(): array
    {
        $path = base_path('content_packs/BIG5_OCEAN/v2/drafts/en_parity/result_page_v2_en_asset_catalog_draft.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
