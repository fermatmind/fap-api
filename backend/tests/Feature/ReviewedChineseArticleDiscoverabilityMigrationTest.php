<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ReviewedChineseArticleDiscoverabilityMigrationTest extends TestCase
{
    private const CONNECTION = 'reviewed_zh_discoverability_fixture';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'zh_seo_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.'.self::CONNECTION => $connection]);
        DB::setDefaultConnection(self::CONNECTION);
        Schema::clearResolvedInstance('db.schema');
        Schema::create('articles', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('org_id')->default(0);
            $t->string('locale');
            $t->string('slug');
            $t->string('status')->default('published');
            $t->boolean('is_public')->default(true);
            foreach (['is_indexable', 'sitemap_eligible', 'llms_eligible'] as $field) {
                $t->boolean($field)->default(false);
            }
            $t->integer('published_revision_id')->nullable();
            $t->integer('working_revision_id')->nullable();
            $t->integer('source_article_id')->nullable();
            $t->string('source_locale')->default('zh-CN');
            $t->string('translation_group_id');
            $t->string('source_version_hash');
            $t->string('translated_from_version_hash')->nullable();
            $t->text('content_md');
            $t->string('lifecycle_state')->default('active');
            $t->string('lifecycle_note')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('article_translation_revisions', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('article_id');
            $t->integer('org_id')->default(0);
            $t->string('locale');
            $t->string('revision_status')->default('published');
            $t->text('content_md');
            $t->string('translated_from_version_hash')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable();
        });
        Schema::create('article_seo_meta', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('article_id');
            $t->integer('org_id')->default(0);
            $t->string('locale');
            $t->boolean('is_indexable')->default(false);
            $t->string('robots')->default('noindex,follow');
            $t->string('canonical_url');
            $t->text('schema_json')->default('{"keep":"existing gates"}');
        });
        Schema::create('review_attestations', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('review_mode');
            $t->string('review_source');
            $t->string('decision');
        });
        Schema::create('review_attestation_target_evidences', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('review_attestation_id');
            $t->string('target_identity');
            $t->string('target_sha256');
            $t->string('target_decision');
        });
        $this->app->instance(AuditLogger::class, Mockery::spy(AuditLogger::class));
    }

    protected function tearDown(): void
    {
        try {
            foreach (['review_attestation_target_evidences', 'review_attestations', 'article_seo_meta', 'article_translation_revisions', 'articles'] as $table) {
                Schema::connection(self::CONNECTION)->dropIfExists($table);
            }
        } finally {
            DB::setDefaultConnection($this->originalConnection);
            DB::purge(self::CONNECTION);
            Schema::clearResolvedInstance('db.schema');
            parent::tearDown();
        }
    }

    public function test_releases_only_two_reviewed_chinese_metadata_sets_and_is_idempotent(): void
    {
        $this->seedArticles();
        $before = $this->snapshot();
        $this->activate();
        $once = $this->snapshot();
        $this->activate();
        self::assertEquals($once, $this->snapshot());
        foreach ($before['articles'] as $i => $row) {
            $expected = clone $row;
            if (in_array($row->id, [254, 265], true)) {
                $expected->is_indexable = $expected->sitemap_eligible = $expected->llms_eligible = 1;
            }
            self::assertEquals($expected, $once['articles'][$i]);
        }
        foreach ($before['article_seo_meta'] as $i => $row) {
            $expected = clone $row;
            if (in_array($row->article_id, [254, 265], true)) {
                $expected->is_indexable = 1;
                $expected->robots = 'index,follow';
            }
            self::assertEquals($expected, $once['article_seo_meta'][$i]);
        }
        foreach (['article_translation_revisions', 'review_attestations', 'review_attestation_target_evidences'] as $table) {
            self::assertEquals($before[$table], $once[$table]);
        }
        $this->app->make(AuditLogger::class)->shouldHaveReceived('log')->twice();
        $this->migration()->down();
        self::assertEquals($once, $this->snapshot());
    }

    public function test_changed_reviewed_payload_refuses_both_targets_atomically(): void
    {
        $this->seedArticles();
        DB::table('articles')->where('id', 265)->update(['content_md' => 'Unreviewed replacement']);
        DB::table('article_translation_revisions')->where('id', 849)->update(['content_md' => 'Unreviewed replacement']);
        $this->assertRejectedWithoutChanges();
    }

    public function test_missing_article_or_revision_approval_refuses_activation(): void
    {
        $this->seedArticles();
        foreach (['article:App\\Models\\Article:265', 'article_translation_revision:App\\Models\\ArticleTranslationRevision:849'] as $identity) {
            DB::table('review_attestation_target_evidences')->where('target_identity', $identity)->delete();
            $this->assertRejectedWithoutChanges();
            $record = str_starts_with($identity, 'article:')
                ? Article::withoutGlobalScopes()->findOrFail(265)
                : ArticleTranslationRevision::withoutGlobalScopes()->findOrFail(849);
            $this->approve(str_starts_with($identity, 'article:') ? 'article' : 'article_translation_revision', $record);
        }
    }

    public function test_stale_english_source_refuses_both_targets_atomically(): void
    {
        $this->seedArticles();
        DB::table('articles')->where('id', 264)->update(['source_version_hash' => str_repeat('b', 64)]);
        $this->assertRejectedWithoutChanges();
    }

    public function test_wrong_source_revision_identity_is_rejected_even_with_approved_payload(): void
    {
        $this->seedArticles();
        DB::table('article_translation_revisions')->where('id', 848)->update(['article_id' => 259]);
        $this->approve('article_translation_revision', ArticleTranslationRevision::withoutGlobalScopes()->findOrFail(848));
        $this->assertRejectedWithoutChanges();
    }

    public function test_wrong_canonical_or_unpublished_target_is_rejected(): void
    {
        $this->seedArticles();
        DB::table('article_seo_meta')->where('article_id', 265)->update(['canonical_url' => 'https://fermatmind.com/en/articles/wrong']);
        $this->assertRejectedWithoutChanges();
        DB::table('article_seo_meta')->where('article_id', 265)->update(['canonical_url' => 'https://fermatmind.com/zh/articles/a-levels-t-levels-apprenticeship-choose-at-16-england']);
        DB::table('articles')->where('id', 254)->update(['status' => 'draft']);
        $this->assertRejectedWithoutChanges();
    }

    public function test_archived_or_not_yet_published_target_is_rejected(): void
    {
        $this->seedArticles();
        DB::table('articles')->where('id', 265)->update(['lifecycle_state' => 'archived']);
        $this->assertRejectedWithoutChanges();
        DB::table('articles')->where('id', 265)->update(['lifecycle_state' => 'active', 'published_at' => now()->addDay()]);
        $this->assertRejectedWithoutChanges();
    }

    public function test_environment_without_either_target_does_not_create_or_publish_content(): void
    {
        $before = $this->snapshot();
        $this->activate();
        self::assertEquals($before, $this->snapshot());
        $this->app->make(AuditLogger::class)->shouldNotHaveReceived('log');
    }

    public function test_environment_with_only_one_existing_target_releases_only_that_article(): void
    {
        $this->seedArticles();
        DB::table('articles')->where('id', 265)->delete();
        $this->activate();
        self::assertEquals(1, DB::table('articles')->where('id', 254)->value('is_indexable'));
        self::assertNull(DB::table('articles')->where('id', 265)->first());
        $this->app->make(AuditLogger::class)->shouldHaveReceived('log')->once();
    }

    public function test_same_slug_staging_object_without_this_review_is_not_released(): void
    {
        $this->seedArticles();
        DB::table('articles')->where('id', 254)->update(['id' => 205, 'working_revision_id' => 466, 'published_revision_id' => 466]);
        DB::table('articles')->where('id', 265)->delete();
        $before = $this->snapshot();
        $this->activate();
        self::assertEquals($before, $this->snapshot());
        $this->app->make(AuditLogger::class)->shouldNotHaveReceived('log');
    }

    public function test_target_revision_drift_cannot_reuse_the_prior_release_scope(): void
    {
        $this->seedArticles();
        DB::table('articles')->where('id', 265)->update(['published_revision_id' => 848]);
        $this->assertRejectedWithoutChanges();
    }

    public function test_cache_failure_after_commit_is_repaired_without_rewriting_or_reauditing(): void
    {
        $this->seedArticles();
        $manager = Cache::getFacadeRoot();
        $cache = Mockery::mock($manager)->makePartial();
        $attempts = 0;
        $cache->shouldReceive('forever')->with('public:articles:list:v1:generation', Mockery::type('string'))
            ->twice()->andReturnUsing(function (string $key, string $value) use (&$attempts, $manager): bool {
                if (++$attempts === 1) {
                    throw new RuntimeException('cache_fixture_failure');
                }

                return $manager->forever($key, $value);
            });
        Cache::swap($cache);
        try {
            $this->activate();
            self::fail('Expected the post-commit cache failure.');
        } catch (RuntimeException $error) {
            self::assertSame('cache_fixture_failure', $error->getMessage());
        }
        self::assertEquals(1, DB::table('articles')->where('id', 254)->value('is_indexable'));
        self::assertEquals(1, DB::table('articles')->where('id', 265)->value('is_indexable'));
        $committed = $this->snapshot();
        $this->activate();
        self::assertSame(2, $attempts);
        self::assertEquals($committed, $this->snapshot());
        $this->app->make(AuditLogger::class)->shouldHaveReceived('log')->twice();
    }

    public function test_audit_failure_during_second_write_rolls_back_both_releases(): void
    {
        $this->seedArticles();
        $before = $this->snapshot();
        $calls = 0;
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->twice()->andReturnUsing(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('audit_fixture_failure');
            }
        });
        $this->app->instance(AuditLogger::class, $logger);
        try {
            $this->activate();
            self::fail('Expected the audit failure inside the transaction.');
        } catch (RuntimeException $error) {
            self::assertSame('audit_fixture_failure', $error->getMessage());
        }
        self::assertSame(2, $calls);
        self::assertEquals($before, $this->snapshot());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_15_060000_activate_reviewed_c07_c11_chinese_indexability.php');
    }

    private function activate(): void
    {
        $this->migration()->up();
    }

    private function assertRejectedWithoutChanges(): void
    {
        $before = $this->snapshot();
        try {
            $this->activate();
            self::fail('Expected a fail-closed qualification rejection.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Reviewed Chinese article', $error->getMessage());
        }
        self::assertEquals($before, $this->snapshot());
        $this->app->make(AuditLogger::class)->shouldNotHaveReceived('log');
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['articles', 'article_translation_revisions', 'article_seo_meta', 'review_attestations', 'review_attestation_target_evidences'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return $rows;
    }

    private function approve(string $surface, Model $record): void
    {
        $target = app(CmsEditorialReviewAttestationService::class)->targets([['surface_id' => $surface, 'record' => $record]])[0];
        $id = DB::table('review_attestations')->insertGetId(['review_mode' => 'solo_owner',
            'review_source' => config('review_governance.attestation.review_source'), 'decision' => 'approved_all']);
        DB::table('review_attestation_target_evidences')->insert(['review_attestation_id' => $id,
            ...$target, 'target_decision' => 'approved']);
    }

    private function seedArticles(): void
    {
        foreach ([254 => ['eq60-score-and-results-guide', 'zh-CN', null, 785],
            259 => ['eq60-score-and-results-guide', 'en', 254, 853],
            264 => ['a-levels-t-levels-apprenticeship-choose-at-16-england', 'en', null, 848],
            265 => ['a-levels-t-levels-apprenticeship-choose-at-16-england', 'zh-CN', 264, 849],
            270 => ['eq60-score-and-results-guide', 'zh-CN', null, 900]] as $id => [$slug, $locale, $source, $revision]) {
            $sourceLocale = in_array($id, [264, 265], true) ? 'en' : 'zh-CN';
            DB::table('articles')->insert(['id' => $id, 'org_id' => $id === 270 ? 7 : 0, 'slug' => $slug, 'locale' => $locale,
                'source_locale' => $sourceLocale, 'source_article_id' => $source, 'translation_group_id' => $slug,
                'source_version_hash' => str_repeat('a', 64), 'translated_from_version_hash' => $source ? str_repeat('a', 64) : null,
                'working_revision_id' => $revision, 'published_revision_id' => $revision,
                'content_md' => 'Reviewed fixture '.$id, 'published_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
            DB::table('article_translation_revisions')->insert(['id' => $revision, 'org_id' => $id === 270 ? 7 : 0,
                'article_id' => $id, 'locale' => $locale, 'content_md' => 'Reviewed fixture '.$id,
                'translated_from_version_hash' => $source ? str_repeat('a', 64) : null,
                'approved_at' => now()->subDay(), 'published_at' => now()->subDay()]);
            DB::table('article_seo_meta')->insert(['id' => $id, 'org_id' => $id === 270 ? 7 : 0, 'article_id' => $id,
                'locale' => $locale, 'canonical_url' => 'https://fermatmind.com/'.($locale === 'zh-CN' ? 'zh' : 'en').'/articles/'.$slug]);
            $this->approve('article', Article::withoutGlobalScopes()->withTrashed()->findOrFail($id));
            $this->approve('article_translation_revision', ArticleTranslationRevision::withoutGlobalScopes()->findOrFail($revision));
        }
    }
}
