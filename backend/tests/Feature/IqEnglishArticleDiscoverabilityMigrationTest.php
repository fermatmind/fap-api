<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class IqEnglishArticleDiscoverabilityMigrationTest extends TestCase
{
    private const CONNECTION = 'iq_english_discoverability_fixture';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'iq_seo_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.'.self::CONNECTION => $connection]);
        DB::setDefaultConnection(self::CONNECTION);
        Schema::clearResolvedInstance('db.schema');
        Schema::create('articles', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('org_id')->default(0);
            $table->string('locale');
            $table->string('slug');
            $table->string('status')->default('published');
            $table->boolean('is_public')->default(true);
            foreach (['is_indexable', 'sitemap_eligible', 'llms_eligible'] as $field) {
                $table->boolean($field)->default(false);
            }
            $table->integer('published_revision_id')->nullable();
            $table->integer('working_revision_id')->nullable();
            $table->integer('source_article_id')->nullable();
            $table->string('translation_group_id');
            $table->string('source_version_hash');
            $table->text('content_md')->default('Keep current body');
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('article_translation_revisions', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('article_id');
            $table->integer('org_id')->default(0);
            $table->string('locale')->default('en');
            $table->string('revision_status')->default('published');
            $table->text('content_md')->default('Reviewed public reasoning guide');
            $table->string('translated_from_version_hash');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
        });
        Schema::create('article_seo_meta', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('article_id');
            $table->integer('org_id')->default(0);
            $table->string('locale')->default('en');
            $table->boolean('is_indexable')->default(false);
            $table->string('robots')->nullable()->default('noindex,nofollow');
            $table->string('canonical_url')->nullable();
            $table->text('schema_json')->default('{"keep":"existing policy"}');
        });
        $this->app->instance(AuditLogger::class, Mockery::spy(AuditLogger::class));

    }

    protected function tearDown(): void
    {
        try {
            foreach (['article_seo_meta', 'article_translation_revisions', 'articles'] as $table) {
                Schema::connection(self::CONNECTION)->dropIfExists($table);
            }
        } finally {
            DB::setDefaultConnection($this->originalConnection);
            DB::purge(self::CONNECTION);
            Schema::clearResolvedInstance('db.schema');
            parent::tearDown();
        }
    }

    public function test_activates_only_reviewed_english_metadata_and_is_idempotent(): void
    {
        $this->seedArticles();
        $before = $this->snapshot();
        $this->activate();
        $once = $this->snapshot();
        $this->activate();
        self::assertEquals($once, $this->snapshot());
        foreach ($before['articles'] as $index => $row) {
            $expected = $row;
            if ($row->locale === 'en' && $row->org_id === 0) {
                $expected = clone $row;
                $expected->is_indexable = $expected->sitemap_eligible = $expected->llms_eligible = 1;
            }
            self::assertEquals($expected, $once['articles'][$index]);
        }
        foreach ($before['article_seo_meta'] as $index => $row) {
            $expected = clone $row;
            $expected->is_indexable = 1;
            $expected->robots = 'index,follow';
            self::assertEquals($expected, $once['article_seo_meta'][$index]);
        }
        self::assertEquals($before['article_translation_revisions'], $once['article_translation_revisions']);
        $this->app->make(AuditLogger::class)->shouldHaveReceived('log')->once();
    }

    public function test_stale_translation_refuses_the_entire_batch(): void
    {
        $this->seedArticles();
        DB::table('article_translation_revisions')->where('id', 108)->update(['translated_from_version_hash' => str_repeat('b', 64)]);
        $this->assertRejectedWithoutChanges();
    }

    public function test_unapproved_revision_refuses_the_entire_batch(): void
    {
        $this->seedArticles();
        DB::table('article_translation_revisions')->where('id', 108)->update(['approved_at' => null]);
        $this->assertRejectedWithoutChanges();
    }

    public function test_unpublished_article_refuses_the_entire_batch(): void
    {
        $this->seedArticles();
        DB::table('articles')->where('id', 8)->update(['status' => 'draft']);
        $this->assertRejectedWithoutChanges();
    }

    public function test_partial_set_or_wrong_canonical_cannot_activate(): void
    {
        $this->seedArticles();
        DB::table('article_seo_meta')->where('article_id', 8)->update(['canonical_url' => 'https://fermatmind.com/zh/articles/iq-test-growth-guide']);
        $this->assertRejectedWithoutChanges();
        DB::table('articles')->where('id', 8)->delete();
        $this->assertRejectedWithoutChanges();
    }

    public function test_empty_database_does_not_create_or_publish_articles(): void
    {
        $this->activate();
        self::assertSame(0, DB::table('articles')->count());
        $this->app->make(AuditLogger::class)->shouldNotHaveReceived('log');
    }

    private function assertRejectedWithoutChanges(): void
    {
        $before = $this->snapshot();
        try {
            $this->activate();
            self::fail('Expected an atomic activation refusal.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('IQ English article', $error->getMessage());
        }
        self::assertEquals($before, $this->snapshot());
        $this->app->make(AuditLogger::class)->shouldNotHaveReceived('log');
    }

    private function activate(): void
    {
        (require database_path('migrations/2026_10_15_030000_activate_iq_english_article_indexability.php'))->up();
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (['articles', 'article_seo_meta', 'article_translation_revisions'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return $out;
    }

    private function seedArticles(): void
    {
        foreach (['iq-test-score-and-limits-explained', 'iq-test-tool-guide', 'iq-test-narrative-portrait', 'iq-test-growth-guide'] as $i => $slug) {
            $source = 2 * $i + 1;
            $english = $source + 1;
            DB::table('articles')->insert([
                ['id' => $source, 'locale' => 'zh-CN', 'slug' => $slug, 'translation_group_id' => $slug, 'source_version_hash' => str_repeat('a', 64)],
                ['id' => $english, 'locale' => 'en', 'slug' => $slug, 'translation_group_id' => $slug, 'source_version_hash' => str_repeat('c', 64)],
            ]);
            DB::table('articles')->where('id', $english)->update(['source_article_id' => $source, 'published_revision_id' => 100 + $english,
                'working_revision_id' => 999, 'updated_at' => '2026-10-10 12:00:00']);
            DB::table('article_translation_revisions')->insert(['id' => 100 + $english, 'article_id' => $english,
                'translated_from_version_hash' => str_repeat('a', 64), 'approved_at' => '2026-10-10 12:00:00', 'published_at' => '2026-10-10 12:00:00']);
            DB::table('article_seo_meta')->insert(['id' => 200 + $english, 'article_id' => $english, 'canonical_url' => 'https://fermatmind.com/en/articles/'.$slug]);
        }
        DB::table('articles')->insert(['id' => 20, 'org_id' => 7, 'locale' => 'en', 'slug' => 'iq-test-tool-guide', 'translation_group_id' => 'foreign', 'source_version_hash' => 'keep']);
    }
}
