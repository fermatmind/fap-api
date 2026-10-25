<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticleEquivalentWorkingRevisionWorkspace as Workspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision as Revision;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticleEquivalentWorkingRevisionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_archive_restores_source_pointer_without_publishing_or_changing_public_revision(): void
    {
        $article = $this->fixture();
        $before = $this->state($article);
        $input = app(Workspace::class)->prepare($article);
        $this->assertSame($before, $this->state($article));
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->callAction('archiveEquivalentWorkingRevision', [...$input, 'archive_equivalent' => true])
            ->assertHasNoActionErrors()->assertNotified('等价工作版本已归档；公开版本保持不变')->assertRedirect();
        $after = $this->state($article);
        $this->assertSame(array_replace($before['article'], [
            'working_revision_id' => $before['article']['published_revision_id'],
            'source_version_hash' => $before['published']['source_version_hash'],
        ]), $after['article']);
        $this->assertSame($before['published'], $after['published']);
        $this->assertSame($before['seo'], $after['seo']);
        $this->assertSame(Revision::STATUS_ARCHIVED, Revision::find($before['working']['id'])->revision_status);
        $this->assertDatabaseCount('article_translation_revisions', 2);
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
        $audit = AuditLog::withoutGlobalScopes()->sole();
        $this->assertSame('article_equivalent_working_revision_archived', $audit->action);
        $this->assertSame((int) auth('admin')->id(), $audit->actor_admin_id);
        $this->assertFalse(data_get($audit->meta_json, 'published'));
        $this->assertFalse(data_get($audit->meta_json, 'source_review_completed'));
        Http::assertNothingSent();
    }

    public function test_private_english_copy_can_be_archived_without_faking_source_freshness(): void
    {
        $article = $this->fixture(false);
        $before = $this->state($article);
        $this->archive($article);
        $after = $this->state($article);
        $this->assertSame(array_replace($before['article'], ['working_revision_id' => $before['published']['id']]), $after['article']);
        $this->assertSame($before['published'], $after['published']);
        $this->assertSame(str_repeat('c', 64), $article->fresh('workingRevision')->workingRevision->translated_from_version_hash);
        $this->expectException(ValidationException::class);
        $this->archive($article);
    }

    #[DataProvider('rejectedCases')]
    public function test_invalid_identity_state_and_concurrent_changes_are_zero_write(string $case): void
    {
        $article = $this->fixture();
        $service = app(Workspace::class);
        $input = [...$service->prepare($article), 'archive_equivalent' => true];
        $working = $article->workingRevision;
        $published = $article->publishedRevision;
        match ($case) {
            'title', 'excerpt', 'content_md', 'seo_title', 'seo_description' => $working->forceFill([$case => 'Changed'])->saveQuietly(),
            'null_empty' => $working->forceFill(['excerpt' => null])->saveQuietly(),
            'newline' => $working->forceFill(['content_md' => $working->content_md."\n"])->saveQuietly(),
            'org' => $article->forceFill(['org_id' => 9])->saveQuietly(),
            'locale' => $working->forceFill(['locale' => 'en'])->saveQuietly(),
            'group' => $working->forceFill(['translation_group_id' => 'other'])->saveQuietly(),
            'owner' => $working->forceFill(['article_id' => 999])->saveQuietly(),
            'source' => $working->forceFill(['source_article_id' => 999])->saveQuietly(),
            'published_working' => $working->forceFill(['published_at' => now()])->saveQuietly(),
            'archived_working' => $working->forceFill(['revision_status' => 'archived'])->saveQuietly(),
            'draft_public' => $published->forceFill(['revision_status' => 'human_review'])->saveQuietly(),
            'future' => $article->forceFill(['published_at' => now()->addDay()])->saveQuietly(),
            'private' => $article->forceFill(['is_public' => false])->saveQuietly(),
            'deleted' => $article->forceFill(['deleted_at' => now()])->saveQuietly(),
            'lifecycle' => $article->forceFill(['lifecycle_state' => Article::LIFECYCLE_ARCHIVED])->saveQuietly(),
            'projection' => $article->forceFill(['content_md' => 'Different public body'])->saveQuietly(),
            'published_hash' => $published->forceFill(['source_version_hash' => 'invalid'])->saveQuietly(),
            'published_review_drift' => $published->forceFill(['reviewed_at' => now()->subDay()])->saveQuietly(),
            'seo_drift' => $article->seoMeta->forceFill(['seo_title' => 'Concurrent metadata'])->saveQuietly(),
            'pointer_drift' => $article->forceFill(['working_revision_id' => $published->id])->saveQuietly(),
            'confirmation' => $input['archive_equivalent'] = false,
            'mfa' => session()->forget('ops_admin_totp_verified_user_id'),
            'permission' => $this->login('admin.content.read'),
        };
        $before = $this->allState();
        try {
            $service->archive($article, $input);
            $this->fail('Unsafe archive accepted: '.$case);
        } catch (ValidationException|HttpException) {
            $this->assertSame($before, $this->allState());
        }
        $this->assertDatabaseCount('audit_logs', 0);
        Http::assertNothingSent();
    }

    public static function rejectedCases(): array
    {
        return array_map(fn ($case) => [$case], ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description', 'null_empty', 'newline', 'org', 'locale', 'group', 'owner', 'source', 'published_working', 'archived_working', 'draft_public', 'future', 'private', 'deleted', 'lifecycle', 'projection', 'published_hash', 'published_review_drift', 'seo_drift', 'pointer_drift', 'confirmation', 'mfa', 'permission']);
    }

    #[DataProvider('auditFailures')]
    public function test_audit_failure_rolls_back_archive_and_pointer(string $failure): void
    {
        $article = $this->fixture();
        $before = $this->allState();
        $mock = $this->mock(AuditLogger::class);
        if ($failure === 'throw') {
            $mock->shouldReceive('log')->once()->andThrow(new RuntimeException('audit failed'));
        } else {
            $mock->shouldReceive('log')->once()->andReturnNull();
        }
        try {
            $this->archive($article);
            $this->fail('Missing audit accepted');
        } catch (RuntimeException|ValidationException) {
            $this->assertSame($before, $this->allState());
        }
        Http::assertNothingSent();
    }

    public static function auditFailures(): array
    {
        return [['throw'], ['missing']];
    }

    private function archive(Article $article): void
    {
        $service = app(Workspace::class);
        $service->archive($article, [...$service->prepare($article), 'archive_equivalent' => true]);
    }

    private function state(Article $article): array
    {
        $article = $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);

        return ['article' => $article->getAttributes(), 'working' => $article->workingRevision->getAttributes(), 'published' => $article->publishedRevision->getAttributes(), 'seo' => $article->seoMeta->getAttributes()];
    }

    private function allState(): array
    {
        return [Article::withoutGlobalScopes()->get()->toArray(), Revision::withoutGlobalScopes()->get()->toArray(), ArticleSeoMeta::withoutGlobalScopes()->get()->toArray()];
    }

    private function login(string $permission = 'admin.owner'): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com', 'admin.totp.enabled' => true]);
        $role = Role::create(['name' => 'archive-'.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
        $actor = AdminUser::create(['name' => 'Archive operator', 'email' => uniqid().'@example.test', 'password' => 'fixture-only', 'is_active' => true]);
        $actor->roles()->attach($role);
        $this->actingAs($actor, 'admin');
        session(['ops_admin_totp_verified_user_id' => $actor->id]);
    }

    private function fixture(bool $source = true): Article
    {
        $this->login();
        Http::fake();
        $article = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'equivalent-article', 'locale' => $source ? 'zh-CN' : 'en', 'source_locale' => 'zh-CN', 'source_article_id' => $source ? null : 999, 'translation_status' => $source ? 'source' : 'published', 'translation_group_id' => 'equivalent-group', 'source_version_hash' => str_repeat('b', 64), 'title' => 'Published title', 'excerpt' => '', 'content_md' => "## Body\n\nPublished content.", 'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false, 'published_at' => now()->subDay()]);
        $published = Revision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $source ? $article->id : 999, 'translation_group_id' => $article->translation_group_id, 'locale' => $article->locale, 'source_locale' => 'zh-CN', 'revision_number' => 1, 'revision_status' => 'published', 'source_version_hash' => str_repeat('a', 64), 'translated_from_version_hash' => str_repeat('c', 64), 'title' => $article->title, 'excerpt' => '', 'content_md' => $article->content_md, 'seo_title' => 'SEO title', 'seo_description' => 'SEO description', 'approved_at' => now()->subDay(), 'published_at' => now()->subDay()]);
        $working = $published->replicate();
        $working->forceFill(['revision_number' => 2, 'revision_status' => $source ? 'approved' : 'published', 'published_at' => null, 'source_version_hash' => str_repeat('b', 64)])->saveQuietly();
        $article->forceFill(['working_revision_id' => $working->id, 'published_revision_id' => $published->id])->saveQuietly();
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => $article->locale, 'seo_title' => 'SEO title', 'seo_description' => 'SEO description', 'robots' => 'noindex,follow', 'schema_json' => ['retained' => true]]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
    }
}
