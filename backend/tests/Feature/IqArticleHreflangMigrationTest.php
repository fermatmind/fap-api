<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\AuditLog;
use App\Services\Cms\ArticleSeoService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\ContentPromotion\Concerns\SeedsIqEqTopicPrerequisites;

final class IqArticleHreflangMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsIqEqTopicPrerequisites;

    private const SLUGS = ['iq-test-tool-guide', 'iq-test-score-and-limits-explained', 'iq-test-growth-guide', 'iq-test-narrative-portrait'];

    public function test_releases_only_eight_hreflang_gates_through_the_native_rollout_and_preserves_every_published_byte(): void
    {
        $this->seedCurrent();
        $before = $this->snapshot();
        $this->activate();
        $after = $this->snapshot();
        self::assertSame($before['articles'], $after['articles']);
        self::assertSame($before['article_translation_revisions'], $after['article_translation_revisions']);
        foreach ($before['article_seo_meta'] as $index => $seo) {
            $actual = $after['article_seo_meta'][$index];
            if (in_array((int) $seo['article_id'], $this->targets()->pluck('id')->all(), true)) {
                $schema = json_decode($actual['schema_json'], true, flags: JSON_THROW_ON_ERROR);
                self::assertTrue(data_get($schema, 'editorial_package_v1.hreflang_gate_v1.enabled'));
                self::assertSame('reciprocal_counterparts_verified', data_get($schema, 'editorial_package_v1.hreflang_gate_v1.policy'));
                $old = json_decode($seo['schema_json'], true, flags: JSON_THROW_ON_ERROR);
                unset($schema['editorial_package_v1']['hreflang_gate_v1'], $old['editorial_package_v1']['hreflang_gate_v1']);
                unset($schema['editorial_package_v1']['hreflang_hold'], $old['editorial_package_v1']['hreflang_hold']);
                self::assertSame($old, $schema);
                unset($actual['schema_json'], $actual['updated_at'], $seo['schema_json'], $seo['updated_at']);
            }
            self::assertSame($seo, $actual);
        }
        self::assertSame(8, AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_seo_gate_rollout')->count());
        foreach ($this->targets() as $article) {
            $seo = app(ArticleSeoService::class)->buildSeoPayload($article, $article->publishedRevision);
            self::assertSame('https://fermatmind.com/en/articles/'.$article->slug, $seo['alternates']['en']);
            self::assertSame('https://fermatmind.com/zh/articles/'.$article->slug, $seo['alternates']['zh-CN']);
            $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale='.$article->locale.'&org_id=0')
                ->assertOk()->assertJsonPath('article.seo_meta.hreflang_gate_v1.enabled', true);
        }
        $once = $this->snapshot();
        $this->travel(5)->minutes();
        $this->activate();
        (require $this->migration())->down();
        self::assertSame($once, $this->snapshot());
    }

    public function test_an_already_enabled_locale_is_not_rewritten(): void
    {
        $this->seedCurrent();
        $seo = $this->targets()->first()->seoMeta;
        $schema = $seo->schema_json;
        $schema['editorial_package_v1']['hreflang_gate_v1'] = ['enabled' => true, 'policy' => 'existing_verified_policy'];
        $seo->forceFill(['schema_json' => $schema])->saveQuietly();
        $before = DB::table('article_seo_meta')->where('id', $seo->id)->first();
        $this->activate();
        self::assertEquals($before, DB::table('article_seo_meta')->where('id', $seo->id)->first());
        self::assertSame(7, AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_seo_gate_rollout')->count());
    }

    public function test_staging_english_canonical_fallback_is_verified_without_rewriting_it(): void
    {
        $this->seedCurrent();
        DB::table('article_seo_meta')->whereIn('article_id', $this->targets()->where('locale', 'en')->pluck('id'))->update(['canonical_url' => null]);
        $this->activate();
        foreach ($this->targets()->where('locale', 'en') as $article) {
            self::assertNull($article->seoMeta->canonical_url);
            self::assertTrue(data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled'));
            $seo = app(ArticleSeoService::class)->buildSeoPayload($article, $article->publishedRevision);
            self::assertSame('https://fermatmind.com/en/articles/'.$article->slug, $seo['canonical']);
        }
    }

    #[DataProvider('authorityConflicts')]
    public function test_authority_conflicts_refuse_the_entire_set_without_writes(string $table, string $field, mixed $value): void
    {
        $this->seedCurrent();
        $article = $this->targets()->where('locale', 'en')->last();
        $id = match ($table) {
            'articles' => $article->id,
            'article_seo_meta' => $article->seoMeta->id,
            'article_translation_revisions' => $article->published_revision_id,
        };
        DB::table($table)->where('id', $id)->update([$field => $value]);
        $this->assertRejected();
    }

    public static function authorityConflicts(): array
    {
        return [
            ['articles', 'is_indexable', false], ['articles', 'is_public', false],
            ['articles', 'sitemap_eligible', false], ['articles', 'llms_eligible', false],
            ['articles', 'status', 'draft'], ['articles', 'source_article_id', 999999],
            ['articles', 'translation_group_id', 'unrelated-group'],
            ['article_seo_meta', 'robots', 'noindex,follow'], ['article_seo_meta', 'is_indexable', false],
            ['article_seo_meta', 'canonical_url', 'https://example.invalid/not-this-page'],
            ['article_seo_meta', 'seo_title', 'An unreviewed title'],
            ['article_translation_revisions', 'approved_at', null],
            ['article_translation_revisions', 'published_at', '2030-01-01 00:00:00'],
            ['article_translation_revisions', 'revision_status', 'draft'],
            ['article_translation_revisions', 'content_md', 'Changed after review'],
            ['article_translation_revisions', 'authority_package_sha256', str_repeat('a', 64)],
            ['article_translation_revisions', 'authority_source_hash', str_repeat('a', 64)],
            ['article_translation_revisions', 'translated_from_version_hash', str_repeat('a', 64)],
        ];
    }

    public function test_a_missing_locale_does_not_partially_enable_other_pairs(): void
    {
        $this->seedCurrent();
        DB::table('articles')->where('id', $this->targets()->last()->id)->update(['locale' => 'fr']);
        $this->assertRejected();
    }

    public function test_a_late_native_write_error_rolls_back_metadata_and_native_audits(): void
    {
        $this->seedCurrent();
        $updates = 0;
        DB::listen(static function (QueryExecuted $query) use (&$updates): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update') && str_contains($query->sql, 'article_seo_meta') && ++$updates === 3) {
                throw new RuntimeException('Injected native metadata write failure');
            }
        });
        $this->assertRejected('Injected native metadata');
        self::assertSame(3, $updates);
    }

    public function test_an_empty_database_does_not_create_articles_or_gates(): void
    {
        $before = $this->snapshot();
        $this->activate();
        self::assertSame($before, $this->snapshot());
    }

    private function seedCurrent(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->seedArticles(true);
        foreach ($this->targets() as $article) {
            $source = Article::query()->withoutGlobalScopes()->where('org_id', 0)->where('locale', 'zh-CN')->where('slug', $article->slug)->firstOrFail();
            $article->forceFill(['is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true,
                'source_article_id' => $article->locale === 'en' ? $source->id : null,
                'translated_from_version_hash' => $article->locale === 'en' ? $source->source_version_hash : null,
            ])->saveQuietly();
            $article->publishedRevision->forceFill(['approved_at' => now()->subMinute()])->saveQuietly();
            $schema = $article->seoMeta->schema_json;
            $schema['editorial_package_v1'] += ['article_schema_enabled' => $article->locale === 'zh-CN', 'breadcrumb_schema_enabled' => $article->locale === 'zh-CN',
                'faq_schema_enabled' => $article->locale === 'zh-CN' && $article->slug !== 'iq-test-score-and-limits-explained', 'hreflang_hold' => 'historical_hold',
                'hreflang_gate_v1' => ['enabled' => false, 'policy' => 'no_hreflang', 'reason' => 'no_direct_english_counterpart_approved']];
            $path = '/'.($article->locale === 'en' ? 'en' : 'zh').'/articles/'.$article->slug;
            $article->seoMeta->forceFill(['schema_json' => $schema, 'robots' => 'index,follow', 'is_indexable' => true,
                'canonical_url' => $article->locale === 'zh-CN' ? $path : 'https://fermatmind.com'.$path,
            ])->saveQuietly();
        }
        Article::query()->withoutGlobalScopes()->create(['org_id' => 7, 'locale' => 'en', 'slug' => 'iq-test-tool-guide', 'title' => 'Other tenant',
            'translation_group_id' => 'unrelated', 'content_md' => 'Leave this untouched', 'status' => 'draft']);
        $article = $this->targets()->where('locale', 'en')->first();
        $draft = $article->publishedRevision->replicate();
        $draft->forceFill(['revision_number' => 2, 'revision_status' => 'draft', 'approved_at' => null, 'published_at' => null,
            'authority_package_sha256' => null, 'authority_asset_key' => null, 'authority_source_package' => null,
            'authority_source_hash' => null, 'authority_metadata_json' => null,
            'content_md' => 'Unrelated working draft retained for its owner'])->saveQuietly();
        $article->forceFill(['working_revision_id' => $draft->id])->saveQuietly();
    }

    private function targets()
    {
        return Article::query()->withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])
            ->where('org_id', 0)->whereIn('slug', self::SLUGS)->whereIn('locale', ['en', 'zh-CN'])->orderBy('id')->get();
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (['articles', 'article_seo_meta', 'article_translation_revisions', 'audit_logs'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $out;
    }

    private function migration(): string
    {
        return database_path('migrations/2026_10_15_080000_activate_iq_article_hreflang.php');
    }

    private function activate(): void
    {
        (require $this->migration())->up();
    }

    private function assertRejected(string $message = 'IQ hreflang'): void
    {
        $before = $this->snapshot();
        try {
            $this->activate();
            self::fail('Expected atomic refusal');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }
}
