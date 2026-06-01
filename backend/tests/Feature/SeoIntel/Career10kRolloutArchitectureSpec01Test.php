<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class Career10kRolloutArchitectureSpec01Test extends TestCase
{
    public function test_career_10k_rollout_architecture_spec_preserves_authority_and_no_write_boundaries(): void
    {
        $reportPath = base_path('docs/seo/generated/career-10k-rollout-architecture-spec-01.v1.json');
        $handbookPath = base_path('docs/career/README.md');
        $markdownPath = base_path('docs/seo/career-10k-rollout-architecture-spec-01.md');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($handbookPath);
        $this->assertFileExists($markdownPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        $handbook = (string) file_get_contents($handbookPath);
        $markdown = (string) file_get_contents($markdownPath);

        $this->assertSame('career_10k_rollout_architecture_spec.v1', $report['schema_version'] ?? null);
        $this->assertSame('CAREER-10K-ROLLOUT-ARCHITECTURE-SPEC-01', $report['task'] ?? null);
        $this->assertSame(
            'career_10k_rollout_architecture_spec_completed_ready_for_future_scoped_prs',
            $report['final_decision'] ?? null,
        );
        $this->assertSame(1046, $report['current_public_detail_count'] ?? null);
        $this->assertSame(2092, $report['current_localized_detail_url_count'] ?? null);
        $this->assertSame(10000, $report['target_scale'] ?? null);

        $this->assertSame('fap-api', $report['authority_source']['owner'] ?? null);
        $this->assertFalse($report['authority_source']['frontend_fallback_allowed'] ?? true);

        foreach ([
            'software-developers',
            'digital-forensics-analysts',
            'computer-occupations-all-other',
        ] as $heldSlug) {
            $this->assertContains($heldSlug, $report['held_slug_policy']['held_slugs'] ?? []);
            $this->assertStringContainsString($heldSlug, $handbook);
            $this->assertStringContainsString($heldSlug, $markdown);
        }

        foreach ([
            'authority_manifest_schema_validation',
            'runtime_projection_dry_run',
            'explicit_apply_approval',
            'post_deploy_smoke',
        ] as $gate) {
            $this->assertContains($gate, $report['rollout_gates'] ?? []);
        }

        $this->assertSame(50, $report['directory_api_budget']['default_first_page_size'] ?? null);
        $this->assertSame(100, $report['directory_api_budget']['maximum_page_size_without_future_benchmark'] ?? null);
        $this->assertFalse($report['frontend_directory_policy']['ssr_full_database_allowed'] ?? true);
        $this->assertTrue($report['frontend_directory_policy']['ssr_first_page_and_facets_only'] ?? false);
        $this->assertFalse($report['sitemap_llms_policy']['runtime_detail_fanout_allowed'] ?? true);
        $this->assertTrue($report['sitemap_llms_policy']['degraded_200_preferred_over_504'] ?? false);
        $this->assertSame('HOLD', $report['search_channel_policy']['current_decision'] ?? null);
        $this->assertTrue($report['search_channel_policy']['future_explicit_approval_required'] ?? false);

        foreach ([
            'track_directory_count_parity',
            'track_public_detail_indexable_count',
            'track_sitemap_career_url_count',
            'track_llms_career_url_count',
            'track_llms_full_complete_or_degraded_state',
            'track_held_slug_absence',
            'track_sampled_detail_status_canonical_robots',
            'track_cache_warm_duration_and_payload_size',
            'track_legacy_full_jobs_index_consumers',
            'track_search_channel_gate_state',
        ] as $field) {
            $this->assertTrue($report['observability_slo'][$field] ?? false, $field);
        }

        foreach ([
            'no_runtime_change',
            'no_cms_mutation',
            'no_database_mutation',
            'no_deploy',
            'no_fap_web_change',
            'no_search_channel_action',
            'no_url_submission',
            'no_external_search_api_call',
        ] as $field) {
            $this->assertTrue($report[$field] ?? false, $field);
        }

        $this->assertFalse($report['held_slug_release_performed'] ?? true);
        $this->assertSame('none', $report['next_task'] ?? null);
        $this->assertStringContainsString('10k Target Architecture Contract', $handbook);
        $this->assertStringContainsString('Runtime expansion still requires separate authority', $handbook);
        $this->assertStringContainsString('manifests, dry-runs, controlled apply approval', $handbook);
    }
}
