<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ActivateBigFiveReportUnlockCommerce extends Command
{
    protected $signature = 'report-unlock:activate-big5-commerce
        {--execute-token= : Exact token emitted by dry-run}';

    protected $description = 'Dry-run by default; activate the Big Five ¥1.99 SKU and full paywall without changing rollout.';

    public function handle(): int
    {
        $evidence = [
            'scale_code' => 'BIG5_OCEAN',
            'sku' => 'SKU_BIG5_FULL_REPORT_199',
            'historical_skus' => ['SKU_BIG5_FULL_REPORT_499', 'SKU_BIG5_FULL_REPORT_299'],
            'benefit_code' => 'BIG5_FULL_REPORT',
            'price_cents' => 199,
            'currency' => 'CNY',
            'paywall_mode' => 'full',
            'rollout_changed' => false,
        ];
        $token = 'BIG5_REPORT_UNLOCK_COMMERCE_EXECUTE:'.hash('sha256', json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)).':NO_ROLLOUT';
        $provided = trim((string) $this->option('execute-token'));

        if ($provided === '') {
            $this->line(json_encode(array_merge($evidence, ['execute_token' => $token, 'dry_run' => true]), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        if (! hash_equals($token, $provided)) {
            $this->error('execute token mismatch; no writes performed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($evidence): void {
            $now = now();
            $skuValues = [
                'org_id' => 0,
                'sku' => $evidence['sku'],
                'scale_code' => $evidence['scale_code'],
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => $evidence['benefit_code'],
                'scope' => 'attempt',
                'price_cents' => $evidence['price_cents'],
                'currency' => $evidence['currency'],
                'is_active' => true,
                'meta_json' => json_encode([
                    'effective_default' => true,
                    'anchor_sku' => 'SKU_BIG5_FULL_REPORT',
                    'modules_included' => ['big5_full', 'big5_action_plan'],
                ], JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ];
            if (DB::table('skus')->where('sku', $evidence['sku'])->exists()) {
                DB::table('skus')->where('sku', $evidence['sku'])->update($skuValues);
            } else {
                DB::table('skus')->insert(array_merge($skuValues, ['created_at' => $now]));
            }

            foreach ($evidence['historical_skus'] as $historicalSku) {
                $historical = DB::table('skus')->where('sku', $historicalSku)->lockForUpdate()->first();
                if ($historical === null) {
                    continue;
                }
                $historicalMeta = $this->json($historical->meta_json ?? null);
                $historicalMeta['effective_default'] = false;
                $historicalMeta['historical_only'] = true;
                $historicalMeta['deprecated'] = true;
                $historicalMeta['offer'] = false;
                DB::table('skus')->where('sku', $historicalSku)->update([
                    'is_active' => false,
                    'meta_json' => json_encode($historicalMeta, JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
            }

            $scale = DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'BIG5_OCEAN')->lockForUpdate()->first();
            if ($scale === null) {
                throw new \RuntimeException('BIG5_OCEAN V2 scale registry missing.');
            }
            $payload = (array) $scale;
            unset($payload['id'], $payload['created_at'], $payload['updated_at']);
            foreach (['slugs_json', 'capabilities_json', 'view_policy_json', 'commercial_json', 'seo_schema_json', 'seo_i18n_json', 'content_i18n_json', 'report_summary_i18n_json'] as $column) {
                if (is_string($payload[$column] ?? null)) {
                    $payload[$column] = $this->json($payload[$column]);
                }
            }
            $payload['capabilities_json']['paywall_mode'] = 'full';
            $payload['commercial_json']['report_unlock_sku'] = $evidence['sku'];
            $payload['commercial_json']['report_benefit_code'] = $evidence['benefit_code'];
            app(ScaleRegistryWriter::class)->upsertScale($payload);
        });

        $this->info('Big Five commerce activated; rollout was not changed.');

        return self::SUCCESS;
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
}
