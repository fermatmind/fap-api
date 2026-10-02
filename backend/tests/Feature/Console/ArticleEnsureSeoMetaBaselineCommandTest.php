<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticleEnsureSeoMetaBaseline as Baseline;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class ArticleEnsureSeoMetaBaselineCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_article_pipeline_commands_are_registered_for_production_artisan_path(): void
    {
        $commands = Artisan::all();

        foreach ([
            'articles:ensure-seo-meta-baseline',
            'articles:update-existing-seo-content-package',
            'content-release:revalidate',
            'seo-intel:search-channel-queue',
            'seo-intel:url-truth-handoff',
        ] as $commandName) {
            $this->assertArrayHasKey($commandName, $commands);
        }
    }

    public function test_default_invocation_is_dry_run_and_does_not_write(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle();

        $exitCode = Artisan::call('articles:ensure-seo-meta-baseline', [
            '--article-id' => (string) $article->id,
            '--translation-group-id' => 'article-8',
            '--expected-slug' => 'mbti-basics',
            '--expected-canonical' => '/zh/articles/mbti-basics',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['would_write']);
        $this->assertFalse($payload['before']['seo_meta_exists']);
        $this->assertTrue($payload['after']['seo_meta_exists']);
        $this->assertSame('https://fermatmind.com/zh/articles/mbti-basics', $payload['after']['canonical_url']);
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
    }

    public function test_execute_creates_baseline_without_schema_hreflang_or_indexability_drift(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle();

        $exitCode = Artisan::call('articles:ensure-seo-meta-baseline', [
            '--article-id' => (string) $article->id,
            '--translation-group-id' => 'article-8',
            '--expected-slug' => 'mbti-basics',
            '--expected-canonical' => '/zh/articles/mbti-basics',
            ...$this->executeAuthority($article),
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
            '--no-schema' => true,
            '--no-hreflang' => true,
            '--no-search' => true,
            '--no-sitemap-llms-change' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('ensured_seo_meta_baseline', $payload['action']);

        $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail((int) $article->id);
        $this->assertSame('published', (string) $fresh->status);
        $this->assertTrue((bool) $fresh->is_public);
        $this->assertTrue((bool) $fresh->is_indexable);
        $this->assertTrue((bool) $fresh->sitemap_eligible);
        $this->assertTrue((bool) $fresh->llms_eligible);
        $this->assertSame('https://fermatmind.com/zh/articles/mbti-basics', (string) $fresh->seoMeta?->canonical_url);
        $this->assertSame('index,follow', (string) $fresh->seoMeta?->robots);
        $this->assertTrue((bool) $fresh->seoMeta?->is_indexable);
        $this->assertNull($fresh->seoMeta?->schema_json);
    }

    public function test_execute_requires_all_safety_flags(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle();

        $exitCode = Artisan::call('articles:ensure-seo-meta-baseline', [
            '--article-id' => (string) $article->id,
            '--translation-group-id' => 'article-8',
            '--expected-slug' => 'mbti-basics',
            '--expected-canonical' => '/zh/articles/mbti-basics',
            ...$this->executeAuthority($article),
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'required_safety_flag_missing');
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
    }

    public function test_execute_preserves_existing_schema_json(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle();
        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'canonical_url' => 'https://fermatmind.com/zh/articles/mbti-basics',
            'robots' => 'index,follow',
            'is_indexable' => true,
            'schema_json' => [
                'editorial_package_v1' => [
                    'article_schema_enabled' => false,
                    'breadcrumb_schema_enabled' => false,
                    'faq_schema_enabled' => false,
                    'hreflang_gate_v1' => [
                        'enabled' => false,
                    ],
                ],
            ],
        ]);

        $exitCode = Artisan::call('articles:ensure-seo-meta-baseline', [
            '--article-id' => (string) $article->id,
            '--translation-group-id' => 'article-8',
            '--expected-slug' => 'mbti-basics',
            '--expected-canonical' => '/zh/articles/mbti-basics',
            ...$this->executeAuthority($article),
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
            '--no-schema' => true,
            '--no-hreflang' => true,
            '--no-search' => true,
            '--no-sitemap-llms-change' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $fresh = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->firstOrFail();
        $this->assertFalse((bool) data_get($fresh->schema_json, 'editorial_package_v1.article_schema_enabled'));
        $this->assertFalse((bool) data_get($fresh->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled'));
    }

    public function test_lock_mismatch_rejects_without_write(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle();

        $exitCode = Artisan::call('articles:ensure-seo-meta-baseline', [
            '--article-id' => (string) $article->id,
            '--translation-group-id' => 'wrong-group',
            '--expected-slug' => 'mbti-basics',
            '--expected-canonical' => '/zh/articles/mbti-basics',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'translation_group_id_mismatch');
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
    }

    public function test_drift_and_failed_audit_leave_native_state_unchanged(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle();
        $args = $this->executeArgs($article);
        Article::withoutGlobalScopes()->whereKey($article->id)->update(['excerpt' => 'Changed after preview']);
        $this->assertSame(1, Artisan::call('articles:ensure-seo-meta-baseline', $args));
        $this->assertSame(0, ArticleSeoMeta::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        $article = $article->fresh();
        $before = Baseline::nativeState($article);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        $this->assertSame(1, Artisan::call('articles:ensure-seo-meta-baseline', $this->executeArgs($article)));
        $this->assertSame($before, Baseline::nativeState($article->fresh()));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_existing_editorial_copy_and_seo_noindex_override_are_preserved(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle();
        $seo = ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id,
            'locale' => 'zh-CN', 'seo_title' => 'Exact reviewed title', 'seo_description' => 'Reviewed description',
            'canonical_url' => 'https://fermatmind.com/zh/articles/mbti-basics', 'is_indexable' => false,
            'robots' => '', 'schema_json' => null]);
        $this->assertSame(0, Artisan::call('articles:ensure-seo-meta-baseline', $this->executeArgs($article->fresh())));
        $this->assertSame('Exact reviewed title', $seo->fresh()->seo_title);
        $this->assertSame('Reviewed description', $seo->fresh()->seo_description);
        $this->assertFalse($seo->fresh()->is_indexable);
        $this->assertSame('noindex,nofollow', $seo->fresh()->robots);
        $this->assertNull($seo->fresh()->schema_json);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->count());
    }

    private function executeAuthority(Article $article): array
    {
        $actor = AdminUser::create(['name' => 'Local operator', 'email' => uniqid().'@example.test', 'password' => 'test-secret', 'is_active' => 1]);
        $role = Role::create(['name' => uniqid('baseline-')]);
        $permission = Permission::firstOrCreate(['name' => PermissionNames::ADMIN_CONTENT_PUBLISH]);
        $role->permissions()->attach($permission);
        $actor->roles()->attach($role);
        $hash = Hash::hash(Baseline::nativeState($article->fresh()));

        return ['--admin-user-id' => $actor->id, '--expected-state-sha256' => $hash, '--confirm' => $hash];
    }

    private function executeArgs(Article $article): array
    {
        return [...$this->executeAuthority($article), '--article-id' => $article->id,
            '--translation-group-id' => $article->translation_group_id, '--expected-slug' => $article->slug,
            '--expected-canonical' => '/zh/articles/'.$article->slug, '--execute' => true, '--json' => true,
            '--no-publish' => true, '--no-schema' => true, '--no-hreflang' => true,
            '--no-search' => true, '--no-sitemap-llms-change' => true];
    }

    private function createArticle(): Article
    {
        /** @var Article $article */
        $article = Article::query()->withoutGlobalScopes()->create([
            'id' => 8,
            'org_id' => 0,
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-8',
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'MBTI 性格测试是什么？16 型人格能告诉你什么，不能告诉你什么',
            'excerpt' => '了解 MBTI 性格测试、16 型人格和四组偏好。',
            'content_md' => '正文',
            'content_html' => '',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now(),
        ]);

        return $article->refresh();
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
    }
}
