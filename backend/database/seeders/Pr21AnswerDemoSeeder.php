<?php

namespace Database\Seeders;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class Pr21AnswerDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('scales_registry') || !Schema::hasTable('scale_slugs')) {
            $this->command?->warn('Pr21AnswerDemoSeeder skipped: missing tables.');
            return;
        }

        $writer = app(ScaleRegistryWriter::class);
        $defaultPackId = (string) config('content_packs.demo_pack_id', '');
        $skuDefaults = $this->resolveSkuDefaults();

        $scale = $writer->upsertScale([
            'code' => 'DEMO_ANSWERS',
            'org_id' => 0,
            'primary_slug' => 'demo-answers',
            'slugs_json' => [
                'demo-answers',
            ],
            'driver_type' => 'simple_score',
            'default_pack_id' => $defaultPackId,
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => 'DEMO-ANSWERS-CN-v0.3.0-DEMO',
            'capabilities_json' => [
                'assets' => true,
            ],
            'view_policy_json' => [
                'free_sections' => ['intro', 'score'],
                'blur_others' => true,
                'teaser_percent' => 0.3,
                'upgrade_sku' => $skuDefaults['effective_sku'] ?? null,
            ],
            'commercial_json' => [
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
                'report_unlock_sku' => $skuDefaults['effective_sku'] ?? null,
                'upgrade_sku_anchor' => $skuDefaults['anchor_sku'] ?? null,
                'offers' => $skuDefaults['offers'] ?? [],
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $writer->syncSlugsForScale($scale);
        $this->command?->info('Pr21AnswerDemoSeeder: DEMO_ANSWERS scale upserted.');
    }

    private function resolveSkuDefaults(): array
    {
        $rows = $this->loadSkuSeedData();
        if (count($rows) === 0) {
            return [
                'effective_sku' => null,
                'anchor_sku' => null,
                'offers' => [],
            ];
        }

        $anchorSku = null;
        $effectiveSku = null;

        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['metadata_json'] ?? [];
            $meta = is_array($meta) ? $meta : [];

            if ($anchorSku === null && !empty($meta['anchor'])) {
                $anchorSku = $sku;
            }

            if ($effectiveSku === null && (!empty($meta['effective_default']) || !empty($meta['default']))) {
                $effectiveSku = $sku;
            }
        }

        $offers = $this->buildOffersFromSeed($rows);

        return [
            'effective_sku' => $effectiveSku,
            'anchor_sku' => $anchorSku,
            'offers' => $offers,
        ];
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

    private function buildOffersFromSeed(array $rows): array
    {
        $offers = [];
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            if ($sku === '') {
                continue;
            }

            $meta = $item['metadata_json'] ?? [];
            $meta = is_array($meta) ? $meta : [];
            if (!empty($meta['anchor']) || !empty($meta['deprecated'])) {
                continue;
            }
            if (array_key_exists('offer', $meta) && $meta['offer'] === false) {
                continue;
            }

            $grantType = trim((string) ($meta['grant_type'] ?? ''));
            if ($grantType === '') {
                $grantType = strtolower(trim((string) ($item['benefit_type'] ?? '')));
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
