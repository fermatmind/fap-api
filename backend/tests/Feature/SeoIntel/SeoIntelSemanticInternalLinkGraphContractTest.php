<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSemanticInternalLinkGraphContractTest extends TestCase
{
    #[Test]
    public function contract_file_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/semantic-internal-link-graph-contract.md'));

        $artifact = $this->artifact();

        $this->assertSame('semantic-internal-link-graph-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('INTERNAL-LINK-01A', $artifact['task'] ?? null);
        $this->assertSame('semantic_internal_link_graph_contract', $artifact['purpose'] ?? null);
        $this->assertSame('contract_only_no_link_mutation', $artifact['mode'] ?? null);
    }

    #[Test]
    public function required_link_families_and_fields_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'article_to_test',
            'article_to_topic',
            'article_to_research',
            'article_to_related_article',
            'topic_to_test',
            'topic_to_article',
            'topic_to_personality_entity',
            'research_to_topic_test_article',
            'test_to_article_topic_research',
            'career_guide_to_test_topic_article',
            'career_job_to_career_guide_test_topic',
            'personality_page_to_test_topic_article',
        ] as $family) {
            $this->assertContains($family, $artifact['required_link_families'] ?? []);
        }

        foreach ([
            'source_entity_type',
            'source_entity_key',
            'target_entity_type',
            'target_entity_key',
            'link_role',
            'locale',
            'authority_source',
            'visibility_state',
            'safety_state',
            'created_by_system',
            'review_state',
        ] as $field) {
            $this->assertContains($field, $artifact['future_graph_fields'] ?? []);
        }
    }

    #[Test]
    public function entity_key_and_authority_rules_block_fallback_truth(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('translation_group_uuid', data_get($artifact, 'entity_key_rule.preferred'));
        $this->assertSame('translation_group_id_where_already_supported', data_get($artifact, 'entity_key_rule.transitional'));
        $this->assertSame('legacy_unpaired', data_get($artifact, 'entity_key_rule.missing'));
        $this->assertSame('title_slug_similarity', data_get($artifact, 'entity_key_rule.migration_helper_only'));

        foreach ([
            'frontend_fallback',
            'static_sitemap',
            'static_llms',
            'crawler_logs',
            'search_engine_responses',
            'local_copies',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_authority_sources'] ?? []);
        }
    }

    #[Test]
    public function forbidden_behaviors_and_safety_flags_block_link_mutation(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'runtime_link_write',
            'cms_mutation',
            'fap_web_modification',
            'migration_creation',
            'crawler_derived_link_authority',
            'sitemap_derived_graph_truth',
            'llms_derived_graph_truth',
            'frontend_fallback_as_graph_authority',
            'gsc_ga4_referral_auto_link_creation',
            'title_slug_similarity_as_permanent_key',
            'search_channel_enqueue',
            'url_submission',
            'observation_queue_write',
            'pseo_generation',
        ] as $behavior) {
            $this->assertContains($behavior, $artifact['forbidden_behaviors'] ?? []);
        }

        foreach ([
            'docs_only',
            'generated_json_only',
            'focused_tests_only',
        ] as $flag) {
            $this->assertTrue((bool) data_get($artifact, "safety_flags.{$flag}"), $flag.' must be true');
        }

        foreach ([
            'runtime_link_writer_added',
            'cms_content_mutation',
            'fap_web_modified',
            'migration_added',
            'crawler_authority_added',
            'search_submission_added',
            'observation_queue_write_added',
            'pseo_generation',
        ] as $flag) {
            $this->assertFalse((bool) data_get($artifact, "safety_flags.{$flag}", true), $flag.' must be false');
        }
    }

    #[Test]
    public function docs_lock_contract_only_boundary_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/semantic-internal-link-graph-contract.md')));
        $artifactJson = strtolower((string) json_encode($this->artifact(), JSON_THROW_ON_ERROR));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'backend-owned semantic internal link graph',
            'contract only',
            'does not mutate cms content',
            'does not create internal links',
            'does not modify fap-web',
            'crawler logs',
            'cannot auto-create links',
            'translation_group_uuid',
            'legacy_unpaired',
            'title or slug similarity',
            'next task: `internal-link-01b`',
            '"next_task":"internal-link-01b"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/semantic-internal-link-graph-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
