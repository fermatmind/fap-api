<?php

namespace Database\Seeders;

use App\Services\Commerce\CommerceConfigValidator;
use App\Services\Commerce\SkuCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Pr19CommerceSeeder extends Seeder
{
    private const FREE_ONLY_SCALE_CODES = [
        'ENNEAGRAM',
        'RIASEC',
        'IQ_RAVEN',
        'EQ_60',
    ];

    public function run(): void
    {
        if (! Schema::hasTable('skus')) {
            $this->command?->warn('Pr19CommerceSeeder skipped: skus table missing.');

            return;
        }

        $rows = $this->loadSkuSeedData();
        if (count($rows) === 0) {
            $this->command?->warn('Pr19CommerceSeeder skipped: seed data missing.');

            return;
        }
        /** @var CommerceConfigValidator $validator */
        $validator = app(CommerceConfigValidator::class);
        $validator->validate($rows);

        $now = now();

        foreach ($rows as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = strtoupper(trim((string) ($item['sku'] ?? ($item['sku_code'] ?? ''))));
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

            $offerCode = trim((string) ($item['offer_code'] ?? ($meta['offer_code'] ?? '')));
            if ($offerCode !== '' && empty($meta['offer_code'])) {
                $meta['offer_code'] = $offerCode;
            }

            if (array_key_exists('modules_included', $item) || array_key_exists('modules_included', $meta)) {
                $meta['modules_included'] = $this->normalizeModulesIncluded(
                    $item['modules_included'] ?? ($meta['modules_included'] ?? null)
                );
            }

            $payload = [
                'sku' => $sku,
                'scale_code' => strtoupper(trim((string) ($item['scale_code'] ?? 'MBTI'))),
                'kind' => (string) ($item['benefit_type'] ?? ''),
                'unit_qty' => (int) ($item['benefit_qty'] ?? 1),
                'benefit_code' => strtoupper(trim((string) ($item['benefit_code'] ?? ''))),
                'scope' => (string) ($item['scope'] ?? ''),
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => strtoupper(trim((string) ($item['currency'] ?? 'USD'))),
                'is_active' => (bool) ($item['is_active'] ?? true),
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            DB::table('skus')->updateOrInsert(['sku' => $sku], $payload);
        }

        $this->syncRegistryCommerce($now);

        $this->command?->info('Pr19CommerceSeeder completed.');
    }

    private function loadSkuSeedData(): array
    {
        $paths = [
            database_path('seed_data/skus_mbti.json'),
            database_path('seed_data/skus_big5_ocean.json'),
            database_path('seed_data/skus_clinical_combo_68_pro.json'),
            database_path('seed_data/skus_sds_20.json'),
            database_path('seed_data/skus_eq_60.json'),
        ];

        $rows = [];
        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $raw = file_get_contents($path);
            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private function syncRegistryCommerce(mixed $now): void
    {
        $catalog = app(SkuCatalog::class);
        $paidTargets = [
            'MBTI' => [
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_REPORT_FULL',
            ],
            'BIG5_OCEAN' => [
                'report_benefit_code' => 'BIG5_FULL_REPORT',
                'credit_benefit_code' => 'BIG5_FULL_REPORT',
            ],
            'CLINICAL_COMBO_68' => [
                'report_benefit_code' => 'CLINICAL_COMBO_68_PRO',
                'credit_benefit_code' => 'CLINICAL_COMBO_68_PRO',
            ],
            'SDS_20' => [
                'report_benefit_code' => 'SDS_20_FULL',
                'credit_benefit_code' => 'SDS_20_FULL',
            ],
        ];

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (self::FREE_ONLY_SCALE_CODES as $scaleCode) {
                DB::table($table)
                    ->where('org_id', 0)
                    ->where('code', $scaleCode)
                    ->update([
                        'commercial_json' => json_encode(
                            $this->freeCommercialContract(),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        'updated_at' => $now,
                    ]);
            }

            foreach ($paidTargets as $scaleCode => $benefits) {
                $scale = DB::table($table)->where('org_id', 0)->where('code', $scaleCode)->first();
                if (! $scale) {
                    continue;
                }

                $commercial = $scale->commercial_json ?? null;
                if (is_string($commercial)) {
                    $decoded = json_decode($commercial, true);
                    $commercial = is_array($decoded) ? $decoded : null;
                }
                if (! is_array($commercial)) {
                    $commercial = [];
                }
                $capabilities = $this->json($scale->capabilities_json ?? null);
                $viewPolicy = $this->json($scale->view_policy_json ?? null);

                $reportSkus = array_values(array_filter(
                    $catalog->listActiveSkus($scaleCode, 0),
                    static fn (array $item): bool => ($item['kind'] ?? null) === 'report_unlock'
                        && ($item['scope'] ?? null) === 'attempt',
                ));
                $offers = $this->buildOffersFromSkus($reportSkus);
                $defaultReport = collect($reportSkus)->first(
                    static fn (array $item): bool => ! empty($item['meta_json']['effective_default'])
                        || ! empty($item['meta_json']['default']),
                    $reportSkus[0] ?? null,
                );
                $defaultEffective = $defaultReport['sku'] ?? null;
                $defaultAnchor = $defaultEffective === null ? null : $catalog->anchorForSku($defaultEffective, $scaleCode, 0);

                $commercial['report_benefit_code'] = $benefits['report_benefit_code'];
                $commercial['credit_benefit_code'] = $benefits['credit_benefit_code'];
                if ($defaultEffective) {
                    $commercial['report_unlock_sku'] = $defaultEffective;
                    $commercial['upgrade_sku'] = $defaultEffective;
                    $viewPolicy['upgrade_sku'] = $defaultEffective;
                }
                if ($defaultAnchor) {
                    $commercial['upgrade_sku_anchor'] = $defaultAnchor;
                }
                if (count($offers) > 0) {
                    $commercial['offers'] = $offers;
                }
                $capabilities['paywall_mode'] = 'full';
                $viewPolicy['blur_others'] = true;

                DB::table($table)
                    ->where('org_id', 0)
                    ->where('code', $scaleCode)
                    ->update([
                        'commercial_json' => json_encode($commercial, JSON_UNESCAPED_UNICODE),
                        'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE),
                        'view_policy_json' => json_encode($viewPolicy, JSON_UNESCAPED_UNICODE),
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function freeCommercialContract(): array
    {
        return [
            'price_tier' => 'FREE',
            'report_benefit_code' => null,
            'credit_benefit_code' => null,
            'report_unlock_sku' => null,
            'upgrade_sku' => null,
            'upgrade_sku_anchor' => null,
            'offers' => [],
        ];
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

    private function buildOffersFromSkus(array $items): array
    {
        $offers = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
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
            $offerCode = trim((string) ($meta['offer_code'] ?? ''));
            $benefitCode = strtoupper(trim((string) ($item['benefit_code'] ?? '')));
            $modulesIncluded = $this->normalizeModulesIncluded($meta['modules_included'] ?? null);

            $offers[] = [
                'sku' => $sku,
                'sku_code' => $sku,
                'price_cents' => (int) ($item['price_cents'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'CNY'),
                'title' => (string) ($meta['title'] ?? $meta['label'] ?? ''),
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'benefit_code' => $benefitCode !== '' ? $benefitCode : null,
                'offer_code' => $offerCode !== '' ? $offerCode : null,
                'modules_included' => $modulesIncluded,
                'grant' => [
                    'type' => $grantType !== '' ? $grantType : null,
                    'qty' => $grantQty,
                    'period_days' => $periodDays,
                ],
            ];
        }

        return $offers;
    }

    /**
     * @return list<string>
     */
    private function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $module) {
            $module = strtolower(trim((string) $module));
            if ($module === '') {
                continue;
            }
            $out[$module] = true;
        }

        return array_keys($out);
    }
}
