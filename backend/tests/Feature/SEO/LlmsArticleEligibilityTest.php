<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LlmsArticleEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_llms_surfaces_project_only_publicly_llms_eligible_articles(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $this->createPublishedArticle('included-article', 'zh-CN', true);
        $this->createPublishedArticle('excluded-from-llms', 'zh-CN', false);
        $this->createPublishedArticle('included-english-article', 'en', true);
        $this->createPublishedArticle('private-article', 'en', true, false);
        $careerCache = app(PublicCareerAuthorityResponseCache::class);
        $careerCache->publishDirectoryReadModel('en', ['items' => []]);
        $careerCache->publishDirectoryReadModel('zh-CN', ['items' => []]);

        $generator = app(SitemapGenerator::class);
        $sitemapLocations = collect($generator->generateUrls())->pluck('loc')->all();
        $llmsRows = $generator->generateLlmsUrls();
        $llmsLocations = collect($llmsRows)->pluck('loc')->all();

        $this->assertContains('https://fermatmind.com/zh/articles/excluded-from-llms', $sitemapLocations);
        $this->assertNotContains('https://fermatmind.com/zh/articles/excluded-from-llms', $llmsLocations);
        $this->assertContains('https://fermatmind.com/zh/articles/included-article', $llmsLocations);
        $this->assertNotContains('https://fermatmind.com/en/articles/private-article', $llmsLocations);
        $this->assertSame([], array_values(array_filter(
            $llmsRows,
            static fn (array $row): bool => array_key_exists('__llms_eligible', $row),
        )));

        $this->prepareUrlTruthFixture();
        foreach ($llmsRows as $row) {
            $this->insertUrl((string) parse_url($row['loc'], PHP_URL_PATH), (string) ($row['locale'] ?? (str_contains($row['loc'], '/zh/') ? 'zh-CN' : 'en')), (string) ($row['page_entity_type'] ?? 'article'), 'trusted', null);
        }

        foreach (['/llms.txt', '/llms-full.txt'] as $path) {
            Cache::forget('seo:llms-txt:v1:body');
            Cache::forget('seo:llms-full-txt:v1:body');
            $body = (string) $this->get($path)
                ->assertOk()
                ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
                ->getContent();

            $this->assertSame(1, substr_count($body, 'https://fermatmind.com/zh/articles/included-article'));
            $this->assertSame(1, substr_count($body, 'https://fermatmind.com/en/articles/included-english-article'));
            $this->assertStringContainsString('https://fermatmind.com/zh/articles', $body);
            $this->assertStringContainsString('https://fermatmind.com/en/articles', $body);
            $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/excluded-from-llms', $body);
            $this->assertStringNotContainsString('https://fermatmind.com/en/articles/private-article', $body);
        }
    }

    private function createPublishedArticle(
        string $slug,
        string $locale,
        bool $llmsEligible,
        bool $isPublic = true,
    ): void {
        $publishedAt = Carbon::create(2026, 7, 28, 12, 0, 0, 'UTC');
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'translation_group_id' => 'test-'.$locale.'-'.$slug,
            'locale' => $locale,
            'source_locale' => $locale,
            'title' => 'Article '.$slug,
            'excerpt' => 'Eligibility fixture.',
            'content_md' => '# Article',
            'status' => 'published',
            'is_public' => $isPublic,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => $llmsEligible,
            'published_at' => $publishedAt,
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'published_at' => $publishedAt,
        ]);

        $article->forceFill(['published_revision_id' => $revision->id])->save();
    }

    private function prepareUrlTruthFixture(): void
    {
        config([
            'cache.default' => 'array',
            'services.seo.public_sitemap_authority' => 'backend',
            'app.frontend_url' => 'https://fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('seo_intel');
        Cache::flush();
        $previousConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('seo_intel');
        foreach ([
            '2026_05_17_000100_create_seo_urls_table.php',
            '2026_05_17_000200_create_seo_url_entities_table.php',
            '2026_08_25_020000_expand_url_truth_current_bindings.php',
            '2026_08_28_030000_expand_url_truth_material_authority.php',
        ] as $file) {
            (require database_path('migrations/seo_intel/'.$file))->up();
        }
        DB::setDefaultConnection($previousConnection);
    }

    /** @param array<string,mixed> $metadata @param array<string,mixed> $attributes */
    private function insertUrl(
        string $path,
        string $locale,
        string $type,
        string $materialState,
        ?string $materialLastmod,
        bool $isPrivate = false,
        array $metadata = [],
        array $attributes = [],
    ): void {
        $url = 'https://fermatmind.com'.$path;
        $hash = hash('sha256', rtrim($url, '/'));
        $entity = trim($path, '/');
        $identity = hash('sha256', $type.'|'.$entity.'|'.$locale);
        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => $hash,
            'canonical_url' => $url,
            'locale' => $locale,
            'page_entity_type' => $type,
            'page_family' => $type,
            'entity_id_or_slug' => $entity,
            'source_authority' => 'cms',
            'authority_revision' => hash('sha256', 'authority|'.$url),
            'canonical_revision' => $hash,
            'indexability_state' => 'indexable',
            'is_private_flow' => $isPrivate,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'material_authority_state' => $materialState,
            'material_lastmod_at' => $materialLastmod,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection('seo_intel')->table('seo_url_entities')->insert([
            'canonical_url_hash' => $hash,
            'locale' => $locale,
            'page_entity_type' => $type,
            'page_family' => $type,
            'entity_id_or_slug' => $entity,
            'entity_source' => 'cms',
            'authority_status' => 'published_approved',
            'authority_revision' => hash('sha256', 'authority|'.$url),
            'canonical_revision' => $hash,
            'binding_status' => 'current',
            'current_binding_key' => $identity,
            'attributes_json' => json_encode($attributes, JSON_THROW_ON_ERROR),
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        parent::tearDown();
    }
}
