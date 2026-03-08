<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\PersonalityProfileResource;
use App\Filament\Ops\Resources\PersonalityProfileResource\Support\PersonalityWorkspace;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonalityWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_personality_workspace_pages_render_for_authorized_admin(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $selectedOrg = $this->createSelectedOrg();
        $profile = $this->seedProfile([
            'title' => 'INTJ - Architect',
        ]);

        PersonalityWorkspace::syncWorkspaceSections($profile, $this->workspaceSectionsState());
        PersonalityWorkspace::syncWorkspaceSeo($profile, $this->workspaceSeoState());
        PersonalityWorkspace::createRevision($profile, 'Seed revision');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/personality')
            ->assertOk()
            ->assertSee('Global MBTI content workspace', false)
            ->assertSee('Create Personality Profile');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/personality/create')
            ->assertOk()
            ->assertSee('ops-personality-workspace-layout', false)
            ->assertSee('Create Personality Profile');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/personality/'.$profile->id.'/edit')
            ->assertOk()
            ->assertSee('ops-personality-workspace-layout', false)
            ->assertSee('Planned public URL');
    }

    public function test_workspace_sync_persists_sections_seo_and_initial_revision(): void
    {
        $profile = $this->seedProfile();

        PersonalityWorkspace::syncWorkspaceSections($profile, $this->workspaceSectionsState());
        PersonalityWorkspace::syncWorkspaceSeo($profile, $this->workspaceSeoState());
        PersonalityWorkspace::createRevision($profile, 'Initial workspace snapshot');

        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => $profile->id,
            'section_key' => 'core_snapshot',
            'title' => 'Core snapshot',
            'render_variant' => 'rich_text',
            'is_enabled' => 1,
        ]);

        $this->assertDatabaseHas('personality_profile_seo_meta', [
            'profile_id' => $profile->id,
            'seo_title' => 'INTJ Personality Type: Traits, Careers, and Growth | FermatMind',
            'robots' => 'index,follow',
        ]);

        $this->assertDatabaseHas('personality_profile_revisions', [
            'profile_id' => $profile->id,
            'revision_no' => 1,
            'note' => 'Initial workspace snapshot',
        ]);
    }

    public function test_workspace_sync_updates_profile_sections_seo_and_revision_counter(): void
    {
        $profile = $this->seedProfile();

        PersonalityWorkspace::syncWorkspaceSections($profile, $this->workspaceSectionsState());
        PersonalityWorkspace::syncWorkspaceSeo($profile, $this->workspaceSeoState());
        PersonalityWorkspace::createRevision($profile, 'Initial workspace snapshot');

        $profile->update([
            'title' => 'INTJ - Architect (Updated)',
            'subtitle' => 'Sharper, quieter, and more deliberate.',
        ]);

        $updatedSections = $this->workspaceSectionsState();
        $updatedSections['core_snapshot']['body_md'] = 'Updated long-range systems framing.';
        $updatedSections['faq']['is_enabled'] = true;
        $updatedSections['faq']['payload_json_text'] = json_encode([
            'items' => [
                [
                    'question' => 'Do INTJs always work alone?',
                    'answer' => 'No. They often prefer clarity, autonomy, and high trust.',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $updatedSeo = $this->workspaceSeoState();
        $updatedSeo['seo_description'] = 'Updated SEO summary for the INTJ workspace.';

        PersonalityWorkspace::syncWorkspaceSections($profile, $updatedSections);
        PersonalityWorkspace::syncWorkspaceSeo($profile, $updatedSeo);
        PersonalityWorkspace::createRevision($profile, 'Workspace update');

        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => $profile->id,
            'section_key' => 'faq',
            'is_enabled' => 1,
            'render_variant' => 'faq',
        ]);

        $this->assertDatabaseHas('personality_profile_seo_meta', [
            'profile_id' => $profile->id,
            'seo_description' => 'Updated SEO summary for the INTJ workspace.',
        ]);

        $this->assertDatabaseHas('personality_profile_revisions', [
            'profile_id' => $profile->id,
            'revision_no' => 2,
            'note' => 'Workspace update',
        ]);

        $latestRevision = PersonalityProfileRevision::query()
            ->where('profile_id', $profile->id)
            ->where('revision_no', 2)
            ->firstOrFail();

        $this->assertSame('INTJ - Architect (Updated)', $latestRevision->snapshot_json['profile']['title']);
        $this->assertSame('Updated long-range systems framing.', $latestRevision->snapshot_json['sections'][0]['body_md']);
    }

    public function test_resource_query_is_locked_to_global_mbti_profiles(): void
    {
        $globalProfile = $this->seedProfile([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'slug' => 'intj-global',
        ]);

        $tenantProfile = $this->seedProfile([
            'org_id' => 77,
            'slug' => 'intj-tenant',
        ]);

        $otherScale = $this->seedProfile([
            'org_id' => 0,
            'scale_code' => 'DISC',
            'type_code' => 'DISC',
            'slug' => 'disc-driver',
            'title' => 'DISC - Driver',
        ]);

        $request = Request::create('/ops/personality', 'GET');
        app()->instance('request', $request);

        $context = app(OrgContext::class);
        $context->set(77, 9001, 'admin');
        app()->instance(OrgContext::class, $context);

        $ids = PersonalityProfileResource::getEloquentQuery()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertContains($globalProfile->id, $ids);
        $this->assertNotContains($tenantProfile->id, $ids);
        $this->assertNotContains($otherScale->id, $ids);
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
    private function seedProfile(array $overrides = []): PersonalityProfile
    {
        return PersonalityProfile::query()->create(array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ - Architect',
            'subtitle' => 'Independent, strategic, and future-oriented.',
            'excerpt' => 'INTJs tend to value competence, systems, and long-range thinking.',
            'hero_kicker' => 'The Strategist',
            'hero_quote' => 'See the pattern. Build the system.',
            'status' => 'draft',
            'is_public' => true,
            'is_indexable' => true,
        ], $overrides));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function workspaceSectionsState(): array
    {
        $state = PersonalityWorkspace::defaultWorkspaceSectionsState();

        $state['core_snapshot']['body_md'] = 'INTJs are analytical, strategic, and long-range systems thinkers.';
        $state['strengths']['payload_json_text'] = json_encode([
            'items' => [
                [
                    'title' => 'Strategic thinking',
                    'body' => 'Sees long-range patterns and system dependencies.',
                ],
                [
                    'title' => 'Independent execution',
                    'body' => 'Can move fast with clarity and autonomy.',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $state['growth_edges']['payload_json_text'] = json_encode([
            'items' => [
                [
                    'title' => 'Over-indexing on systems',
                    'body' => 'Can miss emotional pacing when pushing for the cleanest plan.',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $state;
    }

    /**
     * @return array<string, string>
     */
    private function workspaceSeoState(): array
    {
        return [
            'seo_title' => 'INTJ Personality Type: Traits, Careers, and Growth | FermatMind',
            'seo_description' => 'Explore INTJ traits, strengths, blind spots, work style, relationships, and growth advice.',
            'canonical_url' => '',
            'og_title' => 'INTJ Personality Type',
            'og_description' => 'Explore INTJ traits, careers, relationships, and growth.',
            'og_image_url' => '',
            'twitter_title' => 'INTJ Personality Type',
            'twitter_description' => 'Explore INTJ traits, careers, relationships, and growth.',
            'twitter_image_url' => '',
            'robots' => 'index,follow',
            'jsonld_overrides_json_text' => '',
        ];
    }
}
