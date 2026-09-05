<?php

declare(strict_types=1);

namespace Tests\Feature\Scale;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssessmentCatalogProductTruthConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private const FREE_SCALE_CODES = [
        'MBTI',
        'BIG5_OCEAN',
        'ENNEAGRAM',
        'RIASEC',
        'IQ_RAVEN',
        'EQ_60',
    ];

    public function test_commerce_seed_preserves_free_scales_and_exact_current_paid_report_offers(): void
    {
        $this->artisan('fap:scales:seed-default')->assertExitCode(0);
        $this->artisan('db:seed', [
            '--class' => 'Database\\Seeders\\Pr19CommerceSeeder',
            '--force' => true,
        ])->assertExitCode(0);

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (self::FREE_SCALE_CODES as $scaleCode) {
                $commercial = $this->decodeJson(DB::table($table)
                    ->where('org_id', 0)
                    ->where('code', $scaleCode)
                    ->value('commercial_json'));

                if (in_array($scaleCode, ['MBTI', 'BIG5_OCEAN'], true)) {
                    $this->assertSame($scaleCode === 'MBTI' ? 'MBTI_REPORT_FULL_199' : 'SKU_BIG5_FULL_REPORT_199', $commercial['report_unlock_sku']);
                    $this->assertNotEmpty($commercial['offers']);
                    $this->assertSame($commercial['report_unlock_sku'], $commercial['upgrade_sku']);
                    $this->assertNotContains('MBTI_CREDIT', array_column($commercial['offers'], 'sku'));

                    continue;
                }

                $this->assertSame('FREE', $commercial['price_tier'] ?? null);
                $this->assertNull($commercial['report_unlock_sku'] ?? null);
                $this->assertNull($commercial['upgrade_sku'] ?? null);
                $this->assertNull($commercial['upgrade_sku_anchor'] ?? null);
                $this->assertSame([], $commercial['offers'] ?? null);
            }
        }

        $reportUnlocks = DB::table('skus')
            ->whereIn('scale_code', ['MBTI', 'BIG5_OCEAN', 'EQ_60'])
            ->where('kind', 'report_unlock')
            ->where('scope', 'attempt')
            ->get(['sku', 'price_cents', 'is_active', 'meta_json']);
        $this->assertNotEmpty($reportUnlocks);
        foreach ($reportUnlocks as $sku) {
            $metadata = $this->decodeJson($sku->meta_json ?? null);
            if (in_array($sku->sku, ['MBTI_REPORT_FULL_199', 'SKU_BIG5_FULL_REPORT_199'], true)) {
                $this->assertTrue((bool) $sku->is_active);
                $this->assertSame(199, (int) $sku->price_cents);
                $this->assertFalse((bool) ($metadata['historical_only'] ?? false));

                continue;
            }
            $this->assertFalse((bool) ($sku->is_active ?? true));
            $this->assertTrue((bool) ($metadata['deprecated'] ?? false));
            $this->assertTrue((bool) ($metadata['historical_only'] ?? false));
            $this->assertFalse((bool) ($metadata['offer'] ?? true));
        }

        $orgSkus = DB::table('skus')
            ->where('scale_code', 'MBTI')
            ->where('kind', 'credit_pack')
            ->where('scope', 'org')
            ->get(['is_active']);
        $this->assertCount(4, $orgSkus);
        foreach ($orgSkus as $sku) {
            $this->assertTrue((bool) ($sku->is_active ?? false));
        }
    }

    public function test_forward_only_migration_is_idempotent_and_preserves_tenant_and_org_skus(): void
    {
        $this->artisan('fap:scales:seed-default')->assertExitCode(0);

        $staleCapabilities = json_encode([
            'paywall_mode' => 'full',
            'forms' => ['legacy'],
            'default_form_code' => 'legacy',
        ], JSON_THROW_ON_ERROR);
        $staleViewPolicy = json_encode([
            'blur_others' => true,
            'teaser_percent' => 0.5,
            'upgrade_sku' => 'LEGACY_UPGRADE',
        ], JSON_THROW_ON_ERROR);
        $staleCommercial = json_encode([
            'price_tier' => 'PAID',
            'report_unlock_sku' => 'LEGACY_UNLOCK',
            'offers' => [['sku' => 'LEGACY_UNLOCK']],
        ], JSON_THROW_ON_ERROR);

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            DB::table($table)
                ->where('org_id', 0)
                ->whereIn('code', self::FREE_SCALE_CODES)
                ->update([
                    'capabilities_json' => $staleCapabilities,
                    'view_policy_json' => $staleViewPolicy,
                    'commercial_json' => $staleCommercial,
                ]);
        }

        $tenantRow = (array) DB::table('scales_registry_v2')
            ->where('org_id', 0)
            ->where('code', 'MBTI')
            ->first();
        unset($tenantRow['id']);
        $tenantRow['org_id'] = 42;
        $tenantRow['primary_slug'] = 'tenant-mbti-product';
        $tenantRow['capabilities_json'] = $staleCapabilities;
        $tenantRow['view_policy_json'] = $staleViewPolicy;
        $tenantRow['commercial_json'] = $staleCommercial;
        DB::table('scales_registry_v2')->insert($tenantRow);

        $this->insertSku('LEGACY_UNLOCK', 'report_unlock', 'attempt', true);
        $this->insertSku('MBTI_PRO_TEST', 'credit_pack', 'org', true);

        $this->productTruthMigration()->up();
        $this->productTruthMigration()->up();

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (self::FREE_SCALE_CODES as $scaleCode) {
                $row = DB::table($table)
                    ->where('org_id', 0)
                    ->where('code', $scaleCode)
                    ->first();
                $this->assertNotNull($row);

                $capabilities = $this->decodeJson($row->capabilities_json ?? null);
                $viewPolicy = $this->decodeJson($row->view_policy_json ?? null);
                $commercial = $this->decodeJson($row->commercial_json ?? null);

                $this->assertSame('free_only', $capabilities['paywall_mode'] ?? null);
                $this->assertNotEmpty($capabilities['forms'] ?? []);
                $this->assertFalse((bool) ($viewPolicy['blur_others'] ?? true));
                $this->assertSame(0.0, (float) ($viewPolicy['teaser_percent'] ?? -1));
                $this->assertNull($viewPolicy['upgrade_sku'] ?? null);
                $this->assertSame('FREE', $commercial['price_tier'] ?? null);
                $this->assertNull($commercial['report_unlock_sku'] ?? null);
                $this->assertSame([], $commercial['offers'] ?? null);

                if (property_exists($row, 'content_i18n_json')) {
                    $content = $this->decodeJson($row->content_i18n_json ?? null);
                    $this->assertSame(0, data_get($content, 'en.highlight.rating'));
                    $this->assertGreaterThan(0, (int) data_get($content, 'en.catalog.questions_count'));
                    $this->assertGreaterThan(0, (int) data_get($content, 'en.catalog.time_minutes'));
                }
            }
        }

        $tenantCommercial = $this->decodeJson(DB::table('scales_registry_v2')
            ->where('org_id', 42)
            ->where('code', 'MBTI')
            ->value('commercial_json'));
        $this->assertSame('PAID', $tenantCommercial['price_tier'] ?? null);
        $this->assertSame('LEGACY_UNLOCK', $tenantCommercial['report_unlock_sku'] ?? null);

        $reportUnlock = DB::table('skus')->where('sku', 'LEGACY_UNLOCK')->first();
        $reportMetadata = $this->decodeJson($reportUnlock->meta_json ?? null);
        $this->assertFalse((bool) ($reportUnlock->is_active ?? true));
        $this->assertTrue((bool) ($reportMetadata['deprecated'] ?? false));
        $this->assertTrue((bool) ($reportMetadata['historical_only'] ?? false));
        $this->assertFalse((bool) ($reportMetadata['offer'] ?? true));

        $this->assertTrue((bool) DB::table('skus')->where('sku', 'MBTI_PRO_TEST')->value('is_active'));
    }

    private function insertSku(string $sku, string $kind, string $scope, bool $active): void
    {
        DB::table('skus')->insert([
            'sku' => $sku,
            'scale_code' => 'MBTI',
            'kind' => $kind,
            'unit_qty' => 1,
            'benefit_code' => $sku,
            'scope' => $scope,
            'price_cents' => 100,
            'currency' => 'CNY',
            'is_active' => $active,
            'meta_json' => json_encode(['offer' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function productTruthMigration(): object
    {
        return require base_path('database/migrations/2026_08_10_120000_converge_assessment_catalog_product_truth.php');
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
}
