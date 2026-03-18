<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SCALE_REGISTRY_TABLES = [
        'scales_registry',
        'scales_registry_v2',
    ];

    private const MBTI_EFFECTIVE_SKU = 'MBTI_REPORT_FULL_199';

    private const MBTI_ANCHOR_SKU = 'MBTI_REPORT_FULL';

    private const LEGACY_PARTIAL_SKUS = [
        'MBTI_CAREER_99',
        'MBTI_RELATIONSHIP_99',
    ];

    public function up(): void
    {
        $this->deactivateLegacyMbtiPartials();
        $this->syncMbtiScaleRegistryCommerce();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to avoid reopening deprecated partial SKUs.
    }

    private function deactivateLegacyMbtiPartials(): void
    {
        if (! Schema::hasTable('skus')) {
            return;
        }

        $rows = DB::table('skus')
            ->whereIn('sku', self::LEGACY_PARTIAL_SKUS)
            ->get(['sku', 'meta_json']);

        foreach ($rows as $row) {
            $meta = $this->decodeJson($row->meta_json ?? null);
            $meta['deprecated'] = true;
            $meta['offer'] = false;

            DB::table('skus')
                ->where('sku', (string) ($row->sku ?? ''))
                ->update([
                    'is_active' => false,
                    'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    private function syncMbtiScaleRegistryCommerce(): void
    {
        foreach (self::SCALE_REGISTRY_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->where('code', 'MBTI')
                ->get(['org_id', 'code', 'commercial_json', 'view_policy_json']);

            foreach ($rows as $row) {
                $commercial = $this->decodeJson($row->commercial_json ?? null);
                $viewPolicy = $this->decodeJson($row->view_policy_json ?? null);

                $commercial['report_unlock_sku'] = self::MBTI_EFFECTIVE_SKU;
                $commercial['upgrade_sku_anchor'] = self::MBTI_ANCHOR_SKU;
                $commercial['offers'] = array_values(array_filter(
                    is_array($commercial['offers'] ?? null) ? $commercial['offers'] : [],
                    fn (mixed $offer): bool => is_array($offer) && $this->isMbtiFullOffer($offer)
                ));

                $viewPolicy['upgrade_sku'] = self::MBTI_EFFECTIVE_SKU;

                DB::table($table)
                    ->where('org_id', (int) ($row->org_id ?? 0))
                    ->where('code', (string) ($row->code ?? ''))
                    ->update([
                        'commercial_json' => json_encode($commercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'view_policy_json' => json_encode($viewPolicy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $offer
     */
    private function isMbtiFullOffer(array $offer): bool
    {
        $sku = strtoupper(trim((string) ($offer['sku'] ?? $offer['sku_code'] ?? '')));
        $benefitCode = strtoupper(trim((string) ($offer['benefit_code'] ?? '')));

        return $sku === self::MBTI_EFFECTIVE_SKU
            || $sku === self::MBTI_ANCHOR_SKU
            || $benefitCode === self::MBTI_ANCHOR_SKU;
    }
};
