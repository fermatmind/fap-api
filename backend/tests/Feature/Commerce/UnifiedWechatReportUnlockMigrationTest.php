<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedWechatReportUnlockMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_updates_an_existing_global_sku_from_a_legacy_nonzero_org(): void
    {
        DB::table('skus')->where('sku', 'MBTI_REPORT_FULL_199')->update([
            'org_id' => 37,
            'price_cents' => 499,
            'is_active' => false,
        ]);

        $migration = require database_path('migrations/2026_08_13_120000_unify_wechat_report_unlock_to_199.php');
        $migration->up();

        $this->assertSame(1, DB::table('skus')->where('sku', 'MBTI_REPORT_FULL_199')->count());
        $this->assertDatabaseHas('skus', [
            'sku' => 'MBTI_REPORT_FULL_199',
            'org_id' => 0,
            'price_cents' => 199,
            'currency' => 'CNY',
            'is_active' => true,
        ]);
    }
}
