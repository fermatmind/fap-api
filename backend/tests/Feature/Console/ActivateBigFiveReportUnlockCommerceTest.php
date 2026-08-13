<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ActivateBigFiveReportUnlockCommerceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_emits_exact_token_and_execute_changes_only_product_registry(): void
    {
        (new ScaleRegistrySeeder)->run();
        DB::table('skus')->insert([
            'org_id' => 0,
            'sku' => 'SKU_BIG5_FULL_REPORT_299',
            'scale_code' => 'BIG5_OCEAN',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'BIG5_FULL_REPORT',
            'scope' => 'attempt',
            'price_cents' => 299,
            'currency' => 'CNY',
            'is_active' => true,
            'meta_json' => json_encode(['effective_default' => true], JSON_THROW_ON_ERROR),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->assertSame(0, Artisan::call('report-unlock:activate-big5-commerce'));
        $dryRun = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue((bool) ($dryRun['dry_run'] ?? false));
        $this->assertFalse((bool) ($dryRun['rollout_changed'] ?? true));
        $this->assertSame('SKU_BIG5_FULL_REPORT_199', $dryRun['sku'] ?? null);
        $this->assertSame(199, $dryRun['price_cents'] ?? null);

        $this->assertSame(1, Artisan::call('report-unlock:activate-big5-commerce', [
            '--execute-token' => 'wrong-token',
        ]));

        $this->assertSame(0, Artisan::call('report-unlock:activate-big5-commerce', [
            '--execute-token' => (string) $dryRun['execute_token'],
        ]));
        $sku = DB::table('skus')->where('sku', 'SKU_BIG5_FULL_REPORT_199')->first();
        $this->assertNotNull($sku);
        $this->assertSame(199, (int) $sku->price_cents);
        $this->assertSame('BIG5_FULL_REPORT', (string) $sku->benefit_code);
        $historical = DB::table('skus')->where('sku', 'SKU_BIG5_FULL_REPORT_299')->first();
        $historicalMeta = json_decode((string) $historical->meta_json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) ($historicalMeta['effective_default'] ?? true));
        $this->assertTrue((bool) ($historicalMeta['historical_only'] ?? false));

        $scale = DB::table('scales_registry')->where('org_id', 0)->where('code', 'BIG5_OCEAN')->first();
        $capabilities = json_decode((string) $scale->capabilities_json, true, flags: JSON_THROW_ON_ERROR);
        $commercial = json_decode((string) $scale->commercial_json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('full', $capabilities['paywall_mode'] ?? null);
        $this->assertSame('SKU_BIG5_FULL_REPORT_199', $commercial['report_unlock_sku'] ?? null);
        $this->assertSame('disabled', config('report_unlock.big5_rollout.mode'));
    }
}
