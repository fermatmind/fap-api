<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skus')) {
            return;
        }
        $this->upsert('WEAPP_MEMBERSHIP_ANNUAL_999', 'MEMBERSHIP_ANNUAL', 999, 365, 'annual');
        $this->upsert('WEAPP_MEMBERSHIP_LIFETIME_1999', 'MEMBERSHIP_LIFETIME', 1999, 0, 'lifetime');
        $this->upsert('WEAPP_MEMBERSHIP_LIFETIME_UPGRADE_999', 'MEMBERSHIP_UPGRADE', 999, 0, 'lifetime');
    }

    public function down(): void {}

    private function upsert(string $sku, string $scale, int $price, int $durationDays, string $plan): void
    {
        $now = now();
        $values = [
            'org_id' => 0, 'scale_code' => $scale, 'kind' => 'report_unlock', 'unit_qty' => 1,
            'benefit_code' => 'FERMAT_MEMBER', 'scope' => 'org', 'price_cents' => $price,
            'currency' => 'CNY', 'is_active' => true,
            'meta_json' => json_encode(['offer' => true, 'grant_type' => 'membership', 'membership_plan' => $plan, 'duration_days' => $durationDays, 'modules_included' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        $query = DB::table('skus')->where('org_id', 0)->where('sku', $sku);
        $query->exists() ? $query->update($values) : DB::table('skus')->insert(array_merge(['sku' => $sku, 'created_at' => $now], $values));
    }
};
