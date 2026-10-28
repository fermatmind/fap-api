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
use App\Services\Cms\ArticlePublicListReadCache;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class UpdateArticlePublicReaderMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_editorial_author_previews_and_updates_only_locked_metadata_then_invalidates_lists(): void
    {
        $article = $this->article();
        $article->forceFill(['author_name' => 'Existing author'])->saveQuietly();
        $before = Updater::state($article->fresh());
        $package = $this->package($article->fresh());
        $package['candidate'] = ['schema_json' => $article->fresh()->seoMeta->schema_json,
            'article_fields' => ['author_name' => 'FermatMind Editorial']];
        $generationKey = ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation';
        Cache::forever($generationKey, 'before-author-update');
        $exit = $this->runPackage($package);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertJson($output);
        $preview = json_decode($output, true);
        $this->assertSame('FermatMind Editorial', $preview['candidate_author_name']);
        $this->assertArrayHasKey('candidate_jsonld', $preview);
        $this->assertSame($before, Updater::state($article->fresh()));
        $this->assertSame('before-author-update', Cache::get($generationKey));
        $this->assertSame(0, $this->runPackage($package, $this->actor()), Artisan::output());
        $this->assertNotSame('before-author-update', Cache::get($generationKey));
        $after = Updater::state($article->fresh());
        $before['article']['author_name'] = 'FermatMind Editorial';
        unset($before['article']['updated_at'], $after['article']['updated_at'], $before['seo']['updated_at'], $after['seo']['updated_at']);
        $this->assertSame($before, $after);
    }

    public function test_wrong_locale_or_personal_author_is_rejected_and_failure_does_not_invalidate_lists(): void
    {
        $article = $this->article();
        $before = Updater::state($article);
        $generationKey = ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation';
        Cache::forever($generationKey, 'before-author-update');
        foreach (['费马测试编辑部', 'A named person'] as $invalid) {
            $package = $this->package($article);
            $package['candidate']['article_fields'] = ['author_name' => $invalid];
            $this->assertSame(1, $this->runPackage($package, $this->actor()));
            $this->assertSame('localized_editorial_author_required', json_decode(Artisan::output(), true)['error']);
            $this->assertSame('before-author-update', Cache::get($generationKey));
            $this->assertSame($before, Updater::state($article->fresh()));
        }
    }

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
        $schema = $article->seoMeta->schema_json;
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = [['question' => 'An existing question?', 'answer' => 'Original answer.']];
        $article->seoMeta->update(['schema_json' => $schema]);
        $items = array_fill(0, 8, ['question' => 'Old question', 'answer' => 'Old answer']);
        $article->cover_image_variants = ['editorial_package_v1' => ['answer_surface_v1' => ['faq_items' => $items]]];
        $article->saveQuietly();
        $p = $this->package($article->fresh());
        $this->assertSame(0, $this->runPackage($p, $this->actor()), Artisan::output());
        $this->assertCount(1, data_get($article->fresh()->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
        $this->assertJsonValueSame($items, data_get($article->fresh()->cover_image_variants, 'editorial_package_v1.answer_surface_v1.faq_items'));
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

    public function test_unchanged_seven_faqs_allow_a_test_entry_update_without_touching_authority(): void
    {
        $article = $this->article();
        $schema = $this->package($article)['candidate']['schema_json'];
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = $this->faqItems(7);
        $article->seoMeta->update(['schema_json' => $schema]);
        ScaleRegistry::query()->create(['code' => 'IQ_TEST', 'org_id' => 0, 'primary_slug' => 'iq-test',
            'slugs_json' => ['iq-test'], 'driver_type' => 'test', 'default_pack_id' => 'test',
            'default_region' => 'CN_MAINLAND', 'default_locale' => 'en', 'default_dir_version' => 'v1',
            'is_public' => true, 'is_active' => true]);
        $article = $article->fresh();
        $before = Updater::state($article);
        $publicBefore = app(ArticleController::class)->show(Request::create('/api/v0.5/articles/'.$article->slug, 'GET',
            ['locale' => 'en', 'org_id' => 0]), $article->slug)->getData(true);
        $package = $this->package($article);
        $package['candidate'] = ['schema_json' => $schema, 'article_fields' => ['related_test_slug' => 'iq-test']];
        $this->assertSame(0, $this->runPackage($package));
        $this->assertSame($before, Updater::state($article->fresh()));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->runPackage($package, $this->actor()), Artisan::output());
        $after = Updater::state($article->fresh());
        $before['article']['related_test_slug'] = 'iq-test';
        unset($before['article']['updated_at'], $after['article']['updated_at'], $before['seo']['updated_at'], $after['seo']['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->count());
        $response = app(ArticleController::class)->show(Request::create('/api/v0.5/articles/'.$article->slug, 'GET',
            ['locale' => 'en', 'org_id' => 0]), $article->slug);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(7, data_get($article->fresh()->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
        $this->assertSame(data_get($publicBefore, 'answer_surface_v1.faq_blocks'), data_get($response->getData(true), 'answer_surface_v1.faq_blocks'));
    }

    public function test_unchanged_large_fallback_faqs_can_pass_through(): void
    {
        $article = $this->article();
        $article->cover_image_variants = ['editorial_package_v1' => ['answer_surface_v1' => ['faq_items' => $this->faqItems(7)]]];
        $article->saveQuietly();
        $article = $article->fresh();
        $before = Updater::state($article);
        $package = $this->package($article);
        $package['candidate']['schema_json'] = $article->seoMeta->schema_json;
        $package['candidate']['article_fields'] = ['reading_minutes' => 20];
        $this->assertSame(0, $this->runPackage($package, $this->actor()), Artisan::output());
        $after = Updater::state($article->fresh());
        $before['article']['reading_minutes'] = 20;
        unset($before['article']['updated_at'], $after['article']['updated_at'], $before['seo']['updated_at'], $after['seo']['updated_at']);
        $this->assertSame($before, $after);
    }

    public function test_inventory_changes_to_existing_seven_faqs_are_refused_without_writes(): void
    {
        $article = $this->article();
        $schema = $this->package($article)['candidate']['schema_json'];
        $items = $this->faqItems(7);
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = $items;
        $article->seoMeta->update(['schema_json' => $schema]);
        $article = $article->fresh();
        $before = Updater::state($article);
        $actor = $this->actor();
        $changed = $items;
        $changed[0]['question'] = 'Changed question?';
        $changedKey = $items;
        $changedKey[0]['key'] = 'changed';
        foreach ([$changed, $changedKey, array_reverse($items), array_slice($items, 0, 6), [...$items, $items[0]]] as $replacement) {
            $package = $this->package($article);
            $package['candidate']['schema_json'] = $schema;
            $package['candidate']['schema_json']['editorial_package_v1']['answer_surface_v1']['faq_items'] = $replacement;
            $this->assertSame(1, $this->runPackage($package, $actor));
            $this->assertSame('ordinary_faq_scope_invalid', json_decode(Artisan::output(), true)['error']);
            $this->assertSame($before, Updater::state($article->fresh()));
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_six_faqs_cannot_be_expanded_to_seven(): void
    {
        $article = $this->article();
        $schema = $this->package($article)['candidate']['schema_json'];
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = $this->faqItems(6);
        $article->seoMeta->update(['schema_json' => $schema]);
        $article = $article->fresh();
        $before = Updater::state($article);
        $package = $this->package($article);
        $package['candidate']['schema_json'] = $schema;
        $package['candidate']['schema_json']['editorial_package_v1']['answer_surface_v1']['faq_items'] = $this->faqItems(7);
        $this->assertSame(1, $this->runPackage($package, $this->actor()));
        $this->assertSame('ordinary_faq_scope_invalid', json_decode(Artisan::output(), true)['error']);
        $this->assertSame($before, Updater::state($article->fresh()));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedExistingInventorySizes')]
    public function test_long_faq_answer_updates_cannot_change_other_reader_authority(int $count): void
    {
        $article = $this->article();
        $schema = $this->package($article)['candidate']['schema_json'];
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = $this->faqItems($count);
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'][0]['opaque'] = ['source' => 'keep'];
        $article->seoMeta->update(['schema_json' => $schema]);
        $article = $article->fresh();
        $before = Updater::state($article);
        $actor = $this->actor();
        foreach (['policy', 'visibility', 'article_field', 'opaque', 'too_long', 'answer_key', 'integer', 'boolean', 'array', 'shrink_integer'] as $case) {
            $package = $this->package($article);
            $package['candidate']['article_fields'] = [];
            $package['candidate']['schema_json'] = $schema;
            $candidate = &$package['candidate']['schema_json']['editorial_package_v1'];
            $candidate['answer_surface_v1']['faq_items'][0]['answer'] = 'An updated eligible reference answer.';
            if ($case === 'policy') {
                $candidate['answer_surface_policy'] = 'changed';
            } elseif ($case === 'visibility') {
                $candidate['answer_surface_visibility'] = 'changed';
            } elseif ($case === 'article_field') {
                $package['candidate']['article_fields'] = ['reading_minutes' => 20];
            } elseif ($case === 'opaque') {
                $candidate['answer_surface_v1']['faq_items'][0]['opaque'] = ['source' => 'changed'];
            } elseif ($case === 'too_long') {
                $candidate['answer_surface_v1']['faq_items'][0]['answer'] = str_repeat('a', 10001);
            } elseif ($case === 'integer') {
                $candidate['answer_surface_v1']['faq_items'][0]['answer'] = 123;
            } elseif ($case === 'boolean') {
                $candidate['answer_surface_v1']['faq_items'][0]['answer'] = true;
            } elseif ($case === 'array') {
                $candidate['answer_surface_v1']['faq_items'][0]['answer'] = ['invalid'];
            } elseif ($case === 'shrink_integer') {
                array_pop($candidate['answer_surface_v1']['faq_items']);
                $candidate['answer_surface_v1']['faq_items'][0]['answer'] = 123;
            } else {
                unset($candidate['answer_surface_v1']['faq_items'][0]['answer']);
                $candidate['answer_surface_v1']['faq_items'][0]['a'] = 'A renamed answer key.';
            }
            unset($candidate);
            $this->assertSame(1, $this->runPackage($package, $actor), $case);
            $this->assertSame($before, Updater::state($article->fresh()), $case);
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public static function protectedExistingInventorySizes(): array
    {
        return [[5], [7]];
    }

    private function faqItems(int $count): array
    {
        return array_map(static fn (int $index): array => ['question' => 'Question '.$index.'?', 'answer' => 'Answer '.$index.'.'], range(1, $count));
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
        $article->publishedRevision->forceFill(['revision_status' => 'published'])->saveQuietly();
        $beforeArticle = $article->getAttributes();
        $beforeHistory = Updater::state($article)['history'];
        $article->seoMeta->delete();
        $locks = ['--article-id' => $article->id, '--translation-group-id' => $article->translation_group_id,
            '--expected-slug' => $article->slug, '--expected-canonical' => '/en/articles/'.$article->slug,
            '--json' => true];
        $this->assertSame(0, Artisan::call('articles:ensure-seo-meta-baseline', $locks), Artisan::output());
        $this->assertSame(0, ArticleSeoMeta::withoutGlobalScopes()->count());
        $baselineHash = Hash::hash(\App\Console\Commands\ArticleEnsureSeoMetaBaseline::nativeState($article->fresh()));
        $this->assertSame(0, Artisan::call('articles:ensure-seo-meta-baseline', [...$locks, '--execute' => true,
            '--admin-user-id' => $this->actor()->id, '--expected-state-sha256' => $baselineHash, '--confirm' => $baselineHash,
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
        $p['candidate']['article_fields'] = ['cover_image_alt' => 'Updated alt', 'author_name' => 'FermatMind Editorial'];
        $generationKey = ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation';
        Cache::forever($generationKey, 'before-rollback');
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        $this->assertSame(1, $this->runPackage($p, $this->actor()));
        $this->assertSame('before-rollback', Cache::get($generationKey));
        $this->assertSame($before, Updater::state($article->fresh()));
    }

    public function test_reading_time_override_can_be_cleared_without_republishing_text(): void
    {
        $article = $this->article();
        $actor = $this->actor();
        $before = Updater::state($article);
        $package = $this->package($article);
        $package['candidate']['schema_json'] = $article->seoMeta->schema_json;
        foreach ([0, 1441, '20', 1.5] as $invalid) {
            $package['candidate']['article_fields'] = ['reading_minutes' => $invalid];
            $this->assertSame(1, $this->runPackage($package, $actor));
            $this->assertSame($before, Updater::state($article->fresh()));
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        $package['candidate']['article_fields'] = ['reading_minutes' => 20];
        $this->assertSame(0, $this->runPackage($package));
        $this->assertSame($before, Updater::state($article->fresh()));
        $this->assertSame(0, $this->runPackage($package, $actor));
        $this->assertSame(20, $article->fresh()->reading_minutes);
        $clear = $this->package($article->fresh());
        $clear['candidate']['schema_json'] = $article->fresh()->seoMeta->schema_json;
        $clear['candidate']['article_fields'] = ['reading_minutes' => null];
        $this->assertSame(0, $this->runPackage($clear, $actor));
        $this->assertNull($article->fresh()->reading_minutes);
        $after = Updater::state($article->fresh());
        foreach (['before', 'after'] as $state) {
            unset(${$state}['article']['updated_at'], ${$state}['seo']['updated_at']);
        }
        $this->assertSame($before, $after);
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->count());
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

    public function test_published_provenance_previews_without_writes_and_atomically_releases_only_schema(): void
    {
        foreach (['en', 'zh-CN'] as $locale) {
            [$article, $actor, $package] = $this->provenanceFixture($locale);
            $before = Updater::state($article);
            $auditCount = AuditLog::withoutGlobalScopes()->count();
            $exit = $this->runPackage($package);
            $output = Artisan::output();
            $this->assertSame(0, $exit, $output);
            $preview = json_decode($output, true);
            $this->assertSame($before, Updater::state($article->fresh()));
            $this->assertSame($auditCount, AuditLog::withoutGlobalScopes()->count());
            $this->assertSame(3, $preview['citation_count']);
            $this->assertStringNotContainsString('admin_user:', $output);
            $this->assertStringNotContainsString('source-ledger:', $output);
            $this->assertSame(0, $this->runPackage($package, $actor), Artisan::output());
            $article->refresh()->load('publishedRevision', 'seoMeta');
            $jsonld = app(ArticleSeoService::class)->generateJsonLd($article, $article->publishedRevision);
            $this->assertSame($preview['candidate_jsonld'], $jsonld);
            $fragments = collect($jsonld['@graph']);
            $this->assertSame(['Article', 'BreadcrumbList'], $fragments->pluck('@type')->all());
            $this->assertSame('Organization', data_get($fragments->first(), 'author.@type'));
            $this->assertSame($article->author_name, data_get($fragments->first(), 'author.name'));
            $this->assertSame($article->published_at->toIso8601String(), $fragments->first()['datePublished']);
            $this->assertCount(3, $fragments->first()['citation']);
            $this->assertFalse(data_get($article->seoMeta->schema_json, 'editorial_package_v1.faq_schema_enabled'));
            $after = Updater::state($article);
            $this->assertSame($before['article'], $after['article']);
            $this->assertSame($before['history'][0], $after['history'][0]);
            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_public_reader_metadata_updated')->latest('id')->firstOrFail();
            $this->assertSame($actor->id, $audit->meta_json['authorized_operator_id']);
            $this->assertSame(['old_metadata' => true], $audit->meta_json['before_revision_metadata']);
            $this->assertSame(1, $this->runPackage($package, $actor)); // No replay after a commit.
            $noop = $package;
            $noop['before_sha256'] = Hash::hash(Updater::state($article->fresh()));
            $noop['candidate']['schema_json'] = $article->fresh()->seoMeta->schema_json;
            $this->assertSame(0, $this->runPackage($noop), Artisan::output());
        }
    }

    public function test_published_provenance_refuses_unbacked_review_and_citation_or_body_drift(): void
    {
        [$article, $actor, $package] = $this->provenanceFixture();
        $before = Updater::state($article);
        foreach (['body', 'review', 'quote', 'private_url', 'actor', 'category'] as $invalid) {
            $candidate = $package;
            $input = &$candidate['candidate']['published_provenance'];
            match ($invalid) {
                'body' => $input['body_sha256'] = str_repeat('a', 64),
                'review' => $input['editorial_review_audit_id'] = 999999,
                'quote' => $input['citations'][0]['body_quote'] = 'Not in the published body',
                'private_url' => $input['citations'][0]['url'] = 'https://example.test/private?token=secret',
                'actor' => $input['author'] = ['identity' => 'admin_user:999'],
                'category' => $input['citations'][0]['category'] = 'peer_reviewed_claim',
            };
            $this->assertSame(1, $this->runPackage($candidate, $actor), $invalid);
            $this->assertSame($before, Updater::state($article->fresh()));
        }
        $article->publishedRevision->update(['reviewed_by' => null]);
        $package['before_sha256'] = Hash::hash(Updater::state($article->fresh()));
        $this->assertSame(1, $this->runPackage($package, $actor));
    }

    public function test_published_provenance_rejects_an_approval_for_a_different_revision_payload(): void
    {
        [$article, $actor, $package] = $this->provenanceFixture();
        $revision = $article->publishedRevision;
        $revision->content_md .= "\nAn unreviewed body change.";
        $revision->saveQuietly();
        $package['before_sha256'] = Hash::hash(Updater::state($article->fresh()));
        $package['candidate']['published_provenance']['body_sha256'] = hash('sha256', $revision->content_md);
        $before = Updater::state($article->fresh());
        $this->assertSame(1, $this->runPackage($package, $actor));
        $output = Artisan::output();
        $this->assertSame('published_provenance_exact_review_evidence_missing', json_decode($output, true)['error']);
        $this->assertSame($before, Updater::state($article->fresh()));
    }

    public function test_published_provenance_uses_exact_resource_tenant_not_the_ops_audit_context(): void
    {
        [$article, $actor, $package] = $this->provenanceFixture();
        $revision = $article->publishedRevision;
        $revision->org_id = 2;
        $revision->saveQuietly();
        $this->assertSame(1, $this->runPackage($package, $actor));
        $this->assertFalse(data_get($article->fresh()->seoMeta->schema_json, 'editorial_package_v1.article_schema_enabled', false));
    }

    public function test_published_provenance_audit_failure_rolls_back_metadata_and_schema(): void
    {
        [$article, $actor, $package] = $this->provenanceFixture();
        $before = Updater::state($article);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        $this->assertSame(1, $this->runPackage($package, $actor));
        $this->assertSame($before, Updater::state($article->fresh()));
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_published_provenance_gate_audit_failure_rolls_back_every_write(): void
    {
        [$article, $actor, $package] = $this->provenanceFixture();
        $before = Updater::state($article);
        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->action === 'articles_seo_gate_rollout') {
                throw new RuntimeException('injected_gate_audit_failure');
            }
        });
        try {
            $this->assertSame(1, $this->runPackage($package, $actor));
            $this->assertSame($before, Updater::state($article->fresh()));
            $this->assertSame(1, AuditLog::withoutGlobalScopes()->count());
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    private function provenanceFixture(string $locale = 'en'): array
    {
        $this->travelTo(now()->startOfSecond());
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $actor = $this->actor();
        $article = $this->article();
        $article->forceFill(['slug' => 'big-five-provenance-'.strtolower($locale), 'locale' => $locale,
            'author_name' => $locale === 'en' ? 'FermatMind Editorial' : '费马测试编辑部',
            'is_indexable' => true, 'published_at' => now()->subDays(10)])->saveQuietly();
        $old = $article->publishedRevision;
        $body = "Sources\n- Soto & John (2017). [BFI-2](https://doi.org/10.1037/pspp0000096).\n"
            ."- CCI. [Assert Yourself](https://www.cci.health.wa.gov.au/example.pdf).\n"
            .'- FermatMind. [Product scope](/en/tests/explore/social-battery-test).';
        $revision = $old->replicate();
        $revision->forceFill(['revision_number' => 2, 'locale' => $locale, 'revision_status' => 'published',
            'content_md' => $body, 'created_by' => $actor->id, 'reviewed_by' => $actor->id,
            'reviewed_at' => now()->subHours(2), 'approved_at' => now()->subHours(2),
            'published_at' => now()->subHour(), 'authority_metadata_json' => ['old_metadata' => true]])->save();
        $article->forceFill(['content_md' => $body, 'published_revision_id' => $revision->id,
            'working_revision_id' => $revision->id])->saveQuietly();
        $article->seoMeta->update(['locale' => $locale, 'is_indexable' => true, 'robots' => 'index,follow']);
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => $actor->id]);
        app(CmsEditorialReviewAttestationService::class)->bindOrCreateApproved(null,
            'cms_editorial_review', 'article:'.$article->id.':workflow:fixture',
            [['surface_id' => 'article_translation_revision', 'record' => $revision]], $actor->id);
        $audit = AuditLog::query()->create(['org_id' => 1, 'actor_admin_id' => $actor->id,
            'action' => 'editorial_review_approved', 'target_type' => 'article', 'target_id' => (string) $article->id,
            'reason' => 'cms_editorial_review', 'meta_json' => ['locale' => $locale],
            'result' => 'success', 'created_at' => now()]);
        $article = $article->fresh();
        $package = $this->package($article);
        $package['candidate']['schema_json'] = $article->seoMeta->schema_json;
        $quotes = explode("\n", $body);
        $package['candidate']['published_provenance'] = ['body_sha256' => hash('sha256', $body),
            'editorial_review_audit_id' => $audit->id, 'preview_qa_acknowledged' => true, 'citations' => [
                ['url' => 'https://doi.org/10.1037/pspp0000096', 'label' => 'Soto & John (2017).', 'category' => 'academic_evidence', 'body_quote' => $quotes[1]],
                ['url' => 'https://www.cci.health.wa.gov.au/example.pdf', 'label' => 'CCI.', 'category' => 'official_guidance', 'body_quote' => $quotes[2]],
                ['url' => '/en/tests/explore/social-battery-test', 'label' => 'FermatMind.', 'category' => 'product_authority', 'body_quote' => $quotes[3]],
            ]];

        return [$article, $actor, $package];
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
