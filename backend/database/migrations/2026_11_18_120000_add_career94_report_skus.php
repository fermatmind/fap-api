<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['CNY', 'USD'] as $currency) {
            DB::table('skus')->insertOrIgnore([
                'org_id' => 0,
                'sku' => 'CAREER94_REPORT_'.$currency.'_299',
                'scale_code' => 'CAREER94',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'CAREER94_REPORT',
                'scope' => 'attempt',
                'price_cents' => 299,
                'currency' => $currency,
                // The 94-question checkout explicitly resolves these rows. Older releases cannot sell them.
                'is_active' => false,
                'meta_json' => json_encode(['entitlement_id' => 'career94_report'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Retain prices referenced by historical orders.
    }
};
