<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\CareerJobResource\Support\CareerJobWorkspace;
use App\Models\AdminUser;
use App\Models\CareerJob;
use App\Models\CareerJobRevision;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerJobWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_career_job_workspace_pages_render_for_authorized_admin(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $selectedOrg = $this->createSelectedOrg();
        $job = $this->seedJob([
            'title' => 'Product Manager',
        ]);

        CareerJobWorkspace::syncWorkspaceSections($job, $this->workspaceSectionsState());
        CareerJobWorkspace::syncWorkspaceSeo($job, $this->workspaceSeoState());
        CareerJobWorkspace::createRevision($job, 'Seed revision', $admin);

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/career-jobs')
            ->assertOk()
            ->assertSee('结构化职业岗位档案的全局内容工作区', false)
            ->assertSee('创建职业岗位');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/career-jobs/create')
            ->assertOk()
            ->assertSee('ops-career-job-workspace-layout', false)
            ->assertSee('创建职业岗位');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/career-jobs/'.$job->id.'/edit')
            ->assertOk()
            ->assertSee('ops-career-job-workspace-layout', false)
            ->assertSee('Planned public URL');
    }

    public function test_workspace_sync_persists_sections_seo_revision_and_json_signals(): void
    {
        $job = $this->seedJob();

        CareerJobWorkspace::syncWorkspaceSections($job, $this->workspaceSectionsState());
        CareerJobWorkspace::syncWorkspaceSeo($job, $this->workspaceSeoState());
        CareerJobWorkspace::createRevision($job, 'Initial workspace snapshot');

        $this->assertDatabaseHas('career_job_sections', [
            'job_id' => $job->id,
            'section_key' => 'day_to_day',
            'title' => 'A typical day',
            'render_variant' => 'rich_text',
            'is_enabled' => 1,
        ]);

        $this->assertDatabaseHas('career_job_seo_meta', [
            'job_id' => $job->id,
            'seo_title' => 'Product Manager Career Guide | FermatMind',
            'robots' => 'index,follow',
        ]);

        $this->assertDatabaseHas('career_job_revisions', [
            'job_id' => $job->id,
            'revision_no' => 1,
            'note' => 'Initial workspace snapshot',
        ]);

        $job->refresh();

        $this->assertSame('USD', $job->salary_json['currency']);
        $this->assertSame(['ENTJ', 'ENFJ'], $job->mbti_primary_codes_json);
        $this->assertSame(88, $job->riasec_profile_json['E']);
    }

    public function test_workspace_sync_updates_job_sections_seo_revision_counter_and_signals(): void
    {
        $job = $this->seedJob();

        CareerJobWorkspace::syncWorkspaceSections($job, $this->workspaceSectionsState());
        CareerJobWorkspace::syncWorkspaceSeo($job, $this->workspaceSeoState());
        CareerJobWorkspace::createRevision($job, 'Initial workspace snapshot');

        $job->update([
            'title' => 'Product Manager (Updated)',
            'subtitle' => 'Sharper, clearer, and more actionable.',
            'salary_json' => [
                'currency' => 'USD',
                'region' => 'US',
                'low' => 90000,
                'median' => 135000,
                'high' => 195000,
                'notes' => 'Updated salary range after market review.',
            ],
            'mbti_primary_codes_json' => ['ENTJ', 'INTJ'],
            'riasec_profile_json' => [
                'R' => 35,
                'I' => 70,
                'A' => 52,
                'S' => 58,
                'E' => 92,
                'C' => 66,
            ],
        ]);

        $updatedSections = $this->workspaceSectionsState();
        $updatedSections['faq']['is_enabled'] = true;
        $updatedSections['faq']['payload_json_text'] = json_encode([
            'items' => [
                [
                    'question' => 'Do product managers need to code?',
                    'answer' => 'Not always, but strong product judgment and technical fluency help.',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $updatedSections['growth_story']['body_md'] = 'Updated growth story for the product manager path.';

        $updatedSeo = $this->workspaceSeoState();
        $updatedSeo['seo_description'] = 'Updated SEO summary for the product manager workspace.';

        CareerJobWorkspace::syncWorkspaceSections($job, $updatedSections);
        CareerJobWorkspace::syncWorkspaceSeo($job, $updatedSeo);
        CareerJobWorkspace::createRevision($job, 'Workspace update');

        $this->assertDatabaseHas('career_job_sections', [
            'job_id' => $job->id,
            'section_key' => 'faq',
            'is_enabled' => 1,
            'render_variant' => 'faq',
        ]);

        $this->assertDatabaseHas('career_job_seo_meta', [
            'job_id' => $job->id,
            'seo_description' => 'Updated SEO summary for the product manager workspace.',
        ]);

        $this->assertDatabaseHas('career_job_revisions', [
            'job_id' => $job->id,
            'revision_no' => 2,
            'note' => 'Workspace update',
        ]);

        $job->refresh();
        $this->assertSame(135000, $job->salary_json['median']);
        $this->assertSame(['ENTJ', 'INTJ'], $job->mbti_primary_codes_json);
        $this->assertSame(92, $job->riasec_profile_json['E']);

        $latestRevision = CareerJobRevision::query()
            ->where('job_id', $job->id)
            ->where('revision_no', 2)
            ->firstOrFail();

        $this->assertSame('Product Manager (Updated)', $latestRevision->snapshot_json['job']['title']);
        $this->assertSame('Updated growth story for the product manager path.', $latestRevision->snapshot_json['sections'][2]['body_md']);
    }

    public function test_resource_query_is_locked_to_global_career_jobs(): void
    {
        $globalJob = $this->seedJob([
            'org_id' => 0,
            'slug' => 'product-manager-global',
            'job_code' => 'product-manager-global',
        ]);

        $tenantJob = $this->seedJob([
            'org_id' => 77,
            'slug' => 'product-manager-tenant',
            'job_code' => 'product-manager-tenant',
        ]);

        $request = Request::create('/ops/career-jobs', 'GET');
        app()->instance('request', $request);

        $context = app(OrgContext::class);
        $context->set(77, 9001, 'admin');
        app()->instance(OrgContext::class, $context);

        $ids = CareerJobResource::getEloquentQuery()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertContains($globalJob->id, $ids);
        $this->assertNotContains($tenantJob->id, $ids);
    }

    public function test_workspace_ignores_unknown_section_keys(): void
    {
        $job = $this->seedJob();

        $sections = $this->workspaceSectionsState();
        $sections['rogue_section'] = [
            'title' => 'Rogue',
            'render_variant' => 'rich_text',
            'body_md' => 'Should never persist.',
            'payload_json_text' => '',
            'sort_order' => 999,
            'is_enabled' => true,
        ];

        CareerJobWorkspace::syncWorkspaceSections($job, $sections);

        $this->assertDatabaseMissing('career_job_sections', [
            'job_id' => $job->id,
            'section_key' => 'rogue_section',
        ]);
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
    private function seedJob(array $overrides = []): CareerJob
    {
        return CareerJob::query()->create(array_merge([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager',
            'subtitle' => 'Lead product direction across user, business, and engineering goals.',
            'excerpt' => 'Understand the responsibilities, salary, growth path, and personality fit for product managers.',
            'hero_kicker' => 'Career profile',
            'hero_quote' => 'Translate uncertainty into direction.',
            'cover_image_url' => 'https://example.test/product-manager.png',
            'industry_slug' => 'technology',
            'industry_label' => 'Technology',
            'body_md' => "## Role Overview\n\nGuide product direction across user, business, and engineering goals.",
            'salary_json' => [
                'currency' => 'USD',
                'region' => 'US',
                'low' => 80000,
                'median' => 120000,
                'high' => 180000,
                'notes' => 'Ranges vary by city and seniority.',
            ],
            'outlook_json' => [
                'summary' => 'Growing',
                'horizon_years' => 5,
                'notes' => 'Strong demand in AI-enabled product roles.',
            ],
            'skills_json' => [
                'core' => ['roadmapping', 'prioritization'],
                'supporting' => ['stakeholder management'],
            ],
            'work_contents_json' => [
                'items' => ['Define product strategy', 'Coordinate design and engineering execution'],
            ],
            'growth_path_json' => [
                'entry' => 'Associate Product Manager',
                'mid' => 'Product Manager',
                'senior' => 'Senior PM / Group PM',
                'notes' => 'Track can branch into leadership or strategy.',
            ],
            'fit_personality_codes_json' => ['ENTJ', 'ENFJ', 'ESTP'],
            'mbti_primary_codes_json' => ['ENTJ', 'ENFJ'],
            'mbti_secondary_codes_json' => ['ENFP', 'ENTP'],
            'riasec_profile_json' => [
                'R' => 42,
                'I' => 66,
                'A' => 56,
                'S' => 60,
                'E' => 88,
                'C' => 70,
            ],
            'big5_targets_json' => [
                'openness' => 'high',
                'conscientiousness' => 'high',
                'extraversion' => 'balanced',
                'agreeableness' => 'balanced',
                'neuroticism' => 'low',
            ],
            'iq_eq_notes_json' => [
                'iq' => 'Requires strong analytical reasoning.',
                'eq' => 'Requires stakeholder empathy and communication.',
            ],
            'market_demand_json' => [
                'signal' => 'high',
                'notes' => 'Demand remains strong across SaaS, fintech, and AI startups.',
            ],
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 10,
            'schema_version' => 'v1',
        ], $overrides));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function workspaceSectionsState(): array
    {
        return array_replace_recursive(CareerJobWorkspace::defaultWorkspaceSectionsState(), [
            'day_to_day' => [
                'is_enabled' => true,
                'body_md' => 'Mornings focus on priorities, afternoons on alignment, and evenings on decision follow-through.',
            ],
            'skills_explained' => [
                'is_enabled' => true,
                'render_variant' => 'cards',
                'payload_json_text' => json_encode([
                    'items' => [
                        [
                            'title' => 'Roadmapping',
                            'body' => 'Turn ambiguity into clear priorities and staged delivery decisions.',
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'growth_story' => [
                'is_enabled' => true,
                'body_md' => 'The path often begins with execution detail, then expands into prioritization, strategy, and people influence.',
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function workspaceSeoState(): array
    {
        return [
            'seo_title' => 'Product Manager Career Guide | FermatMind',
            'seo_description' => 'Responsibilities, salary, growth path, and personality fit for product managers.',
            'canonical_url' => '',
            'og_title' => 'Product Manager Career Guide',
            'og_description' => 'Responsibilities, salary, growth path, and personality fit for product managers.',
            'og_image_url' => 'https://example.test/product-manager-og.png',
            'twitter_title' => 'Product Manager Career Guide',
            'twitter_description' => 'Responsibilities, salary, growth path, and personality fit for product managers.',
            'twitter_image_url' => 'https://example.test/product-manager-twitter.png',
            'robots' => 'index,follow',
        ];
    }
}
