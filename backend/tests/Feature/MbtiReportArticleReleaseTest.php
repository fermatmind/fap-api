<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\MbtiReportArticleRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class MbtiReportArticleReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => 1,
            'ops.content_release_observability.hmac_revalidation_url' => 'https://frontend.example.test/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'fixture-only-secret']);
        Http::preventStrayRequests();
        Http::fake(['frontend.example.test/*' => function ($request) {
            $body = $request->data();
            $key = $body['content']['locale'].':'.$body['content']['slug'];

            return Http::response(['ok' => true, 'revalidated_paths' => $body['cache_signal']['paths'], 'rejected_paths' => [],
                'invalidated_tags' => ['article-detail:'.$key, 'article-seo:'.$key]]);
        }]);
        AdminUser::query()->create(['name' => 'Fixture owner', 'email' => 'owner@example.test', 'password' => 'fixture-password', 'is_active' => 1]);
    }

    public function test_publishes_complete_reviewed_bodies_seo_and_all_faqs_without_media_or_route_changes(): void
    {
        $this->seedBaseline();
        $beforeMedia = DB::table('articles')->orderBy('id')->get(['id', 'slug', 'locale', 'cover_image_url', 'cover_image_alt', 'cover_image_variants'])->toJson();
        $oldBodies = DB::table('article_translation_revisions')->pluck('content_md', 'id')->all();
        $service = app(MbtiReportArticleRelease::class);
        $this->assertSame(['status' => 'ready', 'changed' => 0], $service->publish(true));
        $this->assertSame(['status' => 'published', 'changed' => 2], $service->publish());
        $package = json_decode(file_get_contents(base_path(MbtiReportArticleRelease::ROOT.'/package.json')), true);
        foreach ($package['articles'] as $row) {
            $response = $this->getJson('/api/v0.5/articles/'.$row['identity']['slug'].'?locale='.$row['identity']['locale'].'&org_id=0');
            $response->assertOk()->assertJsonPath('article.title', $row['title'])->assertJsonPath('article.excerpt', $row['excerpt'])
                ->assertJsonPath('article.content_md', file_get_contents(base_path(MbtiReportArticleRelease::ROOT.'/'.$row['file'])))
                ->assertJsonCount(count($row['faq_items']), 'answer_surface_v1.faq_blocks');
            foreach ($row['faq_items'] as $i => $faq) {
                $response->assertJsonPath('answer_surface_v1.faq_blocks.'.$i.'.question', $faq['question'])
                    ->assertJsonPath('answer_surface_v1.faq_blocks.'.$i.'.answer', $faq['answer']);
            }
            $this->assertSame('stale', DB::table('article_translation_revisions')->where('id', $row['baselines']['production']['revision_id'])->value('revision_status'));
        }
        $this->assertSame($beforeMedia, DB::table('articles')->orderBy('id')->get(['id', 'slug', 'locale', 'cover_image_url', 'cover_image_alt', 'cover_image_variants'])->toJson());
        foreach ($oldBodies as $id => $body) {
            $this->assertSame($body, DB::table('article_translation_revisions')->where('id', $id)->value('content_md'));
        }
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], $service->publish());
        $this->assertSame(4, DB::table('article_translation_revisions')->count());
    }

    public function test_foreign_draft_and_concurrent_body_change_are_rejected_without_writes(): void
    {
        $this->seedBaseline();
        DB::table('articles')->where('id', 61)->update(['working_revision_id' => 9999]);
        $this->assertRejected();
        DB::table('articles')->where('id', 61)->update(['working_revision_id' => 885, 'content_md' => 'Another owner edit']);
        $this->assertRejected();
    }

    public function test_later_audit_failure_rolls_back_both_articles_seo_and_revision_history(): void
    {
        $this->seedBaseline();
        $reachedPromotion = false;
        $this->mock(\App\Services\Audit\AuditLogger::class)->shouldReceive('log')->andReturnUsing(function (...$args) use (&$reachedPromotion) {
            if (($args[1] ?? null) === 'mbti_report_articles_reviewed_update') {
                $reachedPromotion = (int) DB::table('articles')->where('id', 10)->value('published_revision_id') !== 779;
                throw new RuntimeException('injected audit failure after promotion');
            }
        });
        $this->assertRejected();
        $this->assertTrue($reachedPromotion);
    }

    public function test_staging_captures_its_own_clean_published_baseline(): void
    {
        $this->seedBaseline('staging');
        $this->app->detectEnvironment(fn () => 'staging');
        $this->assertSame(['status' => 'published', 'changed' => 2], app(MbtiReportArticleRelease::class)->publish());
    }

    public function test_concurrent_seo_and_faq_changes_are_rejected(): void
    {
        $this->seedBaseline();
        $seo = DB::table('article_seo_meta')->where('article_id', 61)->first();
        DB::table('article_seo_meta')->where('article_id', 61)->update(['seo_title' => 'Concurrent editor title']);
        $this->assertRejected();
        DB::table('article_seo_meta')->where('article_id', 61)->update(['seo_title' => $seo->seo_title]);
        $schema = json_decode($seo->schema_json, true);
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'][0]['answer'] = 'Concurrent editor answer';
        DB::table('article_seo_meta')->where('article_id', 61)->update(['schema_json' => json_encode($schema)]);
        $this->assertRejected();
    }

    public function test_tampered_or_later_revision_cannot_inherit_extended_faq_projection(): void
    {
        $this->seedBaseline();
        app(MbtiReportArticleRelease::class)->publish();
        $article = Article::withoutGlobalScopes()->with('publishedRevision')->findOrFail(61);
        $metadata = $article->seoMeta->schema_json['editorial_package_v1'];
        $this->assertSame(8, MbtiReportArticleRelease::faqLimit($article, $metadata, 6));
        $metadata['mbti_report_reviewed_v1']['body_sha256'] = 'tampered';
        $this->assertSame(6, MbtiReportArticleRelease::faqLimit($article, $metadata, 6));
        $metadata = $article->seoMeta->schema_json['editorial_package_v1'];
        $article->publishedRevision->authority_package_sha256 = 'later-package';
        $this->assertSame(6, MbtiReportArticleRelease::faqLimit($article, $metadata, 6));
    }

    public function test_reviewed_legacy_source_uses_revision_authority_and_preserves_holds(): void
    {
        $this->seedBaseline('staging');
        $this->app->detectEnvironment(fn () => 'staging');
        foreach (['', 'machine_draft', 'human_review', 'stale', 'archived', 'unknown'] as $state) {
            DB::table('articles')->where('id', 10)->update(['translation_status' => $state]);
            $this->assertRejected();
        }
        DB::table('articles')->where('id', 10)->update(['translation_status' => 'approved']);
        $revision = DB::table('article_translation_revisions')->where('id', 440)->first();
        DB::table('article_translation_revisions')->where('id', 440)->update(['reviewed_at' => null, 'approved_at' => null]);
        $this->assertRejected();
        DB::table('article_translation_revisions')->where('id', 440)->update(['reviewed_at' => $revision->reviewed_at]);
        $hold = \App\Models\EditorialReview::query()->create(['org_id' => 0, 'content_type' => 'article', 'content_id' => 10,
            'workflow_state' => 'human_review']);
        $this->assertRejected();
        $hold->delete();
        foreach (['approved', 'published'] as $state) {
            DB::table('articles')->where('id', 10)->update(['translation_status' => $state]);
            $this->assertSame(['status' => 'ready', 'changed' => 0], app(MbtiReportArticleRelease::class)->publish(true));
        }
        $this->assertSame(['status' => 'published', 'changed' => 2], app(MbtiReportArticleRelease::class)->publish());
        $this->assertSame('source', DB::table('articles')->where('id', 10)->value('translation_status'));
    }

    public function test_production_uses_the_same_reviewed_revision_source_authority(): void
    {
        $this->seedBaseline();
        DB::table('articles')->whereIn('id', [10, 61])->update(['translation_status' => 'approved']);
        $this->assertSame(['status' => 'published', 'changed' => 2], app(MbtiReportArticleRelease::class)->publish());
        $this->assertSame(2, DB::table('articles')->where('translation_status', 'source')->count());
    }

    public function test_json_object_key_reordering_preserves_publication_and_faq_projection(): void
    {
        $this->seedBaseline();
        // Emulate MySQL JSON storage: object keys may be reordered, array order stays.
        foreach ([\App\Models\ArticleSeoMeta::class => 'schema_json', ArticleTranslationRevision::class => 'authority_metadata_json'] as $model => $field) {
            $model::retrieved(static function ($record) use ($field): void {
                $value = $record->getAttribute($field);
                if (is_array($value)) {
                    $attrs = $record->getAttributes();
                    $attrs[$field] = \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($value);
                    $record->setRawAttributes($attrs, true);
                }
            });
        }
        $service = app(MbtiReportArticleRelease::class);
        $this->assertSame(['status' => 'published', 'changed' => 2], $service->publish());
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], $service->publish());
        $article = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail(61);
        $metadata = $article->seoMeta->schema_json['editorial_package_v1'];
        $this->assertSame(8, MbtiReportArticleRelease::faqLimit($article, $metadata, 6));
        $metadata['answer_surface_v1']['faq_items'] = array_reverse($metadata['answer_surface_v1']['faq_items']);
        $this->assertSame(6, MbtiReportArticleRelease::faqLimit($article, $metadata, 6));
        $metadata = $article->seoMeta->schema_json['editorial_package_v1'];
        array_pop($metadata['answer_surface_v1']['faq_items']);
        $this->assertSame(6, MbtiReportArticleRelease::faqLimit($article, $metadata, 6));
        $metadata = $article->seoMeta->schema_json['editorial_package_v1'];
        $metadata['answer_surface_v1']['faq_items'][0]['answer'] .= ' changed';
        $this->assertSame(6, MbtiReportArticleRelease::faqLimit($article, $metadata, 6));
    }

    public function test_real_media_change_still_rejects_the_entire_publication(): void
    {
        $this->seedBaseline();
        $changed = false;
        Article::updated(static function ($article) use (&$changed): void {
            if ((int) $article->id === 10 && $article->isDirty('title')) {
                $changed = true;
                DB::table('articles')->where('id', 10)->update(['cover_image_url' => 'https://example.test/changed.jpg']);
            }
        });
        $this->assertRejected();
        $this->assertTrue($changed);
    }

    private function assertRejected(): void
    {
        $state = fn () => json_encode(array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), [
            'articles', 'article_translation_revisions', 'article_seo_meta', 'editorial_reviews', 'review_attestations', 'content_material_decisions',
        ]), JSON_THROW_ON_ERROR);
        $before = $state();
        try {
            app(MbtiReportArticleRelease::class)->publish();
            $this->fail('Must reject a drifting package or failed transaction.');
        } catch (RuntimeException) {
            $this->assertSame($before, $state());
        }
    }

    private function seedBaseline(string $environment = 'production'): void
    {
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/mbti_report_article_baselines.json')), true)[$environment] as $row) {
            $seo = $row['seo_meta'];
            $revisionId = $row['published_revision_id'];
            $faqItems = array_map(fn ($faq) => ['question' => $faq['question'], 'answer' => $faq['answer']], $row['faq_items']);
            $article = array_intersect_key($row, array_flip(Schema::getColumnListing('articles')));
            $article['published_revision_id'] = null;
            $article['working_revision_id'] = null;
            $article['translation_status'] = 'source';
            $article['lifecycle_state'] = 'active';
            $this->insert('articles', $article);
            $model = Article::withoutGlobalScopes()->findOrFail($row['id']);
            $hash = $model->computeSourceVersionHash();
            (new ArticleTranslationRevision)->forceFill(['id' => $revisionId, 'org_id' => 0, 'article_id' => $model->id,
                'source_article_id' => $model->id, 'translation_group_id' => $model->translation_group_id, 'locale' => $model->locale,
                'source_locale' => $model->locale, 'revision_number' => 1, 'revision_status' => 'published', 'source_version_hash' => $hash,
                'translated_from_version_hash' => $hash, 'title' => $row['title'], 'excerpt' => $row['excerpt'], 'content_md' => $row['content_md'],
                'seo_title' => $seo['seo_title'], 'seo_description' => $seo['seo_description'],
                'reviewed_at' => $row['last_reviewed_at'], 'published_at' => now()])->save();
            DB::table('articles')->where('id', $model->id)->update(['working_revision_id' => $revisionId, 'published_revision_id' => $revisionId, 'source_version_hash' => $hash]);
            $seo = array_intersect_key($seo, array_flip(Schema::getColumnListing('article_seo_meta')));
            $seo['schema_json'] = ['editorial_package_v1' => ['answer_surface_policy' => 'editor_supplied', 'answer_surface_visibility' => 'visible',
                'answer_surface_v1' => ['faq_items' => $faqItems]], '@graph' => [['@type' => 'FAQPage', 'mainEntity' => []]]];
            $this->insert('article_seo_meta', $seo);
        }
    }

    private function insert(string $table, array $row): void
    {
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $row[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($value && str_ends_with($key, '_at')) {
                $row[$key] = \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s');
            }
        }
        DB::table($table)->insert($row);
    }
}
