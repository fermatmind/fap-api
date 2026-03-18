<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\EntitlementManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiUpgradeLegacyPartialUnlocksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_upgrades_legacy_partial_orders_and_grants_without_duplication(): void
    {
        $attemptFromOrder = (string) Str::uuid();
        $attemptFromGrant = (string) Str::uuid();

        $this->insertLegacyOrder('ord_mbti_legacy_order', $attemptFromOrder, 'anon_mbti_legacy_order', 'MBTI_CAREER_99');

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $partialGrant = $entitlements->grantAttemptUnlock(
            0,
            null,
            'anon_mbti_legacy_grant',
            'MBTI_RELATIONSHIP',
            $attemptFromGrant,
            'ord_mbti_legacy_grant',
            'attempt',
            null,
            ['relationships']
        );
        $this->assertTrue((bool) ($partialGrant['ok'] ?? false));

        $this->artisan('commerce:mbti-upgrade-legacy-partials', [
            '--org_id' => 0,
            '--json' => 1,
        ])->expectsOutputToContain('"upgraded":2')
            ->assertExitCode(0);

        $fullGrants = DB::table('benefit_grants')
            ->where('org_id', 0)
            ->where('benefit_code', 'MBTI_REPORT_FULL')
            ->where('status', 'active')
            ->whereIn('attempt_id', [$attemptFromOrder, $attemptFromGrant])
            ->orderBy('attempt_id')
            ->get();

        $this->assertCount(2, $fullGrants);

        foreach ($fullGrants as $grant) {
            $meta = json_decode((string) ($grant->meta_json ?? '[]'), true);
            $meta = is_array($meta) ? $meta : [];
            $modules = is_array($meta['modules'] ?? null) ? $meta['modules'] : [];

            $this->assertContains('core_full', $modules);
            $this->assertContains('career', $modules);
            $this->assertContains('relationships', $modules);
        }

        $this->artisan('commerce:mbti-upgrade-legacy-partials', [
            '--org_id' => 0,
            '--json' => 1,
        ])->expectsOutputToContain('"upgraded":0')
            ->assertExitCode(0);

        $this->assertSame(
            2,
            DB::table('benefit_grants')
                ->where('org_id', 0)
                ->where('benefit_code', 'MBTI_REPORT_FULL')
                ->where('status', 'active')
                ->whereIn('attempt_id', [$attemptFromOrder, $attemptFromGrant])
                ->count()
        );
    }

    private function insertLegacyOrder(string $orderNo, string $attemptId, string $anonId, string $sku): void
    {
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => $sku,
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 99,
            'currency' => 'CNY',
            'status' => 'paid',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = $sku;
        }
        if (Schema::hasColumn('orders', 'requested_sku')) {
            $row['requested_sku'] = $sku;
        }
        if (Schema::hasColumn('orders', 'effective_sku')) {
            $row['effective_sku'] = $sku;
        }
        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = 99;
        }
        if (Schema::hasColumn('orders', 'amount_refunded')) {
            $row['amount_refunded'] = 0;
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
        if (Schema::hasColumn('orders', 'fulfilled_at')) {
            $row['fulfilled_at'] = null;
        }
        if (Schema::hasColumn('orders', 'refunded_at')) {
            $row['refunded_at'] = null;
        }
        if (Schema::hasColumn('orders', 'refund_amount_cents')) {
            $row['refund_amount_cents'] = null;
        }
        if (Schema::hasColumn('orders', 'refund_reason')) {
            $row['refund_reason'] = null;
        }

        DB::table('orders')->insert($row);
    }
}
