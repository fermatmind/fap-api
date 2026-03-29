<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ContentReleasePage;
use App\Filament\Ops\Pages\PostReleaseObservabilityPage;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\AuditLog;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class PostReleaseObservabilityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_post_release_observability_requires_org_selection(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/post-release-observability')
            ->assertRedirectContains('/ops/select-org');
    }

    public function test_post_release_observability_shows_recent_publish_state_and_audits(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $owner = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $selectedOrg = $this->createOrganization('Release Observability Org');

        $article = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'observability-article',
            'locale' => 'en',
            'title' => 'Observability Article',
            'excerpt' => 'Observability article excerpt',
            'content_md' => 'Observability body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Observability Article SEO Title',
            'seo_description' => 'Observability Article SEO Description',
            'canonical_url' => 'https://example.test/articles/observability-article',
            'og_title' => 'Observability Article OG Title',
            'og_description' => 'Observability Article OG Description',
            'og_image_url' => 'https://example.test/images/observability-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $guide = CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'observability-guide',
            'slug' => 'observability-guide',
            'locale' => 'en',
            'title' => 'Observability Guide',
            'excerpt' => 'Observability guide excerpt',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide body',
            'body_html' => '<p>Guide body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
            'published_at' => Carbon::now()->subHours(4),
        ]);
        CareerGuideSeoMeta::query()->create([
            'career_guide_id' => (int) $guide->id,
            'seo_title' => 'Observability Guide SEO Title',
            'seo_description' => 'Observability Guide SEO Description',
            'canonical_url' => 'https://example.test/guides/observability-guide',
            'og_title' => 'Observability Guide OG Title',
            'og_description' => 'Observability Guide OG Description',
            'og_image_url' => 'https://example.test/images/observability-guide.png',
            'twitter_title' => 'Observability Guide Twitter Title',
            'twitter_description' => 'Observability Guide Twitter Description',
            'twitter_image_url' => 'https://example.test/images/observability-guide-twitter.png',
            'robots' => 'index,follow',
        ]);

        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'observability-job',
            'slug' => 'observability-job',
            'locale' => 'en',
            'title' => 'Observability Job',
            'excerpt' => 'Observability job excerpt',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => false,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'published_at' => Carbon::now()->subHours(2),
        ]);
        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'seo_title' => 'Observability Job SEO Title',
            'seo_description' => 'Observability Job SEO Description',
            'canonical_url' => 'https://example.test/jobs/observability-job',
            'og_title' => 'Observability Job OG Title',
            'og_description' => 'Observability Job OG Description',
            'og_image_url' => 'https://example.test/images/observability-job.png',
            'twitter_title' => 'Observability Job Twitter Title',
            'twitter_description' => 'Observability Job Twitter Description',
            'twitter_image_url' => 'https://example.test/images/observability-job-twitter.png',
            'robots' => 'index,follow',
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-release', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        $this->routeRecordIntoReview((int) $selectedOrg->id, $owner, $admin, 'article', $article);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/editorial-review', 'POST'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);
        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_APPROVED, 'article', $article);

        Livewire::test(ContentReleasePage::class)
            ->assertOk()
            ->call('releaseItem', 'article', (int) $article->id);

        $article->refresh();

        $this->assertSame('published', $article->status);
        $this->assertTrue($article->is_public);
        $this->assertNotNull($article->published_at);

        $audit = AuditLog::query()
            ->where('action', 'content_release_publish')
            ->where('target_type', 'article')
            ->where('target_id', (string) $article->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame((int) $selectedOrg->id, (int) $audit->org_id);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/post-release-observability')
            ->assertOk()
            ->assertSee('Post-release observability')
            ->assertSee('Release telemetry')
            ->assertSee('Recently published records')
            ->assertSee('Recent publish audits')
            ->assertSee('Observability Article');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/post-release-observability', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(PostReleaseObservabilityPage::class)
            ->assertOk()
            ->assertSet('headlineFields.0.value', '3')
            ->assertSet('headlineFields.1.value', '1')
            ->assertSet('headlineFields.2.value', '2')
            ->assertSet('headlineFields.3.value', '1')
            ->assertSet('headlineFields.4.value', '1')
            ->assertSet('releaseCards.0.title', 'Observability Article')
            ->assertSet('auditCards.0.title', 'Observability Article');
    }

    public function test_resource_release_action_writes_audits_and_observability_ignores_other_org_rows(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $owner = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $selectedOrg = $this->createOrganization('Selected Observability Org');
        $otherOrg = $this->createOrganization('Other Observability Org');

        $article = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'resource-release-article',
            'locale' => 'en',
            'title' => 'Resource Release Article',
            'excerpt' => 'Resource release excerpt',
            'content_md' => 'Resource release body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Resource Release Article SEO Title',
            'seo_description' => 'Resource Release Article SEO Description',
            'canonical_url' => 'https://example.test/articles/resource-release-article',
            'og_title' => 'Resource Release Article OG Title',
            'og_description' => 'Resource Release Article OG Description',
            'og_image_url' => 'https://example.test/images/resource-release-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/articles', 'POST'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        $this->routeRecordIntoReview((int) $selectedOrg->id, $owner, $admin, 'article', $article);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/editorial-review', 'POST'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);
        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_APPROVED, 'article', $article);
        ArticleResource::releaseRecord($article, 'resource_table');

        AuditLog::withoutGlobalScopes()->create([
            'org_id' => (int) $otherOrg->id,
            'actor_admin_id' => (int) $admin->id,
            'action' => 'content_release_publish',
            'target_type' => 'article',
            'target_id' => '99999',
            'meta_json' => ['title' => 'Other Org Audit', 'source' => 'resource_table'],
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'other-org-audit',
            'reason' => 'cms_release_workspace',
            'result' => 'success',
            'created_at' => now(),
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/post-release-observability')
            ->assertOk()
            ->assertSee('Resource Release Article')
            ->assertDontSee('Other Org Audit');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/post-release-observability', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(PostReleaseObservabilityPage::class)
            ->assertOk()
            ->assertSet('headlineFields.1.value', '1')
            ->assertSet('auditCards.0.description', 'Actor: [REDACTED] | Visibility: public | Source: resource_table');
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'owner_user_id' => random_int(1000, 9999),
            'status' => 'active',
            'domain' => Str::slug($name).'.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_'.Str::lower(Str::random(6)),
            'email' => 'ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    /**
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int}
     */
    private function opsSession(AdminUser $admin, Organization $selectedOrg): array
    {
        return [
            'ops_org_id' => (int) $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];
    }

    private function routeRecordIntoReview(int $orgId, AdminUser $owner, AdminUser $reviewer, string $type, object $record): void
    {
        $this->actingAs($owner, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/editorial-review', 'POST'));

        $context = app(OrgContext::class);
        $context->set($orgId, (int) $owner->id, 'admin');
        app()->instance(OrgContext::class, $context);
        EditorialReviewAudit::assignOwner((int) $owner->id, $type, $record);

        $this->actingAs($reviewer, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/editorial-review', 'POST'));

        $context = app(OrgContext::class);
        $context->set($orgId, (int) $reviewer->id, 'admin');
        app()->instance(OrgContext::class, $context);
        EditorialReviewAudit::assignReviewer((int) $reviewer->id, $type, $record);

        $this->actingAs($owner, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/editorial-review', 'POST'));

        $context = app(OrgContext::class);
        $context->set($orgId, (int) $owner->id, 'admin');
        app()->instance(OrgContext::class, $context);
        EditorialReviewAudit::submit($type, $record);
    }
}
