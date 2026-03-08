<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\TopicProfileResource;
use App\Filament\Ops\Resources\TopicProfileResource\Support\TopicWorkspace;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TopicWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_workspace_pages_render_for_authorized_admin(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $selectedOrg = $this->createSelectedOrg();
        $profile = $this->seedProfile([
            'title' => 'MBTI Topic Hub',
        ]);

        TopicWorkspace::syncWorkspaceSections($profile, $this->workspaceSectionsState());
        TopicWorkspace::syncWorkspaceEntries($profile, $this->workspaceEntriesState());
        TopicWorkspace::syncWorkspaceSeo($profile, $this->workspaceSeoState());
        TopicWorkspace::createRevision($profile, 'Seed revision');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/topics')
            ->assertOk()
            ->assertSee('Global topic content workspace', false)
            ->assertSee('Create Topic');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/topics/create')
            ->assertOk()
            ->assertSee('ops-topic-workspace-layout', false)
            ->assertSee('Create Topic');

        $this->withSession([
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/topics/'.$profile->id.'/edit')
            ->assertOk()
            ->assertSee('ops-topic-workspace-layout', false)
            ->assertSee('Planned public URL');
    }

    public function test_workspace_sync_persists_sections_entries_seo_and_initial_revision(): void
    {
        $profile = $this->seedProfile();

        TopicWorkspace::syncWorkspaceSections($profile, $this->workspaceSectionsState());
        TopicWorkspace::syncWorkspaceEntries($profile, $this->workspaceEntriesState());
        TopicWorkspace::syncWorkspaceSeo($profile, $this->workspaceSeoState());
        TopicWorkspace::createRevision($profile, 'Initial workspace snapshot');

        $this->assertDatabaseHas('topic_profile_sections', [
            'profile_id' => $profile->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'is_enabled' => 1,
        ]);

        $this->assertDatabaseHas('topic_profile_entries', [
            'profile_id' => $profile->id,
            'group_key' => 'featured',
            'entry_type' => 'personality_profile',
            'target_key' => 'INTJ',
            'is_featured' => 1,
            'is_enabled' => 1,
        ]);

        $this->assertDatabaseHas('topic_profile_seo_meta', [
            'profile_id' => $profile->id,
            'seo_title' => 'MBTI Guide and Type Hub | FermatMind',
            'robots' => 'index,follow',
        ]);

        $this->assertDatabaseHas('topic_profile_revisions', [
            'profile_id' => $profile->id,
            'revision_no' => 1,
            'note' => 'Initial workspace snapshot',
        ]);
    }

    public function test_workspace_sync_updates_topic_sections_entries_seo_and_revision_counter(): void
    {
        $profile = $this->seedProfile();

        TopicWorkspace::syncWorkspaceSections($profile, $this->workspaceSectionsState());
        TopicWorkspace::syncWorkspaceEntries($profile, $this->workspaceEntriesState());
        TopicWorkspace::syncWorkspaceSeo($profile, $this->workspaceSeoState());
        TopicWorkspace::createRevision($profile, 'Initial workspace snapshot');

        $profile->update([
            'title' => 'MBTI Topic Hub (Updated)',
            'subtitle' => 'Sharper, clearer, and more actionable.',
        ]);

        $updatedSections = $this->workspaceSectionsState();
        $updatedSections['overview']['body_md'] = 'Updated overview for the MBTI topic hub.';
        $updatedSections['faq']['is_enabled'] = true;
        $updatedSections['faq']['payload_json_text'] = json_encode([
            'items' => [
                [
                    'question' => 'Is MBTI a personality trait model?',
                    'answer' => 'It is a typology framework rather than a trait scale.',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $updatedEntries = $this->workspaceEntriesState();
        $updatedEntries['articles'][] = [
            'entry_type' => 'article',
            'target_key' => 'how-to-read-mbti-results',
            'target_locale' => 'en',
            'title_override' => 'Read the MBTI guide',
            'excerpt_override' => 'Updated supporting article guidance.',
            'badge_label' => 'Guide',
            'cta_label' => 'Read',
            'target_url_override' => '',
            'payload_json_text' => '',
            'sort_order' => 40,
            'is_featured' => false,
            'is_enabled' => true,
        ];

        $updatedSeo = $this->workspaceSeoState();
        $updatedSeo['seo_description'] = 'Updated SEO summary for the MBTI topic hub.';

        TopicWorkspace::syncWorkspaceSections($profile, $updatedSections);
        TopicWorkspace::syncWorkspaceEntries($profile, $updatedEntries);
        TopicWorkspace::syncWorkspaceSeo($profile, $updatedSeo);
        TopicWorkspace::createRevision($profile, 'Workspace update');

        $this->assertDatabaseHas('topic_profile_sections', [
            'profile_id' => $profile->id,
            'section_key' => 'faq',
            'is_enabled' => 1,
            'render_variant' => 'faq',
        ]);

        $this->assertDatabaseHas('topic_profile_entries', [
            'profile_id' => $profile->id,
            'group_key' => 'articles',
            'entry_type' => 'article',
            'target_key' => 'how-to-read-mbti-results',
        ]);

        $this->assertDatabaseHas('topic_profile_seo_meta', [
            'profile_id' => $profile->id,
            'seo_description' => 'Updated SEO summary for the MBTI topic hub.',
        ]);

        $this->assertDatabaseHas('topic_profile_revisions', [
            'profile_id' => $profile->id,
            'revision_no' => 2,
            'note' => 'Workspace update',
        ]);

        $latestRevision = TopicProfileRevision::query()
            ->where('profile_id', $profile->id)
            ->where('revision_no', 2)
            ->firstOrFail();

        $this->assertSame('MBTI Topic Hub (Updated)', $latestRevision->snapshot_json['profile']['title']);
        $this->assertSame('Updated overview for the MBTI topic hub.', $latestRevision->snapshot_json['sections'][0]['body_md']);
    }

    public function test_resource_query_is_locked_to_global_topic_profiles(): void
    {
        $globalProfile = $this->seedProfile([
            'org_id' => 0,
            'slug' => 'mbti-global',
        ]);

        $tenantProfile = $this->seedProfile([
            'org_id' => 77,
            'slug' => 'mbti-tenant',
        ]);

        $request = Request::create('/ops/topics', 'GET');
        app()->instance('request', $request);

        $context = app(OrgContext::class);
        $context->set(77, 9001, 'admin');
        app()->instance(OrgContext::class, $context);

        $ids = TopicProfileResource::getEloquentQuery()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertContains($globalProfile->id, $ids);
        $this->assertNotContains($tenantProfile->id, $ids);
    }

    public function test_workspace_ignores_unknown_section_group_and_entry_type(): void
    {
        $profile = $this->seedProfile();

        $sections = $this->workspaceSectionsState();
        $sections['rogue_section'] = [
            'title' => 'Rogue',
            'render_variant' => 'rich_text',
            'body_md' => 'Should never persist.',
            'payload_json_text' => '',
            'sort_order' => 999,
            'is_enabled' => true,
        ];

        $entries = $this->workspaceEntriesState();
        $entries['featured'][] = [
            'entry_type' => 'not_real',
            'target_key' => 'rogue',
            'target_locale' => 'en',
            'title_override' => '',
            'excerpt_override' => '',
            'badge_label' => '',
            'cta_label' => '',
            'target_url_override' => '',
            'payload_json_text' => '',
            'sort_order' => 10,
            'is_featured' => false,
            'is_enabled' => true,
        ];
        $entries['rogue_group'] = [[
            'entry_type' => 'article',
            'target_key' => 'rogue',
            'target_locale' => 'en',
        ]];

        TopicWorkspace::syncWorkspaceSections($profile, $sections);
        TopicWorkspace::syncWorkspaceEntries($profile, $entries);

        $this->assertDatabaseMissing('topic_profile_sections', [
            'profile_id' => $profile->id,
            'section_key' => 'rogue_section',
        ]);

        $this->assertSame(0, TopicProfileEntry::query()
            ->where('profile_id', $profile->id)
            ->where('target_key', 'rogue')
            ->count());
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
    private function seedProfile(array $overrides = []): TopicProfile
    {
        return TopicProfile::query()->create(array_merge([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'MBTI Topic Hub',
            'subtitle' => 'Understand type concepts, profiles, and assessments.',
            'excerpt' => 'Explore MBTI concepts, type profiles, and practical guidance.',
            'hero_kicker' => 'Topic hub',
            'hero_quote' => 'Start from the core ideas, then go deeper.',
            'status' => TopicProfile::STATUS_DRAFT,
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
        return array_replace_recursive(TopicWorkspace::defaultWorkspaceSectionsState(), [
            'overview' => [
                'body_md' => 'MBTI is a typology framework for understanding patterns in how people take in information and make decisions.',
            ],
            'key_concepts' => [
                'render_variant' => 'cards',
                'payload_json_text' => json_encode([
                    'items' => [
                        [
                            'title' => 'Preferences',
                            'body' => 'Type codes describe preferences rather than fixed capabilities.',
                        ],
                        [
                            'title' => 'Context',
                            'body' => 'Type interpretation depends on context and development over time.',
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'who_should_read' => [
                'render_variant' => 'bullets',
                'payload_json_text' => json_encode([
                    'items' => [
                        'Readers comparing personality frameworks',
                        'Teams exploring communication and work preferences',
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        ]);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function workspaceEntriesState(): array
    {
        return array_replace_recursive(TopicWorkspace::defaultWorkspaceEntriesState(), [
            'featured' => [[
                'entry_type' => 'personality_profile',
                'target_key' => 'INTJ',
                'target_locale' => 'en',
                'title_override' => 'INTJ - Architect',
                'excerpt_override' => 'Independent, strategic, and future-oriented.',
                'badge_label' => 'Personality',
                'cta_label' => 'Explore',
                'target_url_override' => '',
                'payload_json_text' => '',
                'sort_order' => 10,
                'is_featured' => true,
                'is_enabled' => true,
            ]],
            'tests' => [[
                'entry_type' => 'scale',
                'target_key' => 'MBTI',
                'target_locale' => 'en',
                'title_override' => 'Take the MBTI test',
                'excerpt_override' => 'Start with the core type assessment.',
                'badge_label' => 'Test',
                'cta_label' => 'Take the test',
                'target_url_override' => '',
                'payload_json_text' => '',
                'sort_order' => 10,
                'is_featured' => false,
                'is_enabled' => true,
            ]],
            'related' => [[
                'entry_type' => 'custom_link',
                'target_key' => '',
                'target_locale' => '',
                'title_override' => 'See all personality profiles',
                'excerpt_override' => 'Jump to the broader personality hub.',
                'badge_label' => 'Related',
                'cta_label' => 'Browse',
                'target_url_override' => '/en/personality',
                'payload_json_text' => '',
                'sort_order' => 10,
                'is_featured' => false,
                'is_enabled' => true,
            ]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceSeoState(): array
    {
        return array_replace(TopicWorkspace::defaultWorkspaceSeoState(), [
            'seo_title' => 'MBTI Guide and Type Hub | FermatMind',
            'seo_description' => 'Explore MBTI concepts, type profiles, guides, and tests.',
            'robots' => 'index,follow',
        ]);
    }
}
