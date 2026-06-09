<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ContentPage;
use App\Services\Cms\ScienceContentPageDraftDryRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScienceContentPageDraftDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EN_PACKAGE_PATH = 'docs/seo/import-packages/science-contentpage-en-review-draft-2026-06-09';

    #[Test]
    public function dry_run_maps_six_science_pages_without_database_writes(): void
    {
        $package = $this->writeSciencePackage();

        $this->artisan('content-pages:science-draft-dry-run', [
            '--package' => $package,
        ])
            ->expectsOutputToContain('task=SCIENCE-CONTENTPAGE-IMPORTER-DRYRUN-01')
            ->expectsOutputToContain('dry_run=true')
            ->expectsOutputToContain('would_write=false')
            ->expectsOutputToContain('pages_seen=6')
            ->expectsOutputToContain('pages_ready_for_non_public_draft_import=5')
            ->expectsOutputToContain('pages_reconciled_existing_authority=1')
            ->expectsOutputToContain('pages_blocked=0')
            ->expectsOutputToContain('page=METHOD-BOUNDARY-CONTENT-01 action=preserve_existing_authority_revision_only decision=existing_authority_reconciliation_ready')
            ->expectsOutputToContain('kind=policy status=draft public=false indexable=false')
            ->assertExitCode(0);

        $this->assertSame(0, ContentPage::query()->count());
    }

    #[Test]
    public function json_output_preserves_no_write_flags_and_metadata_only_fields(): void
    {
        $package = $this->writeSciencePackage();

        $this->artisan('content-pages:science-draft-dry-run', [
            '--package' => $package,
            '--json' => true,
        ])->assertExitCode(0);

        $payload = app(ScienceContentPageDraftDryRunService::class)->dryRun($package);

        $this->assertIsArray($payload);
        $this->assertSame('pass_no_write_dry_run', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['would_write']);
        $this->assertFalse($payload['database_writes_allowed']);
        $this->assertSame(6, $payload['pages_seen']);
        $this->assertSame(5, $payload['pages_ready_for_non_public_draft_import']);
        $this->assertSame(1, $payload['pages_reconciled_existing_authority']);
        $this->assertSame(0, $payload['pages_blocked']);
        $this->assertFalse($payload['non_runtime_guarantees']['cms_mutation_performed']);

        $scienceHub = collect($payload['pages'])->firstWhere('page_key', 'SCIENCE-HUB-CONTENT-01');
        $this->assertSame('policy', $scienceHub['normalized_content_page']['kind']);
        $this->assertSame('trust_methodology_hub', $scienceHub['normalized_content_page']['source_kind']);
        $this->assertSame('draft', $scienceHub['normalized_content_page']['status']);
        $this->assertFalse($scienceHub['normalized_content_page']['is_public']);
        $this->assertFalse($scienceHub['normalized_content_page']['is_indexable']);
        $this->assertFalse($scienceHub['normalized_content_page']['publish_allowed']);
        $this->assertTrue($scienceHub['normalized_content_page']['operator_approval_required']);
        $this->assertNull($scienceHub['normalized_content_page']['operator_approved_at']);
        $this->assertSame('not_reviewed', $scienceHub['normalized_content_page']['claim_gate_status']);
        $this->assertSame([], $scienceHub['normalized_content_page']['forbidden_claims']);
        $this->assertFalse($scienceHub['normalized_content_page']['faq_schema_eligible']);
        $this->assertNull($scienceHub['normalized_content_page']['schema_eligibility_reviewed_at']);
        $this->assertContains('visible_faq_items', $scienceHub['normalized_content_page']['metadata_only_not_content_page_fields']);
        $this->assertContains('sitemap_eligible', $scienceHub['normalized_content_page']['metadata_only_not_content_page_fields']);

        $dataNotes = collect($payload['pages'])->firstWhere('page_key', 'DATA-NOTES-CONTENT-01');
        $this->assertSame('data-privacy', $dataNotes['normalized_content_page']['slug']);

        $methodBoundaries = collect($payload['pages'])->firstWhere('page_key', 'METHOD-BOUNDARY-CONTENT-01');
        $this->assertSame('preserve_existing_authority_revision_only', $methodBoundaries['planned_action']);
        $this->assertSame('existing_authority_reconciliation_ready', $methodBoundaries['draft_import_decision']);
        $this->assertFalse($methodBoundaries['blocks_package_dry_run']);

        $this->assertSame(0, ContentPage::query()->count());
    }

    #[Test]
    public function approved_english_package_maps_five_en_drafts_without_database_writes(): void
    {
        $package = base_path(self::EN_PACKAGE_PATH);

        $this->artisan('content-pages:science-draft-dry-run', [
            '--package' => $package,
        ])
            ->expectsOutputToContain('task=SCIENCE-CONTENTPAGE-IMPORTER-DRYRUN-01')
            ->expectsOutputToContain('dry_run=true')
            ->expectsOutputToContain('would_write=false')
            ->expectsOutputToContain('pages_seen=5')
            ->expectsOutputToContain('pages_expected=5')
            ->expectsOutputToContain('pages_ready_for_non_public_draft_import=5')
            ->expectsOutputToContain('pages_reconciled_existing_authority=0')
            ->expectsOutputToContain('pages_blocked=0')
            ->expectsOutputToContain('status=pass_no_write_dry_run')
            ->assertExitCode(0);

        $payload = app(ScienceContentPageDraftDryRunService::class)->dryRun($package);
        $scienceHub = collect($payload['pages'])->firstWhere('page_key', 'SCIENCE-HUB-CONTENT-EN-01');
        $this->assertSame('en', $scienceHub['normalized_content_page']['locale']);
        $this->assertSame('zh-CN', $scienceHub['normalized_content_page']['source_locale']);
        $this->assertSame(ContentPage::TRANSLATION_STATUS_DRAFT, $scienceHub['normalized_content_page']['translation_status']);
        $this->assertSame('en_title', $scienceHub['normalized_content_page']['title_source_field']);
        $this->assertSame('science', $scienceHub['normalized_content_page']['slug']);
        $this->assertSame('science', $scienceHub['normalized_content_page']['page_type']);
        $this->assertFalse($scienceHub['normalized_content_page']['is_public']);
        $this->assertFalse($scienceHub['normalized_content_page']['is_indexable']);
        $this->assertFalse($scienceHub['normalized_content_page']['publish_allowed']);
        $this->assertSame('not_reviewed', $scienceHub['normalized_content_page']['claim_gate_status']);

        $this->assertSame(0, ContentPage::query()->count());
    }

    #[Test]
    public function dry_run_blocks_unsafe_defaults_without_writing(): void
    {
        $package = $this->writeSciencePackage([
            'eligibility_defaults' => [
                'is_public' => true,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'footer_eligible' => false,
            ],
        ]);

        $this->artisan('content-pages:science-draft-dry-run', [
            '--package' => $package,
        ])
            ->expectsOutputToContain('issue_count=1')
            ->expectsOutputToContain('status=blocked_no_write_dry_run')
            ->expectsOutputToContain('dry-run completed with blockers; no writes performed.')
            ->assertExitCode(0);

        $this->assertSame(0, ContentPage::query()->count());
    }

    /**
     * @param  array<string, mixed>  $manifestOverrides
     */
    private function writeSciencePackage(array $manifestOverrides = []): string
    {
        $root = storage_path('framework/testing/science-contentpage-dry-run-'.str()->uuid());
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

        $manifest = array_replace_recursive([
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
        ], $manifestOverrides);

        file_put_contents($root.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
h1: Draft heading
internal_links_allowed:
  - /tests
  - /privacy
forbidden_routes:
  - /result/*
  - /orders/*
unknown_fields:
  - public_validation_report_url
---

# content_md

## Draft body

This is draft-only explanatory content for importer validation.

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
