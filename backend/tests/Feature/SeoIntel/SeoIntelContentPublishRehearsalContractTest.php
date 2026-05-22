<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelContentPublishRehearsalContractTest extends TestCase
{
    #[Test]
    public function contract_file_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/content-publish-rehearsal-contract.md'));

        $artifact = $this->artifact();

        $this->assertSame('content-publish-rehearsal-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('CONTENT-OPS-02A', $artifact['task'] ?? null);
        $this->assertSame('content_publish_rehearsal_contract', $artifact['purpose'] ?? null);
        $this->assertSame('dry_run_contract_only', $artifact['mode'] ?? null);
    }

    #[Test]
    public function candidate_surfaces_and_required_checks_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'articles',
            'research_reports',
            'content_pages',
            'support_articles',
            'interpretation_guides',
            'topics',
            'personality_pages',
            'career_guides',
            'career_jobs',
            'test_landing_detail_pages',
            'homepage_landing_surfaces',
        ] as $surface) {
            $this->assertContains($surface, $artifact['candidate_surfaces'] ?? []);
        }

        foreach ([
            'status_review_state',
            'is_public_is_indexable',
            'canonical_path_or_url',
            'seo_title',
            'seo_description',
            'robots_noindex_state',
            'locale',
            'slug',
            'references',
            'cta',
            'faq',
            'media_cover_readiness',
            'claim_boundary',
            'internal_link_readiness',
            'search_channel_eligibility_dry_run',
            'observation_queue_planned_event_dry_run',
        ] as $check) {
            $this->assertContains($check, $artifact['required_rehearsal_checks'] ?? []);
        }
    }

    #[Test]
    public function rehearsal_states_draft_exclusions_and_gates_are_defined(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(['safe', 'needs_review', 'blocked'], $artifact['rehearsal_states'] ?? []);

        foreach ([
            'sitemap',
            'llms',
            'search_channel_queue',
            'url_submission',
            'indexable_url_truth_handoff',
        ] as $exclusion) {
            $this->assertContains($exclusion, $artifact['draft_exclusions'] ?? []);
        }

        foreach ([
            'claim_lint',
            'internal_link_readiness',
            'search_channel_eligibility_dry_run',
            'observation_planning_dry_run',
        ] as $gate) {
            $this->assertContains($gate, $artifact['required_gates'] ?? []);
        }
    }

    #[Test]
    public function planned_observation_events_do_not_write_observation_queue(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'published',
            'metadata_changed',
            'canonical_changed',
            'robots_changed',
            'locale_link_changed',
            'claim_boundary_changed',
            'issue_detected',
        ] as $eventType) {
            $this->assertContains($eventType, $artifact['planned_observation_event_types'] ?? []);
        }

        $this->assertContains('observation_queue_write', $artifact['forbidden_behaviors'] ?? []);
        $this->assertFalse((bool) data_get($artifact, 'safety_flags.observation_queue_write', true));
    }

    #[Test]
    public function authority_boundaries_forbid_fallback_and_search_truth_drift(): void
    {
        $artifact = $this->artifact();

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

        foreach ([
            'cms_content_mutation',
            'article_publish',
            'production_write',
            'sitemap_mutation',
            'llms_mutation',
            'search_channel_queue_enqueue',
            'url_submission',
            'crawler_log_read',
            'claim_linter_auto_rewrite',
            'internal_link_auto_creation',
            'pseo_generation',
        ] as $behavior) {
            $this->assertContains($behavior, $artifact['forbidden_behaviors'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_prove_this_pr_is_contract_only(): void
    {
        $flags = $this->artifact()['safety_flags'] ?? [];

        foreach ([
            'docs_only',
            'generated_json_only',
            'focused_tests_only',
        ] as $flag) {
            $this->assertTrue((bool) ($flags[$flag] ?? false), $flag.' must be true');
        }

        foreach ([
            'runtime_service_added',
            'migration_added',
            'cms_content_mutation',
            'article_published',
            'production_write',
            'production_migration_execution',
            'sitemap_changed',
            'llms_changed',
            'search_channel_queue_enqueue',
            'url_submission',
            'crawler_log_read',
            'scheduler_enabled',
            'collector_write',
            'metabase_exposure',
            'fap_web_modified',
            'pseo_generation',
        ] as $flag) {
            $this->assertFalse((bool) ($flags[$flag] ?? true), $flag.' must be false');
        }
    }

    #[Test]
    public function docs_lock_dry_run_policy_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/content-publish-rehearsal-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/content-publish-rehearsal-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'dry-run only',
            'does not publish articles',
            'does not mutate cms',
            'does not write `seo_intel`',
            'does not write `seo_intel`',
            'observation queue write',
            'does not enqueue search channel queue',
            'does not submit urls',
            'sitemap mutation',
            'llms_mutation',
            'claim lint gate',
            'internal link readiness gate',
            'deterministic public runtime renderer',
            'next task: `content-ops-02b`',
            '"next_task": "content-ops-02b"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/content-publish-rehearsal-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
