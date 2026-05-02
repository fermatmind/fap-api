<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\AttemptResource\Pages\ListAttempts;
use App\Models\AdminUser;
use App\Models\Attempt;
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

final class AttemptsExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_attempts_explorer_list_is_accessible_read_only_and_has_core_columns(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Selected Ops Org');
        $chain = $this->seedFullDiagnosticChain(orgId: (int) $selectedOrg->id);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/attempts')
            ->assertOk()
            ->assertSee('作答排查')
            ->assertDontSee('Create Attempt')
            ->assertDontSee('Edit Attempt');

        session($this->opsSession($admin, $selectedOrg));
        $this->setOpsOrgContext((int) $selectedOrg->id, $admin);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListAttempts::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$chain['attempt']])
            ->assertTableColumnExists('id')
            ->assertTableColumnExists('scale_code')
            ->assertTableColumnExists('submitted_status')
            ->assertTableColumnExists('submitted_at')
            ->assertTableColumnExists('locale')
            ->assertTableColumnExists('region')
            ->assertTableColumnExists('channel')
            ->assertTableColumnExists('has_result')
            ->assertTableColumnExists('latest_report_snapshot_status')
            ->assertTableColumnExists('unlock_status')
            ->assertTableColumnExists('org_id')
            ->assertTableActionExists('view', null, $chain['attempt'])
            ->assertTableActionDoesNotExist('edit', null, $chain['attempt'])
            ->assertTableActionDoesNotExist('delete', null, $chain['attempt']);
    }

    public function test_attempts_explorer_detail_renders_full_chain_sections_and_drill_through_links(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Support Selection Org');
        $chain = $this->seedFullDiagnosticChain(orgId: (int) $selectedOrg->id);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/attempts/'.$chain['attempt']->id)
            ->assertOk()
            ->assertSee('Attempt Summary')
            ->assertSee('Answers Summary')
            ->assertSee('Result')
            ->assertSee('Report Snapshot')
            ->assertSee('Commerce / Unlock Linkage')
            ->assertSee('Event Timeline')
            ->assertSee((string) $chain['attempt']->id)
            ->assertSee((string) $chain['order_no'])
            ->assertSee((string) $chain['share_id'])
            ->assertSee('Result page')
            ->assertSee('Report page')
            ->assertSee('Order page')
            ->assertSee('Share page')
            ->assertSee('Order Lookup')
            ->assertSee('succeeded')
            ->assertSee('present')
            ->assertSee('INTJ')
            ->assertSee('full')
            ->assertSee('active');
    }

    public function test_attempts_explorer_cross_org_order_search_respects_selected_org_context(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Current Session Org');
        $localAttempt = $this->createAttempt([
            'org_id' => $selectedOrg->id,
            'ticket_code' => 'FMT-LOCAL01',
            'anon_id' => 'anon-local',
            'user_id' => '1010',
        ]);
        $foreignChain = $this->seedFullDiagnosticChain(orgId: 77, orderNo: 'ord_cross_org_001');

        session($this->opsSession($admin, $selectedOrg));
        $this->setOpsOrgContext((int) $selectedOrg->id, $admin);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListAttempts::class)
            ->assertOk()
            ->searchTable('ord_cross_org_001')
            ->assertCanNotSeeTableRecords([$foreignChain['attempt']])
            ->assertCanNotSeeTableRecords([$localAttempt])
            ->searchTable('FMT-LOCAL01')
            ->assertCanSeeTableRecords([$localAttempt]);
    }

    public function test_attempts_explorer_keeps_raw_answers_hidden_and_surfaces_sensitive_storage_modes(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Sensitivity Org');

        $clinicalAttempt = $this->createAttempt([
            'id' => '11111111-1111-4111-8111-111111111111',
            'org_id' => (int) $selectedOrg->id,
            'scale_code' => 'CLINICAL_COMBO_68',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'channel' => 'mobile',
            'pack_id' => 'CLINICAL_COMBO_68',
            'scoring_spec_version' => 'clinical_v1',
            'question_count' => 68,
        ]);
        $this->insertAnswerSet($clinicalAttempt->id, (int) $selectedOrg->id, 'CLINICAL_COMBO_68', null, hash('sha256', 'clinical-secret-answer'), 68);

        $sdsAttempt = $this->createAttempt([
            'id' => '22222222-2222-4222-8222-222222222222',
            'org_id' => (int) $selectedOrg->id,
            'scale_code' => 'SDS_20',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'channel' => 'mobile',
            'pack_id' => 'SDS_20',
            'scoring_spec_version' => 'sds_v2',
            'question_count' => 20,
        ]);
        $this->insertAnswerSet($sdsAttempt->id, (int) $selectedOrg->id, 'SDS_20', null, hash('sha256', 'sds-secret-answer'), 20);
        $this->insertAnswerRow($sdsAttempt->id, (int) $selectedOrg->id, 'SDS_20', 'Q-1', ['code' => 'SDS_SECRET', 'answer' => 'Very secret']);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/attempts/'.$clinicalAttempt->id)
            ->assertOk()
            ->assertSee('rowless')
            ->assertSee('Clinical assessments default to rowless storage.')
            ->assertDontSee('clinical-secret-answer')
            ->assertDontSeeText('answers_json')
            ->assertDontSeeText('answer_json');

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/attempts/'.$sdsAttempt->id)
            ->assertOk()
            ->assertSee('redacted')
            ->assertSee('SDS answer rows stay redacted by design.')
            ->assertDontSee('SDS_SECRET')
            ->assertDontSee('Very secret')
            ->assertDontSeeText('answers_json')
            ->assertDontSeeText('answer_json');
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
     * @return array{attempt:Attempt,order_no:string,share_id:string}
     */
    private function seedFullDiagnosticChain(int $orgId, ?string $orderNo = null): array
    {
        $attemptId = (string) Str::uuid();
        $shareId = (string) Str::uuid();
        $orderNo ??= 'ord_ops_chain_'.Str::lower(Str::random(6));

        $attempt = $this->createAttempt([
            'id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_uid' => '33333333-3333-4333-8333-333333333333',
            'user_id' => '1001',
            'anon_id' => 'anon_ops_chain',
            'ticket_code' => 'FMT-CHAIN01',
            'locale' => 'en',
            'region' => 'US',
            'channel' => 'web',
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'content_package_version' => 'content_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'norm_version' => 'norm_2026_03',
            'question_count' => 93,
            'result_json' => [
                'type_code' => 'INTJ',
                'public_hint' => true,
            ],
        ]);

        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ',
            'scores_json' => json_encode(['EI' => 78, 'SN' => 62], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scores_pct' => json_encode(['EI' => 88, 'SN' => 74], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'axis_states' => json_encode(['EI' => 'I', 'SN' => 'N'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_version' => 'profile_2026_03',
            'is_valid' => 1,
            'computed_at' => now()->subMinutes(12),
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(11),
            'content_package_version' => 'content_2026_03',
            'result_json' => json_encode(['type_code' => 'INTJ', 'private_hint' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'report_engine_version' => 'v2.3',
            'scale_uid' => '33333333-3333-4333-8333-333333333333',
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
            'report_full_json' => json_encode(['variant' => 'full'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(9),
            'scale_uid' => '33333333-3333-4333-8333-333333333333',
        ]);

        $orderId = $this->insertOrder($orderNo, $attemptId, $orgId, 'paid', 'anon_ops_chain', '1001');
        $paymentEventId = $this->insertPaymentEvent($orderId, $orderNo, $orgId, 'paid', 'payment_intent.succeeded');
        $this->insertBenefitGrant($attemptId, $orderId, $paymentEventId, $orderNo, $orgId, 'active');

        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => 'anon_ops_chain',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'content_package_version' => 'content_2026_03',
            'scale_uid' => '33333333-3333-4333-8333-333333333333',
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(5),
        ]);

        DB::table('attempt_submissions')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'actor_user_id' => '1001',
            'actor_anon_id' => 'anon_ops_chain',
            'dedupe_key' => 'attempt-chain-dedupe',
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

        $this->insertAnswerSet($attemptId, $orgId, 'MBTI', base64_encode(json_encode([['question_id' => 'Q-1', 'code' => 'A']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), hash('sha256', 'mbti-chain'), 93);

        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'attempt_submitted',
                'user_id' => '1001',
                'anon_id' => 'anon_ops_chain',
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'attempt_id' => $attemptId,
                'channel' => 'web',
                'region' => 'US',
                'locale' => 'en',
                'meta_json' => json_encode(['status' => 'submitted', 'stage' => 'complete'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => now()->subMinutes(13),
                'created_at' => now()->subMinutes(13),
                'updated_at' => now()->subMinutes(13),
                'share_id' => null,
                'org_id' => $orgId,
                'scale_uid' => '33333333-3333-4333-8333-333333333333',
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'share_created',
                'user_id' => '1001',
                'anon_id' => 'anon_ops_chain',
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
                'scale_uid' => '33333333-3333-4333-8333-333333333333',
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
            'attempt' => $attempt->fresh(),
            'order_no' => $orderNo,
            'share_id' => $shareId,
        ];
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

    private function insertAnswerSet(string $attemptId, int $orgId, string $scaleCode, ?string $answersJson, string $answersHash, int $questionCount): void
    {
        DB::table('attempt_answer_sets')->insert([
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'pack_id' => $scaleCode,
            'dir_version' => 'dir_v1',
            'scoring_spec_version' => 'spec_v1',
            'answers_json' => $answersJson,
            'answers_hash' => $answersHash,
            'question_count' => $questionCount,
            'duration_ms' => 120000,
            'submitted_at' => now()->subMinutes(15),
            'created_at' => now()->subMinutes(15),
            'scale_uid' => '44444444-4444-4444-8444-444444444444',
        ]);
    }

    /**
     * @param  array<string, mixed>  $answerJson
     */
    private function insertAnswerRow(string $attemptId, int $orgId, string $scaleCode, string $questionId, array $answerJson): void
    {
        DB::table('attempt_answer_rows')->insert([
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'question_id' => $questionId,
            'question_index' => 1,
            'question_type' => 'single_choice',
            'answer_json' => json_encode($answerJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'duration_ms' => 5000,
            'submitted_at' => now()->subMinutes(15),
            'created_at' => now()->subMinutes(15),
            'scale_uid' => '44444444-4444-4444-8444-444444444444',
        ]);
    }

    private function insertOrder(string $orderNo, string $attemptId, int $orgId, string $status, string $anonId, ?string $userId): string
    {
        $orderId = (string) Str::uuid();
        $row = [
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'sku' => 'MBTI_FULL_REPORT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 2990,
            'currency' => 'USD',
            'status' => $status,
            'provider' => 'stripe',
            'paid_at' => now()->subMinutes(9),
            'fulfilled_at' => now()->subMinutes(8),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(8),
            'entitlement_id' => 'ent_ops_chain',
            'meta_json' => json_encode(['claim_status' => 'claimed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = 2990;
        }
        if (Schema::hasColumn('orders', 'amount_refunded')) {
            $row['amount_refunded'] = 0;
        }
        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = 'MBTI_FULL_REPORT';
        }
        if (Schema::hasColumn('orders', 'provider_order_id')) {
            $row['provider_order_id'] = null;
        }
        if (Schema::hasColumn('orders', 'device_id')) {
            $row['device_id'] = null;
        }
        if (Schema::hasColumn('orders', 'request_id')) {
            $row['request_id'] = null;
        }
        if (Schema::hasColumn('orders', 'created_ip')) {
            $row['created_ip'] = null;
        }
        if (Schema::hasColumn('orders', 'refunded_at')) {
            $row['refunded_at'] = null;
        }
        if (Schema::hasColumn('orders', 'external_trade_no')) {
            $row['external_trade_no'] = null;
        }
        if (Schema::hasColumn('orders', 'requested_sku')) {
            $row['requested_sku'] = 'MBTI_FULL_REPORT';
        }
        if (Schema::hasColumn('orders', 'effective_sku')) {
            $row['effective_sku'] = 'MBTI_FULL_REPORT';
        }
        if (Schema::hasColumn('orders', 'idempotency_key')) {
            $row['idempotency_key'] = 'idem_'.$orderNo;
        }

        DB::table('orders')->insert($row);

        return $orderId;
    }

    private function insertPaymentEvent(string $orderId, string $orderNo, int $orgId, string $status, string $eventType): string
    {
        $paymentEventId = (string) Str::uuid();

        DB::table('payment_events')->insert([
            'id' => $paymentEventId,
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.Str::lower(Str::random(8)),
            'order_id' => $orderId,
            'event_type' => $eventType,
            'payload_json' => '{}',
            'signature_ok' => 1,
            'handled_at' => now()->subMinutes(9),
            'handle_status' => 'processed',
            'request_id' => null,
            'ip' => null,
            'headers_digest' => null,
            'created_at' => now()->subMinutes(9),
            'updated_at' => now()->subMinutes(9),
            'order_no' => $orderNo,
            'received_at' => now()->subMinutes(9),
            'requested_sku' => 'MBTI_FULL_REPORT',
            'effective_sku' => 'MBTI_FULL_REPORT',
            'entitlement_id' => 'ent_ops_chain',
            'status' => $status,
            'processed_at' => now()->subMinutes(9),
            'attempts' => 1,
            'last_error_code' => null,
            'last_error_message' => null,
            'reason' => null,
            'payload_size_bytes' => 128,
            'payload_sha256' => hash('sha256', 'payload-'.$orderNo),
            'payload_s3_key' => null,
            'payload_excerpt' => null,
            'org_id' => $orgId,
            'scale_uid' => '33333333-3333-4333-8333-333333333333',
        ]);

        return $paymentEventId;
    }

    private function insertBenefitGrant(string $attemptId, string $orderId, string $paymentEventId, string $orderNo, int $orgId, string $status): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => '1001',
            'benefit_type' => 'report',
            'benefit_ref' => 'benefit_ref_'.$orderNo,
            'source_order_id' => $orderId,
            'source_event_id' => $paymentEventId,
            'status' => $status,
            'expires_at' => now()->addDays(30),
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(8),
            'org_id' => $orgId,
            'benefit_code' => 'MBTI_FULL_REPORT',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'revoked_at' => null,
            'order_no' => $orderNo,
            'meta_json' => json_encode(['claim_status' => 'claimed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
