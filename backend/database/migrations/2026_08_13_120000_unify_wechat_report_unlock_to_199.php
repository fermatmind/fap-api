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

        DB::transaction(function (): void {
            $this->upsertSku(
                'MBTI_REPORT_FULL_199',
                'MBTI',
                'MBTI_REPORT_FULL',
                ['core_full', 'career', 'relationships'],
                'MBTI_REPORT_FULL'
            );
            $this->upsertSku(
                'SKU_BIG5_FULL_REPORT_199',
                'BIG5_OCEAN',
                'BIG5_FULL_REPORT',
                ['big5_full', 'big5_action_plan'],
                'SKU_BIG5_FULL_REPORT'
            );
            $this->upsertSku(
                'WEAPP_LOCAL_REPORT_FULL_199',
                'LOCAL_REPORT',
                'WEAPP_LOCAL_REPORT_FULL',
                [],
                null
            );

            $this->retireHistoricalSku('SKU_BIG5_FULL_REPORT_499');
            $this->retireHistoricalSku('SKU_BIG5_FULL_REPORT_299');
            $this->updateAnchor('MBTI_REPORT_FULL', 'MBTI_REPORT_FULL_199');
            $this->updateAnchor('SKU_BIG5_FULL_REPORT', 'SKU_BIG5_FULL_REPORT_199');
            $this->updateScaleCommerce('MBTI', 'MBTI_REPORT_FULL_199');
            $this->updateScaleCommerce('BIG5_OCEAN', 'SKU_BIG5_FULL_REPORT_199');
        });
    }

    public function down(): void
    {
        // Forward-only commercial change. Historical orders retain their captured amounts and SKUs.
    }

    /** @param list<string> $modules */
    private function upsertSku(
        string $sku,
        string $scaleCode,
        string $benefitCode,
        array $modules,
        ?string $anchorSku
    ): void {
        $existing = DB::table('skus')->where('sku', $sku)->first();
        $meta = $this->json($existing->meta_json ?? null);
        unset($meta['deprecated'], $meta['historical_only']);
        $meta['effective_default'] = true;
        $meta['offer'] = true;
        $meta['grant_type'] = 'report_unlock';
        $meta['grant_qty'] = 1;
        $meta['entitlement_id'] = 'report_full';
        $meta['modules_included'] = $modules;
        if ($anchorSku !== null) {
            $meta['anchor_sku'] = $anchorSku;
        }

        $values = [
            'org_id' => 0,
            'sku' => $sku,
            'scale_code' => $scaleCode,
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => $benefitCode,
            'scope' => 'attempt',
            'price_cents' => 199,
            'currency' => 'CNY',
            'is_active' => true,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            DB::table('skus')->where('sku', $sku)->update($values);

            return;
        }

        DB::table('skus')->insert(array_merge($values, ['created_at' => now()]));
    }

    private function retireHistoricalSku(string $sku): void
    {
        $row = DB::table('skus')->where('sku', $sku)->first();
        if ($row === null) {
            return;
        }

        $meta = $this->json($row->meta_json ?? null);
        $meta['effective_default'] = false;
        $meta['offer'] = false;
        $meta['deprecated'] = true;
        $meta['historical_only'] = true;
        DB::table('skus')->where('sku', $sku)->update([
            'is_active' => false,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function updateAnchor(string $anchorSku, string $effectiveSku): void
    {
        $row = DB::table('skus')->where('sku', $anchorSku)->first();
        if ($row === null) {
            return;
        }

        $meta = $this->json($row->meta_json ?? null);
        $meta['anchor'] = true;
        $meta['effective_sku'] = $effectiveSku;
        $meta['offer'] = false;
        DB::table('skus')->where('sku', $anchorSku)->update([
            'price_cents' => 199,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function updateScaleCommerce(string $scaleCode, string $sku): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->where('code', $scaleCode)->get();
            foreach ($rows as $row) {
                $capabilities = $this->json($row->capabilities_json ?? null);
                $commercial = $this->json($row->commercial_json ?? null);
                $viewPolicy = $this->json($row->view_policy_json ?? null);
                $capabilities['paywall_mode'] = 'full';
                $commercial['report_unlock_sku'] = $sku;
                $viewPolicy['upgrade_sku'] = $sku;
                $viewPolicy['blur_others'] = true;
                DB::table($table)
                    ->where('org_id', (int) ($row->org_id ?? 0))
                    ->where('code', $scaleCode)
                    ->update([
                        'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'commercial_json' => json_encode($commercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'view_policy_json' => json_encode($viewPolicy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
};
