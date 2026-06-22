<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ReportSnapshotResource;
use App\Filament\Ops\Resources\ReportSnapshotResource\Pages\ListReportSnapshots;
use App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport;
use App\Http\Middleware\SetOpsLocale;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\ReportSnapshot;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ReportPdfCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_report_pdf_center_list_is_accessible_read_only_and_has_core_columns(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Selected Support Org');
        $chain = $this->seedDiagnosticChain(orgId: 61);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/reports')
            ->assertOk()
            ->assertSee('报告 / PDF 中心')
            ->assertSee('最近 45 天记录')
            ->assertDontSee('Create Report Snapshot')
            ->assertDontSee('Edit Report Snapshot');

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListReportSnapshots::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$chain['snapshot']])
            ->assertTableColumnExists('attempt_id')
            ->assertTableColumnExists('order_no')
            ->assertTableColumnExists('scale_code')
            ->assertTableColumnExists('locale')
            ->assertTableColumnExists('snapshot_status')
            ->assertTableColumnExists('unlock_status')
            ->assertTableColumnExists('pdf_ready')
            ->assertTableColumnExists('delivery_status')
            ->assertTableColumnExists('updated_at')
            ->assertTableColumnExists('big5_result_page_v2_status')
            ->assertTableColumnExists('big5_result_page_v2_fallback_reason')
            ->assertTableColumnExists('big5_result_page_v2_validation_error_count')
            ->assertTableColumnExists('big5_result_page_v2_audited_at')
            ->assertTableColumnExists('org_id')
            ->assertTableActionExists('view', null, $chain['snapshot'])
            ->assertTableActionDoesNotExist('edit', null, $chain['snapshot'])
            ->assertTableActionDoesNotExist('delete', null, $chain['snapshot']);
    }

    public function test_report_pdf_center_surfaces_big5_v2_audit_without_raw_payloads(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Big Five V2 Audit Org');
        $attemptId = (string) Str::uuid();

        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => 'BIG5_OCEAN',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q2_form90_v1',
            'report_engine_version' => 'v1.2',
            'big5_result_page_v2_status' => 'attached',
            'big5_result_page_v2_fallback_reason' => 'v2_attached',
            'big5_result_page_v2_validation_error_count' => 0,
            'big5_result_page_v2_audited_at' => now()->subMinute(),
            'snapshot_version' => 'v1',
            'report_json' => json_encode([
                'locked' => false,
                'access_level' => 'full',
                'variant' => 'full',
                'big5_result_page_v2' => ['payload_key' => 'big5_result_page_v2'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_free_json' => json_encode(['variant' => 'free'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_full_json' => json_encode(['payload_json' => 'raw-hidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        $snapshot = ReportSnapshot::query()
            ->withoutGlobalScopes()
            ->whereKey($attemptId)
            ->firstOrFail();

        session($this->opsSession($admin, $selectedOrg, 'en'));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListReportSnapshots::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$snapshot])
            ->assertTableColumnStateSet('big5_result_page_v2_status', 'Attached', $snapshot);

        $this->withSession($this->opsSession($admin, $selectedOrg, 'en'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/reports/'.$attemptId.'?locale=en')
            ->assertOk()
            ->assertSee('Big Five V2 status')
            ->assertSee('Attached')
            ->assertSee('v2_attached')
            ->assertSee('Big Five V2 validation errors')
            ->assertDontSee('report_json')
            ->assertDontSee('report_full_json')
            ->assertDontSee('payload_json')
            ->assertDontSee('raw-hidden');
    }

    public function test_big5_v2_ops_summary_counts_coverage_fallback_invalid_and_rejection_reasons(): void
    {
        $now = now();
        $rows = [
            ['status' => 'attached', 'reason' => 'v2_attached', 'errors' => 0, 'minutes_ago' => 5],
            ['status' => 'attached', 'reason' => 'v2_attached', 'errors' => 0, 'minutes_ago' => 6],
            ['status' => 'fallback', 'reason' => 'production_rollout_denied', 'errors' => 0, 'minutes_ago' => 7],
            ['status' => 'fallback', 'reason' => 'locked_or_free_preview', 'errors' => 0, 'minutes_ago' => 8],
            ['status' => 'invalid', 'reason' => 'payload_validation_failed', 'errors' => 3, 'minutes_ago' => 9],
            ['status' => 'invalid', 'reason' => 'route_input_invalid', 'errors' => 2, 'minutes_ago' => 60 * 24 * 50],
        ];

        foreach ($rows as $index => $row) {
            DB::table('report_snapshots')->insert([
                'org_id' => 0,
                'attempt_id' => (string) Str::uuid(),
                'order_no' => null,
                'scale_code' => 'BIG5_OCEAN',
                'pack_id' => 'BIG5_OCEAN',
                'dir_version' => 'v1',
                'scoring_spec_version' => 'big5_spec_2026Q2_form90_v1',
                'report_engine_version' => 'v1.2',
                'big5_result_page_v2_status' => $row['status'],
                'big5_result_page_v2_fallback_reason' => $row['reason'],
                'big5_result_page_v2_validation_error_count' => $row['errors'],
                'big5_result_page_v2_audited_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
                'snapshot_version' => 'v1',
                'report_json' => json_encode(['variant' => 'full', 'row' => $index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'ready',
                'last_error' => null,
                'created_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
                'updated_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
            ]);
        }

        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => (string) Str::uuid(),
            'order_no' => null,
            'scale_code' => 'MBTI_16',
            'pack_id' => 'MBTI_16',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'mbti_spec',
            'report_engine_version' => 'v1',
            'big5_result_page_v2_status' => 'attached',
            'big5_result_page_v2_fallback_reason' => 'v2_attached',
            'big5_result_page_v2_validation_error_count' => 0,
            'big5_result_page_v2_audited_at' => $now,
            'snapshot_version' => 'v1',
            'report_json' => json_encode(['variant' => 'full'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = app(ReportSnapshotExplorerSupport::class)->bigFiveResultPageV2CoverageSummary();

        $this->assertSame(45, $summary['window_days']);
        $this->assertSame(5, $summary['total']);
        $this->assertSame(2, $summary['attached']);
        $this->assertSame(2, $summary['fallback']);
        $this->assertSame(1, $summary['invalid']);
        $this->assertSame(0, $summary['disabled_or_not_evaluated_count']);
        $this->assertSame('40.0%', $summary['coverage_rate']);
        $this->assertSame('60.0%', $summary['fallback_rate']);
        $this->assertSame(3, $summary['validation_error_count']);
        $this->assertIsString($summary['latest_audited_at']);
        $this->assertSame([
            'locked_or_free_preview' => 1,
            'production_rollout_denied' => 1,
        ], $summary['fallback_reasons']);
        $this->assertSame([
            'payload_validation_failed' => 1,
        ], $summary['invalid_reasons']);
        $this->assertSame([
            'payload_validation_failed' => 1,
        ], $summary['malformed_rejection_reasons']);
    }

    public function test_report_pdf_center_index_query_stays_lightweight_for_production_listing(): void
    {
        $support = app(ReportSnapshotExplorerSupport::class);
        $sql = strtolower($support->indexQuery()->toBase()->toSql());
        $resourceSql = strtolower(ReportSnapshotResource::getEloquentQuery()->toBase()->toSql());

        $this->assertStringContainsString('from "report_snapshots"', $sql);
        $this->assertStringContainsString('"report_snapshots"."updated_at" >= ?', $sql);
        $this->assertStringContainsString('"report_snapshots"."created_at" >= ?', $sql);
        $this->assertStringContainsString('last_delivery_email_sent_at', $sql);
        $this->assertStringContainsString('report_job_status', $sql);
        $this->assertStringContainsString('benefit_grants', $sql);
        $this->assertSame($sql, $resourceSql);
        $this->assertSame(45, $support->indexLookbackDays());
    }

    public function test_report_pdf_center_index_query_hydrates_lightweight_status_fields_without_detail_fetches(): void
    {
        $chain = $this->seedDiagnosticChain(orgId: 91, orderNo: 'ord_report_index_status_001');

        $snapshot = app(ReportSnapshotExplorerSupport::class)->indexQuery()->firstWhere('attempt_id', $chain['attempt_id']);

        $this->assertInstanceOf(ReportSnapshot::class, $snapshot);
        $this->assertSame('en', (string) $snapshot->locale);
        $this->assertSame('paid', (string) $snapshot->order_status);
        $this->assertSame('paid', (string) $snapshot->payment_status);
        $this->assertSame('succeeded', (string) $snapshot->report_job_status);
        $this->assertNotEmpty((string) $snapshot->last_delivery_email_sent_at);
        $this->assertSame(1, (int) $snapshot->has_order);
        $this->assertSame(1, (int) $snapshot->has_active_benefit_grant);
    }

    public function test_report_pdf_center_initial_render_avoids_expensive_distinct_filter_option_queries(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Recent Window Org');
        $this->seedDiagnosticChain(orgId: 61);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/reports')
            ->assertOk()
            ->assertSee('最近 45 天记录');

        $resourceSource = file_get_contents(__DIR__.'/../../..'.'/app/Filament/Ops/Resources/ReportSnapshotResource.php');

        $this->assertIsString($resourceSource);
        $this->assertStringNotContainsString("->options(fn (): array => \$support->distinctSnapshotOptions('scale_code'))", $resourceSource);
        $this->assertStringNotContainsString("->options(fn (): array => \$support->distinctAttemptOptions('locale'))", $resourceSource);
        $this->assertStringNotContainsString("->options(fn (): array => \$support->distinctAttemptOptions('region'))", $resourceSource);
    }

    public function test_report_pdf_center_detail_renders_seven_sections_hides_sensitive_payloads_and_shows_drill_through_links(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Cross Org Session');
        $chain = $this->seedDiagnosticChain(orgId: 72, orderNo: 'ord_report_diag_001');

        $response = $this->withSession($this->opsSession($admin, $selectedOrg, 'en'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/reports/'.$chain['attempt_id'].'?locale=en');

        $response
            ->assertOk()
            ->assertSee('Snapshot Summary')
            ->assertSee('PDF / Delivery Status')
            ->assertSee('Report Job / Generation Status')
            ->assertSee('Attempt Linkage')
            ->assertSee('Result Linkage')
            ->assertSee('Commerce / Unlock Linkage')
            ->assertSee('Share / Access Linkage + Exception Diagnostics')
            ->assertSee('unlock: Unlocked')
            ->assertSee('snapshot: Ready')
            ->assertSee((string) $chain['order_no'])
            ->assertSee((string) $chain['attempt_id'])
            ->assertSee((string) $chain['share_id'])
            ->assertSee('/api/v0.3/attempts/'.$chain['attempt_id'].'/report.pdf')
            ->assertSee('Order page')
            ->assertSee('Result page')
            ->assertSee('Report page')
            ->assertSee('Share page')
            ->assertSee('Order Lookup')
            ->assertDontSee('report_json')
            ->assertDontSee('report_free_json')
            ->assertDontSee('report_full_json')
            ->assertDontSee('result_json')
            ->assertDontSee('payload_json')
            ->assertDontSee((string) $chain['payment_secret'])
            ->assertDontSee((string) $chain['report_secret'])
            ->assertDontSee((string) $chain['email_secret']);
    }

    public function test_report_pdf_center_cross_org_search_works_for_attempt_id_order_no_and_share_id(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Current Session Org');
        $local = $this->seedDiagnosticChain(orgId: (int) $selectedOrg->id, orderNo: 'ord_report_local_001');
        $foreign = $this->seedDiagnosticChain(orgId: 88, orderNo: 'ord_report_cross_001');

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListReportSnapshots::class)
            ->assertOk()
            ->searchTable('ord_report_cross_001')
            ->assertCanSeeTableRecords([$foreign['snapshot']])
            ->assertCanNotSeeTableRecords([$local['snapshot']])
            ->assertTableColumnStateSet('org_id', 88, $foreign['snapshot'])
            ->searchTable((string) $foreign['attempt_id'])
            ->assertCanSeeTableRecords([$foreign['snapshot']])
            ->searchTable((string) $foreign['share_id'])
            ->assertCanSeeTableRecords([$foreign['snapshot']]);
    }

    public function test_report_pdf_center_uses_benefit_grant_for_unlock_truth_and_keeps_report_job_auxiliary(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Unlock Truth Org');
        $chain = $this->seedDiagnosticChain(orgId: 91, orderNo: 'ord_unlock_truth_001');

        $response = $this->withSession($this->opsSession($admin, $selectedOrg, 'en'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/reports/'.$chain['attempt_id'].'?locale=en');

        $response
            ->assertOk()
            ->assertSee('Unlock truth is derived from benefit_grants first, not from report_jobs or order status alone.')
            ->assertSee('Report jobs stay auxiliary. report_snapshots remain the persisted delivery object read by access flows.')
            ->assertSee('Active benefit grant')
            ->assertSee('Unlocked')
            ->assertSee('Succeeded');
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
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int,ops_locale?:string,ops_locale_explicit?:bool}
     */
    private function opsSession(AdminUser $admin, Organization $selectedOrg, ?string $locale = null): array
    {
        $session = [
            'ops_org_id' => (int) $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];

        if ($locale !== null) {
            $session[SetOpsLocale::SESSION_KEY] = $locale;
            $session[SetOpsLocale::EXPLICIT_SESSION_KEY] = true;
        }

        return $session;
    }

    /**
     * @return array{
     *   snapshot:ReportSnapshot,
     *   attempt_id:string,
     *   order_no:string,
     *   share_id:string,
     *   payment_secret:string,
     *   report_secret:string,
     *   email_secret:string
     * }
     */
    private function seedDiagnosticChain(int $orgId, ?string $orderNo = null): array
    {
        $attemptId = (string) Str::uuid();
        $resultId = (string) Str::uuid();
        $shareId = (string) Str::uuid();
        $orderId = (string) Str::uuid();
        $paymentEventId = (string) Str::uuid();
        $orderNo ??= 'ord_report_'.Str::lower(Str::random(6));
        $paymentSecret = 'payment-secret-'.Str::lower(Str::random(6));
        $reportSecret = 'report-secret-'.Str::lower(Str::random(6));
        $emailSecret = 'email-secret-'.Str::lower(Str::random(6));

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => $orgId,
            'user_id' => '1001',
            'anon_id' => 'anon_report_chain',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
            'region' => 'US',
            'locale' => 'en',
            'question_count' => 93,
            'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'channel' => 'web',
            'started_at' => now()->subMinutes(20),
            'submitted_at' => now()->subMinutes(15),
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'content_package_version' => 'content_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'norm_version' => 'norm_2026_03',
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(15),
            'result_json' => json_encode(['type_code' => 'INTJ'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        DB::table('results')->insert([
            'id' => $resultId,
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ',
            'scores_json' => json_encode(['EI' => 78], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scores_pct' => json_encode(['EI' => 88], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'axis_states' => json_encode(['EI' => 'I'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_version' => 'profile_2026_03',
            'result_json' => json_encode(['secret' => 'result-hidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'content_package_version' => 'content_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'report_engine_version' => 'v2.3',
            'is_valid' => 1,
            'computed_at' => now()->subMinutes(12),
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(11),
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
        ]);

        DB::table('report_snapshots')->insert([
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'scale_code' => 'MBTI',
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'report_engine_version' => 'v2.3',
            'snapshot_version' => 'v1',
            'report_json' => json_encode([
                'locked' => false,
                'access_level' => 'full',
                'variant' => 'full',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_free_json' => json_encode(['variant' => 'free'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_full_json' => json_encode(['secret' => $reportSecret], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(9),
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
        ]);

        $orderRow = [
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => '1001',
            'anon_id' => 'anon_report_chain',
            'sku' => 'MBTI_FULL_REPORT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 2990,
            'currency' => 'USD',
            'status' => 'paid',
            'provider' => 'stripe',
            'paid_at' => now()->subMinutes(9),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(8),
        ];

        if (Schema::hasColumn('orders', 'amount_total')) {
            $orderRow['amount_total'] = 2990;
        }
        if (Schema::hasColumn('orders', 'amount_refunded')) {
            $orderRow['amount_refunded'] = 0;
        }
        if (Schema::hasColumn('orders', 'item_sku')) {
            $orderRow['item_sku'] = 'MBTI_FULL_REPORT';
        }
        if (Schema::hasColumn('orders', 'provider_order_id')) {
            $orderRow['provider_order_id'] = 'pi_'.$orderNo;
        }
        if (Schema::hasColumn('orders', 'fulfilled_at')) {
            $orderRow['fulfilled_at'] = now()->subMinutes(7);
        }
        if (Schema::hasColumn('orders', 'requested_sku')) {
            $orderRow['requested_sku'] = 'MBTI_FULL_REPORT';
        }
        if (Schema::hasColumn('orders', 'effective_sku')) {
            $orderRow['effective_sku'] = 'MBTI_FULL_REPORT';
        }
        if (Schema::hasColumn('orders', 'entitlement_id')) {
            $orderRow['entitlement_id'] = 'ent_'.$orderNo;
        }
        if (Schema::hasColumn('orders', 'contact_email_hash')) {
            $orderRow['contact_email_hash'] = hash('sha256', 'buyer+'.$orderNo.'@example.test');
        }
        if (Schema::hasColumn('orders', 'meta_json')) {
            $orderRow['meta_json'] = json_encode([
                'attribution' => [
                    'share_id' => $shareId,
                    'share_click_id' => 'click_'.$orderNo,
                    'entrypoint' => 'checkout_return',
                    'utm' => [
                        'source' => 'wechat',
                        'medium' => 'organic',
                        'campaign' => 'spring',
                    ],
                ],
                'claim_status' => 'claimed',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        DB::table('orders')->insert($orderRow);

        DB::table('payment_events')->insert([
            'id' => $paymentEventId,
            'org_id' => $orgId,
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.Str::lower(Str::random(8)),
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'event_type' => 'payment_intent.succeeded',
            'signature_ok' => 1,
            'status' => 'paid',
            'attempts' => 1,
            'last_error_code' => null,
            'last_error_message' => null,
            'processed_at' => now()->subMinutes(9),
            'handled_at' => now()->subMinutes(9),
            'handle_status' => 'processed',
            'reason' => 'checkout_paid',
            'requested_sku' => 'MBTI_FULL_REPORT',
            'effective_sku' => 'MBTI_FULL_REPORT',
            'entitlement_id' => 'ent_'.$orderNo,
            'payload_json' => json_encode(['secret' => $paymentSecret], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_size_bytes' => 128,
            'payload_sha256' => hash('sha256', $paymentSecret),
            'payload_s3_key' => null,
            'payload_excerpt' => null,
            'received_at' => now()->subMinutes(9),
            'created_at' => now()->subMinutes(9),
            'updated_at' => now()->subMinutes(9),
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
        ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => '1001',
            'benefit_code' => 'MBTI_FULL_REPORT',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => 'active',
            'expires_at' => now()->addDays(30),
            'benefit_type' => 'report',
            'benefit_ref' => 'benefit_'.$orderNo,
            'order_no' => $orderNo,
            'source_order_id' => $orderId,
            'source_event_id' => $paymentEventId,
            'meta_json' => json_encode(['claim_status' => 'claimed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(8),
        ]);

        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => 'anon_report_chain',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'content_package_version' => 'content_2026_03',
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(5),
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
        ]);

        DB::table('report_jobs')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'status' => 'succeeded',
            'tries' => 1,
            'available_at' => now()->subMinutes(11),
            'started_at' => now()->subMinutes(11),
            'finished_at' => now()->subMinutes(10),
            'failed_at' => null,
            'last_error' => null,
            'last_error_trace' => null,
            'report_json' => '{}',
            'meta' => '{}',
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(10),
            'org_id' => $orgId,
        ]);

        $this->insertEmailOutbox($attemptId, $orderNo, $emailSecret);

        DB::table('events')->insert([
            'id' => (string) Str::uuid(),
            'event_code' => 'share_visit',
            'user_id' => null,
            'anon_id' => 'anon_report_chain',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'attempt_id' => $attemptId,
            'channel' => 'web',
            'region' => 'US',
            'locale' => 'en',
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'meta_json' => json_encode([
                'stage' => 'share',
                'variant' => 'full',
                'locked' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
            'org_id' => $orgId,
            'share_id' => $shareId,
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
        ]);

        $snapshot = ReportSnapshot::query()
            ->withoutGlobalScopes()
            ->whereKey($attemptId)
            ->firstOrFail();

        return [
            'snapshot' => $snapshot,
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'share_id' => $shareId,
            'payment_secret' => $paymentSecret,
            'report_secret' => $reportSecret,
            'email_secret' => $emailSecret,
        ];
    }

    private function insertEmailOutbox(string $attemptId, string $orderNo, string $emailSecret): void
    {
        if (! Schema::hasTable('email_outbox')) {
            return;
        }

        $row = [
            'id' => (string) Str::uuid(),
            'user_id' => '1001',
            'email' => 'redacted+'.substr(hash('sha256', $orderNo), 0, 20).'@privacy.local',
            'template' => 'payment_success',
            'payload_json' => json_encode(['secret' => $emailSecret], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'claim_token_hash' => hash('sha256', 'claim_'.$orderNo),
            'claim_expires_at' => now()->addDay(),
            'status' => 'sent',
            'sent_at' => now()->subMinutes(4),
            'consumed_at' => null,
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ];

        if (Schema::hasColumn('email_outbox', 'attempt_id')) {
            $row['attempt_id'] = $attemptId;
        }
        if (Schema::hasColumn('email_outbox', 'to_email')) {
            $row['to_email'] = 'redacted+'.substr(hash('sha256', $orderNo), 0, 20).'@privacy.local';
        }
        if (Schema::hasColumn('email_outbox', 'locale')) {
            $row['locale'] = 'en';
        }
        if (Schema::hasColumn('email_outbox', 'template_key')) {
            $row['template_key'] = 'payment_success';
        }
        if (Schema::hasColumn('email_outbox', 'subject')) {
            $row['subject'] = 'Payment success';
        }
        if (Schema::hasColumn('email_outbox', 'body_html')) {
            $row['body_html'] = '<p>redacted</p>';
        }

        DB::table('email_outbox')->insert($row);
    }
}
