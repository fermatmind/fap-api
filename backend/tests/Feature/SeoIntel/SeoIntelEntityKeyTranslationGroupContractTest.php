<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelEntityKeyTranslationGroupContractTest extends TestCase
{
    #[Test]
    public function entity_key_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/entity-key-translation-group-contract.md'));
        $this->assertSame('entity-key-translation-group-contract.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('SEO-OBS-GOV-04', $this->artifact()['task'] ?? null);
        $this->assertSame('translation_group_uuid', $this->artifact()['required_future_key'] ?? null);
    }

    #[Test]
    public function entity_key_preference_and_formats_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('translation_group_id', $artifact['allowed_transitional_key'] ?? null);
        $this->assertSame([
            'translation_group_uuid',
            'translation_group_id_where_already_supported',
            'legacy_unpaired',
        ], $artifact['entity_key_preference_order'] ?? []);

        $this->assertSame('translation_group_uuid:<uuid>', $artifact['entity_key_formats']['preferred'] ?? null);
        $this->assertSame('translation_group_id:<source_table>:<translation_group_id>', $artifact['entity_key_formats']['transitional'] ?? null);
        $this->assertSame('legacy_unpaired:<surface>:<locale>:<stable_slug_or_id>', $artifact['entity_key_formats']['legacy_unpaired'] ?? null);
    }

    #[Test]
    public function locale_pair_rules_require_stable_entity_key(): void
    {
        foreach ([
            'locale_compare_groups_by_entity_key',
            'en_zh_pairs_require_same_translation_group_uuid',
            'translation_group_id_is_transitional_paired_only',
            'missing_locale_peer_is_observation_not_content_task',
            'legacy_unpaired_requires_followup_review',
        ] as $rule) {
            $this->assertContains($rule, $this->artifact()['locale_pair_rules'] ?? []);
        }
    }

    #[Test]
    public function all_required_surfaces_are_covered(): void
    {
        foreach ([
            'research_reports',
            'articles',
            'topics',
            'personality_pages',
            'career_guides',
            'career_jobs',
            'test_landing_detail_pages',
            'content_support_pages',
        ] as $surface) {
            $this->assertContains($surface, $this->artifact()['surface_coverage'] ?? []);
            $this->assertArrayHasKey($surface, $this->artifact()['surface_policy'] ?? []);
        }
    }

    #[Test]
    public function transitional_and_migration_helper_rules_are_explicit(): void
    {
        $policy = $this->artifact()['surface_policy'] ?? [];

        $this->assertSame('migration_helper_only', $policy['research_reports']['current_slug_pairing'] ?? null);
        $this->assertSame('transitional', $policy['articles']['current_translation_group_id'] ?? null);
        $this->assertSame('migration_helper_only', $policy['topics']['slug_similarity'] ?? null);
        $this->assertFalse((bool) ($policy['personality_pages']['frontend_fallback_authority'] ?? true));
        $this->assertFalse((bool) ($policy['career_jobs']['crawler_derived_pairing'] ?? true));

        foreach ([
            'schema_after_human_approval',
            'dry_run_mapping_by_surface',
            'use_existing_translation_group_id_where_present',
            'mark_unpaired_legacy_content_without_mutation',
            'slug_title_similarity_candidate_suggestion_only',
            'human_review_before_content_or_key_mutation',
        ] as $step) {
            $this->assertContains($step, $this->artifact()['backfill_plan'] ?? []);
        }
    }

    #[Test]
    public function forbidden_authority_and_safety_flags_block_drift(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'automatic_content_mutation',
            'frontend_fallback_pairing',
            'crawler_derived_pairing',
            'title_slug_similarity_as_final_authority',
            'search_engine_response_pairing',
            'local_copy_authority',
            'static_sitemap_llms_authority',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_authority'] ?? []);
        }

        foreach ([
            'cms_content_mutation',
            'backfill_execution',
            'migration_added',
            'fap_web_modified',
            'frontend_fallback_authority',
            'title_slug_similarity_final_authority',
            'crawler_derived_pairing',
            'production_env_changed',
            'deploy',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact['safety_flags'][$flag] ?? true), $flag.' must be false');
        }
    }

    #[Test]
    public function docs_lock_no_mutation_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/entity-key-translation-group-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/entity-key-translation-group-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'translation_group_uuid',
            'translation_group_id is allowed only where it already exists today',
            'legacy_unpaired',
            'slug/title similarity may be used only as a migration helper, not authority',
            'no automatic content mutation',
            'no frontend fallback pairing',
            'no crawler-derived pairing',
            'no title/slug similarity as final authority',
            'next task: `seo-obs-gov-05`',
            '"next_task": "seo-obs-gov-05"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/entity-key-translation-group-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
