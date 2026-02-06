<?php

namespace Database\Seeders;

use App\Services\Commerce\SkuCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Pr19CommerceSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('skus')) {
            $this->command?->warn('Pr19CommerceSeeder skipped: skus table missing.');
            return;
        }

        $rows = $this->loadSkuSeedData();
        if (count($rows) === 0) {
            $this->command?->warn('Pr19CommerceSeeder skipped: seed data missing.');
            return;
        }

        $now = now();

        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['metadata_json'] ?? [];
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : [];
            }
            $meta = is_array($meta) ? $meta : [];

            $anchorSku = strtoupper(trim((string) ($item['anchor_sku'] ?? '')));
            if ($anchorSku !== '') {
                $meta['anchor_sku'] = $anchorSku;
            }

            $title = (string) ($item['title'] ?? '');
            if ($title !== '' && empty($meta['title'])) {
                $meta['title'] = $title;
            }

            $payload = [
                'sku' => $sku,
                'scale_code' => strtoupper(trim((string) ($item['scale_code'] ?? 'MBTI'))),
                'kind' => (string) ($item['benefit_type'] ?? ''),
                'unit_qty' => (int) ($item['benefit_qty'] ?? 1),
                'benefit_code' => strtoupper(trim((string) ($item['benefit_code'] ?? ''))),
                'scope' => (string) ($item['scope'] ?? ''),
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'USD'),
                'is_active' => (bool) ($item['is_active'] ?? true),
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            DB::table('skus')->updateOrInsert(['sku' => $sku], $payload);
        }

        if (Schema::hasTable('scales_registry')) {
            $mbti = DB::table('scales_registry')->where('org_id', 0)->where('code', 'MBTI')->first();
            if ($mbti) {
                $commercial = $mbti->commercial_json ?? null;
                if (is_string($commercial)) {
                    $decoded = json_decode($commercial, true);
                    $commercial = is_array($decoded) ? $decoded : null;
                }
                if (!is_array($commercial)) {
                    $commercial = [];
                }

                $catalog = app(SkuCatalog::class);
                $offers = $this->buildOffersFromSkus($catalog->listActiveSkus('MBTI'));
                $defaultEffective = $catalog->defaultEffectiveSku('MBTI');
                $defaultAnchor = $catalog->defaultAnchorSku('MBTI');

                $commercial['report_benefit_code'] = 'MBTI_REPORT_FULL';
                $commercial['credit_benefit_code'] = 'MBTI_CREDIT';
                if ($defaultEffective) {
                    $commercial['report_unlock_sku'] = $defaultEffective;
                }
                if ($defaultAnchor) {
                    $commercial['upgrade_sku_anchor'] = $defaultAnchor;
                }
                if (count($offers) > 0) {
                    $commercial['offers'] = $offers;
                }

                $payload = json_encode($commercial, JSON_UNESCAPED_UNICODE);

                DB::table('scales_registry')
                    ->where('org_id', 0)
                    ->where('code', 'MBTI')
                    ->update([
                        'commercial_json' => $payload,
                        'updated_at' => $now,
                    ]);
            }
        }

        $this->command?->info('Pr19CommerceSeeder completed.');
    }

    private function loadSkuSeedData(): array
    {
        $path = database_path('seed_data/skus_mbti.json');
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildOffersFromSkus(array $items): array
    {
        $offers = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['meta_json'] ?? [];
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : [];
            }
            $meta = is_array($meta) ? $meta : [];

            if (array_key_exists('offer', $meta) && $meta['offer'] === false) {
                continue;
            }

            $grantType = trim((string) ($meta['grant_type'] ?? ''));
            if ($grantType === '') {
                $grantType = strtolower(trim((string) ($item['kind'] ?? '')));
            }

            $grantQty = isset($meta['grant_qty']) ? (int) $meta['grant_qty'] : 1;
            $periodDays = isset($meta['period_days']) ? (int) $meta['period_days'] : null;

            $entitlementId = trim((string) ($meta['entitlement_id'] ?? ''));

            $offers[] = [
                'sku' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($meta['title'] ?? $meta['label'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'grant' => [
                    'type' => $grantType !== '' ? $grantType : null,
                    'qty' => $grantQty,
                    'period_days' => $periodDays,
                ],
            ];
        }

        return $offers;
    }
}
