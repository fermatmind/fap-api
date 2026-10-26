<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\UpdateArticlePublicReaderMetadata as NativeUpdater;
use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticleHreflangWorkspace;
use App\Filament\Ops\Support\ContentReleaseFollowUp;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticleReviewedCounterpartService;
use App\Services\Cms\ArticleSeoService;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ArticleReviewedCounterpartTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_action_links_two_independent_sources_without_changing_their_history(): void
    {
        [$en, $zh] = $this->pair();
        $before = [NativeUpdater::state($en), NativeUpdater::state($zh)];
        $data = app(ArticleHreflangWorkspace::class)->prepare($en, $zh->id);
        $data['pair_reviewed'] = true;
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $en->id])
            ->callAction('linkReviewedCounterpart', $data)->assertHasNoActionErrors()->assertRedirect();
        foreach ([$en, $zh] as $i => $article) {
            $after = NativeUpdater::state($article->fresh());
            $this->assertSame($before[$i]['article'], $after['article']);
            $this->assertSame($before[$i]['history'], $after['history']);
            $this->assertSame(Arr::except($before[$i]['seo'], ['schema_json', 'updated_at']), Arr::except($after['seo'], ['schema_json', 'updated_at']));
            $this->assertTrue($article->fresh()->isSourceArticle());
            $this->assertSame(1, Article::withoutGlobalScopes()->where('translation_group_id', $article->translation_group_id)->count());
            $this->assertCount(2, app(ArticleReviewedCounterpartService::class)->resolve($article->fresh()));
            $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale='.$article->locale)->assertOk()
                ->assertJsonPath('article.seo_meta.hreflang_gate_v1.enabled', true)
                ->assertJsonMissingPath('article.seo_meta.hreflang_gate_v1.counterpart_binding_v1');
            $seo = $this->getJson('/api/v0.5/articles/'.$article->slug.'/seo?locale='.$article->locale)->assertOk()->json('meta');
            $this->assertSame('https://fermatmind.com/en/articles/'.$en->slug, $seo['alternates']['en']);
            $this->assertSame('https://fermatmind.com/zh/articles/'.$zh->slug, $seo['alternates']['zh-CN']);
        }
        Http::assertSentCount(2);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'article_hreflang_pair_released')->count());
    }

    #[DataProvider('driftCases')]
    public function test_any_public_pair_drift_disables_cross_links_in_both_readers(string $case): void
    {
        [$en, $zh] = $this->pair();
        $this->release($en, $zh);
        $schema = $zh->fresh()->seoMeta->schema_json;
        match ($case) {
            'revision' => $zh->updateQuietly(['published_revision_id' => $this->revision($zh, 2)->id]),
            'bytes' => $zh->publishedRevision->updateQuietly(['content_md' => 'Changed after review']),
            'private' => $zh->updateQuietly(['is_public' => false]),
            'index' => $zh->updateQuietly(['is_indexable' => false]),
            'seo_index' => $zh->seoMeta->updateQuietly(['is_indexable' => false]),
            'robots' => $zh->seoMeta->updateQuietly(['robots' => 'noindex,follow']),
            'canonical' => $zh->seoMeta->updateQuietly(['canonical_url' => '/zh/articles/somewhere-else']),
            'deleted' => $zh->forceFill(['deleted_at' => now()])->saveQuietly(),
            'archived' => $zh->updateQuietly(['lifecycle_state' => Article::LIFECYCLE_ARCHIVED]),
            'locale' => $zh->updateQuietly(['locale' => 'en']),
            'slug' => $zh->updateQuietly(['slug' => 'changed-slug']),
            'missing_gate' => $zh->seoMeta->updateQuietly(['schema_json' => []]),
            'disabled' => $this->gatePatch($zh, $schema, ['enabled' => false]),
            'one_sided' => $this->gatePatch($zh, $schema, ['counterpart_binding_v1' => ['endpoints' => []]]),
            'duplicate' => $this->gatePatch($zh, $schema, ['counterpart_binding_v1' => ['endpoints' => [
                $schema['editorial_package_v1']['hreflang_gate_v1']['counterpart_binding_v1']['endpoints'][0],
                $schema['editorial_package_v1']['hreflang_gate_v1']['counterpart_binding_v1']['endpoints'][0],
            ]]]),
        };
        foreach ([$en, $zh] as $article) {
            $this->assertSame([], app(ArticleReviewedCounterpartService::class)->resolve($article->fresh()) ?? []);
            $meta = app(ArticleSeoService::class)->buildSeoPayload($article->fresh());
            $this->assertFalse(isset($meta['alternates']['en'], $meta['alternates']['zh-CN']));
        }
        $this->getJson('/api/v0.5/articles/'.$en->slug.'?locale=en')->assertOk()
            ->assertJsonPath('article.seo_meta.hreflang_gate_v1.enabled', false);
    }

    public static function driftCases(): array
    {
        return array_map(fn ($case): array => [$case], ['revision', 'bytes', 'private', 'index', 'seo_index', 'robots', 'canonical', 'deleted', 'archived', 'locale', 'slug', 'missing_gate', 'disabled', 'one_sided', 'duplicate']);
    }

    public function test_public_faq_change_invalidates_reviewed_semantic_pair(): void
    {
        [$en, $zh] = $this->pair();
        foreach ([$en, $zh] as $member) {
            $schema = $member->seoMeta->schema_json;
            $schema['editorial_package_v1'] += ['answer_surface_policy' => 'editor_supplied',
                'answer_surface_visibility' => 'visible', 'answer_surface_v1' => ['faq_items' => [
                    ['key' => 'test', 'question' => 'Is it suitable?', 'answer' => 'It is suitable.'],
                ]]];
            $member->seoMeta->updateQuietly(['schema_json' => $schema]);
        }
        $this->release($en->fresh(), $zh->fresh());
        $revision = $zh->published_revision_id;
        $workspace = app(\App\Filament\Ops\Support\ArticlePublishedFaqWorkspace::class);
        $data = $workspace->prepare($zh->fresh());
        $data['faq_items'][0]['answer'] = 'It is unsuitable; use another approach.';
        $this->assertTrue($workspace->update($zh->fresh(), $data)['ok']);
        $this->assertSame($revision, $zh->fresh()->published_revision_id);
        $this->assertSame([], app(ArticleReviewedCounterpartService::class)->resolve($en->fresh()));
        $this->getJson('/api/v0.5/articles/'.$en->slug.'?locale=en')->assertOk()
            ->assertJsonPath('article.seo_meta.hreflang_gate_v1.enabled', false);
        foreach ([$en, $zh] as $member) {
            $seo = $this->getJson('/api/v0.5/articles/'.$member->slug.'/seo?locale='.$member->locale)->assertOk()->json('meta');
            $this->assertFalse(isset($seo['alternates']['en'], $seo['alternates']['zh-CN']));
        }
        Http::assertSent(fn ($request): bool => $request['source'] === 'article_published_faq_updated'
            && $request['content']['locale'] === 'en' && $request['content']['slug'] === $en->slug);
    }

    public function test_private_draft_changes_do_not_invalidate_the_published_correspondence(): void
    {
        [$en, $zh] = $this->pair();
        $this->release($en, $zh);
        $draft = $this->revision($zh, 2);
        $draft->updateQuietly(['revision_status' => 'human_review', 'content_md' => 'Private future body']);
        $zh->updateQuietly(['working_revision_id' => $draft->id]);
        $this->assertCount(2, app(ArticleReviewedCounterpartService::class)->resolve($en->fresh()));
        $this->getJson('/api/v0.5/articles/'.$en->slug.'?locale=en')->assertOk()
            ->assertJsonPath('article.seo_meta.hreflang_gate_v1.enabled', true);
    }

    public function test_post_publish_cache_closeout_uses_the_expired_raw_peer_binding(): void
    {
        [$en, $zh] = $this->pair();
        $this->release($en, $zh);
        $zh->updateQuietly(['published_revision_id' => $this->revision($zh, 2)->id]);
        $this->assertSame([], app(ArticleReviewedCounterpartService::class)->resolve($zh->fresh()));
        ContentReleaseFollowUp::dispatchArticlePathsStrict($zh->fresh(), 'new_public_revision');
        Http::assertSentCount(4);
        Http::assertSent(fn ($request): bool => $request['source'] === 'new_public_revision'
            && $request['content']['locale'] === 'en' && $request['content']['slug'] === $en->slug);
    }

    public function test_failed_cache_is_committed_once_and_does_not_republish(): void
    {
        [$en, $zh] = $this->pair();
        Http::swap((new \Illuminate\Http\Client\Factory)->fake(['*' => Http::response(['ok' => false], 503)]));
        $data = app(ArticleHreflangWorkspace::class)->prepare($en, $zh->id);
        $data['pair_reviewed'] = true;
        $this->assertSame(['ok' => false, 'hreflang' => 'committed', 'cache_closeout' => 'failed'], app(ArticleHreflangWorkspace::class)->release($en, $data));
        $this->assertCount(2, app(ArticleReviewedCounterpartService::class)->resolve($en->fresh()));
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'article_hreflang_pair_released')->count());
        $this->assertSame(2, ArticleTranslationRevision::withoutGlobalScopes()->count());
    }

    public function test_failed_audit_rolls_back_both_gate_writes(): void
    {
        [$en, $zh] = $this->pair();
        $data = app(ArticleHreflangWorkspace::class)->prepare($en, $zh->id);
        $data['pair_reviewed'] = true;
        $before = [NativeUpdater::state($en), NativeUpdater::state($zh)];
        $this->mock(AuditLogger::class)->shouldReceive('log')->andThrow(new RuntimeException('audit failed'));
        try {
            app(ArticleHreflangWorkspace::class)->release($en, $data);
            $this->fail('Audit failure must reject the write.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        }
        $this->assertSame($before, [NativeUpdater::state($en->fresh()), NativeUpdater::state($zh->fresh())]);
        Http::assertNothingSent();
    }

    #[DataProvider('writeRejections')]
    public function test_unauthorized_stale_or_conflicting_pair_is_zero_write(string $case): void
    {
        [$en, $zh] = $this->pair();
        $workspace = app(ArticleHreflangWorkspace::class);
        $data = $workspace->prepare($en, $zh->id);
        $data['pair_reviewed'] = true;
        match ($case) {
            'stale' => $zh->seoMeta->updateQuietly(['seo_title' => 'Concurrent metadata']),
            'draft' => $zh->updateQuietly(['working_revision_id' => null]),
            'noindex' => $zh->updateQuietly(['is_indexable' => false]),
            'mfa' => session()->forget('ops_admin_totp_verified_user_id'),
            'permission' => $this->login('content.write'),
            'review' => $data['pair_reviewed'] = false,
            'third' => $this->gatePatch($zh, $zh->seoMeta->schema_json, ['counterpart_binding_v1' => ['endpoints' => [['article_id' => 987], ['article_id' => $zh->id]]]]),
        };
        $before = [NativeUpdater::state($en->fresh()), NativeUpdater::state($zh->fresh())];
        try {
            $workspace->release($en, $data);
            $this->fail('Invalid release must be rejected.');
        } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->assertSame($before, [NativeUpdater::state($en->fresh()), NativeUpdater::state($zh->fresh())]);
        }
        Http::assertNothingSent();
    }

    public static function writeRejections(): array
    {
        return array_map(fn ($case): array => [$case], ['stale', 'draft', 'noindex', 'mfa', 'permission', 'review', 'third']);
    }

    private function release(Article $en, Article $zh): void
    {
        $workspace = app(ArticleHreflangWorkspace::class);
        $data = $workspace->prepare($en, $zh->id);
        $data['pair_reviewed'] = true;
        $this->assertTrue($workspace->release($en, $data)['ok']);
    }

    private function gatePatch(Article $a, array $schema, array $patch): void
    {
        $schema['editorial_package_v1']['hreflang_gate_v1'] = array_replace($schema['editorial_package_v1']['hreflang_gate_v1'] ?? [], $patch);
        $a->seoMeta->updateQuietly(['schema_json' => $schema]);
    }

    private function pair(): array
    {
        $this->login();

        return [$this->article('en'), $this->article('zh-CN')];
    }

    private function login(string $permission = 'admin.owner'): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com', 'admin.totp.enabled' => true,
            'ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        $role = Role::create(['name' => 'counterpart-'.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
        $actor = AdminUser::create(['name' => 'Operator', 'email' => uniqid().'@example.test', 'password' => 'test-only', 'is_active' => true]);
        $actor->roles()->attach($role);
        $this->actingAs($actor, 'admin');
        session(['ops_admin_totp_verified_user_id' => $actor->id]);
        Http::fake(function ($request) {
            $payload = $request->data();
            $locale = $payload['content']['locale'];
            $slug = $payload['content']['slug'];
            $paths = array_values(array_unique([...$payload['cache_signal']['paths'], '/zh/articles', '/en/articles', '/zh/articles/'.$slug, '/en/articles/'.$slug, '/llms.txt', '/llms-full.txt']));

            return Http::response(['ok' => true, 'revalidated_paths' => $paths, 'rejected_paths' => [],
                'invalidated_tags' => ['article-detail:'.$locale.':'.$slug, 'article-seo:'.$locale.':'.$slug]]);
        });
    }

    private function article(string $locale): Article
    {
        $article = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'reviewed-'.($locale === 'en' ? 'english' : 'chinese'),
            'locale' => $locale, 'source_locale' => $locale, 'translation_status' => 'source', 'translation_group_id' => 'own-'.$locale,
            'title' => 'Published '.$locale, 'excerpt' => 'Existing excerpt', 'content_md' => "## Body\n\nReviewed public content.",
            'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true, 'published_at' => now()]);
        $revision = $this->revision($article, 1);
        $article->updateQuietly(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id]);
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => $locale,
            'seo_title' => 'Existing title', 'seo_description' => 'Existing description', 'is_indexable' => true,
            'canonical_url' => '/'.($locale === 'en' ? 'en' : 'zh').'/articles/'.$article->slug, 'robots' => 'index,follow',
            'schema_json' => ['editorial_package_v1' => ['opaque_review' => 'keep']]]);

        return $article->fresh(['publishedRevision', 'workingRevision', 'seoMeta']);
    }

    private function revision(Article $article, int $number): ArticleTranslationRevision
    {
        return ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id,
            'source_article_id' => $article->id, 'translation_group_id' => $article->translation_group_id, 'locale' => $article->locale,
            'source_locale' => $article->locale, 'revision_number' => $number, 'revision_status' => 'published',
            'source_version_hash' => $article->source_version_hash, 'title' => $article->title, 'excerpt' => $article->excerpt,
            'content_md' => $article->content_md, 'published_at' => now(), 'approved_at' => now()]);
    }
}
