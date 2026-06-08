<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ContentPage;
use App\Services\Cms\ScienceContentPageDraftImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScienceContentPageImportDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_PATH = 'docs/seo/import-packages/science-contentpage-gpt55-review-draft-2026-06-08';

    #[Test]
    public function default_mode_is_dry_run_and_writes_zero_content_pages(): void
    {
        [$exitCode, $payload] = $this->runImport();

        $this->assertSame(0, $exitCode, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($payload['ok']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(6, $payload['pages_seen']);
        $this->assertSame(5, $payload['planned_create_count']);
        $this->assertSame(1, $payload['authority_revision_only_count']);
        $this->assertSame(0, $payload['blocked_count']);
        $this->assertFalse($payload['publish_allowed']);
        $this->assertFalse($payload['discoverability_allowed']);
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function execute_requires_exact_approval_phrase_and_writes_nothing_on_mismatch(): void
    {
        [$exitCode, $payload] = $this->runImport([
            '--execute' => true,
            '--approval-phrase' => 'approve',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('approval_phrase_mismatch', data_get($payload, 'errors.0.code'));
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function execute_and_dry_run_options_are_mutually_exclusive(): void
    {
        [$exitCode, $payload] = $this->runImport([
            '--execute' => true,
            '--dry-run' => true,
            '--approval-phrase' => ScienceContentPageDraftImportService::APPROVAL_PHRASE,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('execute_dry_run_conflict', data_get($payload, 'errors.0.code'));
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function approved_execute_creates_only_missing_non_public_non_indexable_drafts(): void
    {
        [$exitCode, $payload] = $this->runImport([
            '--execute' => true,
            '--approval-phrase' => ScienceContentPageDraftImportService::APPROVAL_PHRASE,
        ]);

        $this->assertSame(0, $exitCode, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($payload['ok']);
        $this->assertSame('execute', $payload['mode']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(5, $payload['planned_create_count']);
        $this->assertSame(1, $payload['authority_revision_only_count']);
        $this->assertSame(5, $payload['created_count']);
        $this->assertFalse($payload['publish_allowed']);
        $this->assertFalse($payload['discoverability_allowed']);

        $this->assertSame(5, ContentPage::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('content_pages', [
            'slug' => 'method-boundaries',
            'locale' => 'zh-CN',
        ]);

        foreach (['science', 'item-design-notes', 'reliability-validity', 'data-privacy', 'common-misconceptions'] as $slug) {
            $page = ContentPage::query()
                ->withoutGlobalScopes()
                ->where('slug', $slug)
                ->where('locale', 'zh-CN')
                ->firstOrFail();

            $this->assertSame(ContentPage::STATUS_DRAFT, $page->status);
            $this->assertFalse((bool) $page->is_public);
            $this->assertFalse((bool) $page->is_indexable);
            $this->assertFalse((bool) $page->publish_allowed);
            $this->assertTrue((bool) $page->operator_approval_required);
            $this->assertSame('not_reviewed', $page->claim_gate_status);
            $this->assertFalse((bool) $page->faq_schema_eligible);
            $this->assertFalse((bool) $page->schema_enabled);
            $this->assertNull($page->published_at);
            $this->assertNull($page->operator_approved_at);
            $this->assertNull($page->schema_eligibility_reviewed_at);
            $this->assertSame('policy', $page->kind);
            $this->assertSame('zh-CN', $page->locale);
            $this->assertStringStartsWith('/'.trim((string) $page->slug, '/'), (string) $page->path);
            $this->assertStringContainsString('science-contentpage-gpt55-review-draft-2026-06-08/pages/', (string) $page->source_doc);
        }

        $science = ContentPage::query()->withoutGlobalScopes()->where('slug', 'science')->firstOrFail();
        $this->assertGreaterThan(0, count($science->faq_items ?? []));
        $this->assertStringContainsString('visible_faq_items:', (string) $science->content_md);
    }

    #[Test]
    public function repeated_execute_skips_existing_rows_without_duplicates_or_upsert(): void
    {
        $this->runImport([
            '--execute' => true,
            '--approval-phrase' => ScienceContentPageDraftImportService::APPROVAL_PHRASE,
        ]);

        [$exitCode, $payload] = $this->runImport([
            '--execute' => true,
            '--approval-phrase' => ScienceContentPageDraftImportService::APPROVAL_PHRASE,
        ]);

        $this->assertSame(0, $exitCode, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['planned_create_count']);
        $this->assertSame(5, $payload['skipped_existing_count']);
        $this->assertSame(1, $payload['authority_revision_only_count']);
        $this->assertSame(0, $payload['created_count']);
        $this->assertSame(5, ContentPage::query()->withoutGlobalScopes()->count());
    }

    /**
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function runImport(array $options = []): array
    {
        $exitCode = Artisan::call('content-pages:science-import-drafts', array_merge([
            '--package' => base_path(self::PACKAGE_PATH),
            '--json' => true,
        ], $options));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return [$exitCode, $payload];
    }
}
