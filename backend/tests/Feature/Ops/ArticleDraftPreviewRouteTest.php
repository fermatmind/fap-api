<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleDraftPreviewRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_read_admin_can_preview_unpublished_noindex_article_without_publication_side_effects(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $org = $this->createOrganization();
        $article = $this->createDraftArticle();
        $revision = $this->createWorkingRevision($article);
        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Preview SEO Title',
            'seo_description' => 'Preview SEO description.',
            'canonical_url' => 'https://fermatmind.com/en/articles/preview-draft',
            'robots' => 'noindex,nofollow',
            'is_indexable' => false,
        ]);

        $response = $this
            ->withSession($this->opsSession($admin, $org))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/article-preview/'.$article->id);

        $response
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, noarchive, nosnippet')
            ->assertSee('CMS Article Draft Preview')
            ->assertSee('Working Revision Preview Title')
            ->assertSee('Draft preview only')
            ->assertSee('https://assets.fermatmind.com/storage/media-library/variants/articlepreviewcoverv1/hero_1600x900.jpg', false)
            ->assertSee('https://assets.fermatmind.com/storage/media-library/variants/articlepreviewbodyvisualv1/hero_1600x900.jpg', false)
            ->assertSee('data-preview-media="body_visual"', false)
            ->assertSee('Body visual from public API media metadata.')
            ->assertSee('Body visual asset key')
            ->assertSee('article.preview.body-visual.v1')
            ->assertSee('Body visual fallback authorized')
            ->assertSee('false')
            ->assertSee('schema_enabled: false')
            ->assertSee('hreflang_enabled: false')
            ->assertSee('revalidation_allowed: false')
            ->assertSee('[redacted-private-url]')
            ->assertDontSee('abc123', false)
            ->assertDontSee('application/ld+json', false)
            ->assertDontSee('rel=\"canonical\"', false)
            ->assertDontSee('rel=\"alternate\"', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertFalse((bool) $article->is_public);
        $this->assertFalse((bool) $article->is_indexable);
        $this->assertNull($article->published_revision_id);
        $this->assertSame((int) $revision->id, (int) $article->working_revision_id);
    }

    public function test_preview_route_requires_content_read_authorization(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_OPS_READ]);
        $org = $this->createOrganization();
        $article = $this->createDraftArticle();

        $this
            ->withSession($this->opsSession($admin, $org))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/article-preview/'.$article->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'admin_content_read_required');
    }

    public function test_preview_route_requires_authenticated_ops_session(): void
    {
        $article = $this->createDraftArticle();

        $this
            ->get('/ops/article-preview/'.$article->id)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'admin_token_missing');
    }

    public function test_article_workspace_builds_ops_preview_url_instead_of_public_canonical_url(): void
    {
        $article = $this->createDraftArticle();

        $this->assertSame(
            url('/ops/article-preview/'.$article->id),
            ArticleWorkspace::previewUrl($article)
        );
    }

    private function createDraftArticle(): Article
    {
        return Article::query()->create([
            'org_id' => 0,
            'slug' => 'preview-draft-'.Str::lower(Str::random(6)),
            'locale' => 'en',
            'title' => 'Article Row Draft Title',
            'excerpt' => 'Article row excerpt.',
            'content_md' => 'Article row body.',
            'cover_image_url' => 'https://assets.fermatmind.com/storage/media-library/variants/articlepreviewcoverv1/hero_1600x900.jpg',
            'cover_image_alt' => 'Preview cover image',
            'cover_image_variants' => [
                'hero' => [
                    'url' => 'https://assets.fermatmind.com/storage/media-library/variants/articlepreviewcoverv1/hero_1600x900.jpg',
                    'width' => 1600,
                    'height' => 900,
                ],
                'editorial_package_v1' => [
                    'body_visual_asset_key' => 'article.preview.body-visual.v1',
                    'body_visual_image_url' => 'https://assets.fermatmind.com/storage/media-library/variants/articlepreviewbodyvisualv1/hero_1600x900.jpg',
                    'body_visual_fallback_authorized' => false,
                ],
            ],
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
        ]);
    }

    private function createWorkingRevision(Article $article): ArticleTranslationRevision
    {
        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'title' => 'Working Revision Preview Title',
            'excerpt' => 'Working revision excerpt.',
            'content_md' => '## Body\\n\\nDraft body with /result/abc123?token=abc123 private link.',
            'seo_title' => 'Revision SEO Title',
            'seo_description' => 'Revision SEO description.',
        ]);

        $article->forceFill(['working_revision_id' => $revision->id])->save();

        return $revision;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'article_preview_'.Str::lower(Str::random(6)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['guard_name' => (string) config('admin.guard', 'admin')]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Article Preview Org',
            'owner_user_id' => 9101,
            'status' => 'active',
            'domain' => 'article-preview.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function opsSession(AdminUser $admin, Organization $org): array
    {
        return [
            'ops_org_id' => $org->id,
            'ops_locale' => 'en',
            'ops_admin_totp_verified_user_id' => $admin->id,
        ];
    }
}
