<?php

declare(strict_types=1);

namespace Tests\Feature\Ops\Support;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait InteractsWithCommerceOpsWorkbench
{
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
     * @param  array<string, mixed>  $options
     * @return array{
     *     order:Order,
     *     order_id:string,
     *     order_no:string,
     *     attempt_id:string,
     *     payment_attempt_id:?string,
     *     payment_event_id:?string,
     *     benefit_grant_id:?string,
     *     share_id:string
     * }
     */
    private function seedCommerceOpsChain(int $orgId, ?string $orderNo = null, array $options = []): array
    {
        $attemptId = (string) Str::uuid();
        $shareId = (string) Str::uuid();
        $orderId = (string) Str::uuid();
        $paymentAttemptId = (bool) ($options['with_payment_attempt'] ?? true) ? (string) Str::uuid() : null;
        $paymentEventId = (bool) ($options['with_payment_event'] ?? true) ? (string) Str::uuid() : null;
        $benefitGrantId = (bool) ($options['with_grant'] ?? true) ? (string) Str::uuid() : null;
        $orderNo ??= 'ord_ops_'.Str::lower(Str::random(8));

        $createdAt = $options['created_at'] ?? now()->subHours(2);
        $updatedAt = $options['updated_at'] ?? now()->subMinutes(15);
        $paymentState = (string) ($options['payment_state'] ?? Order::PAYMENT_STATE_PAID);
        $grantState = (string) ($options['grant_state'] ?? Order::GRANT_STATE_GRANTED);
        $orderStatus = (string) ($options['status'] ?? Order::STATUS_PAID);
        $channel = (string) ($options['channel'] ?? 'web');
        $provider = (string) ($options['provider'] ?? 'stripe');
        $providerApp = (string) ($options['provider_app'] ?? 'web-primary');
        $payScene = (string) ($options['pay_scene'] ?? 'checkout');
        $paymentAttemptState = (string) ($options['payment_attempt_state'] ?? 'paid');
        $eventStatus = (string) ($options['payment_event_status'] ?? 'paid');
        $eventHandleStatus = (string) ($options['payment_event_handle_status'] ?? 'processed');
        $signatureOk = (int) ($options['signature_ok'] ?? 1);

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => $orgId,
            'user_id' => '1001',
            'anon_id' => 'anon_ops',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
            'region' => 'US',
            'locale' => 'en',
            'question_count' => 93,
            'answers_summary_json' => json_encode(['stage' => 'ops'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'channel' => $channel,
            'started_at' => now()->subMinutes(45),
            'submitted_at' => now()->subMinutes(35),
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'content_package_version' => 'content_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'norm_version' => 'norm_2026_03',
            'created_at' => now()->subMinutes(45),
            'updated_at' => now()->subMinutes(35),
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
            'result_json' => json_encode(['private' => 'hidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pack_id' => 'MBTI',
            'dir_version' => 'mbti_dir_2026_03',
            'content_package_version' => 'content_2026_03',
            'scoring_spec_version' => 'scoring_2026_03',
            'report_engine_version' => 'v2.3',
            'is_valid' => 1,
            'computed_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(29),
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
            'report_full_json' => json_encode(['secret' => 'hidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now()->subMinutes(25),
            'updated_at' => now()->subMinutes(24),
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
        ]);

        $orderRow = [
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => '1001',
            'anon_id' => 'anon_ops',
            'sku' => 'MBTI_FULL_REPORT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 2990,
            'currency' => 'USD',
            'status' => $orderStatus,
            'payment_state' => $paymentState,
            'grant_state' => $grantState,
            'provider' => $provider,
            'channel' => $channel,
            'provider_app' => $providerApp,
            'paid_at' => $options['paid_at'] ?? ($paymentState === Order::PAYMENT_STATE_PAID ? now()->subMinutes(20) : null),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'last_reconciled_at' => $options['last_reconciled_at'] ?? null,
        ];

        if (Schema::hasColumn('orders', 'amount_total')) {
            $orderRow['amount_total'] = 2990;
        }
        if (Schema::hasColumn('orders', 'amount_refunded')) {
            $orderRow['amount_refunded'] = (int) ($options['amount_refunded'] ?? 0);
        }
        if (Schema::hasColumn('orders', 'item_sku')) {
            $orderRow['item_sku'] = 'MBTI_FULL_REPORT';
        }
        if (Schema::hasColumn('orders', 'provider_order_id')) {
            $orderRow['provider_order_id'] = 'pi_'.$orderNo;
        }
        if (Schema::hasColumn('orders', 'requested_sku')) {
            $orderRow['requested_sku'] = 'MBTI_FULL_REPORT';
        }
        if (Schema::hasColumn('orders', 'effective_sku')) {
            $orderRow['effective_sku'] = 'MBTI_FULL_REPORT';
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
                        'source' => 'ops',
                        'medium' => 'organic',
                        'campaign' => 'workbench',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (Schema::hasColumn('orders', 'closed_at')) {
            $orderRow['closed_at'] = $options['closed_at'] ?? null;
        }
        if (Schema::hasColumn('orders', 'expired_at')) {
            $orderRow['expired_at'] = $options['expired_at'] ?? null;
        }
        if (Schema::hasColumn('orders', 'refunded_at')) {
            $orderRow['refunded_at'] = $options['refunded_at'] ?? null;
        }

        DB::table('orders')->insert($orderRow);

        if ($paymentAttemptId !== null && Schema::hasTable('payment_attempts')) {
            DB::table('payment_attempts')->insert([
                'id' => $paymentAttemptId,
                'org_id' => $orgId,
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'attempt_no' => 1,
                'provider' => $provider,
                'channel' => $channel,
                'provider_app' => $providerApp,
                'pay_scene' => $payScene,
                'state' => $paymentAttemptState,
                'external_trade_no' => 'ext_'.$orderNo,
                'provider_trade_no' => $options['provider_trade_no'] ?? 'pi_'.$orderNo,
                'provider_session_ref' => 'cs_'.$orderNo,
                'amount_expected' => 2990,
                'currency' => 'USD',
                'payload_meta_json' => json_encode(['source' => 'ops-test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'latest_payment_event_id' => null,
                'initiated_at' => now()->subMinutes(25),
                'provider_created_at' => now()->subMinutes(25),
                'client_presented_at' => now()->subMinutes(24),
                'callback_received_at' => $options['callback_received_at'] ?? ($paymentAttemptState !== 'initiated' ? now()->subMinutes(22) : null),
                'verified_at' => $options['verified_at'] ?? (in_array($paymentAttemptState, ['verified', 'paid'], true) ? now()->subMinutes(21) : null),
                'finalized_at' => $options['finalized_at'] ?? (in_array($paymentAttemptState, ['paid', 'failed', 'canceled', 'expired'], true) ? now()->subMinutes(20) : null),
                'last_error_code' => $options['last_error_code'] ?? null,
                'last_error_message' => $options['last_error_message'] ?? null,
                'meta_json' => null,
                'created_at' => now()->subMinutes(25),
                'updated_at' => now()->subMinutes(20),
            ]);
        }

        if ($paymentEventId !== null && Schema::hasTable('payment_events')) {
            DB::table('payment_events')->insert([
                'id' => $paymentEventId,
                'org_id' => $orgId,
                'provider' => $provider,
                'provider_event_id' => 'evt_'.Str::lower(Str::random(8)),
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'payment_attempt_id' => $paymentAttemptId,
                'event_type' => 'payment_intent.succeeded',
                'signature_ok' => $signatureOk,
                'status' => $eventStatus,
                'attempts' => 1,
                'last_error_code' => $options['event_error_code'] ?? null,
                'last_error_message' => $options['event_error_message'] ?? null,
                'processed_at' => now()->subMinutes(20),
                'handled_at' => now()->subMinutes(20),
                'handle_status' => $eventHandleStatus,
                'reason' => $options['event_reason'] ?? 'checkout_paid',
                'requested_sku' => 'MBTI_FULL_REPORT',
                'effective_sku' => 'MBTI_FULL_REPORT',
                'entitlement_id' => 'ent_'.$orderNo,
                'payload_json' => json_encode(['secret' => 'hidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payload_size_bytes' => 128,
                'payload_sha256' => hash('sha256', 'hidden'),
                'payload_s3_key' => null,
                'payload_excerpt' => null,
                'received_at' => now()->subMinutes(20),
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
                'scale_uid' => '55555555-5555-4555-8555-555555555555',
            ]);

            if ($paymentAttemptId !== null && Schema::hasTable('payment_attempts')) {
                DB::table('payment_attempts')->where('id', $paymentAttemptId)->update([
                    'latest_payment_event_id' => $paymentEventId,
                ]);
            }
        }

        if ($benefitGrantId !== null && Schema::hasTable('benefit_grants')) {
            DB::table('benefit_grants')->insert([
                'id' => $benefitGrantId,
                'org_id' => $orgId,
                'user_id' => '1001',
                'benefit_code' => 'MBTI_FULL_REPORT',
                'scope' => 'attempt',
                'attempt_id' => $attemptId,
                'status' => (string) ($options['grant_status'] ?? 'active'),
                'expires_at' => now()->addDays(30),
                'benefit_type' => 'report',
                'benefit_ref' => 'benefit_'.$orderNo,
                'order_no' => $orderNo,
                'source_order_id' => $orderId,
                'source_event_id' => $paymentEventId,
                'meta_json' => json_encode(['source' => 'ops-test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now()->subMinutes(18),
                'updated_at' => now()->subMinutes(18),
            ]);
        }

        if ((bool) ($options['with_access'] ?? true) && Schema::hasTable('unified_access_projections')) {
            DB::table('unified_access_projections')->insert([
                'attempt_id' => $attemptId,
                'access_state' => (string) ($options['access_state'] ?? 'granted'),
                'report_state' => (string) ($options['access_report_state'] ?? 'ready'),
                'pdf_state' => (string) ($options['access_pdf_state'] ?? 'ready'),
                'reason_code' => (string) ($options['access_reason_code'] ?? 'payment_granted'),
                'projection_version' => 1,
                'actions_json' => json_encode(['can_download_pdf' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payload_json' => json_encode(['source' => 'ops-test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'produced_at' => now()->subMinutes(17),
                'refreshed_at' => now()->subMinutes(16),
                'created_at' => now()->subMinutes(17),
                'updated_at' => now()->subMinutes(16),
            ]);
        }

        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => 'anon_ops',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'content_package_version' => 'content_2026_03',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(9),
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
        ]);

        DB::table('report_jobs')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'status' => 'succeeded',
            'tries' => 1,
            'available_at' => now()->subMinutes(28),
            'started_at' => now()->subMinutes(28),
            'finished_at' => now()->subMinutes(27),
            'failed_at' => null,
            'last_error' => null,
            'last_error_trace' => null,
            'report_json' => '{}',
            'meta' => '{}',
            'created_at' => now()->subMinutes(28),
            'updated_at' => now()->subMinutes(27),
            'org_id' => $orgId,
        ]);

        if (Schema::hasTable('email_outbox')) {
            $emailRow = [
                'id' => (string) Str::uuid(),
                'user_id' => '1001',
                'email' => 'redacted+'.substr(hash('sha256', $orderNo), 0, 20).'@privacy.local',
                'template' => 'payment_success',
                'payload_json' => json_encode(['order_no' => $orderNo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'claim_token_hash' => hash('sha256', 'claim_'.$orderNo),
                'claim_expires_at' => now()->addDay(),
                'status' => 'sent',
                'sent_at' => now()->subMinutes(5),
                'consumed_at' => null,
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ];

            if (Schema::hasColumn('email_outbox', 'attempt_id')) {
                $emailRow['attempt_id'] = $attemptId;
            }
            if (Schema::hasColumn('email_outbox', 'to_email')) {
                $emailRow['to_email'] = 'redacted+'.substr(hash('sha256', $orderNo), 0, 20).'@privacy.local';
            }
            if (Schema::hasColumn('email_outbox', 'locale')) {
                $emailRow['locale'] = 'en';
            }
            if (Schema::hasColumn('email_outbox', 'template_key')) {
                $emailRow['template_key'] = 'payment_success';
            }
            if (Schema::hasColumn('email_outbox', 'subject')) {
                $emailRow['subject'] = 'Payment success';
            }
            if (Schema::hasColumn('email_outbox', 'body_html')) {
                $emailRow['body_html'] = '<p>sent</p>';
            }

            DB::table('email_outbox')->insert($emailRow);
        }

        return [
            'order' => Order::query()->withoutGlobalScopes()->whereKey($orderId)->firstOrFail(),
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'attempt_id' => $attemptId,
            'payment_attempt_id' => $paymentAttemptId,
            'payment_event_id' => $paymentEventId,
            'benefit_grant_id' => $benefitGrantId,
            'share_id' => $shareId,
        ];
    }
}
