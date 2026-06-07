<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ContentPage;
use App\Services\Cms\ScienceContentPageOperatorReviewReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScienceContentPageOperatorReviewReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function operator_review_readiness_is_conditional_and_does_not_write_content_pages(): void
    {
        $package = $this->writeSciencePackage();

        $this->artisan('content-pages:science-operator-review-readiness', [
            '--package' => $package,
        ])
            ->expectsOutputToContain('task=SCIENCE-CONTENTPAGE-OPERATOR-REVIEW-01')
            ->expectsOutputToContain('mode=read_only_operator_review_gate')
            ->expectsOutputToContain('decision=CONDITIONAL')
            ->expectsOutputToContain('cms_mutation_performed=false')
            ->expectsOutputToContain('database_writes_allowed=false')
            ->expectsOutputToContain('content_import_performed=false')
            ->expectsOutputToContain('publish_performed=false')
            ->expectsOutputToContain('operator_review_ready_for_non_public_draft=true')
            ->expectsOutputToContain('operator_publish_decision_ready=false')
            ->expectsOutputToContain('publish_allowed_default=false')
            ->expectsOutputToContain('draft_pages_reviewable=5')
            ->expectsOutputToContain('draft_pages_requiring_authority_reconciliation=0')
            ->expectsOutputToContain('draft_pages_reconciled_existing_authority=1')
            ->assertExitCode(0);

        $this->assertSame(0, ContentPage::query()->count());
    }

    #[Test]
    public function json_payload_distinguishes_draft_review_readiness_from_publish_decision_readiness(): void
    {
        $package = $this->writeSciencePackage();

        $payload = app(ScienceContentPageOperatorReviewReadinessService::class)->review($package);

        $this->assertSame('SCIENCE-CONTENTPAGE-OPERATOR-REVIEW-01', $payload['task']);
        $this->assertSame('CONDITIONAL', $payload['decision']);
        $this->assertTrue($payload['operator_review_ready_for_non_public_draft']);
        $this->assertFalse($payload['operator_publish_decision_ready']);
        $this->assertFalse($payload['publish_allowed_default']);
        $this->assertFalse($payload['natural_distribution_allowed']);
        $this->assertSame(5, $payload['draft_package']['pages_reviewable_as_non_public_draft']);
        $this->assertSame(0, $payload['draft_package']['pages_requiring_authority_reconciliation']);
        $this->assertSame(1, $payload['draft_package']['pages_reconciled_existing_authority']);
        $this->assertFalse($payload['draft_package']['would_write']);

        foreach (['review_state', 'science_review_required', 'legal_review_required', 'is_public', 'is_indexable'] as $field) {
            $this->assertTrue($payload['capabilities']['content_page_core_fields'][$field]['present']);
            $this->assertTrue($payload['capabilities']['filament_operator_fields'][$field]['present']);
            $this->assertTrue($payload['capabilities']['internal_api_fields'][$field]['present']);
        }

        $this->assertSame([], $payload['missing_first_class_publish_safety_fields']);
        foreach (['publish_allowed', 'operator_approval_required', 'claim_gate_status', 'faq_schema_eligible'] as $field) {
            $this->assertTrue($payload['capabilities']['publish_safety_fields'][$field]['present']);
            $this->assertTrue($payload['capabilities']['filament_operator_fields'][$field]['present']);
            $this->assertTrue($payload['capabilities']['internal_api_fields'][$field]['present']);
        }
        $this->assertContains('sitemap_llms_footer_remain_false_until_final_gate', $payload['operator_must_check_before_publish']);
        $this->assertContains('no_real_import', $payload['hard_no_go']);
        $this->assertSame(0, ContentPage::query()->count());
    }

    private function writeSciencePackage(): string
    {
        $root = storage_path('framework/testing/science-contentpage-operator-review-'.str()->uuid());
        $pagesDir = $root.DIRECTORY_SEPARATOR.'pages';
        mkdir($pagesDir, 0777, true);

        $pages = [
            ['SCIENCE-HUB-CONTENT-01', '/science', 'science', 'trust_methodology_hub', 'science_review', true, true, '01-science-hub-content-01.md'],
            ['METHOD-BOUNDARY-CONTENT-01', '/method-boundaries', 'boundary', 'trust_methodology_boundary', 'science_review', true, true, '02-method-boundary-content-01.md'],
            ['ITEM-DESIGN-CONTENT-01', '/item-design-notes', 'methodology', 'item_design_notes', 'science_review', true, false, '03-item-design-content-01.md'],
            ['RELIABILITY-VALIDITY-CONTENT-01', '/reliability-validity', 'methodology', 'evidence_measurement_error', 'science_review', true, false, '04-reliability-validity-content-01.md'],
            ['DATA-NOTES-CONTENT-01', '/data-results-notes', 'privacy', 'data_results_notes', 'owner_review', false, true, '05-data-notes-content-01.md', '/data-privacy'],
            ['MISCONCEPTIONS-CONTENT-01', '/common-misconceptions', 'boundary', 'misconceptions', 'science_review', true, false, '06-misconceptions-content-01.md'],
        ];

        $manifestPages = [];
        foreach ($pages as $page) {
            [$key, $slug, $pageType, $kind, $reviewState, $scienceReview, $legalReview, $file] = $page;
            $fallback = $page[8] ?? $slug;
            $manifestPages[] = [
                'page_key' => $key,
                'zh_title' => 'Draft '.$key,
                'en_title' => 'Draft '.$key,
                'proposed_slug' => $slug,
                'fallback_slug' => $fallback,
                'page_type' => $pageType,
                'kind' => $kind,
                'review_state' => $reviewState,
                'science_review_required' => $scienceReview,
                'legal_review_required' => $legalReview,
                'file' => 'pages/'.$file,
            ];

            file_put_contents($pagesDir.DIRECTORY_SEPARATOR.$file, $this->pageMarkdown(
                $key,
                $slug,
                $fallback,
                $pageType,
                $kind,
                $reviewState,
                $scienceReview,
                $legalReview,
            ));
        }

        file_put_contents($root.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'package' => 'FermatMind Science / Methodology CMS Draft Package',
            'status' => 'draft_only_not_for_publication',
            'eligibility_defaults' => [
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'footer_eligible' => false,
            ],
            'pages' => $manifestPages,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $root;
    }

    private function pageMarkdown(
        string $key,
        string $slug,
        string $fallback,
        string $pageType,
        string $kind,
        string $reviewState,
        bool $scienceReview,
        bool $legalReview,
    ): string {
        $science = $scienceReview ? 'true' : 'false';
        $legal = $legalReview ? 'true' : 'false';

        return <<<MD
---
page_key: {$key}
zh_title: Draft {$key}
en_title: Draft {$key}
proposed_slug: {$slug}
fallback_slug_if_nested_route_not_supported: {$fallback}
page_type: {$pageType}
kind: {$kind}
review_state: {$reviewState}
science_review_required: {$science}
legal_review_required: {$legal}
is_public: false
is_indexable: false
sitemap_eligible: false
llms_eligible: false
footer_eligible: false
meta_title_draft: Draft meta
meta_description_draft: Draft description.
internal_links_allowed:
  - /tests
  - /privacy
forbidden_routes:
  - /result/*
  - /orders/*
---

# content_md

Draft-only body for operator review readiness validation.

visible_faq_items:
  - question: Is this publishable?
    answer: No. This fixture is draft-only.

claim_boundary_notes:
  - Keep claims conservative.

reviewer_checklist:
  - Confirm review gates.
MD;
    }
}
