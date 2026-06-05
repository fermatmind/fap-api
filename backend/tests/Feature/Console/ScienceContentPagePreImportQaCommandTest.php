<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ContentPage;
use App\Services\Cms\ScienceContentPagePreImportQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScienceContentPagePreImportQaCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pre_import_qa_passes_draft_package_but_blocks_real_import_and_publish(): void
    {
        $package = $this->writeSciencePackage();

        $this->artisan('content-pages:science-pre-import-qa', [
            '--package' => $package,
        ])
            ->expectsOutputToContain('task=SCIENCE-CONTENTPAGE-PRE-IMPORT-QA-01')
            ->expectsOutputToContain('mode=read_only_pre_real_import_qa_gate')
            ->expectsOutputToContain('decision=NO-GO')
            ->expectsOutputToContain('cms_mutation_performed=false')
            ->expectsOutputToContain('database_writes_allowed=false')
            ->expectsOutputToContain('content_import_performed=false')
            ->expectsOutputToContain('publish_performed=false')
            ->expectsOutputToContain('non_public_draft_import_qa_passed=true')
            ->expectsOutputToContain('real_import_allowed=false')
            ->expectsOutputToContain('publish_allowed=false')
            ->expectsOutputToContain('natural_distribution_allowed=false')
            ->expectsOutputToContain('package_pre_import_qa_issue_count=0')
            ->expectsOutputToContain('dry_run_pages_blocked=1')
            ->expectsOutputToContain('operator_publish_decision_ready=false')
            ->expectsOutputToContain('blocking_reason=authority_reconciliation_required')
            ->expectsOutputToContain('blocking_reason=operator_publish_decision_not_ready')
            ->assertExitCode(0);

        $this->assertSame(0, ContentPage::query()->count());
    }

    #[Test]
    public function service_blocks_forbidden_claim_private_route_and_hidden_schema_patterns_without_writes(): void
    {
        $package = $this->writeSciencePackage(pageBodySuffix: <<<'MD'

schema_enabled: true

这个草稿保证职业成功。

允许链接：
- /orders/real-order
MD);

        $payload = app(ScienceContentPagePreImportQaService::class)->check($package);

        $this->assertSame('NO-GO', $payload['decision']);
        $this->assertFalse($payload['non_public_draft_import_qa_passed']);
        $this->assertFalse($payload['real_import_allowed']);
        $this->assertFalse($payload['publish_allowed']);
        $this->assertSame(0, ContentPage::query()->count());

        $codes = array_column($payload['issues'], 'code');
        $this->assertContains('forbidden_claim_pattern_present', $codes);
        $this->assertContains('private_url_pattern_present', $codes);
        $this->assertContains('hidden_faq_or_schema_pattern_present', $codes);
        $this->assertContains('package_pre_import_qa_issues_present', $payload['blocking_reasons']);
        $this->assertFalse($payload['guards']['forbidden_claims_absent']);
        $this->assertFalse($payload['guards']['private_url_absent']);
        $this->assertFalse($payload['guards']['faq_visible_only']);
    }

    private function writeSciencePackage(string $pageBodySuffix = ''): string
    {
        $root = storage_path('framework/testing/science-contentpage-pre-import-qa-'.str()->uuid());
        $pagesDir = $root.DIRECTORY_SEPARATOR.'pages';
        mkdir($pagesDir, 0777, true);

        $pages = [
            ['SCIENCE-HUB-CONTENT-01', '/science', 'science', 'trust_methodology_hub', 'science_review', true, true, '01-science-hub-content-01.md'],
            ['METHOD-BOUNDARY-CONTENT-01', '/method-boundaries', 'boundary', 'trust_methodology_boundary', 'science_review', true, true, '02-method-boundary-content-01.md'],
            ['ITEM-DESIGN-CONTENT-01', '/item-design-notes', 'methodology', 'item_design_notes', 'science_review', true, false, '03-item-design-content-01.md'],
            ['RELIABILITY-VALIDITY-CONTENT-01', '/reliability-validity', 'methodology', 'evidence_measurement_error', 'science_review', true, false, '04-reliability-validity-content-01.md', '/evidence-measurement-error'],
            ['DATA-NOTES-CONTENT-01', '/data-results-notes', 'privacy', 'data_results_notes', 'owner_review', false, true, '05-data-notes-content-01.md', '/data-privacy'],
            ['MISCONCEPTIONS-CONTENT-01', '/common-misconceptions', 'boundary', 'misconceptions', 'science_review', true, false, '06-misconceptions-content-01.md'],
        ];

        $manifestPages = [];
        foreach ($pages as $offset => $page) {
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
                key: $key,
                slug: $slug,
                fallback: $fallback,
                pageType: $pageType,
                kind: $kind,
                reviewState: $reviewState,
                scienceReview: $scienceReview,
                legalReview: $legalReview,
                bodySuffix: $offset === 0 ? $pageBodySuffix : '',
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
        string $bodySuffix,
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
  - /support
forbidden_routes:
  - /result/*
  - /orders/*
  - /share/*
  - /pay/*
  - /payment/*
  - /history/*
---

# content_md

Draft-only body for pre-import QA validation.
{$bodySuffix}

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
