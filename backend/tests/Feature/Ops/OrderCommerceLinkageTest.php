<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\OrderResource\Pages\ListOrders;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class OrderCommerceLinkageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_orders_linkage_list_is_accessible_read_only_and_has_core_columns(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Selected Commerce Org');
        $chain = $this->seedDiagnosticChain(orgId: (int) $selectedOrg->id);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders')
            ->assertOk()
            ->assertDontSee('Request Manual Grant')
            ->assertDontSee('Request Refund');

        session($this->opsSession($admin, $selectedOrg));
        $this->setOpsOrgContext((int) $selectedOrg->id, $admin);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListOrders::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$chain['order']])
            ->assertTableColumnExists('order_no')
            ->assertTableColumnExists('created_at')
            ->assertTableColumnExists('paid_at')
            ->assertTableColumnExists('updated_at')
            ->assertTableColumnExists('provider')
            ->assertTableColumnExists('amount_cents')
            ->assertTableColumnExists('currency')
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('payment_state')
            ->assertTableColumnExists('grant_state')
            ->assertTableColumnExists('commerce_exception')
            ->assertTableColumnExists('payment_attempts_count')
            ->assertTableColumnExists('latest_payment_attempt_state')
            ->assertTableColumnExists('compensation_status')
            ->assertTableColumnExists('latest_payment_status')
            ->assertTableColumnExists('target_attempt_id')
            ->assertTableColumnExists('requested_sku')
            ->assertTableColumnExists('effective_sku')
            ->assertTableColumnExists('latest_benefit_code')
            ->assertTableColumnExists('unlock_status')
            ->assertTableColumnExists('latest_snapshot_status')
            ->assertTableColumnExists('pdf_ready')
            ->assertTableActionExists('view', null, $chain['order'])
            ->assertTableActionDoesNotExist('requestManualGrant', null, $chain['order'])
            ->assertTableActionDoesNotExist('requestRefund', null, $chain['order']);
    }

    public function test_orders_linkage_detail_renders_six_sections_hides_sensitive_payloads_and_uses_grant_for_unlock(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Cross Org Session');
        $chain = $this->seedDiagnosticChain(orgId: (int) $selectedOrg->id, orderNo: 'ord_unlock_chain_001');

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders/'.$chain['order']->getKey())
            ->assertOk()
            ->assertSee('Order Summary')
            ->assertSee('Payment Attempts')
            ->assertSee('Payment Events')
            ->assertSee('Benefit / Unlock')
            ->assertSee('Report / PDF Delivery')
            ->assertSee('Assessment Attempt Linkage')
            ->assertSee('Unified Access')
            ->assertSee('Compensation Summary')
            ->assertSee('Exception Diagnostics')
            ->assertSee('unlock: unlocked')
            ->assertSee('paid')
            ->assertSee('active')
            ->assertSee('INTJ')
            ->assertSee((string) $chain['order_no'])
            ->assertSee((string) $chain['attempt_id'])
            ->assertSee((string) $chain['payment_attempt_id'])
            ->assertSee((string) $chain['share_id'])
            ->assertSee('Order page')
            ->assertSee('Result page')
            ->assertSee('Report page')
            ->assertSee('Share page')
            ->assertSee('Latest payment attempt')
            ->assertSee('Latest payment event')
            ->assertSee('Latest benefit grant')
            ->assertSee('Order Lookup')
            ->assertDontSee('payload_json')
            ->assertDontSee('report_json')
            ->assertDontSee('report_full_json')
            ->assertDontSee((string) $chain['payment_secret'])
            ->assertDontSee((string) $chain['report_secret']);
    }

    public function test_orders_linkage_cross_org_search_respects_selected_org_context(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Current Session Org');
        $localOrder = $this->seedDiagnosticChain(orgId: (int) $selectedOrg->id, orderNo: 'ord_local_scope_001')['order'];
        $foreign = $this->seedDiagnosticChain(orgId: 88, orderNo: 'ord_cross_scope_001');

        session($this->opsSession($admin, $selectedOrg));
        $this->setOpsOrgContext((int) $selectedOrg->id, $admin);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListOrders::class)
            ->assertOk()
            ->searchTable('ord_cross_scope_001')
            ->assertCanNotSeeTableRecords([$foreign['order']])
            ->assertCanNotSeeTableRecords([$localOrder])
            ->searchTable((string) $foreign['attempt_id'])
            ->assertCanNotSeeTableRecords([$foreign['order']]);
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

    private function setOpsOrgContext(int $orgId, AdminUser $admin): void
    {
        $context = app(OrgContext::class);
        $context->set($orgId, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);
    }

    /**
     * @return array{
     *     order:Order,
     *     order_no:string,
     *     attempt_id:string,
     *     payment_attempt_id:string,
     *     share_id:string,
     *     payment_secret:string,
     *     report_secret:string
     * }
     */
    private function seedDiagnosticChain(int $orgId, ?string $orderNo = null): array
    {
        $attemptId = (string) Str::uuid();
        $shareId = (string) Str::uuid();
        $orderId = (string) Str::uuid();
        $paymentEventId = (string) Str::uuid();
        $paymentAttemptId = (string) Str::uuid();
        $benefitGrantId = (string) Str::uuid();
        $orderNo ??= 'ord_diag_'.Str::lower(Str::random(6));
        $paymentSecret = 'payment-secret-'.Str::lower(Str::random(6));
        $reportSecret = 'report-secret-'.Str::lower(Str::random(6));

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => $orgId,
            'user_id' => '1001',
            'anon_id' => 'anon_linkage',
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
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ',
            'scores_json' => json_encode(['EI' => 78], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scores_pct' => json_encode(['EI' => 88], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'axis_states' => json_encode(['EI' => 'I'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_version' => 'profile_2026_03',
            'result_json' => json_encode(['private' => 'result-hidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            'anon_id' => 'anon_linkage',
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
        if (Schema::hasColumn('orders', 'payment_state')) {
            $orderRow['payment_state'] = 'paid';
        }
        if (Schema::hasColumn('orders', 'grant_state')) {
            $orderRow['grant_state'] = 'granted';
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

        if (Schema::hasTable('payment_attempts')) {
            DB::table('payment_attempts')->insert([
                'id' => $paymentAttemptId,
                'org_id' => $orgId,
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'attempt_no' => 1,
                'provider' => 'stripe',
                'channel' => 'web',
                'provider_app' => 'web-primary',
                'pay_scene' => 'checkout',
                'state' => 'paid',
                'external_trade_no' => 'ext_'.$orderNo,
                'provider_trade_no' => 'pi_'.$orderNo,
                'provider_session_ref' => 'cs_'.$orderNo,
                'amount_expected' => 2990,
                'currency' => 'USD',
                'payload_meta_json' => json_encode(['surface' => 'ops-test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'latest_payment_event_id' => null,
                'initiated_at' => now()->subMinutes(10),
                'provider_created_at' => now()->subMinutes(10),
                'client_presented_at' => now()->subMinutes(10),
                'callback_received_at' => now()->subMinutes(9),
                'verified_at' => now()->subMinutes(9),
                'finalized_at' => now()->subMinutes(9),
                'last_error_code' => null,
                'last_error_message' => null,
                'meta_json' => null,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(9),
            ]);
        }

        DB::table('payment_events')->insert([
            'id' => $paymentEventId,
            'org_id' => $orgId,
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.Str::lower(Str::random(8)),
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'payment_attempt_id' => $paymentAttemptId,
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

        if (Schema::hasTable('payment_attempts')) {
            DB::table('payment_attempts')
                ->where('id', $paymentAttemptId)
                ->update(['latest_payment_event_id' => $paymentEventId]);
        }

        DB::table('benefit_grants')->insert([
            'id' => $benefitGrantId,
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

        if (Schema::hasTable('unified_access_projections')) {
            DB::table('unified_access_projections')->insert([
                'attempt_id' => $attemptId,
                'access_state' => 'granted',
                'report_state' => 'ready',
                'pdf_state' => 'ready',
                'reason_code' => 'payment_granted',
                'projection_version' => 1,
                'actions_json' => json_encode(['can_download_pdf' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payload_json' => json_encode(['source' => 'ops-test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'produced_at' => now()->subMinutes(8),
                'refreshed_at' => now()->subMinutes(7),
                'created_at' => now()->subMinutes(8),
                'updated_at' => now()->subMinutes(7),
            ]);
        }

        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => 'anon_linkage',
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

        $this->insertEmailOutbox($attemptId, $orderNo);

        $order = Order::query()->withoutGlobalScopes()->whereKey($orderId)->firstOrFail();

        return [
            'order' => $order,
            'order_no' => $orderNo,
            'attempt_id' => $attemptId,
            'payment_attempt_id' => $paymentAttemptId,
            'share_id' => $shareId,
            'payment_secret' => $paymentSecret,
            'report_secret' => $reportSecret,
        ];
    }

    private function insertEmailOutbox(string $attemptId, string $orderNo): void
    {
        if (! Schema::hasTable('email_outbox')) {
            return;
        }

        $row = [
            'id' => (string) Str::uuid(),
            'user_id' => '1001',
            'email' => 'redacted+'.substr(hash('sha256', $orderNo), 0, 20).'@privacy.local',
            'template' => 'payment_success',
            'payload_json' => json_encode(['order_no' => $orderNo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            $row['body_html'] = '<p>sent</p>';
        }

        DB::table('email_outbox')->insert($row);
    }
}
