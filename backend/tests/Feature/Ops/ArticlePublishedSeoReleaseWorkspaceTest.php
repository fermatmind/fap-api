<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Events\PublicAuthorityChanged;
use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticleControlledPublicationWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ArticleSourceTargetSnapshot;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticlePublishedSeoReleaseWorkspaceTest extends TestCase
{
    use DatabaseMigrations;

    private object $realEvents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realEvents = Event::getFacadeRoot();
    }

    public function test_owner_action_releases_only_native_gates_and_feeds_with_actor_bound_audit(): void
    {
        $this->login();
        $article = $this->article();
        $before = $article->getRawOriginal();
        $revisions = ArticleTranslationRevision::withoutGlobalScopes()->get()->map->getRawOriginal()->all();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareSeoRelease($article);
        $plans = json_decode($data['preflight'], true);
        $this->assertTrue($plans[0]['ok']);
        $this->assertTrue($plans[1]['ok']);
        $this->assertSame($before, $article->fresh()->getRawOriginal());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        $data['public_page_reviewed'] = true;
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        $page = Livewire::test(EditArticle::class, ['record' => $article->id])
            ->callAction('releasePublishedSeo', $data)->assertHasNoActionErrors();
        $this->assertSame('success', AuditLog::withoutGlobalScopes()->where('action', 'content_release_cache_signal')->value('result'),
            json_encode(AuditLog::withoutGlobalScopes()->where('action', 'content_release_cache_signal')->value('meta_json')));
        $page->assertNotified(__('ops.resources.articles.seo_release.success'))->assertRedirect();
        $fresh = $article->fresh(['seoMeta']);
        $this->assertTrue($fresh->sitemap_eligible);
        $this->assertTrue($fresh->llms_eligible);
        $this->assertSame(Arr::except($before, ['sitemap_eligible', 'llms_eligible', 'updated_at']), Arr::except($fresh->getRawOriginal(), ['sitemap_eligible', 'llms_eligible', 'updated_at']));
        $this->assertSame($revisions, ArticleTranslationRevision::withoutGlobalScopes()->get()->map->getRawOriginal()->all());
        $gates = $fresh->seoMeta->schema_json['editorial_package_v1'];
        $this->assertTrue($gates['article_schema_enabled']);
        $this->assertTrue($gates['breadcrumb_schema_enabled']);
        $this->assertFalse($gates['faq_schema_enabled']);
        $this->assertFalse($gates['hreflang_gate_v1']['enabled']);
        $this->assertSame('no_verified_reciprocal_counterpart', $gates['hreflang_gate_v1']['reason']);
        $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_native_seo_released')->sole();
        $this->assertSame((int) auth('admin')->id(), (int) $audit->actor_admin_id);
        $this->assertFalse($audit->meta_json['search_submission']);
        $this->assertSame([], $audit->meta_json['params_sanitized']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => in_array('/sitemap.xml', data_get($request->data(), 'cache_signal.urls', []), true));
        Event::assertDispatched(PublicAuthorityChanged::class, 2);
    }

    #[DataProvider('rejectCases')]
    public function test_invalid_or_concurrent_state_never_changes_gates_or_feeds(string $case): void
    {
        $this->login();
        $article = $this->article();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareSeoRelease($article);
        $data['public_page_reviewed'] = true;
        if ($case === 'stale') {
            $article->seoMeta->forceFill(['seo_description' => 'Concurrent description'])->saveQuietly();
        } elseif ($case === 'writer' || $case === 'reader') {
            $this->login($case === 'writer' ? 'admin.content.write' : 'admin.content.read');
        } elseif ($case === 'no_mfa') {
            session()->forget('ops_admin_totp_verified_user_id');
        } elseif ($case === 'unreviewed') {
            $data['public_page_reviewed'] = false;
        } elseif ($case === 'no_cache') {
            config(['ops.content_release_observability.cache_invalidation_urls' => []]);
        } elseif ($case === 'foreign_org') {
            $article->forceFill(['org_id' => 2])->saveQuietly();
        } elseif ($case === 'working_draft') {
            $article->forceFill(['working_revision_id' => null])->saveQuietly();
        } elseif ($case === 'sibling') {
            Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'zh-peer', 'locale' => 'zh-CN', 'translation_group_id' => $article->translation_group_id, 'title' => '对应草稿', 'content_md' => '对应内容', 'status' => 'draft']);
        } elseif ($case === 'already_released') {
            $article->forceFill(['sitemap_eligible' => true, 'llms_eligible' => true])->saveQuietly();
        }
        Event::fake([PublicAuthorityChanged::class]);
        $before = ArticleSourceTargetSnapshot::publicationHash($article);
        try {
            $workspace->releaseSeo($article, $data);
            $this->fail('Condition must fail closed: '.$case);
        } catch (ValidationException|HttpException|RuntimeException) {
            $this->assertSame($before, ArticleSourceTargetSnapshot::publicationHash($article));
        }
        Http::assertNothingSent();
        Event::assertNotDispatched(PublicAuthorityChanged::class);
    }

    public static function rejectCases(): array
    {
        return array_map(fn ($case): array => [$case], ['stale', 'writer', 'reader', 'no_mfa', 'unreviewed', 'no_cache', 'foreign_org', 'working_draft', 'sibling', 'already_released']);
    }

    public function test_second_native_command_failure_rolls_back_first_native_gate_changes(): void
    {
        $this->login();
        $article = $this->article();
        $article->seoMeta->forceFill(['canonical_url' => 'https://fermatmind.com/en/articles/wrong-route'])->saveQuietly();
        Event::fake([PublicAuthorityChanged::class]);
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareSeoRelease($article);
        $data['public_page_reviewed'] = true;
        $this->assertTrue(json_decode($data['preflight'], true)[0]['ok']);
        $this->assertFalse(json_decode($data['preflight'], true)[1]['ok']);
        $before = ArticleSourceTargetSnapshot::publicationHash($article);
        try {
            $workspace->releaseSeo($article, $data);
            $this->fail('Native canonical rejection must roll back the full release.');
        } catch (ValidationException) {
            $this->assertSame($before, ArticleSourceTargetSnapshot::publicationHash($article));
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        Event::assertNotDispatched(PublicAuthorityChanged::class);
        Http::assertNothingSent();
    }

    public function test_native_audit_failure_rolls_back_both_commands(): void
    {
        $this->login();
        $article = $this->article();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareSeoRelease($article);
        $data['public_page_reviewed'] = true;
        $before = ArticleSourceTargetSnapshot::publicationHash($article);
        $this->mock(\App\Services\Audit\AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Synthetic audit failure.'));
        try {
            $workspace->releaseSeo($article, $data);
            $this->fail('Native audit failure must roll back.');
        } catch (ValidationException) {
            $this->assertSame($before, ArticleSourceTargetSnapshot::publicationHash($article));
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_committed_release_with_failed_cache_cannot_replay(): void
    {
        $this->login();
        $article = $this->article(fakeCache: false);
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareSeoRelease($article);
        $data['public_page_reviewed'] = true;
        // A 200 without the sitemap acknowledgement must fail strict closeout.
        Http::fake(['*' => Http::response(['ok' => true])]);
        $result = $workspace->releaseSeo($article, $data);
        $this->assertSame(['ok' => false, 'seo_release' => 'committed', 'cache_closeout' => 'failed'], $result);
        $this->assertTrue($article->fresh()->sitemap_eligible);
        $sent = count(Http::recorded());
        try {
            $workspace->releaseSeo($article, $data);
            $this->fail('Committed SEO release cannot replay.');
        } catch (ValidationException) {
            $this->assertSame($sent, count(Http::recorded()));
        }
    }

    public function test_after_commit_listener_failure_is_reported_as_committed_without_retry(): void
    {
        $this->login();
        $article = $this->article();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareSeoRelease($article);
        $data['public_page_reviewed'] = true;
        $this->assertSame(0, DB::transactionLevel());
        Event::swap($this->realEvents);
        Event::forget(PublicAuthorityChanged::class);
        $seen = 0;
        $listenerTransactionLevel = null;
        Event::listen(PublicAuthorityChanged::class, function () use (&$seen, &$listenerTransactionLevel): void {
            $seen++;
            $listenerTransactionLevel = DB::transactionLevel();
            throw new RuntimeException('Synthetic native authority listener failure.');
        });
        $this->assertSame(['ok' => false, 'seo_release' => 'committed', 'cache_closeout' => 'failed'], $workspace->releaseSeo($article, $data));
        $this->assertSame(1, $seen);
        $this->assertSame(0, $listenerTransactionLevel);
        $this->assertTrue($article->fresh()->sitemap_eligible);
        $this->assertTrue($article->fresh()->llms_eligible);
        Http::assertNothingSent();
        try {
            $workspace->releaseSeo($article, $data);
            $this->fail('Listener failure after commit cannot replay the release.');
        } catch (ValidationException) {
            $this->assertSame(1, $seen);
        }
    }

    private function login(string $permission = 'admin.owner'): void
    {
        config(['admin.totp.enabled' => true, 'app.frontend_url' => 'https://fermatmind.com',
            'ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        Event::fake([PublicAuthorityChanged::class]);
        $role = Role::create(['name' => 'seo-editor-'.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
        $user = AdminUser::create(['name' => 'SEO editor', 'email' => uniqid().'@example.test', 'password' => 'test-only', 'is_active' => true]);
        $user->roles()->attach($role);
        $this->actingAs($user, 'admin');
        session(['ops_admin_totp_verified_user_id' => (int) $user->id]);
    }

    private function article(bool $fakeCache = true): Article
    {
        $article = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'mbti-tool-choice', 'locale' => 'en', 'source_locale' => 'en', 'translation_group_id' => 'mbti-standalone', 'title' => 'Choosing a personality test', 'excerpt' => 'Compare the free results and report scope.', 'content_md' => "## Free results\n\nCompare what each test actually provides.", 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'sitemap_eligible' => false, 'llms_eligible' => false, 'published_at' => now()]);
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id, 'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => 'en', 'revision_number' => 1, 'revision_status' => 'published', 'source_version_hash' => $article->source_version_hash, 'translated_from_version_hash' => $article->source_version_hash, 'title' => $article->title, 'excerpt' => $article->excerpt, 'content_md' => $article->content_md, 'published_at' => now()]);
        $article->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => 'en', 'seo_title' => $article->title, 'seo_description' => $article->excerpt, 'canonical_url' => 'https://fermatmind.com/en/articles/'.$article->slug, 'robots' => 'index,follow', 'is_indexable' => true, 'schema_json' => ['editorial_package_v1' => ['schema_hold' => true, 'hreflang_hold' => true, 'article_schema_enabled' => false, 'breadcrumb_schema_enabled' => false, 'faq_schema_enabled' => false]]]);
        if ($fakeCache) {
            Http::fake(['*' => Http::response(['ok' => true, 'revalidated_paths' => ['/en', '/en/articles', '/en/articles/mbti-tool-choice', '/llms.txt', '/llms-full.txt', '/sitemap.xml', '/zh/articles', '/zh/articles/mbti-tool-choice'], 'rejected_paths' => [], 'invalidated_tags' => ['article-detail:en:mbti-tool-choice', 'article-seo:en:mbti-tool-choice']])]);
        }
        Event::fake([PublicAuthorityChanged::class]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
    }
}
