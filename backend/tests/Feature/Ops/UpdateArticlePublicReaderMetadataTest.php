<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\UpdateArticlePublicReaderMetadata as Updater;
use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScaleRegistry;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class UpdateArticlePublicReaderMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_default_preflight_preserves_all_native_state(): void
    {
        $article = $this->article();
        $state = Updater::state($article);
        $p = $this->package($article);
        $this->assertSame(0, $this->runPackage($p));
        $this->assertSame(0, $this->runPackage($p));
        $this->assertSame($state, Updater::state($article->fresh()));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_native_faq_merge_preserves_old_opaque_review_and_noindex_and_restores(): void
    {
        $article = $this->article();
        $actor = $this->actor();
        $before = Updater::state($article);
        $p = $this->package($article);
        $this->assertSame(0, $this->runPackage($p, $actor), Artisan::output());
        $after = Updater::state($article->fresh());
        $this->assertSame($before['history'], $after['history']);
        $this->assertSame($article->published_revision_id, $after['article']['published_revision_id']);
        $this->assertFalse($article->fresh()->is_indexable);
        $this->assertSame('noindex,nofollow', $article->fresh()->seoMeta->robots);
        $this->assertSame('opaque-original', data_get($article->fresh()->seoMeta->schema_json, 'editorial_package_v1.review_approval_v1.token'));
        $audit = AuditLog::withoutGlobalScopes()->sole();
        $this->assertNull($audit->actor_admin_id);
        $this->assertSame($actor->id, $audit->meta_json['authorized_operator_id']);
        $this->assertFalse($audit->meta_json['editorial_attestation_created']);
        $restore = $this->package($article->fresh());
        $restore['candidate']['schema_json'] = json_decode($before['seo']['schema_json'], true);
        $this->assertSame(0, $this->runPackage($restore, $actor), Artisan::output());
        $this->assertSame(json_decode($before['seo']['schema_json'], true), $article->fresh()->seoMeta->schema_json);
        $this->assertSame($before['history'], Updater::state($article->fresh())['history']);
    }

    public function test_faq_array_replaces_schema_array_without_changing_fallback_metadata(): void
    {
        $article = $this->article();
        $items = array_fill(0, 8, ['question' => 'Old question', 'answer' => 'Old answer']);
        $article->cover_image_variants = ['editorial_package_v1' => ['answer_surface_v1' => ['faq_items' => $items]]];
        $article->saveQuietly();
        $p = $this->package($article->fresh());
        $this->assertSame(0, $this->runPackage($p, $this->actor()), Artisan::output());
        $this->assertCount(1, data_get($article->fresh()->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
        $this->assertSame($items, data_get($article->fresh()->cover_image_variants, 'editorial_package_v1.answer_surface_v1.faq_items'));
        $response = app(ArticleController::class)->show(Request::create('/api/v0.5/articles/'.$article->slug, 'GET',
            ['locale' => 'en', 'org_id' => 0]), $article->slug);
        $this->assertSame(200, $response->getStatusCode());
        $faq = data_get($response->getData(true), 'answer_surface_v1.faq_blocks');
        $this->assertCount(1, $faq);
        $this->assertSame('An existing question?', $faq[0]['question']);
    }

    public function test_metadata_drift_and_protected_flag_changes_are_refused(): void
    {
        $article = $this->article();
        $p = $this->package($article);
        $p['candidate']['schema_json']['editorial_package_v1']['schema_enabled'] = true;
        $this->assertSame(1, $this->runPackage($p));
        $p = $this->package($article);
        $article->excerpt = 'Concurrent body context edit';
        $article->saveQuietly();
        $this->assertSame(1, $this->runPackage($p, $this->actor()));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_cap_hold_and_missing_seo_do_not_get_bypassed(): void
    {
        $article = $this->article();
        $p = $this->package($article);
        $p['candidate']['schema_json']['editorial_package_v1']['answer_surface_v1']['faq_items'] = array_fill(0, 7, ['question' => 'Q', 'answer' => 'A']);
        $this->assertSame(1, $this->runPackage($p));
        $schema = $article->seoMeta->schema_json;
        $schema['editorial_package_v1']['answer_surface_visibility'] = 'disabled';
        $article->seoMeta->update(['schema_json' => $schema]);
        $this->assertSame(1, $this->runPackage($this->package($article->fresh())));
        $article->seoMeta->delete();
        $this->assertSame(1, $this->runPackage($p));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_existing_native_baseline_then_faq_update_preserves_noindex_and_revision_authority(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->article();
        $beforeArticle = $article->getAttributes();
        $beforeHistory = Updater::state($article)['history'];
        $article->seoMeta->delete();
        $locks = ['--article-id' => $article->id, '--translation-group-id' => $article->translation_group_id,
            '--expected-slug' => $article->slug, '--expected-canonical' => '/en/articles/'.$article->slug,
            '--json' => true];
        $this->assertSame(0, Artisan::call('articles:ensure-seo-meta-baseline', $locks), Artisan::output());
        $this->assertSame(0, ArticleSeoMeta::withoutGlobalScopes()->count());
        $this->assertSame(0, Artisan::call('articles:ensure-seo-meta-baseline', [...$locks, '--execute' => true,
            '--no-publish' => true, '--no-schema' => true, '--no-hreflang' => true,
            '--no-search' => true, '--no-sitemap-llms-change' => true]), Artisan::output());
        $article = $article->fresh();
        $this->assertSame($beforeArticle, $article->getAttributes());
        $this->assertSame('noindex,nofollow', $article->seoMeta->robots);
        $this->assertFalse($article->seoMeta->is_indexable);
        $this->assertNull($article->seoMeta->schema_json);
        $this->assertSame($beforeHistory, Updater::state($article)['history']);
        $p = $this->package($article);
        $this->assertSame(0, $this->runPackage($p, $this->actor()), Artisan::output());
        $this->assertSame($beforeArticle, $article->fresh()->getAttributes());
        $this->assertSame($beforeHistory, Updater::state($article->fresh())['history']);
        $this->assertSame('noindex,nofollow', $article->fresh()->seoMeta->robots);
        $this->assertCount(1, data_get($article->fresh()->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
    }

    public function test_canonical_test_target_and_alt_are_native_metadata_only(): void
    {
        $article = $this->article();
        ScaleRegistry::query()->create(['code' => 'IQ_TEST', 'org_id' => 0, 'primary_slug' => 'iq-test',
            'slugs_json' => ['iq-test'],
            'driver_type' => 'test', 'default_pack_id' => 'test', 'default_region' => 'CN_MAINLAND',
            'default_locale' => 'en', 'default_dir_version' => 'v1', 'is_public' => true, 'is_active' => true]);
        $p = $this->package($article);
        $p['candidate']['article_fields'] = ['related_test_slug' => 'missing-test'];
        $this->assertSame(1, $this->runPackage($p));
        $p['candidate']['article_fields'] = ['related_test_slug' => 'iq-test', 'cover_image_alt' => 'Neutral cognitive task illustration'];
        $history = Updater::state($article)['history'];
        $this->assertSame(0, $this->runPackage($p, $this->actor()), Artisan::output());
        $this->assertSame('iq-test', $article->fresh()->related_test_slug);
        $this->assertSame('Neutral cognitive task illustration', $article->fresh()->cover_image_alt);
        $this->assertSame($history, Updater::state($article->fresh())['history']);
    }

    public function test_audit_failure_rolls_back_both_metadata_models(): void
    {
        $article = $this->article();
        $before = Updater::state($article);
        $p = $this->package($article);
        $p['candidate']['article_fields'] = ['cover_image_alt' => 'Updated alt'];
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        $this->assertSame(1, $this->runPackage($p, $this->actor()));
        $this->assertSame($before, Updater::state($article->fresh()));
    }

    public function test_broken_public_pointer_or_future_revision_cannot_update_metadata(): void
    {
        $article = $this->article();
        $p = $this->package($article);
        $article->publishedRevision->update(['article_id' => 999]);
        $this->assertSame(1, $this->runPackage($p));
        $article->publishedRevision->update(['article_id' => $article->id, 'published_at' => now()->addDay()]);
        $this->assertSame(1, $this->runPackage($this->package($article->fresh())));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_production_release_and_inactive_operator_are_refused(): void
    {
        $article = $this->article();
        $p = $this->package($article);
        $actor = $this->actor();
        $actor->update(['is_active' => 0]);
        $this->assertSame(1, $this->runPackage($p, $actor));
        $actor->update(['is_active' => 1]);
        $environment = app()['env'];
        app()['env'] = 'production';
        try {
            $this->assertSame(1, $this->runPackage($p, $actor));
            $this->assertSame('production_release_drift', json_decode(Artisan::output(), true)['error']);
        } finally {
            app()['env'] = $environment;
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    private function article(): Article
    {
        $article = Article::query()->create(['org_id' => 0, 'slug' => 'native-reader-test', 'locale' => 'en',
            'title' => 'Reader test', 'content_md' => 'Existing public text', 'status' => 'published',
            'is_public' => true, 'is_indexable' => false, 'translation_group_id' => 'reader-fixture', 'translation_status' => 'source', 'source_locale' => 'en']);
        $revision = ArticleTranslationRevision::query()->create(['org_id' => 0, 'article_id' => $article->id,
            'source_article_id' => $article->id, 'locale' => 'en', 'source_locale' => 'en', 'revision_number' => 1,
            'translation_group_id' => 'reader-fixture',
            'revision_status' => 'source', 'title' => $article->title, 'content_md' => $article->content_md]);
        $article->forceFill(['published_revision_id' => $revision->id, 'working_revision_id' => $revision->id])->saveQuietly();
        ArticleSeoMeta::query()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => 'en',
            'canonical_url' => 'https://fermatmind.com/en/articles/native-reader-test', 'robots' => 'noindex,nofollow',
            'is_indexable' => false, 'schema_json' => ['unrelated' => ['keep' => true], 'editorial_package_v1' => [
                'schema_enabled' => false, 'review_approval_v1' => ['token' => 'opaque-original']]]]);

        return $article->fresh();
    }

    private function package(Article $article): array
    {
        $state = Updater::state($article);
        $schema = $article->seoMeta->schema_json;
        $schema['editorial_package_v1']['answer_surface_policy'] = 'editor_supplied';
        $schema['editorial_package_v1']['answer_surface_visibility'] = 'visible';
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = [['question' => 'An existing question?', 'answer' => 'A verified editorial answer.']];

        return ['schema' => 'article_public_reader_metadata.v1', 'identity' => $article->only(['id', 'org_id', 'slug', 'locale', 'published_revision_id', 'working_revision_id']),
            'before_sha256' => Hash::hash($state), 'candidate' => ['schema_json' => $schema, 'article_fields' => []]];
    }

    private function actor(): AdminUser
    {
        $actor = AdminUser::create(['name' => 'Local operator', 'email' => uniqid().'@example.test', 'password' => 'test-secret', 'is_active' => 1]);
        $role = Role::create(['name' => uniqid('metadata-')]);
        $permission = Permission::firstOrCreate(['name' => PermissionNames::ADMIN_CONTENT_PUBLISH]);
        $role->permissions()->attach($permission);
        $actor->roles()->attach($role);

        return $actor;
    }

    private function runPackage(array $package, ?AdminUser $actor = null): int
    {
        $file = tempnam(sys_get_temp_dir(), 'article-reader-');
        $raw = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        file_put_contents($file, $raw);
        $sha = hash('sha256', $raw);
        try {
            return Artisan::call('articles:update-public-reader-metadata', ['--file' => $file, '--sha256' => $sha,
                ...($actor ? ['--execute' => true, '--admin-user-id' => $actor->id, '--confirm' => $sha] : [])]);
        } finally {
            unlink($file);
        }
    }
}
