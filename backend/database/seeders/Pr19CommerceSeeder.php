<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Pr19CommerceSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('skus')) {
            $this->command?->warn('Pr19CommerceSeeder skipped: skus table missing.');
            return;
        }

        $now = now();

        $skus = [
            // legacy skus
            [
                'sku' => 'MBTI_REPORT_FULL',
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'MBTI_REPORT_FULL',
                'scope' => 'attempt',
                'price_cents' => 990,
                'currency' => 'USD',
                'is_active' => true,
                'meta_json' => [
                    'label' => 'MBTI full report',
                    'legacy' => true,
                ],
            ],
            [
                'sku' => 'MBTI_EXTRA_TIPS',
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'MBTI_REPORT_FULL',
                'scope' => 'attempt',
                'price_cents' => 0,
                'currency' => 'USD',
                'is_active' => false,
                'meta_json' => [
                    'label' => 'MBTI extra tips (legacy)',
                    'legacy' => true,
                ],
            ],
            [
                'sku' => 'MBTI_CREDIT',
                'scale_code' => 'MBTI',
                'kind' => 'credit_pack',
                'unit_qty' => 100,
                'benefit_code' => 'MBTI_CREDIT',
                'scope' => 'org',
                'price_cents' => 4990,
                'currency' => 'USD',
                'is_active' => true,
                'meta_json' => [
                    'label' => 'MBTI credits pack',
                ],
            ],

            // v0.2.2 skus (CNY)
            [
                'sku' => 'MBTI_REPORT_FULL_199',
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'MBTI_REPORT_FULL',
                'scope' => 'attempt',
                'price_cents' => 199,
                'currency' => 'CNY',
                'is_active' => true,
                'meta_json' => [
                    'label' => 'MBTI full report (single)',
                ],
            ],
            [
                'sku' => 'MBTI_PRO_MONTH_599',
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'MBTI_REPORT_FULL',
                'scope' => 'org',
                'price_cents' => 599,
                'currency' => 'CNY',
                'is_active' => true,
                'meta_json' => [
                    'label' => 'MBTI pro month',
                    'duration_days' => 30,
                ],
            ],
            [
                'sku' => 'MBTI_PRO_YEAR_1999',
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'MBTI_REPORT_FULL',
                'scope' => 'org',
                'price_cents' => 1999,
                'currency' => 'CNY',
                'is_active' => true,
                'meta_json' => [
                    'label' => 'MBTI pro year',
                    'duration_days' => 365,
                ],
            ],
            [
                'sku' => 'MBTI_GIFT_BOGO_2990',
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 2,
                'benefit_code' => 'MBTI_REPORT_FULL',
                'scope' => 'attempt',
                'price_cents' => 2990,
                'currency' => 'CNY',
                'is_active' => true,
                'meta_json' => [
                    'label' => 'MBTI gift bogo',
                    'gift_units' => 2,
                ],
            ],
        ];

        foreach ($skus as $item) {
            $existing = DB::table('skus')->where('sku', $item['sku'])->first();
            $payload = array_merge($item, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (is_array($payload['meta_json'] ?? null)) {
                $payload['meta_json'] = json_encode($payload['meta_json'], JSON_UNESCAPED_UNICODE);
            }

            if ($existing) {
                DB::table('skus')->where('sku', $item['sku'])->update($payload);
            } else {
                DB::table('skus')->insert($payload);
            }
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

                $commercial['report_benefit_code'] = 'MBTI_REPORT_FULL';
                $commercial['credit_benefit_code'] = 'MBTI_CREDIT';
                $commercial['report_unlock_sku'] = 'MBTI_REPORT_FULL_199';
                $commercial['subscription_skus'] = [
                    'MBTI_PRO_MONTH_599',
                    'MBTI_PRO_YEAR_1999',
                ];
                $commercial['gift_sku'] = 'MBTI_GIFT_BOGO_2990';

                $payload = $commercial;
                if (is_array($payload)) {
                    $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                }

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
}
