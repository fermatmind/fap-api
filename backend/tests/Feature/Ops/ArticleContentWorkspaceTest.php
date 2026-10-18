<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Support\ArticleContentWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArticleContentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_denial_does_not_query_content(): void
    {
        $this->assertSame(['state' => 'permission_denied', 'articles' => [], 'selected' => null], app(ArticleContentWorkspace::class)->read());
    }

    public function test_selected_article_uses_real_revisions_and_read_permissions_without_writes(): void
    {
        $this->login(PermissionNames::ADMIN_CONTENT_READ);
        $article = $this->article();
        $published = $this->revision($article, 'published');
        $working = $this->revision($article, 'machine_draft');
        $working->update(['content_md' => '## Working body', 'seo_title' => 'New SEO title']);
        $article->update(['working_revision_id' => $working->id, 'published_revision_id' => $published->id, 'status' => 'published', 'is_public' => true]);
        $before = $article->fresh()->getRawOriginal();
        $snapshot = app(ArticleContentWorkspace::class)->read((int) $article->id);
        $row = $snapshot['selected'];
        $this->assertSame('available', $snapshot['state']);
        $this->assertSame($working->id, $row['working_revision_id']);
        $this->assertSame($published->id, $row['published_revision_id']);
        $this->assertSame(['content_md', 'seo_title'], $row['changed_fields']);
        $this->assertNull($row['edit_url']);
        $this->assertStringContainsString('revision='.$working->id, $row['preview_url']);
        $this->assertStringContainsString('revision_hash=', $row['preview_url']);
        $this->assertNotNull($row['public_url']);
        $this->assertTrue($row['checks']['metadata']);
        $this->assertFalse($row['checks']['revision_review']);
        $this->assertSame($before, $article->fresh()->getRawOriginal());
        $this->assertDatabaseCount('article_translation_revisions', 2);
    }

    public function test_missing_revision_private_and_foreign_articles_do_not_offer_public_actions(): void
    {
        $this->login(PermissionNames::ADMIN_CONTENT_WRITE);
        $article = $this->article();
        $row = app(ArticleContentWorkspace::class)->read((int) $article->id)['selected'];
        $this->assertNotNull($row['edit_url']);
        $this->assertNull($row['preview_url']);
        $this->assertNull($row['public_url']);
        $this->assertFalse($row['checks']['metadata']);
        $foreign = $this->article(77);
        $snapshot = app(ArticleContentWorkspace::class)->read((int) $foreign->id);
        $this->assertSame('empty', $snapshot['state']);
        $this->assertNull($snapshot['selected']);
        $this->assertNotContains($foreign->id, array_column($snapshot['articles'], 'id'));
    }

    public function test_archived_public_article_and_mismatched_revision_fail_closed(): void
    {
        $this->login(PermissionNames::ADMIN_CONTENT_WRITE);
        $article = $this->article();
        $published = $this->revision($article, 'published');
        $article->update(['working_revision_id' => $published->id, 'published_revision_id' => $published->id, 'status' => 'published', 'is_public' => true, 'lifecycle_state' => Article::LIFECYCLE_ARCHIVED]);
        $this->assertNull(app(ArticleContentWorkspace::class)->read((int) $article->id)['selected']['public_url']);
        $other = $this->article();
        $other->update(['working_revision_id' => $published->id]);
        $this->assertSame('revision_mismatch', app(ArticleContentWorkspace::class)->read((int) $other->id)['selected']['state']);
    }

    public function test_workspace_renders_and_native_draft_preflight_is_read_only(): void
    {
        $this->login(PermissionNames::ADMIN_CONTENT_READ);
        \Filament\Facades\Filament::setCurrentPanel(app(\Filament\PanelRegistry::class)->get('ops'));
        $article = $this->article();
        $revision = $this->revision($article, 'machine_draft');
        $article->update(['working_revision_id' => $revision->id]);
        \Livewire\Livewire::withQueryParams(['workspace' => 'content', 'article' => $article->id])
            ->test(\App\Filament\Ops\Pages\SeoOperationsPage::class)
            ->assertSee('Current working revision checks')
            ->assertSee('Publication preflight (read only)')
            ->assertDontSee('Edit working revision')
            ->call('inspectArticlePublication')
            ->assertSet('articlePublicationPreflight.state', 'needs_attention')
            ->assertSet('articlePublicationPreflight.revision_id', $revision->id);
        $this->assertFalse((bool) $article->fresh()->is_public);
        $this->assertNull($article->fresh()->published_revision_id);
        $this->assertDatabaseCount('article_translation_revisions', 1);
    }

    public function test_published_current_revision_does_not_request_republication(): void
    {
        $this->login(PermissionNames::ADMIN_CONTENT_READ);
        $article = $this->article();
        $revision = $this->revision($article, 'published');
        $article->update(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id, 'status' => 'published', 'is_public' => true]);
        $page = new \App\Filament\Ops\Pages\SeoOperationsPage;
        $page->contentArticleId = (int) $article->id;
        $page->inspectArticlePublication();
        $this->assertSame('already_current', $page->articlePublicationPreflight['state']);
        $this->assertDatabaseCount('article_translation_revisions', 1);
    }

    public function test_translation_source_requires_present_equal_hashes(): void
    {
        $this->login(PermissionNames::ADMIN_CONTENT_READ);
        $source = $this->article();
        $sourceRevision = $this->revision($source, 'published');
        $source->update(['working_revision_id' => $sourceRevision->id]);
        $translation = $this->article();
        $translation->update(['source_article_id' => $source->id, 'source_locale' => 'zh-CN', 'translation_status' => 'machine_draft']);
        $revision = $this->revision($translation, 'machine_draft');
        $translation->update(['working_revision_id' => $revision->id]);
        $read = fn () => app(ArticleContentWorkspace::class)->read((int) $translation->id)['selected']['checks']['source_current'];
        $this->assertFalse($read());
        $sourceRevision->update(['source_version_hash' => str_repeat('a', 64)]);
        $this->assertFalse($read());
        $revision->update(['translated_from_version_hash' => str_repeat('a', 64)]);
        $this->assertTrue($read());
        $revision->update(['translated_from_version_hash' => str_repeat('b', 64)]);
        $this->assertFalse($read());
    }

    private function article(int $org = 0): Article
    {
        return Article::withoutGlobalScopes()->create(['org_id' => $org, 'slug' => 'workspace-'.uniqid(), 'locale' => 'en', 'source_locale' => 'en', 'translation_status' => 'source', 'title' => 'Workspace article', 'excerpt' => 'Excerpt', 'content_md' => '## Published body', 'status' => 'draft', 'is_public' => false]);
    }

    private function revision(Article $article, string $status): ArticleTranslationRevision
    {
        return ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => $article->org_id, 'article_id' => $article->id, 'source_article_id' => $article->id, 'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => 'en', 'revision_number' => ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $article->id)->count() + 1, 'revision_status' => $status, 'title' => 'Workspace article', 'excerpt' => 'Excerpt', 'content_md' => '## Published body', 'seo_title' => 'SEO title', 'seo_description' => 'SEO description']);
    }

    private function login(string $permission): void
    {
        $role = Role::query()->create(['name' => 'cms-workspace-'.uniqid()]);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['description' => $permission]));
        $admin = AdminUser::query()->create(['name' => 'CMS workspace', 'email' => uniqid().'@example.test', 'password' => 'test-only-password', 'is_active' => true]);
        $admin->roles()->attach($role);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
    }
}
