<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentPage;
use App\Services\Scale\ScaleRegistry;
use App\Services\SEO\SitemapGenerator;
use App\Services\SeoIntel\TranslationParity\TranslationParityMatrixReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnParity03ContentPagesParityImportPackageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generated_import_package_records_draft_only_content_boundaries(): void
    {
        $path = base_path('docs/seo/generated/en-parity-03-content-pages-parity-import-package.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('en-parity-03-content-pages-parity-import-package.v1', $payload['schema_version'] ?? null);
        $this->assertSame('EN-PARITY-03', $payload['task'] ?? null);
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['auto_publish_performed'] ?? true));
        $this->assertFalse((bool) ($payload['substantial_english_prose_generated'] ?? true));
        $this->assertFalse((bool) data_get($payload, 'content_generation_policy.auto_publish_allowed'));
        $this->assertFalse((bool) data_get($payload, 'content_generation_policy.mass_english_generation_allowed'));
        $this->assertFalse((bool) data_get($payload, 'content_generation_policy.draft_exposure_allowed_in_sitemap_llms'));

        $this->assertSame(28, data_get($payload, 'current_baseline_summary.total_rows'));
        $this->assertSame([
            'brand',
            'charter',
        ], data_get($payload, 'current_baseline_summary.missing_english_counterparts'));

        foreach ($payload['deferred_import_candidates'] ?? [] as $candidate) {
            $this->assertSame('draft_import_candidate', $candidate['target_publication_state'] ?? null);
            $this->assertSame('requires_human_translation', $candidate['body_status'] ?? null);
            $this->assertFalse((bool) ($candidate['sitemap_llms_exposure_allowed'] ?? true));
        }
    }

    #[Test]
    public function local_baseline_import_preserves_content_page_translation_metadata(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--source-dir' => '../content_baselines/content_pages',
            '--status' => ContentPage::STATUS_PUBLISHED,
            '--upsert' => true,
        ])->assertExitCode(0);

        $aboutZh = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('locale', 'zh-CN')
            ->where('slug', 'about')
            ->firstOrFail();
        $aboutEn = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('locale', 'en')
            ->where('slug', 'about')
            ->firstOrFail();
        $helpContactEn = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('locale', 'en')
            ->where('slug', 'help-contact')
            ->firstOrFail();

        $this->assertSame('content-page-about', $aboutZh->translation_group_id);
        $this->assertSame('content-page-about', $aboutEn->translation_group_id);
        $this->assertSame(ContentPage::TRANSLATION_STATUS_SOURCE, $aboutZh->translation_status);
        $this->assertSame(ContentPage::TRANSLATION_STATUS_PUBLISHED, $aboutEn->translation_status);
        $this->assertSame('zh-CN', $aboutEn->source_locale);
        $this->assertSame('approved', $aboutEn->review_state);
        $this->assertSame('support_static', $helpContactEn->page_type);
        $this->assertNotEmpty($aboutEn->seo_description);
    }

    #[Test]
    public function missing_content_page_counterparts_are_explicit_and_not_frontend_fallback(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--source-dir' => '../content_baselines/content_pages',
            '--status' => ContentPage::STATUS_PUBLISHED,
            '--upsert' => true,
        ])->assertExitCode(0);

        $matrix = app(TranslationParityMatrixReadModel::class)->build();
        $missing = collect($matrix['missing_counterparts'] ?? [])
            ->where('entity_type', 'content_page')
            ->where('missing_locale', 'en')
            ->pluck('translation_group_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'content-page-brand',
            'content-page-charter',
        ], $missing);
        $this->assertFalse((bool) ($matrix['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($matrix['summary']['counterpart_lookup_uses_slug_guessing_only'] ?? true));
    }

    #[Test]
    public function deferred_missing_english_pages_do_not_enter_sitemap(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->app->instance(ScaleRegistry::class, $this->mockScaleRegistry());

        $this->artisan('content-pages:import-local-baseline', [
            '--source-dir' => '../content_baselines/content_pages',
            '--status' => ContentPage::STATUS_PUBLISHED,
            '--upsert' => true,
        ])->assertExitCode(0);

        $locs = array_map(
            static fn (array $row): string => (string) ($row['loc'] ?? ''),
            app(SitemapGenerator::class)->generateUrls()
        );

        $this->assertContains('https://fermatmind.com/zh/brand', $locs);
        $this->assertContains('https://fermatmind.com/en/about', $locs);
        $this->assertContains('https://fermatmind.com/en/careers', $locs);
        $this->assertContains('https://fermatmind.com/en/policies', $locs);
        $this->assertNotContains('https://fermatmind.com/en/brand', $locs);
        $this->assertNotContains('https://fermatmind.com/en/charter', $locs);
        $this->assertNotContains('https://fermatmind.com/en/foundation', $locs);
    }

    private function mockScaleRegistry(): ScaleRegistry
    {
        $registry = \Mockery::mock(ScaleRegistry::class);
        $registry->shouldReceive('listActivePublic')->andReturn([]);

        return $registry;
    }
}
