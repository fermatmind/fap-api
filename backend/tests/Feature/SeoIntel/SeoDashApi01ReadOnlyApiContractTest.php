<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\SeoIntel\OpsDashboard\AbstractSeoDashboardReadService;
use App\Support\Rbac\PermissionNames;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoDashApi01ReadOnlyApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    public function read_only_route_family_is_registered_under_private_ops_api(): void
    {
        $artifact = $this->artifact();

        foreach ($artifact['route_names'] as $routeName) {
            $route = Route::getRoutes()->getByName((string) $routeName);

            $this->assertNotNull($route, $routeName.' must be registered');
            $this->assertContains('GET', $route->methods());
        }

        $this->assertTrue((bool) $artifact['runtime_api_added']);
        $this->assertTrue((bool) $artifact['read_only']);
        $this->assertFalse((bool) $artifact['migration_added']);
        $this->assertFalse((bool) $artifact['collector_enabled']);
        $this->assertFalse((bool) $artifact['cms_mutation_allowed']);
    }

    #[Test]
    public function unauthenticated_and_unrelated_admins_cannot_read_seo_intel_api(): void
    {
        $this->getJson('/api/v0.5/ops/seo-intel/overview')
            ->assertUnauthorized()
            ->assertJson([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
            ]);

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/overview')
            ->assertForbidden()
            ->assertJson([
                'ok' => false,
                'error_code' => 'FORBIDDEN',
                'message' => 'admin_seo_intel_read_required',
            ]);
    }

    #[Test]
    public function seo_intel_read_and_ops_read_admins_can_read_all_dash_api_endpoints(): void
    {
        foreach ([PermissionNames::ADMIN_SEO_INTEL_READ, PermissionNames::ADMIN_OPS_READ] as $permission) {
            $admin = $this->createAdminWithPermissions([$permission]);

            foreach ([
                '/api/v0.5/ops/seo-intel/overview',
                '/api/v0.5/ops/seo-intel/url-truth',
                '/api/v0.5/ops/seo-intel/issues',
                '/api/v0.5/ops/seo-intel/trends',
                '/api/v0.5/ops/seo-intel/page-performance',
                '/api/v0.5/ops/seo-intel/opportunity-queue',
            ] as $path) {
                $this->actingAs($admin, (string) config('admin.guard', 'admin'))
                    ->getJson($path)
                    ->assertOk()
                    ->assertJsonPath('ok', true)
                    ->assertJsonPath('meta.contract_version', 'seo-dash-api-01.v1')
                    ->assertJsonPath('meta.read_only', true)
                    ->assertJsonPath('meta.authority', 'fap-api seo_intel read model');
            }

            auth((string) config('admin.guard', 'admin'))->logout();
        }
    }

    #[Test]
    public function issue_queue_endpoint_exposes_dashboard_aliases_not_storage_or_raw_fields(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);

        $response = $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/issues?limit=5')
            ->assertOk()
            ->assertJsonPath('data.recent_rows.0.issue_id', 'issue-warning')
            ->assertJsonPath('data.recent_rows.0.severity', 'medium')
            ->assertJsonPath('data.recent_rows.0.lifecycle_state', 'triaged')
            ->assertJsonPath('data.recent_rows.0.source_signal', 'gsc:google');

        $json = $response->getContent();

        foreach ([
            'issue_uid',
            'metadata_json',
            'evidence_hash',
            'raw_email',
            'raw_ip',
            'raw_user_agent',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'raw_payload',
            'raw_crawler_log_line',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    #[Test]
    public function trends_and_page_performance_are_aggregate_or_safe_path_only(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/trends')
            ->assertOk()
            ->assertJsonPath('data.totals.gsc_clicks', 7)
            ->assertJsonPath('data.totals.baidu_landing_events', 5)
            ->assertJsonPath('data.consent_distribution.0.label', 'analytics_granted')
            ->assertJsonPath('data.consent_distribution.1.label', 'not_applicable_backend_business_event');

        $response = $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/page-performance')
            ->assertOk()
            ->assertJsonPath('data.recent_rows.0.canonical_path', '/en/tests/mbti-personality-test-16-personality-types')
            ->assertJsonPath('data.recent_rows.0.metrics.gsc_clicks', 7)
            ->assertJsonPath('data.recent_rows.0.metrics.revenue_cents', 1200);

        $json = $response->getContent();

        $this->assertStringNotContainsString('https://fermatmind.com', $json);
        $this->assertStringNotContainsString('query_display_masked', $json);
        $this->assertStringNotContainsString('metadata_json', $json);
    }

    #[Test]
    public function opportunity_queue_endpoint_is_gsc_quality_gated_and_read_only(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);
        $queueArtifact = $this->opportunityQueueArtifact();

        $this->assertFalse((bool) data_get($queueArtifact, 'sidecar_artifact_boundary.direct_sidecar_artifact_consumption_allowed', true));
        $this->assertSame(
            'seo_intel read model rows after gsc data_quality_gate=pass',
            data_get($queueArtifact, 'sidecar_artifact_boundary.allowed_source'),
        );
        $this->assertFalse((bool) data_get($queueArtifact, 'sidecar_artifact_boundary.queue_item_creation_allowed', true));
        $this->assertSame(
            'read_only_resolver_by_canonical_url_hash',
            data_get($queueArtifact, 'url_truth_resolution.mode'),
        );
        $this->assertFalse((bool) data_get($queueArtifact, 'url_truth_resolution.raw_canonical_url_response_allowed', true));

        $response = $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/opportunity-queue?limit=5')
            ->assertOk()
            ->assertJsonPath('data.schema_version', 'seo-opportunity-queue-readonly.v1')
            ->assertJsonPath('data.mode', 'read_only')
            ->assertJsonPath('data.source_gate.status', 'pass')
            ->assertJsonPath('data.source_gate.opportunity_queue_eligible', true)
            ->assertJsonPath('data.total_count', 1)
            ->assertJsonPath('data.recent_rows.0.canonical_path', '/en/tests/mbti-personality-test-16-personality-types')
            ->assertJsonPath('data.recent_rows.0.query_display_masked', 'm**********y')
            ->assertJsonPath('data.recent_rows.0.allowed_action', 'read_only_review')
            ->assertJsonPath('data.boundaries.cms_write_allowed', false)
            ->assertJsonPath('data.boundaries.search_channel_enqueue_allowed', false)
            ->assertJsonPath('data.boundaries.search_provider_submission_allowed', false)
            ->assertJsonPath('data.boundaries.execution_allowed', false)
            ->assertJsonPath('data.boundaries.external_calls_attempted', false)
            ->assertJsonPath('data.boundaries.writes_attempted', false);

        $json = $response->getContent();

        foreach ([
            'https://fermatmind.com',
            'mbti career clarity',
            'metadata_json',
            'raw_payload',
            'order_no',
            'attempt_id',
            'payment_id',
            'client_email',
            'private_key',
            'Bearer',
            'gsc-service-account.json',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/ops/seo-intel/opportunity-queue', [])
            ->assertMethodNotAllowed();
    }

    #[Test]
    public function api_contract_has_no_write_routes_or_unsafe_table_reads(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/ops/seo-intel/issues', ['status' => 'resolved'])
            ->assertMethodNotAllowed();

        $this->assertContains('seo_gsc_daily', AbstractSeoDashboardReadService::allowedTables());
        $this->assertContains('seo_revenue_daily', AbstractSeoDashboardReadService::allowedTables());

        foreach ([
            'orders',
            'payment_events',
            'users',
            'seo_baidu_push_logs',
            'seo_indexnow_submissions',
            'seo_domestic_submission_logs',
            'seo_crawler_logs_daily',
        ] as $forbiddenTable) {
            $this->assertNotContains($forbiddenTable, AbstractSeoDashboardReadService::allowedTables());
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'seo_'.Str::lower(Str::random(6)),
            'email' => 'seo_'.Str::lower(Str::random(6)).'@example.test',
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
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-dash-api-01-readonly-api-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function opportunityQueueArtifact(): array
    {
        $path = base_path('docs/seo/generated/seo-opportunity-queue-readonly.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function createSeoIntelTables(): void
    {
        $schema = Schema::connection('seo_intel');

        $schema->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
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
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('status', 32);
            $table->string('lifecycle_state', 32);
            $table->timestamp('detected_at')->nullable();
            $table->string('summary', 512)->nullable();
            $table->string('recommendation', 512)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('query_display_masked', 255)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('google');
            $table->string('device', 32)->nullable();
            $table->string('country', 16)->nullable();
            $table->string('search_type', 32)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('ctr_ppm')->nullable();
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->boolean('is_brand_query')->default(false);
            $table->string('query_type', 32)->default('unknown');
            $table->string('data_state', 32)->default('final');
            $table->timestamp('collected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_baidu_landing_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->unsignedInteger('landing_event_count')->default(0);
            $table->unsignedInteger('start_attempt_count')->default(0);
            $table->unsignedInteger('purchase_success_count')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_consent_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->string('consent_state', 32);
            $table->string('source_engine', 64)->default('unknown');
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_event_funnel_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->unsignedInteger('start_attempt_count')->default(0);
            $table->unsignedInteger('view_result_count')->default(0);
            $table->unsignedInteger('purchase_success_count')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_landing_attribution_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->unsignedInteger('first_touch_count')->default(0);
            $table->unsignedInteger('last_touch_count')->default(0);
            $table->unsignedInteger('cta_touch_count')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_revenue_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('purchase_count')->default(0);
            $table->unsignedBigInteger('revenue_cents')->default(0);
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
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_search_channel_queue_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 96);
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('seo_crawler_log_daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->date('log_date');
            $table->string('host', 128);
            $table->string('surface_family', 64);
            $table->string('bot_family', 64);
            $table->string('bot_variant', 64);
            $table->string('bot_verification_state', 64);
            $table->string('route_family', 96);
            $table->string('method_bucket', 16);
            $table->boolean('query_present');
            $table->string('query_risk_state', 64);
            $table->boolean('private_path_blocked');
            $table->unsignedInteger('hit_count');
            $table->string('source_log_family', 64);
            $table->string('privacy_transform_version', 64);
            $table->string('idempotency_key', 64);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedSeoIntelData(): void
    {
        $hash = str_repeat('a', 64);

        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => $hash,
            'canonical_url' => 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            'locale' => 'en',
            'page_entity_type' => 'test_detail',
            'entity_id_or_slug' => 'mbti',
            'cluster' => 'tests',
            'source_authority' => 'scale_catalog',
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
            'metadata_json' => json_encode(['claim_boundary_state' => 'claim_safe'], JSON_UNESCAPED_SLASHES),
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-02 00:00:00',
        ]);

        DB::connection('seo_intel')->table('seo_url_entities')->insert([
            'locale' => 'en',
            'page_entity_type' => 'test_detail',
            'entity_id_or_slug' => 'mbti',
            'entity_source' => 'scale_catalog',
            'authority_status' => 'approved',
            'source_updated_at' => '2026-06-02 00:00:00',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-02 00:00:00',
        ]);

        DB::connection('seo_intel')->table('seo_issue_queue')->insert([
            'issue_uid' => 'issue-warning',
            'issue_type' => 'gsc_ctr_drop',
            'severity' => 'warning',
            'source_system' => 'gsc',
            'source_engine' => 'google',
            'canonical_url_hash' => $hash,
            'canonical_url' => 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            'locale' => 'en',
            'page_entity_type' => 'test_detail',
            'status' => 'open',
            'lifecycle_state' => 'acknowledged',
            'detected_at' => '2026-06-02 08:00:00',
            'summary' => 'CTR dropped on a safe indexed test page.',
            'recommendation' => 'Review title and snippet copy.',
            'metadata_json' => json_encode(['raw_payload' => 'not exposed'], JSON_UNESCAPED_SLASHES),
            'created_at' => '2026-06-02 08:00:00',
            'updated_at' => '2026-06-02 09:00:00',
        ]);

        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'report_date' => now()->subDays(4)->toDateString(),
            'canonical_url_hash' => $hash,
            'canonical_url' => 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            'query_hash' => hash('sha256', 'fermatmind mbti'),
            'query_display_masked' => 'mbti test',
            'locale' => 'en',
            'source_engine' => 'google',
            'clicks' => 7,
            'impressions' => 70,
            'ctr_ppm' => 100000,
            'average_position_milli' => 4200,
            'is_brand_query' => true,
            'query_type' => 'brand',
            'data_state' => 'final',
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api', 'row_source' => 'live_gsc_api'], JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subDays(4)->toDateTimeString(),
            'updated_at' => now()->subDays(4)->toDateTimeString(),
        ]);

        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'report_date' => now()->subDays(4)->toDateString(),
            'canonical_url_hash' => $hash,
            'canonical_url' => null,
            'query_hash' => hash('sha256', 'mbti career clarity'),
            'query_display_masked' => 'm**********y',
            'locale' => 'en',
            'source_engine' => 'google',
            'clicks' => 0,
            'impressions' => 500,
            'ctr_ppm' => 0,
            'average_position_milli' => 10700,
            'is_brand_query' => false,
            'query_type' => 'non_brand',
            'data_state' => 'final',
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api', 'row_source' => 'live_gsc_api'], JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subDays(4)->toDateTimeString(),
            'updated_at' => now()->subDays(4)->toDateTimeString(),
        ]);

        DB::connection('seo_intel')->table('seo_baidu_landing_daily')->insert([
            'report_date' => '2026-06-02',
            'canonical_url_hash' => $hash,
            'locale' => 'zh-CN',
            'landing_event_count' => 5,
            'start_attempt_count' => 3,
            'purchase_success_count' => 1,
            'created_at' => '2026-06-02 00:00:00',
            'updated_at' => '2026-06-02 00:00:00',
        ]);

        foreach ([
            ['granted', 12],
            ['not_applicable', 4],
        ] as [$state, $count]) {
            DB::connection('seo_intel')->table('seo_consent_daily')->insert([
                'report_date' => '2026-06-02',
                'consent_state' => $state,
                'source_engine' => 'google',
                'event_count' => $count,
                'created_at' => '2026-06-02 00:00:00',
                'updated_at' => '2026-06-02 00:00:00',
            ]);
        }

        DB::connection('seo_intel')->table('seo_event_funnel_daily')->insert([
            'report_date' => '2026-06-02',
            'canonical_url_hash' => $hash,
            'start_attempt_count' => 4,
            'view_result_count' => 2,
            'purchase_success_count' => 1,
            'created_at' => '2026-06-02 00:00:00',
            'updated_at' => '2026-06-02 00:00:00',
        ]);

        DB::connection('seo_intel')->table('seo_landing_attribution_daily')->insert([
            'report_date' => '2026-06-02',
            'canonical_url_hash' => $hash,
            'first_touch_count' => 6,
            'last_touch_count' => 5,
            'cta_touch_count' => 2,
            'created_at' => '2026-06-02 00:00:00',
            'updated_at' => '2026-06-02 00:00:00',
        ]);

        DB::connection('seo_intel')->table('seo_revenue_daily')->insert([
            'report_date' => '2026-06-02',
            'canonical_url_hash' => $hash,
            'orders_count' => 2,
            'purchase_count' => 1,
            'revenue_cents' => 1200,
            'created_at' => '2026-06-02 00:00:00',
            'updated_at' => '2026-06-02 00:00:00',
        ]);

        DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->insert([
            'channel' => 'indexnow',
            'status' => 'planned',
            'item_count' => 1,
            'created_at' => '2026-06-02 00:00:00',
            'updated_at' => '2026-06-02 00:00:00',
        ]);

        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
            'canonical_url' => 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            'locale' => 'en',
            'page_entity_type' => 'test_detail',
            'source_authority' => 'scale_catalog',
            'channel' => 'indexnow',
            'eligibility_state' => 'eligible',
            'approval_state' => 'pending',
            'execution_state' => 'not_submitted',
            'indexability_state' => 'indexable',
            'claim_boundary_state' => 'claim_safe',
            'private_flow' => false,
            'approved_at' => null,
            'created_at' => '2026-06-02 00:00:00',
            'updated_at' => '2026-06-02 00:00:00',
        ]);

        DB::connection('seo_intel')->table('seo_search_channel_queue_events')->insert([
            'event_type' => 'queue_item_planned',
            'created_at' => '2026-06-02 00:00:00',
        ]);
    }
}
