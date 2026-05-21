<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Filament\Ops\Pages\SeoDashboardAccessPage;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsSeoNativeDashboardUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
        ]);

        DB::purge('seo_intel');
        $this->createSeoIntelTables();
        $this->seedSeoIntelData();
    }

    #[Test]
    public function ops_read_admin_can_render_native_read_only_dashboard(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo')
            ->assertOk()
            ->assertSee('Native read-only SEO Engine observability dashboard')
            ->assertSee('Overview heartbeat')
            ->assertSee('URL Truth rows')
            ->assertSee('Entity mappings')
            ->assertSee('Issue queue rows')
            ->assertSee('Search Channel Queue Items')
            ->assertSee('Search Channel Events')
            ->assertSee('Safety heartbeat')
            ->assertSee('Private-flow leaks')
            ->assertSee('Forbidden authority')
            ->assertSee('Claim unsafe')
            ->assertSee('page_entity_type')
            ->assertSee('research_report: 2')
            ->assertSee('locale')
            ->assertSee('zh-CN: 3')
            ->assertSee('source_authority')
            ->assertSee('backend_cms: 2')
            ->assertSee('indexability_state')
            ->assertSee('indexable: 9')
            ->assertSee('Issue Queue overview')
            ->assertSee('missing_lastmod_for_indexable_url')
            ->assertSee('/en/research/issue-5')
            ->assertSee('Search Channel Queue overview')
            ->assertSee('indexnow: 1')
            ->assertSee('approved: 1')
            ->assertSee('submitted: 1')
            ->assertSee('event_type summary')
            ->assertSee('live_submission_response: 1')
            ->assertSee('Access boundary')
            ->assertSee('No public Metabase')
            ->assertDontSee('<iframe', false)
            ->assertDontSee('metadata_json')
            ->assertDontSee('event_payload')
            ->assertDontSee('reason_codes')
            ->assertDontSee('raw_evidence_included');
    }

    #[Test]
    public function page_methods_expose_live_dashboard_shapes_for_blade(): void
    {
        $page = app(SeoDashboardAccessPage::class);

        $this->assertSame([
            [
                'label' => 'URL Truth rows',
                'value' => '9',
                'hint' => 'Live count from seo_urls on the seo_intel connection.',
            ],
            [
                'label' => 'Entity mappings',
                'value' => '9',
                'hint' => 'Live count from seo_url_entities on the seo_intel connection.',
            ],
            [
                'label' => 'Issue queue rows',
                'value' => '5',
                'hint' => 'Live count from seo_issue_queue on the seo_intel connection.',
            ],
            [
                'label' => 'Search Channel Queue Items',
                'value' => '1',
                'hint' => 'Live count from seo_search_channel_queue_items.',
            ],
            [
                'label' => 'Search Channel Queue Batches',
                'value' => '1',
                'hint' => 'Live count from seo_search_channel_queue_batches.',
            ],
            [
                'label' => 'Search Channel Events',
                'value' => '4',
                'hint' => 'Live count from seo_search_channel_queue_events.',
            ],
        ], $page->overviewCards());

        $this->assertSame([
            [
                'label' => 'Private-flow leaks',
                'value' => '0',
                'hint' => 'Expected steady state is zero.',
                'kind' => 'pill',
                'state' => 'success',
            ],
            [
                'label' => 'Forbidden authority',
                'value' => '0',
                'hint' => 'Expected steady state is zero.',
                'kind' => 'pill',
                'state' => 'success',
            ],
            [
                'label' => 'Claim unsafe',
                'value' => '0',
                'hint' => 'Expected steady state is zero.',
                'kind' => 'pill',
                'state' => 'success',
            ],
        ], $page->safetyCards());

        $this->assertCount(4, $page->urlTruthDistributionCards());
        $this->assertCount(3, $page->issueQueueAggregateCards());
        $this->assertCount(3, $page->searchChannelQueueAggregateCards());
        $this->assertCount(5, $page->recentIssueRows());
        $this->assertCount(1, $page->recentQueueRows());
        $this->assertCount(4, $page->eventTypeSummary());
    }

    #[Test]
    public function ui_docs_and_artifact_lock_native_read_only_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-seo-native-dashboard-ui.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-seo-native-dashboard-ui.v1.json')));
        $view = strtolower((string) file_get_contents(resource_path('views/filament/ops/pages/seo-dashboard-access.blade.php')));
        $combined = $doc."\n".$artifactJson."\n".$view;

        foreach ([
            'ops-seo-native-dash-02',
            'native read-only seo engine observability dashboard',
            'overview heartbeat',
            'safety heartbeat',
            'url truth distribution',
            'issue queue overview',
            'search channel queue overview',
            'no metabase iframe',
            'no reverse proxy',
            'no raw sql',
            'no approve/retry/submit buttons',
            'no scheduler or collector controls',
            'next task: `ops-seo-native-dash-03`',
            '"next_task": "ops-seo-native-dash-03"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }

        foreach ([
            '<iframe',
            '<x-filament::button',
            'event_payload }}',
            'metadata_json }}',
            'reason_codes }}',
            'searchchannelsubmissionexecutor',
            'searchchannelqueuewriteservice',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
        }
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

    private function createSeoIntelTables(): void
    {
        $schema = Schema::connection('seo_intel');

        $schema->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->boolean('is_private_flow')->default(false);
            $table->json('metadata_json')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_url_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255);
            $table->string('entity_source', 64);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_uid', 128);
            $table->string('issue_type', 64);
            $table->string('severity', 32);
            $table->string('source_system', 64);
            $table->string('source_engine', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('status', 32);
            $table->string('lifecycle_state', 32);
            $table->timestamp('detected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_search_channel_queue_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 64);
            $table->string('status', 64);
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_search_channel_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('source_authority', 64);
            $table->string('channel', 64);
            $table->string('eligibility_state', 64);
            $table->string('approval_state', 64);
            $table->string('execution_state', 64);
            $table->string('indexability_state', 64);
            $table->string('claim_boundary_state', 64);
            $table->boolean('private_flow')->default(false);
            $table->json('reason_codes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_search_channel_queue_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('queue_item_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('event_type', 96);
            $table->json('event_payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedSeoIntelData(): void
    {
        $urls = [
            ['https://fermatmind.com/en', 'en', 'home', 'home-en', 'backend_public_surface'],
            ['https://fermatmind.com/zh', 'zh-CN', 'home', 'home-zh', 'backend_public_surface'],
            ['https://fermatmind.com/en/tests', 'en', 'test_hub', 'tests-en', 'backend_public_surface'],
            ['https://fermatmind.com/zh/tests', 'zh-CN', 'test_hub', 'tests-zh', 'backend_public_surface'],
            ['https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types', 'en', 'test_detail', 'mbti', 'scale_catalog'],
            ['https://fermatmind.com/en/tests/big-five-personality-test-ocean-model', 'en', 'test_detail', 'big-five', 'scale_catalog'],
            ['https://fermatmind.com/zh/tests/enneagram-personality-test-nine-types', 'zh-CN', 'test_detail', 'enneagram', 'scale_catalog'],
            ['https://fermatmind.com/en/research/hrzone-canary', 'en', 'research_report', 'hrzone-en', 'backend_cms'],
            ['https://fermatmind.com/en/research/search-channel-preflight', 'en', 'research_report', 'preflight-en', 'backend_cms'],
        ];

        foreach ($urls as [$url, $locale, $pageType, $entityId, $authority]) {
            DB::connection('seo_intel')->table('seo_urls')->insert([
                'canonical_url' => $url,
                'locale' => $locale,
                'page_entity_type' => $pageType,
                'entity_id_or_slug' => $entityId,
                'source_authority' => $authority,
                'indexability_state' => 'indexable',
                'is_private_flow' => false,
                'metadata_json' => json_encode(['claim_boundary_state' => 'claim_safe'], JSON_UNESCAPED_SLASHES),
                'created_at' => '2026-05-21 23:00:00',
                'updated_at' => '2026-05-21 23:00:00',
            ]);

            DB::connection('seo_intel')->table('seo_url_entities')->insert([
                'locale' => $locale,
                'page_entity_type' => $pageType,
                'entity_id_or_slug' => $entityId,
                'entity_source' => $authority,
                'authority_status' => 'approved',
                'source_updated_at' => '2026-05-21 23:00:00',
                'created_at' => '2026-05-21 23:00:00',
                'updated_at' => '2026-05-21 23:00:00',
            ]);
        }

        foreach (range(1, 5) as $index) {
            DB::connection('seo_intel')->table('seo_issue_queue')->insert([
                'issue_uid' => 'issue-'.$index,
                'issue_type' => 'missing_lastmod_for_indexable_url',
                'severity' => 'info',
                'source_system' => 'url_truth_inventory',
                'source_engine' => null,
                'canonical_url' => 'https://fermatmind.com/en/research/issue-'.$index,
                'locale' => 'en',
                'page_entity_type' => 'research_report',
                'status' => 'open',
                'lifecycle_state' => 'open',
                'detected_at' => sprintf('2026-05-21 23:0%d:00', $index),
                'metadata_json' => json_encode(['raw_evidence_included' => false], JSON_UNESCAPED_SLASHES),
                'created_at' => '2026-05-21 23:00:00',
                'updated_at' => sprintf('2026-05-21 23:1%d:00', $index),
            ]);
        }

        DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->insert([
            'id' => 1,
            'channel' => 'indexnow',
            'status' => 'submitted',
            'item_count' => 1,
            'created_at' => '2026-05-21 23:20:00',
            'updated_at' => '2026-05-21 23:26:00',
        ]);

        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
            'batch_id' => 1,
            'canonical_url' => 'https://fermatmind.com/en',
            'locale' => 'en',
            'page_entity_type' => 'home',
            'source_authority' => 'backend_public_surface',
            'channel' => 'indexnow',
            'eligibility_state' => 'eligible',
            'approval_state' => 'approved',
            'execution_state' => 'submitted',
            'indexability_state' => 'indexable',
            'claim_boundary_state' => 'claim_safe',
            'private_flow' => false,
            'reason_codes' => json_encode([], JSON_UNESCAPED_SLASHES),
            'approved_at' => '2026-05-21 23:24:00',
            'created_at' => '2026-05-21 23:20:00',
            'updated_at' => '2026-05-21 23:26:00',
        ]);

        foreach ([
            ['queue_item_planned', '2026-05-21 23:21:00'],
            ['batch_created', '2026-05-21 23:20:00'],
            ['live_submission_approved', '2026-05-21 23:25:00'],
            ['live_submission_response', '2026-05-21 23:26:00'],
        ] as [$type, $createdAt]) {
            DB::connection('seo_intel')->table('seo_search_channel_queue_events')->insert([
                'queue_item_id' => 1,
                'batch_id' => 1,
                'event_type' => $type,
                'event_payload' => json_encode(['safe' => true], JSON_UNESCAPED_SLASHES),
                'created_at' => $createdAt,
            ]);
        }
    }
}
