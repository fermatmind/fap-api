<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CareerJob;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PersonalityProfile;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CareerGuideWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_career_guide_workspace_pages_render_for_authorized_admin(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $selectedOrg = $this->createSelectedOrg();
        $guide = $this->seedGuide([
            'title' => 'Annual Career Review System',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $job = $this->seedJob();
        $article = $this->seedArticle();
        $profile = $this->seedProfile();

        CareerGuideWorkspace::syncRelatedJobs($guide, [
            ['career_job_id' => (int) $job->id],
        ]);
        CareerGuideWorkspace::syncRelatedArticles($guide, [
            ['article_id' => (int) $article->id],
        ]);
        CareerGuideWorkspace::syncRelatedPersonalityProfiles($guide, [
            ['personality_profile_id' => (int) $profile->id],
        ]);
        CareerGuideWorkspace::syncWorkspaceSeo($guide, $this->workspaceSeoState());
        CareerGuideWorkspace::createRevision($guide, 'Seed revision', $admin);

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/career-guides')
            ->assertOk()
            ->assertSee('Global career content workspace', false)
            ->assertSee('Create Career Guide');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/career-guides/create')
            ->assertOk()
            ->assertSee('ops-career-job-workspace-layout', false)
            ->assertSee('Create Career Guide')
            ->assertDontSee('Open Public URL');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/career-guides/'.$guide->id.'/edit')
            ->assertOk()
            ->assertSee('ops-career-job-workspace-layout', false)
            ->assertSee('Planned public URL')
            ->assertSee('Planned canonical')
            ->assertDontSee('Open Public URL');
    }

    public function test_workspace_create_persistence_writes_relations_seo_and_initial_revision(): void
    {
        $guide = $this->seedGuide([
            'title' => 'From MBTI to Job Fit',
            'excerpt' => 'How to translate MBTI insights into better career decisions.',
            'category_slug' => 'assessment-usage',
            'body_md' => '# Guide body',
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'related_industry_slugs_json' => CareerGuideWorkspace::normalizeIndustrySlugs([' consulting ', 'Consulting', 'manufacturing']),
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now(),
            'sort_order' => 12,
        ]);

        $jobs = [
            $this->seedJob([
                'job_code' => 'product-manager',
                'slug' => 'product-manager',
                'title' => 'Product Manager',
            ]),
            $this->seedJob([
                'job_code' => 'ux-researcher',
                'slug' => 'ux-researcher',
                'title' => 'UX Researcher',
            ]),
        ];
        $articles = [
            $this->seedArticle([
                'slug' => 'how-to-read-mbti-results',
                'title' => 'How to Read MBTI Results',
            ]),
        ];
        $profiles = [
            $this->seedProfile([
                'type_code' => 'INTJ',
                'slug' => 'intj',
                'title' => 'INTJ Personality Type',
            ]),
        ];

        CareerGuideWorkspace::syncRelatedJobs($guide, [
            ['career_job_id' => (int) $jobs[0]->id],
            ['career_job_id' => (int) $jobs[1]->id],
        ]);
        CareerGuideWorkspace::syncRelatedArticles($guide, [
            ['article_id' => (int) $articles[0]->id],
        ]);
        CareerGuideWorkspace::syncRelatedPersonalityProfiles($guide, [
            ['personality_profile_id' => (int) $profiles[0]->id],
        ]);
        CareerGuideWorkspace::syncWorkspaceSeo($guide, $this->workspaceSeoState());
        CareerGuideWorkspace::createRevision($guide, 'Initial workspace snapshot');

        $this->assertDatabaseHas('career_guides', [
            'id' => $guide->id,
            'title' => 'From MBTI to Job Fit',
            'excerpt' => 'How to translate MBTI insights into better career decisions.',
            'category_slug' => 'assessment-usage',
            'body_md' => '# Guide body',
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'locale' => 'en',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => 1,
            'is_indexable' => 0,
            'sort_order' => 12,
        ]);

        $guide->refresh();
        $this->assertSame(['consulting', 'manufacturing'], $guide->related_industry_slugs_json);

        $this->assertDatabaseHas('career_guide_job_map', [
            'career_guide_id' => $guide->id,
            'career_job_id' => $jobs[0]->id,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('career_guide_job_map', [
            'career_guide_id' => $guide->id,
            'career_job_id' => $jobs[1]->id,
            'sort_order' => 20,
        ]);
        $this->assertDatabaseHas('career_guide_article_map', [
            'career_guide_id' => $guide->id,
            'article_id' => $articles[0]->id,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('career_guide_personality_map', [
            'career_guide_id' => $guide->id,
            'personality_profile_id' => $profiles[0]->id,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('career_guide_seo_meta', [
            'career_guide_id' => $guide->id,
            'seo_title' => 'From MBTI to Job Fit | FermatMind',
            'robots' => 'noindex,follow',
        ]);
        $this->assertDatabaseHas('career_guide_revisions', [
            'career_guide_id' => $guide->id,
            'revision_no' => 1,
            'note' => 'Initial workspace snapshot',
        ]);
    }

    public function test_workspace_edit_persistence_updates_relations_seo_and_revision_counter(): void
    {
        $guide = $this->seedGuide([
            'title' => 'Annual Career Review System',
            'category_slug' => 'career-planning',
            'body_md' => 'First draft body',
        ]);

        $productManager = $this->seedJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'title' => 'Product Manager',
        ]);
        $strategyAnalyst = $this->seedJob([
            'job_code' => 'strategy-analyst',
            'slug' => 'strategy-analyst',
            'title' => 'Strategy Analyst',
        ]);
        $articleA = $this->seedArticle([
            'slug' => 'career-review-mistakes',
            'title' => 'Career Review Mistakes',
        ]);
        $articleB = $this->seedArticle([
            'slug' => 'how-to-read-mbti-results',
            'title' => 'How to Read MBTI Results',
        ]);
        $profileA = $this->seedProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ Personality Type',
        ]);
        $profileB = $this->seedProfile([
            'type_code' => 'ENFJ',
            'slug' => 'enfj',
            'title' => 'ENFJ Personality Type',
        ]);

        CareerGuideWorkspace::syncRelatedJobs($guide, [
            ['career_job_id' => (int) $productManager->id],
        ]);
        CareerGuideWorkspace::syncRelatedArticles($guide, [
            ['article_id' => (int) $articleA->id],
        ]);
        CareerGuideWorkspace::syncRelatedPersonalityProfiles($guide, [
            ['personality_profile_id' => (int) $profileA->id],
        ]);
        CareerGuideWorkspace::syncWorkspaceSeo($guide, $this->workspaceSeoState());
        CareerGuideWorkspace::createRevision($guide, 'Initial workspace snapshot');

        $guide->update([
            'title' => 'Annual Career Review System (Updated)',
            'excerpt' => 'A sharper yearly system for reviewing your career.',
            'category_slug' => 'career-transition',
            'body_md' => 'Updated body for the career guide.',
            'related_industry_slugs_json' => CareerGuideWorkspace::normalizeIndustrySlugs(['strategy', 'operations']),
            'is_public' => false,
            'is_indexable' => true,
            'sort_order' => 20,
        ]);

        CareerGuideWorkspace::syncRelatedJobs($guide, [
            ['career_job_id' => (int) $strategyAnalyst->id],
            ['career_job_id' => (int) $productManager->id],
        ]);
        CareerGuideWorkspace::syncRelatedArticles($guide, [
            ['article_id' => (int) $articleB->id],
            ['article_id' => (int) $articleA->id],
        ]);
        CareerGuideWorkspace::syncRelatedPersonalityProfiles($guide, [
            ['personality_profile_id' => (int) $profileB->id],
            ['personality_profile_id' => (int) $profileA->id],
        ]);

        $updatedSeo = $this->workspaceSeoState();
        $updatedSeo['seo_description'] = 'Updated SEO summary for the annual career review guide.';
        $updatedSeo['twitter_title'] = 'Annual Career Review Guide';

        CareerGuideWorkspace::syncWorkspaceSeo($guide, $updatedSeo);
        CareerGuideWorkspace::createRevision($guide, 'Workspace update');

        $this->assertDatabaseHas('career_guides', [
            'id' => $guide->id,
            'title' => 'Annual Career Review System (Updated)',
            'category_slug' => 'career-transition',
            'body_md' => 'Updated body for the career guide.',
            'is_public' => 0,
            'is_indexable' => 1,
            'sort_order' => 20,
        ]);

        $this->assertDatabaseHas('career_guide_job_map', [
            'career_guide_id' => $guide->id,
            'career_job_id' => $strategyAnalyst->id,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('career_guide_job_map', [
            'career_guide_id' => $guide->id,
            'career_job_id' => $productManager->id,
            'sort_order' => 20,
        ]);
        $this->assertDatabaseHas('career_guide_article_map', [
            'career_guide_id' => $guide->id,
            'article_id' => $articleB->id,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('career_guide_personality_map', [
            'career_guide_id' => $guide->id,
            'personality_profile_id' => $profileB->id,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('career_guide_seo_meta', [
            'career_guide_id' => $guide->id,
            'seo_description' => 'Updated SEO summary for the annual career review guide.',
            'twitter_title' => 'Annual Career Review Guide',
        ]);
        $this->assertDatabaseHas('career_guide_revisions', [
            'career_guide_id' => $guide->id,
            'revision_no' => 2,
            'note' => 'Workspace update',
        ]);

        $latestRevision = CareerGuideRevision::query()
            ->where('career_guide_id', $guide->id)
            ->where('revision_no', 2)
            ->firstOrFail();

        $this->assertSame('Annual Career Review System (Updated)', $latestRevision->snapshot_json['guide']['title']);
        $this->assertSame('strategy-analyst', $latestRevision->snapshot_json['related_jobs'][0]['job_code']);
        $this->assertSame('how-to-read-mbti-results', $latestRevision->snapshot_json['related_articles'][0]['slug']);
        $this->assertSame('ENFJ', $latestRevision->snapshot_json['related_personality_profiles'][0]['type_code']);
    }

    public function test_resource_query_is_locked_to_global_career_guides(): void
    {
        $globalGuide = $this->seedGuide([
            'org_id' => 0,
            'guide_code' => 'annual-career-review-system',
            'slug' => 'annual-career-review-system',
        ]);

        $tenantGuide = $this->seedGuide([
            'org_id' => 77,
            'guide_code' => 'tenant-guide',
            'slug' => 'tenant-guide',
            'title' => 'Tenant Guide',
        ]);

        $request = Request::create('/ops/career-guides', 'GET');
        app()->instance('request', $request);

        $context = app(OrgContext::class);
        $context->set(77, 9001, 'admin');
        app()->instance(OrgContext::class, $context);

        $ids = CareerGuideResource::getEloquentQuery()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertContains($globalGuide->id, $ids);
        $this->assertNotContains($tenantGuide->id, $ids);
    }

    public function test_relation_validation_rejects_cross_locale_targets(): void
    {
        $zhJob = $this->seedJob([
            'locale' => 'zh-CN',
            'job_code' => 'product-manager-zh',
            'slug' => 'product-manager-zh',
            'title' => '产品经理',
        ]);
        $zhArticle = $this->seedArticle([
            'locale' => 'zh-CN',
            'slug' => 'career-review-zh',
            'title' => '职业复盘指南',
        ]);
        $zhProfile = $this->seedProfile([
            'locale' => 'zh-CN',
            'type_code' => 'INTJ',
            'slug' => 'intj-zh',
            'title' => 'INTJ 人格类型',
        ]);

        try {
            CareerGuideWorkspace::normalizeRelatedJobRows([
                ['career_job_id' => (int) $zhJob->id],
            ], 'en');
            $this->fail('Expected cross-locale career job validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Only global career jobs in the selected locale can be attached.',
                $exception->errors()['workspace_related_jobs'][0] ?? null
            );
        }

        try {
            CareerGuideWorkspace::normalizeRelatedArticleRows([
                ['article_id' => (int) $zhArticle->id],
            ], 'en');
            $this->fail('Expected cross-locale article validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Only global articles in the selected locale can be attached.',
                $exception->errors()['workspace_related_articles'][0] ?? null
            );
        }

        try {
            CareerGuideWorkspace::normalizeRelatedPersonalityRows([
                ['personality_profile_id' => (int) $zhProfile->id],
            ], 'en');
            $this->fail('Expected cross-locale personality validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Only global MBTI personality profiles in the selected locale can be attached.',
                $exception->errors()['workspace_related_personality_profiles'][0] ?? null
            );
        }
    }

    private function createSelectedOrg(): Organization
    {
        return Organization::query()->create([
            'name' => 'Ops Workspace Org',
            'owner_user_id' => 9001,
            'status' => 'active',
            'domain' => 'ops-workspace.example.test',
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
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        if ($permissions === []) {
            return $admin;
        }

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(10)),
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
     * @param  array<string, mixed>  $overrides
     */
    private function seedGuide(array $overrides = []): CareerGuide
    {
        return CareerGuide::query()->create(array_merge([
            'org_id' => 0,
            'guide_code' => 'annual-career-review-system',
            'slug' => 'annual-career-review-system',
            'locale' => 'en',
            'title' => 'Annual Career Review System',
            'excerpt' => 'A repeatable system for reviewing your career once a year.',
            'category_slug' => 'career-planning',
            'body_md' => 'Use this guide to review your career progress.',
            'body_html' => '<p>Use this guide to review your career progress.</p>',
            'related_industry_slugs_json' => ['technology', 'strategy'],
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedJob(array $overrides = []): CareerJob
    {
        return CareerJob::query()->create(array_merge([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager',
            'excerpt' => 'Translate ambiguity into roadmap decisions.',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedArticle(array $overrides = []): Article
    {
        return Article::query()->create(array_merge([
            'org_id' => 0,
            'slug' => 'career-review-mistakes',
            'locale' => 'en',
            'title' => 'Career Review Mistakes',
            'excerpt' => 'What to avoid during a yearly career review.',
            'content_md' => 'Article body',
            'content_html' => '<p>Article body</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedProfile(array $overrides = []): PersonalityProfile
    {
        return PersonalityProfile::query()->create(array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'excerpt' => 'Strategic and independent.',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'schema_version' => 'v1',
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceSeoState(): array
    {
        return [
            'seo_title' => 'From MBTI to Job Fit | FermatMind',
            'seo_description' => 'Translate personality insights into practical career decisions.',
            'canonical_url' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
            'og_title' => 'MBTI to Job Fit',
            'og_description' => 'Practical MBTI career guide.',
            'og_image_url' => 'https://cdn.example.test/career-guides/mbti-job-fit-og.png',
            'twitter_title' => 'MBTI to Job Fit',
            'twitter_description' => 'Practical MBTI career guide.',
            'twitter_image_url' => 'https://cdn.example.test/career-guides/mbti-job-fit-twitter.png',
            'robots' => 'noindex,follow',
        ];
    }
}
