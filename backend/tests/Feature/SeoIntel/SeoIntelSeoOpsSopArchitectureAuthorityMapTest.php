<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSeoOpsSopArchitectureAuthorityMapTest extends TestCase
{
    #[Test]
    public function architecture_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-ops-sop-architecture-authority-map.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-ops-sop-architecture-authority-map.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01A', $artifact['task'] ?? null);
        $this->assertSame('SEO-OPS-SOP-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01B', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function authority_model_keeps_truth_observation_distribution_and_repair_separate(): void
    {
        $model = $this->artifact()['authority_model'] ?? [];

        $this->assertSame('truth_for_content_metadata_canonical_publish_state_claim_boundary_and_url_truth', $model['cms_backend'] ?? null);
        $this->assertSame('deterministic_public_runtime_renderer_not_truth_source', $model['fap_web'] ?? null);
        $this->assertSame('observation_only', $model['seo_intel'] ?? null);
        $this->assertSame('read_only_operational_view_not_truth_source', $model['ops_seo'] ?? null);
        $this->assertSame('write_capable_cms_repair_surface_not_daily_observability', $model['ops_seo_operations'] ?? null);
        $this->assertSame('private_read_only_not_public_surface', $model['metabase'] ?? null);
        $this->assertSame('distribution_of_approved_url_truth_only', $model['search_channel_queue'] ?? null);
        $this->assertSame('aggregate_observation_only_not_url_truth', $model['crawler_log'] ?? null);
        $this->assertSame('manual_observation_only', $model['digital_pr_tracking'] ?? null);
    }

    #[Test]
    public function forbidden_authority_sources_are_locked(): void
    {
        $sources = $this->artifact()['forbidden_authority_sources'] ?? [];

        foreach ([
            'frontend_fallback',
            'static_sitemap',
            'static_llms',
            'crawler_log',
            'search_engine_response',
            'digital_pr_mention',
            'local_copy',
        ] as $source) {
            $this->assertContains($source, $sources);
        }
    }

    #[Test]
    public function routine_ops_forbidden_operations_are_explicit(): void
    {
        $forbidden = $this->artifact()['routine_ops_forbidden'] ?? [];

        foreach ([
            'runtime_implementation',
            'migration',
            'production_operation',
            'env_edit',
            'deployment',
            'scheduler_activation',
            'collector_write',
            'crawler_log_read',
            'search_channel_submission',
            'cms_content_mutation',
            'article_publish',
            'metabase_exposure',
            'fap_web_modification',
            'digital_pr_send',
            'auto_rewrite',
            'auto_link_creation',
            'pseo_generation',
            'precise_recommender_overclaim',
        ] as $operation) {
            $this->assertContains($operation, $forbidden);
        }
    }

    #[Test]
    public function safety_flags_confirm_no_runtime_or_production_work(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_include_hard_boundaries_and_mbti_handoff(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-ops-sop-architecture-authority-map.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-ops-sop-architecture-authority-map.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'cms/backend is truth',
            'fap-web is a deterministic public runtime renderer',
            '/ops/seo is an operational read-only view',
            '/ops/seo-operations is a write-capable cms repair surface',
            'metabase remains private',
            'search channel queue distributes only approved url truth',
            'crawler log observes aggregate crawler behavior only',
            'frontend fallback is not truth',
            'static sitemap is not truth',
            'static llms is not truth',
            'crawler log is not truth',
            'search engine response is not truth',
            'digital pr mention is not truth',
            'local copy is not truth',
            'seo-growth-mbti-00',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-ops-sop-architecture-authority-map.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
