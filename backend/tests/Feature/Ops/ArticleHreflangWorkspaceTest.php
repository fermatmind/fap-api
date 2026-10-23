<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\UpdateArticlePublicReaderMetadata as NativeUpdater;
use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticleHreflangWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticleHreflangWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_pair_release_keeps_content_faq_schema_identity_and_unrelated_article(): void
    {
        $this->login();
        $en = $this->article();
        $zh = $this->article('zh-CN', 'native-hreflang');
        $other = $this->article('en', 'other-article', 'other-group');
        $otherBefore = NativeUpdater::state($other);
        $before = [NativeUpdater::state($en), NativeUpdater::state($zh)];
        $workspace = app(ArticleHreflangWorkspace::class);
        $data = $workspace->prepare($en);
        $this->assertTrue(json_decode($data['preflight'], true)['ok']);
        $this->assertSame($before, [NativeUpdater::state($en->fresh()), NativeUpdater::state($zh->fresh())]);
        $data['pair_reviewed'] = true;
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $en->id])->callAction('releaseHreflangPair', $data)
            ->assertHasNoActionErrors()->assertNotified(__('ops.resources.articles.hreflang_release.success'))->assertRedirect();
        foreach ([$en, $zh] as $i => $article) {
            $after = NativeUpdater::state($article->fresh());
            $this->assertSame($before[$i]['article'], $after['article']);
            $this->assertSame($before[$i]['history'], $after['history']);
            $this->assertSame(Arr::except($before[$i]['seo'], ['schema_json', 'updated_at']), Arr::except($after['seo'], ['schema_json', 'updated_at']));
            $schema = $article->fresh()->seoMeta->schema_json;
            $protected = $schema;
            Arr::forget($protected, 'editorial_package_v1.hreflang_gate_v1');
            $this->assertSame(json_decode($before[$i]['seo']['schema_json'], true), $protected);
            $gate = data_get($schema, 'editorial_package_v1.hreflang_gate_v1');
            $this->assertTrue($gate['enabled']);
            $this->assertSame('reciprocal_counterparts_verified', $gate['policy']);
            $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale='.$article->locale.'&org_id=0')->assertOk()->assertJsonPath('article.seo_meta.hreflang_gate_v1.enabled', true);
        }
        $this->assertSame($otherBefore, NativeUpdater::state($other->fresh()));
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'article_hreflang_pair_released')->count());
        Http::assertSentCount(2);
    }

    #[DataProvider('rejectedCases')]
    public function test_invalid_or_concurrent_pair_is_zero_write(string $case): void
    {
        $this->login();
        $en = $this->article();
        $zh = $this->article('zh-CN');
        $workspace = app(ArticleHreflangWorkspace::class);
        $data = $workspace->prepare($en);
        $data['pair_reviewed'] = true;
        match ($case) {
            'stale' => $zh->seoMeta->update(['seo_title' => 'Concurrent title']),
            'draft' => $zh->update(['working_revision_id' => null]),
            'missing' => $zh->update(['translation_group_id' => 'different-group']),
            'private' => $zh->update(['is_public' => false]),
            'noindex' => $zh->update(['is_indexable' => false]),
            'missing_seo' => $zh->seoMeta->delete(),
            'invalid_slug' => $zh->update(['slug' => 'invalid/path']),
            'invalid_url' => $zh->seoMeta->update(['canonical_url' => 'https://outside.example/article']),
            'duplicate' => $this->article('en', 'extra-sibling'),
            'writer' => $this->login('admin.content.write'),
            'mfa' => session()->forget('ops_admin_totp_verified_user_id'),
            'cache' => config(['ops.content_release_observability.cache_invalidation_urls' => []]),
            'review' => $data['pair_reviewed'] = false,
        };
        $before = ArticleSeoMeta::withoutGlobalScopes()->orderBy('id')->get()->toArray();
        try {
            $workspace->release($en, $data);
            $this->fail('Invalid pair accepted: '.$case);
        } catch (ValidationException|HttpException) {
            $this->assertSame($before, ArticleSeoMeta::withoutGlobalScopes()->orderBy('id')->get()->toArray());
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public static function rejectedCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['stale', 'draft', 'missing', 'private', 'noindex', 'missing_seo', 'invalid_slug', 'invalid_url', 'duplicate', 'writer', 'mfa', 'cache', 'review']);
    }

    public function test_audit_failure_rolls_back_both_native_gate_writes(): void
    {
        $this->login();
        $en = $this->article();
        $zh = $this->article('zh-CN');
        $workspace = app(ArticleHreflangWorkspace::class);
        $data = $workspace->prepare($en) + ['pair_reviewed' => true];
        $data['pair_reviewed'] = true;
        $before = [NativeUpdater::state($en), NativeUpdater::state($zh)];
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        try {
            $workspace->release($en, $data);
            $this->fail('Failed audit accepted');
        } catch (RuntimeException) {
            $this->assertSame($before, [NativeUpdater::state($en->fresh()), NativeUpdater::state($zh->fresh())]);
        }
        Http::assertNothingSent();
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_cache_failure_returns_committed_without_replaying_the_transaction(): void
    {
        $this->login();
        $en = $this->article();
        $this->article('zh-CN');
        $workspace = app(ArticleHreflangWorkspace::class);
        $data = $workspace->prepare($en);
        $data['pair_reviewed'] = true;
        Http::swap((new \Illuminate\Http\Client\Factory)->fake(['*' => Http::response(['ok' => false], 503)]));
        $this->assertSame(['ok' => false, 'hreflang' => 'committed', 'cache_closeout' => 'failed'], $workspace->release($en, $data));
        $this->assertTrue(data_get($en->fresh()->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled'));
        $this->expectException(ValidationException::class);
        $workspace->release($en, $data);
    }

    public function test_native_gate_accepts_empty_metadata_without_inventing_other_fields(): void
    {
        $this->login();
        $en = $this->article();
        $zh = $this->article('zh-CN');
        foreach ([$en, $zh] as $article) {
            $article->seoMeta->update(['schema_json' => null]);
        }
        $workspace = app(ArticleHreflangWorkspace::class);
        $data = $workspace->prepare($en);
        $data['pair_reviewed'] = true;
        $this->assertTrue($workspace->release($en, $data)['ok']);
        $this->assertSame(['hreflang_gate_v1'], array_keys($en->fresh()->seoMeta->schema_json['editorial_package_v1']));
    }

    private function login(string $permission = 'admin.owner'): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com', 'admin.totp.enabled' => true,
            'ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        $role = Role::create(['name' => 'faq-editor-'.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
        $actor = AdminUser::create(['name' => 'FAQ operator', 'email' => uniqid().'@example.test', 'password' => 'test-only', 'is_active' => true]);
        $actor->roles()->attach($role);
        $this->actingAs($actor, 'admin');
        session(['ops_admin_totp_verified_user_id' => $actor->id]);
        Http::fake(function ($request) {
            $payload = $request->data();
            $locale = $payload['content']['locale'];
            $slug = $payload['content']['slug'];
            $paths = array_values(array_unique([...$payload['cache_signal']['paths'], '/zh/articles', '/en/articles', '/zh/articles/'.$slug, '/en/articles/'.$slug, '/llms.txt', '/llms-full.txt']));

            return Http::response(['ok' => true, 'revalidated_paths' => $paths, 'rejected_paths' => [], 'invalidated_tags' => ['article-detail:'.$locale.':'.$slug, 'article-seo:'.$locale.':'.$slug]]);
        });
    }

    private function article(string $locale = 'en', string $slug = 'native-hreflang', string $group = 'native-hreflang-group'): Article
    {
        $article = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => $slug, 'locale' => $locale, 'source_locale' => 'en', 'translation_status' => 'source', 'translation_group_id' => $group, 'title' => 'Published article', 'excerpt' => 'Existing excerpt', 'content_md' => "## Existing body\n\nPublished content.", 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true, 'published_at' => now()]);
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id, 'translation_group_id' => $group, 'locale' => $locale, 'source_locale' => 'en', 'revision_number' => 1, 'revision_status' => 'published', 'title' => $article->title, 'content_md' => $article->content_md, 'published_at' => now()]);
        $article->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => $locale, 'seo_title' => 'Existing SEO', 'seo_description' => 'Existing description', 'canonical_url' => '/'.($locale === 'en' ? 'en' : 'zh').'/articles/'.$slug, 'robots' => 'index,follow', 'schema_json' => ['editorial_package_v1' => ['opaque_review' => 'keep', 'answer_surface_policy' => 'editor_supplied', 'answer_surface_visibility' => 'visible', 'answer_surface_v1' => ['faq_items' => [['key' => 'faq_one', 'question' => 'Original question?', 'answer' => 'Original answer.'], ['key' => 'faq_two', 'question' => 'Second question?', 'answer' => 'Original second answer.']]]]]]);

        return $article->fresh(['publishedRevision', 'seoMeta']);
    }
}
