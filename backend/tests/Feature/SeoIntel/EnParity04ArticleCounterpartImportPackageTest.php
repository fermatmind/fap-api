<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Services\SeoIntel\TranslationParity\TranslationParityMatrixReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnParity04ArticleCounterpartImportPackageTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET_COUNTERPART_SLUGS = [
        'big-five-growth-guide',
        'big-five-narrative-portrait',
        'big-five-tool-guide',
        'eq-test-tool-guide',
        'iq-test-growth-guide',
        'iq-test-narrative-portrait',
        'iq-test-tool-guide',
        'mbti-basics',
        'mbti-growth-guide',
        'mbti-narrative-portrait',
    ];

    private const DEFERRED_COUNTERPART_SLUGS = [
        'are-infj-men-rare-or-socially-silenced',
        'best-valentines-date-by-personality-and-relationship-science',
        'childhood-dream-job-still-shapes-career-choice',
        'how-16-personality-types-talk-to-an-ai-coach',
        'how-personality-shapes-attitude-toward-ai',
        'which-love-script-fits-you-best',
    ];

    #[Test]
    public function generated_import_package_records_article_content_controls(): void
    {
        $path = base_path('docs/seo/generated/en-parity-04-article-counterpart-import-package.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('en-parity-04-article-counterpart-import-package.v1', $payload['schema_version'] ?? null);
        $this->assertSame('EN-PARITY-04', $payload['task'] ?? null);
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['auto_publish_performed'] ?? true));
        $this->assertFalse((bool) ($payload['mass_english_generation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['substantial_english_prose_generated'] ?? true));
        $this->assertFalse((bool) ($payload['fap_web_modified'] ?? true));

        $this->assertSame(20, data_get($payload, 'current_baseline_summary.articles_en_count'));
        $this->assertSame(25, data_get($payload, 'current_baseline_summary.articles_zh_count'));
        $this->assertSame(10, data_get($payload, 'current_baseline_summary.target_counterparts_present_in_repo_baseline'));
        $this->assertSame(self::TARGET_COUNTERPART_SLUGS, $payload['target_counterparts_ready_for_review'] ?? []);

        $deferred = collect($payload['deferred_missing_english_counterparts'] ?? [])
            ->pluck('slug')
            ->values()
            ->all();
        $this->assertSame(self::DEFERRED_COUNTERPART_SLUGS, $deferred);

        foreach ($payload['deferred_missing_english_counterparts'] ?? [] as $candidate) {
            $this->assertSame('deferred_draft_required', $candidate['target_publication_state'] ?? null);
            $this->assertFalse((bool) ($candidate['sitemap_llms_exposure_allowed'] ?? true));
        }
    }

    #[Test]
    public function article_baseline_import_pairs_target_counterparts_with_backend_translation_authority(): void
    {
        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])
            ->expectsOutputToContain('files_found=2')
            ->expectsOutputToContain('articles_found=45')
            ->assertExitCode(0);

        foreach (self::TARGET_COUNTERPART_SLUGS as $slug) {
            $zh = Article::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('locale', 'zh-CN')
                ->where('slug', $slug)
                ->firstOrFail();
            $en = Article::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('locale', 'en')
                ->where('slug', $slug)
                ->firstOrFail();

            $this->assertSame('article:'.$slug, (string) $zh->translation_group_id);
            $this->assertSame((string) $zh->translation_group_id, (string) $en->translation_group_id);
            $this->assertSame(Article::TRANSLATION_STATUS_SOURCE, (string) $zh->translation_status);
            $this->assertSame(Article::TRANSLATION_STATUS_PUBLISHED, (string) $en->translation_status);
            $this->assertSame('zh-CN', (string) $en->source_locale);
            $this->assertSame((int) $zh->id, (int) $en->source_article_id);
            $this->assertSame((int) $zh->id, (int) ($en->publishedRevision?->source_article_id ?? 0));
            $this->assertSame('zh-CN', (string) ($en->publishedRevision?->source_locale ?? ''));
        }
    }

    #[Test]
    public function missing_article_counterparts_are_explicit_and_not_frontend_fallback(): void
    {
        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])->assertExitCode(0);

        $matrix = app(TranslationParityMatrixReadModel::class)->build();
        $missing = collect($matrix['missing_counterparts'] ?? [])
            ->where('entity_type', 'article')
            ->where('missing_locale', 'en')
            ->pluck('source_slug')
            ->sort()
            ->values()
            ->all();

        $expected = self::DEFERRED_COUNTERPART_SLUGS;
        sort($expected);

        $this->assertSame($expected, $missing);
        $this->assertFalse((bool) ($matrix['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($matrix['summary']['counterpart_lookup_uses_slug_guessing_only'] ?? true));
    }
}
