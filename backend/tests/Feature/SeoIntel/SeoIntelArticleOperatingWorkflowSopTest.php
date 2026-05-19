<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelArticleOperatingWorkflowSopTest extends TestCase
{
    #[Test]
    public function artifact_defines_required_article_workflow_stages(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('article-operating-workflow-sop.v1', $artifact['version'] ?? null);
        $this->assertContains('CLAIM-LINT-00', $artifact['source_documents'] ?? []);

        $this->assertSame([
            'editorial_package',
            'cms_draft',
            'gate_checks',
            'controlled_publish',
            'post_publish_observation',
        ], $artifact['workflow_stages'] ?? []);
    }

    #[Test]
    public function drafts_are_excluded_from_search_and_url_truth_indexability(): void
    {
        $exclusions = $this->artifact()['draft_exclusions'] ?? [];

        foreach ([
            'sitemap',
            'llms',
            'baidu_push',
            'indexnow',
            'so360',
            'sogou',
            'shenma',
            'url_truth_indexable',
        ] as $target) {
            $this->assertContains($target, $exclusions);
        }
    }

    #[Test]
    public function gate_checks_cover_claims_links_media_pii_and_queue_eligibility(): void
    {
        $checks = $this->artifact()['gate_checks'] ?? [];

        foreach ([
            'required_cms_fields',
            'canonical_url_readiness',
            'no_private_flow_links',
            'claim_boundary_safety',
            'internal_link_validity',
            'media_ownership_and_alt_text',
            'no_raw_pii_or_raw_operational_identifiers',
            'no_unsupported_research_or_pseo_page_type_assumptions',
            'search_channel_queue_eligibility_status',
        ] as $check) {
            $this->assertContains($check, $checks);
        }
    }

    #[Test]
    public function forbidden_claims_and_internal_link_targets_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'diagnosis',
            'treatment',
            'cure',
            'exact_iq',
            'guaranteed_career_outcome',
            'hiring_fit',
            'job_competency',
            'full_career_recommendation',
            'ai_career_planning_authority',
        ] as $claim) {
            $this->assertContains($claim, $artifact['forbidden_claim_classes'] ?? []);
        }

        foreach ([
            'draft',
            'private_flow',
            'checkout',
            'payment',
            'user_report',
            'share_flow',
            'noindex',
        ] as $target) {
            $this->assertContains($target, $artifact['forbidden_internal_link_targets'] ?? []);
        }
    }

    #[Test]
    public function no_runtime_content_publish_or_production_activation_happens(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'runtime_content_changed_in_this_pr',
            'cms_runtime_mutation_in_this_pr',
            'article_published_in_this_pr',
            'sitemap_changed_in_this_pr',
            'llms_changed_in_this_pr',
            'url_submission_performed',
            'production_write_execution',
            'scheduler_enabled_in_this_pr',
            'external_api_live_activation',
            'collector_write_executed_in_this_pr',
            'env_edit_in_this_pr',
            'metabase_deployed_in_this_pr',
            'pseo_generation_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_publish_no_submission_and_train_completion(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/cms/article-operating-workflow-sop.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/article-operating-workflow-sop.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not publish content',
            'draft must not enter sitemap',
            'draft must not enter `llms.txt`',
            'draft must not enter baidu push',
            'controlled publish',
            'publishing must not automatically trigger search submissions',
            'no private-flow links',
            'next task: none',
            '"next_task": null',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/article-operating-workflow-sop.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
