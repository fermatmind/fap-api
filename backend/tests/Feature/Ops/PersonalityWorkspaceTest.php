<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\PersonalityProfileResource;
use App\Filament\Ops\Resources\PersonalityProfileResource\Pages\EditPersonalityProfile;
use App\Filament\Ops\Resources\PersonalityProfileResource\Pages\ListPersonalityProfiles;
use App\Filament\Ops\Resources\PersonalityProfileResource\Support\PersonalityWorkspace;
use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages\ListPersonalityVariantCloneContents;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class PersonalityWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

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
            ->assertSee('结构化人格档案的全局 MBTI 内容工作区', false)
            ->assertSee('创建人格档案');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/personality/create')
            ->assertOk()
            ->assertSee('ops-personality-workspace-layout', false)
            ->assertSee('创建人格档案');

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

    public function test_personality_context_exposes_filtered_desktop_templates_and_empty_creation_path(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $selectedOrg = $this->createSelectedOrg();
        $profile = $this->seedProfile([
            'type_code' => 'INTJ',
            'canonical_type_code' => 'INTJ',
            'slug' => 'intj-desktop-context',
        ]);
        $otherProfile = $this->seedProfile([
            'type_code' => 'INTP',
            'canonical_type_code' => 'INTP',
            'slug' => 'intp-desktop-context',
            'title' => 'INTP - Logician',
        ]);
        $assertive = $this->seedVariant($profile, 'A');
        $turbulent = $this->seedVariant($profile, 'T');
        $otherVariant = $this->seedVariant($otherProfile, 'A');
        $contextContentId = DB::table('personality_profile_variant_clone_contents')->insertGetId([
            'personality_profile_variant_id' => (int) $assertive->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_DRAFT,
            'schema_version' => 'v1',
            'content_json' => '{}',
            'asset_slots_json' => '[]',
            'meta_json' => null,
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherContentId = DB::table('personality_profile_variant_clone_contents')->insertGetId([
            'personality_profile_variant_id' => (int) $otherVariant->id,
            'template_key' => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
            'status' => PersonalityProfileVariantCloneContent::STATUS_DRAFT,
            'schema_version' => 'v1',
            'content_json' => '{}',
            'asset_slots_json' => '[]',
            'meta_json' => null,
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        session([
            'ops_org_id' => (int) $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ]);
        app()->instance('request', Request::create('/ops/personality', 'GET'));
        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        $desktopUrl = PersonalityProfileResource::desktopTemplatesUrl($profile);
        $this->assertStringContainsString('/ops/personality-desktop-clone', $desktopUrl);
        $this->assertStringContainsString('profile='.(int) $profile->id, $desktopUrl);

        Livewire::test(ListPersonalityProfiles::class)
            ->assertTableActionExists('desktopTemplates');

        $editPage = Livewire::test(EditPersonalityProfile::class, ['record' => $profile->getKey()]);
        $editActions = collect($editPage->instance()->getCachedHeaderActions())->keyBy(
            fn ($action): string => $action->getName(),
        );
        $this->assertSame('Desktop Templates', $editActions->get('desktopTemplates')?->getLabel());
        $this->assertSame($desktopUrl, $editActions->get('desktopTemplates')?->getUrl());

        $listPage = Livewire::withQueryParams(['profile' => (int) $profile->id])
            ->test(ListPersonalityVariantCloneContents::class)
            ->loadTable()
            ->assertSee('Desktop templates for INTJ (en)')
            ->assertCanSeeTableRecords([
                PersonalityProfileVariantCloneContent::query()->findOrFail($contextContentId),
            ])
            ->assertCanNotSeeTableRecords([
                PersonalityProfileVariantCloneContent::query()->findOrFail($otherContentId),
            ]);
        $listActions = collect($listPage->instance()->getCachedHeaderActions())->keyBy(
            fn ($action): string => $action->getName(),
        );
        $this->assertStringContainsString('profile='.(int) $profile->id, (string) $listActions->get('create')?->getUrl());

        $emptyHeading = new \ReflectionMethod($listPage->instance(), 'getTableEmptyStateHeading');
        $emptyActions = new \ReflectionMethod($listPage->instance(), 'getTableEmptyStateActions');
        $this->assertSame('No desktop templates for this personality', $emptyHeading->invoke($listPage->instance()));
        $this->assertSame(
            'Create First Desktop Template',
            collect($emptyActions->invoke($listPage->instance()))->first()?->getLabel(),
        );

        $variantOptionIds = array_map('intval', array_keys(PersonalityVariantCloneContentResource::variantOptions((int) $profile->id)));
        $this->assertSame([(int) $assertive->id, (int) $turbulent->id], $variantOptionIds);
        $this->assertNotContains((int) $otherVariant->id, $variantOptionIds);

        app()->instance('request', Request::create('/ops/personality-desktop-clone/create?profile='.(int) $profile->id, 'GET'));
        $this->assertSame(
            $variantOptionIds,
            array_map('intval', array_keys(PersonalityVariantCloneContentResource::variantOptions())),
        );
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

    public function test_v1_profiles_use_legacy_catalog_while_v2_profiles_use_canonical_registry(): void
    {
        $legacyProfile = $this->seedProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj-v1',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V1,
        ]);
        $canonicalProfile = $this->seedProfile([
            'type_code' => 'ENFJ',
            'slug' => 'enfj-v2',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);

        PersonalityWorkspace::syncWorkspaceSections($legacyProfile, $this->workspaceSectionsState());
        PersonalityWorkspace::syncWorkspaceSections($canonicalProfile, $this->v2WorkspaceSectionsState());

        $this->assertSame(
            array_keys(PersonalityWorkspace::legacySectionDefinitions()),
            array_keys(PersonalityWorkspace::sectionDefinitions(PersonalityProfile::SCHEMA_VERSION_V1))
        );
        $this->assertSame(
            array_keys(PersonalityWorkspace::canonicalSectionDefinitions()),
            array_keys(PersonalityWorkspace::sectionDefinitions(PersonalityProfile::SCHEMA_VERSION_V2))
        );
        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => (int) $legacyProfile->id,
            'section_key' => 'core_snapshot',
        ]);
        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => (int) $canonicalProfile->id,
            'section_key' => 'letters_intro',
            'render_variant' => 'letters_intro',
        ]);
    }

    public function test_v2_workspace_sync_keeps_canonical_sections_and_preserves_unmanaged_keys(): void
    {
        $profile = $this->seedProfile([
            'type_code' => 'ENFJ',
            'slug' => 'enfj',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);

        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'core_snapshot',
            'title' => 'Legacy carry-over',
            'render_variant' => 'rich_text',
            'body_md' => 'Keep me.',
            'sort_order' => 5,
            'is_enabled' => true,
        ]);

        PersonalityWorkspace::syncWorkspaceSections($profile, $this->v2WorkspaceSectionsState());

        $freshProfile = $profile->fresh(['sections']);
        $workspaceState = PersonalityWorkspace::workspaceSectionsFromRecord($freshProfile);

        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => (int) $profile->id,
            'section_key' => 'letters_intro',
            'render_variant' => 'letters_intro',
        ]);
        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => (int) $profile->id,
            'section_key' => 'trait_overview',
            'render_variant' => 'trait_dimension_grid',
        ]);
        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => (int) $profile->id,
            'section_key' => 'career.preferred_roles',
            'render_variant' => 'preferred_role_list',
        ]);
        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => (int) $profile->id,
            'section_key' => 'growth.motivators',
            'render_variant' => 'premium_teaser',
        ]);
        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => (int) $profile->id,
            'section_key' => 'relationships.rel_risks',
            'render_variant' => 'premium_teaser',
        ]);
        $this->assertDatabaseHas('personality_profile_sections', [
            'profile_id' => (int) $profile->id,
            'section_key' => 'core_snapshot',
            'body_md' => 'Keep me.',
        ]);

        $this->assertSame('letters_intro', $workspaceState['letters_intro']['render_variant']);
        $this->assertSame('trait_dimension_grid', $workspaceState['trait_overview']['render_variant']);
        $this->assertSame('preferred_role_list', $workspaceState['career.preferred_roles']['render_variant']);
        $this->assertSame('premium_teaser', $workspaceState['growth.motivators']['render_variant']);
        $this->assertSame('premium_teaser', $workspaceState['relationships.rel_risks']['render_variant']);
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
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V1,
        ], $overrides));
    }

    private function seedVariant(PersonalityProfile $profile, string $variantCode): PersonalityProfileVariant
    {
        $variantCode = strtoupper($variantCode);
        $typeCode = strtoupper((string) $profile->type_code);

        return PersonalityProfileVariant::query()->create([
            'org_id' => 0,
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => $typeCode,
            'variant_code' => $variantCode,
            'runtime_type_code' => $typeCode.'-'.$variantCode,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => false,
        ]);
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function v2WorkspaceSectionsState(): array
    {
        $state = PersonalityWorkspace::defaultWorkspaceSectionsState();

        $state['letters_intro']['body_md'] = 'E sees people, N sees patterns, F weighs values, J creates structure.';
        $state['trait_overview']['payload_json_text'] = json_encode([
            'summary' => 'Canonical MBTI dimensions for ENFJ.',
            'dimensions' => [
                ['id' => 'EI', 'label' => 'Energy', 'value_pct' => 68],
                ['id' => 'SN', 'label' => 'Perception', 'value_pct' => 72],
                ['id' => 'TF', 'label' => 'Decision', 'value_pct' => 64],
                ['id' => 'JP', 'label' => 'Lifestyle', 'value_pct' => 58],
                ['id' => 'AT', 'label' => 'Identity', 'value_pct' => 47],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $state['career.preferred_roles']['payload_json_text'] = json_encode([
            'items' => [
                ['title' => 'Teacher', 'fit' => 'high'],
                ['title' => 'Coach', 'fit' => 'high'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $state['growth.motivators']['body_md'] = 'Preview: recognition, purpose, and interpersonal momentum.';
        $state['relationships.rel_risks']['body_md'] = 'Preview: overextending emotionally when mutuality is low.';

        return $state;
    }
}
