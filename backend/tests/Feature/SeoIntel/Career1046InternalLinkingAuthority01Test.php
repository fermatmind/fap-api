<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Domain\Career\Publish\CareerFirstWaveNextStepLinksService;
use App\Domain\Career\Publish\CareerFirstWaveOccupationCompanionLinksService;
use App\Domain\Career\Publish\CareerFirstWaveRecommendationCompanionLinksService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Career1046InternalLinkingAuthority01Test extends TestCase
{
    #[Test]
    public function generated_artifact_records_backend_authority_services(): void
    {
        $payload = $this->payload();

        $this->assertSame('career_1046_internal_linking_authority.v1', $payload['schema_version'] ?? null);
        $this->assertSame('CAREER-1046-INTERNAL-LINKING-AUTHORITY-01', $payload['task'] ?? null);
        $this->assertSame('backend_career_authority_services', $payload['source_of_truth'] ?? null);
        $this->assertSame(1046, $payload['public_career_detail_expected_count'] ?? null);
        $this->assertSame(2092, $payload['localized_public_career_url_expected_count'] ?? null);

        $this->assertSame(
            CareerFirstWaveOccupationCompanionLinksService::SUMMARY_VERSION,
            data_get($payload, 'authority_services.occupation_companion_links.summary_version'),
        );
        $this->assertSame(
            CareerFirstWaveRecommendationCompanionLinksService::SUMMARY_VERSION,
            data_get($payload, 'authority_services.recommendation_companion_links.summary_version'),
        );
        $this->assertSame(
            CareerFirstWaveNextStepLinksService::SUMMARY_VERSION,
            data_get($payload, 'authority_services.next_step_links.summary_version'),
        );
    }

    #[Test]
    public function link_eligibility_blocks_invalid_and_excluded_targets(): void
    {
        $payload = $this->payload();

        foreach ([
            'public_runtime_projection_required',
            'detail_route_enabled_required',
            'robots_indexable_required',
            'release_gate_pass_required',
            'not_private',
            'not_draft',
            'not_fallback_only',
            'not_noindex',
            'not_404',
            'not_manual_hold',
        ] as $rule) {
            $this->assertContains($rule, $payload['link_eligibility_rules'] ?? []);
        }

        $this->assertEqualsCanonicalizing([
            'software-developers',
            'digital-forensics-analysts',
            'computer-occupations-all-other',
        ], $payload['excluded_slugs'] ?? []);
        $this->assertFalse((bool) ($payload['excluded_slug_link_exposure_allowed'] ?? true));

        foreach (['private', 'draft', 'fallback_only', 'noindex', 'hard_404', 'soft_404', 'manual_hold', 'conflict_slug'] as $target) {
            $this->assertContains($target, $payload['invalid_link_targets_blocked'] ?? []);
        }
    }

    #[Test]
    public function no_l3_generation_or_discoverability_mutation_is_recorded(): void
    {
        $payload = $this->payload();

        foreach ([
            'l3_static_generation_performed',
            'pseo_generation_performed',
            'frontend_fallback_authority_used',
            'sitemap_llms_exposure_changed',
            'search_channel_action_performed',
            'url_submission_performed',
            'production_write_performed',
        ] as $flag) {
            $this->assertFalse((bool) ($payload[$flag] ?? true), $flag);
        }

        $this->assertSame([], data_get($payload, 'claim_boundary.forbidden_claim_hits'));
        $this->assertContains('exploration', data_get($payload, 'claim_boundary.allowed_framing'));
        $this->assertContains('best_career_for_user', data_get($payload, 'claim_boundary.blocked_claims'));
    }

    #[Test]
    public function report_has_required_sections_and_next_task(): void
    {
        $path = base_path('docs/seo/career-1046-internal-linking-authority-01.md');

        $this->assertFileExists($path);

        $report = (string) file_get_contents($path);

        foreach ([
            '## 1. Executive Summary',
            '## 2. Link Families',
            '## 3. Eligibility Rules',
            '## 4. Non-Generated L3 Boundary',
            '## 5. Claim Boundary',
            '## 6. Validation',
            '## 7. What Was Not Done',
            '## 8. Final Decision',
            '## 9. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }

        $this->assertSame(
            'career_1046_internal_linking_authority_completed_ready_for_frontend_discovery_ux',
            $this->payload()['final_decision'] ?? null,
        );
        $this->assertSame('CAREER-1046-FRONTEND-DISCOVERY-UX-01', $this->payload()['next_task'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = base_path('docs/seo/generated/career-1046-internal-linking-authority-01.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
