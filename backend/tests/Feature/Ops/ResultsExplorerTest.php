<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ResultResource\Pages\ListResults;
use App\Filament\Ops\Resources\ResultResource\Support\ResultExplorerSupport;
use App\Models\AdminUser;
use App\Models\Attempt;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Result;
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

final class ResultsExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_results_explorer_list_is_accessible_read_only_and_has_core_columns(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Selected Results Org');
        $chain = $this->seedDiagnosticChain(orgId: 22);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/results')
            ->assertOk()
            ->assertSee('Results Explorer')
            ->assertDontSee('Create Result')
            ->assertDontSee('Edit Result');

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListResults::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$chain['result']])
            ->assertTableColumnExists('attempt_id')
            ->assertTableColumnExists('scale_code')
            ->assertTableColumnExists('type_code')
            ->assertTableColumnExists('computed_at')
            ->assertTableColumnExists('result_status')
            ->assertTableColumnExists('latest_snapshot_status')
            ->assertTableColumnExists('unlock_status')
            ->assertTableColumnExists('attempt_locale')
            ->assertTableColumnExists('org_id')
            ->assertTableActionExists('view', null, $chain['result'])
            ->assertTableActionDoesNotExist('edit', null, $chain['result'])
            ->assertTableActionDoesNotExist('delete', null, $chain['result']);
    }

    public function test_results_explorer_detail_renders_seven_sections_hides_raw_payloads_and_shows_chain_links(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Result Detail Org');
        $chain = $this->seedDiagnosticChain(orgId: 33, orderNo: 'ord_results_chain_001');

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/results/'.$chain['result']->getKey())
            ->assertOk()
            ->assertSee('Result Summary')
            ->assertSee('Score / Axis Summary')
            ->assertSee('Version / Diagnostics')
            ->assertSee('Report Linkage')
            ->assertSee('Attempt Linkage')
            ->assertSee('Commerce / Unlock Linkage')
            ->assertSee('Share / Engagement')
            ->assertSee((string) $chain['result']->id)
            ->assertSee((string) $chain['attempt_id'])
            ->assertSee((string) $chain['order_no'])
            ->assertSee((string) $chain['share_id'])
            ->assertSee('Result page')
            ->assertSee('Report page')
            ->assertSee('Share page')
            ->assertSee('Order diagnostics')
            ->assertSee('Order Lookup')
            ->assertSee('unlock: unlocked')
            ->assertSee('valid')
            ->assertSee('INTJ')
            ->assertDontSee('result_json')
            ->assertDontSee('scores_json')
            ->assertDontSee('report_json')
            ->assertDontSee('report_full_json')
            ->assertDontSee((string) $chain['result_secret'])
            ->assertDontSee((string) $chain['payment_secret'])
            ->assertDontSee((string) $chain['report_secret']);
    }

    public function test_results_explorer_cross_org_search_works_for_order_no_share_id_result_id_and_attempt_id(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Current Support Org');
        $local = $this->seedDiagnosticChain(orgId: (int) $selectedOrg->id, orderNo: 'ord_results_local_001');
        $foreign = $this->seedDiagnosticChain(orgId: 77, orderNo: 'ord_results_cross_001');

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListResults::class)
            ->assertOk()
            ->searchTable('ord_results_cross_001')
            ->assertCanSeeTableRecords([$foreign['result']])
            ->assertCanNotSeeTableRecords([$local['result']])
            ->assertTableColumnStateSet('org_id', 77, $foreign['result'])
            ->searchTable($foreign['share_id'])
            ->assertCanSeeTableRecords([$foreign['result']])
            ->searchTable((string) $foreign['result']->id)
            ->assertCanSeeTableRecords([$foreign['result']])
            ->searchTable((string) $foreign['attempt_id'])
            ->assertCanSeeTableRecords([$foreign['result']]);
    }

    public function test_results_explorer_orphan_result_stays_visible_and_degrades_attempt_report_linkage(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Orphan Result Org');
        $orphan = $this->seedOrphanResult(orgId: 66);

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListResults::class)
            ->assertOk()
            ->searchTable((string) $orphan->id)
            ->assertCanSeeTableRecords([$orphan]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/results/'.$orphan->getKey())
            ->assertOk()
            ->assertSee('orphan_result')
            ->assertSee('Attempt row is missing for this result. Result search stays available by result_id and attempt_id.')
            ->assertSee('Result page')
            ->assertDontSee('Report page')
            ->assertDontSee('Attempt Explorer')
            ->assertSee('Report drill-through is unavailable while the attempt row is missing.');
    }

    public function test_results_explorer_prefers_result_quality_truth_and_no_longer_depends_on_attempt_quality(): void
    {
        $truthChain = $this->seedDiagnosticChain(orgId: 88, orderNo: 'ord_results_truth_001');
        $truthResult = Result::query()->findOrFail((string) $truthChain['result']->getKey());
        $truthPayload = is_array($truthResult->result_json ?? null) ? $truthResult->result_json : [];
        $truthPayload['quality'] = [
            'level' => 'B',
            'grade' => 'B',
        ];
        $truthResult->update(['result_json' => $truthPayload]);
        DB::table('attempt_quality')
            ->where('attempt_id', (string) $truthChain['attempt_id'])
            ->update(['grade' => 'A']);

        $fallbackChain = $this->seedDiagnosticChain(orgId: 89, orderNo: 'ord_results_fallback_001');
        $fallbackResult = Result::query()->findOrFail((string) $fallbackChain['result']->getKey());

        /** @var ResultExplorerSupport $support */
        $support = app(ResultExplorerSupport::class);

        $truthDetail = $support->buildDetail($truthResult->fresh());
        $truthQuality = collect($truthDetail['attempt_summary']['fields'])->firstWhere('label', 'quality');
        $this->assertSame('B', $truthQuality['value'] ?? null);

        $fallbackDetail = $support->buildDetail($fallbackResult);
        $fallbackQuality = collect($fallbackDetail['attempt_summary']['fields'])->firstWhere('label', 'quality');
        $this->assertSame('-', $fallbackQuality['value'] ?? null);
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

    /**
     * @return array{
     *   result:Result,
     *   attempt_id:string,
     *   order_no:string,
     *   share_id:string,
     *   result_secret:string,
     *   payment_secret:string,
     *   report_secret:string
     * }
     */
    private function seedDiagnosticChain(int $orgId, ?string $orderNo = null): array
    {
        $attemptId = (string) Str::uuid();
        $resultId = (string) Str::uuid();
        $shareId = (string) Str::uuid();
        $orderId = (string) Str::uuid();
        $paymentEventId = (string) Str::uuid();
        $orderNo ??= 'ord_result_diag_'.Str::lower(Str::random(6));
        $resultSecret = 'result-secret-'.Str::lower(Str::random(6));
        $paymentSecret = 'payment-secret-'.Str::lower(Str::random(6));
        $reportSecret = 'report-secret-'.Str::lower(Str::random(6));

        $this->createAttempt([
            'id' => $attemptId,
            'org_id' => $orgId,
            'user_id' => '1001',
            'anon_id' => 'anon_results_chain',
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_uid' => '66666666-6666-4666-8666-666666666666',
            'locale' => 'en',
            'region' => 'US',
            'channel' => 'web',
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'content_package_version' => 'content_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'norm_version' => 'norm_2026_03',
            'result_json' => [
                'type_code' => 'INTJ',
                'public_hint' => true,
            ],
        ]);

        DB::table('results')->insert([
            'id' => $resultId,
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_uid' => '66666666-6666-4666-8666-666666666666',
            'type_code' => 'INTJ',
            'scores_json' => json_encode(['EI' => 78, 'SN' => 62], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scores_pct' => json_encode(['EI' => 88, 'SN' => 74], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'axis_states' => json_encode(['EI' => 'I', 'SN' => 'N'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_version' => 'profile_2026_03',
            'content_package_version' => 'content_2026_03',
            'result_json' => json_encode(['type_code' => 'INTJ', 'private_secret' => $resultSecret], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'report_engine_version' => 'v2.3',
            'is_valid' => 1,
            'computed_at' => now()->subMinutes(12),
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(11),
        ]);

        DB::table('report_snapshots')->insert([
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'scale_code' => 'MBTI',
            'scale_uid' => '66666666-6666-4666-8666-666666666666',
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
        ]);

        $orderRow = [
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => '1001',
            'anon_id' => 'anon_results_chain',
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
        if (Schema::hasColumn('orders', 'meta_json')) {
            $orderRow['meta_json'] = json_encode(['claim_status' => 'claimed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            'scale_uid' => '66666666-6666-4666-8666-666666666666',
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
            'anon_id' => 'anon_results_chain',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'content_package_version' => 'content_2026_03',
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(5),
            'scale_uid' => '66666666-6666-4666-8666-666666666666',
        ]);

        DB::table('attempt_submissions')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'actor_user_id' => '1001',
            'actor_anon_id' => 'anon_results_chain',
            'dedupe_key' => 'result-chain-dedupe',
            'mode' => 'sync',
            'state' => 'succeeded',
            'error_code' => null,
            'error_message' => null,
            'request_payload_json' => '{}',
            'response_payload_json' => '{}',
            'started_at' => now()->subMinutes(14),
            'finished_at' => now()->subMinutes(13),
            'created_at' => now()->subMinutes(14),
            'updated_at' => now()->subMinutes(13),
        ]);

        DB::table('attempt_quality')->insert([
            'attempt_id' => $attemptId,
            'checks_json' => json_encode(['speeding' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'grade' => 'A',
            'created_at' => now()->subMinutes(13),
        ]);

        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'share_created',
                'user_id' => '1001',
                'anon_id' => 'anon_results_chain',
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'attempt_id' => $attemptId,
                'channel' => 'web',
                'region' => 'US',
                'locale' => 'en',
                'meta_json' => json_encode(['variant' => 'full', 'locked' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => now()->subMinutes(5),
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
                'share_id' => $shareId,
                'org_id' => $orgId,
                'scale_uid' => '66666666-6666-4666-8666-666666666666',
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'share_click',
                'user_id' => '1001',
                'anon_id' => 'anon_results_chain',
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'attempt_id' => $attemptId,
                'channel' => 'web',
                'region' => 'US',
                'locale' => 'en',
                'meta_json' => json_encode(['stage' => 'landing', 'provider' => 'wechat'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => now()->subMinutes(4),
                'created_at' => now()->subMinutes(4),
                'updated_at' => now()->subMinutes(4),
                'share_id' => $shareId,
                'org_id' => $orgId,
                'scale_uid' => '66666666-6666-4666-8666-666666666666',
            ],
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

        return [
            'result' => Result::query()->withoutGlobalScopes()->whereKey($resultId)->firstOrFail(),
            'attempt_id' => $attemptId,
            'order_no' => $orderNo,
            'share_id' => $shareId,
            'result_secret' => $resultSecret,
            'payment_secret' => $paymentSecret,
            'report_secret' => $reportSecret,
        ];
    }

    private function seedOrphanResult(int $orgId): Result
    {
        $resultId = (string) Str::uuid();

        DB::table('results')->insert([
            'id' => $resultId,
            'attempt_id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_uid' => '77777777-7777-4777-8777-777777777777',
            'type_code' => 'ENTP',
            'scores_json' => json_encode(['EI' => 70], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scores_pct' => json_encode(['EI' => 82], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'axis_states' => json_encode(['EI' => 'E'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_version' => 'profile_orphan',
            'content_package_version' => 'content_orphan',
            'result_json' => json_encode(['type_code' => 'ENTP', 'private_secret' => 'orphan-hidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_orphan',
            'scoring_spec_version' => 'scoring_orphan',
            'report_engine_version' => 'v2.3',
            'is_valid' => 1,
            'computed_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(2),
        ]);

        return Result::query()->withoutGlobalScopes()->whereKey($resultId)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAttempt(array $overrides = []): Attempt
    {
        $attemptId = (string) ($overrides['id'] ?? Str::uuid());

        return Attempt::query()->create(array_merge([
            'id' => $attemptId,
            'org_id' => 0,
            'user_id' => '1001',
            'anon_id' => 'anon-default',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
            'region' => 'US',
            'locale' => 'en',
            'question_count' => 93,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'channel' => 'web',
            'started_at' => now()->subMinutes(20),
            'submitted_at' => now()->subMinutes(15),
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_default',
            'content_package_version' => 'content_default',
            'scoring_spec_version' => 'scoring_default',
            'norm_version' => 'norm_default',
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
        ], $overrides));
    }
}
