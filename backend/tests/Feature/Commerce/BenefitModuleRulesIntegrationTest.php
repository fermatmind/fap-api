<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\SkuCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BenefitModuleRulesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_unlock_uses_db_benefit_module_rules(): void
    {
        $benefitCode = 'CUSTOM_BENEFIT_RULES';
        $attemptId = (string) Str::uuid();

        DB::table('benefit_module_rules')->insert([
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'benefit_code' => $benefitCode,
                'module_code' => 'core_free',
                'access_tier' => 'free',
                'priority' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'benefit_code' => $benefitCode,
                'module_code' => 'career',
                'access_tier' => 'paid',
                'priority' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        /** @var EntitlementManager $manager */
        $manager = app(EntitlementManager::class);
        $result = $manager->grantAttemptUnlock(
            0,
            null,
            'anon_rules_case',
            $benefitCode,
            $attemptId,
            'order_rules_case'
        );

        $this->assertTrue((bool) ($result['ok'] ?? false));

        $row = DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('benefit_code', $benefitCode)
            ->first();

        $this->assertNotNull($row);
        $meta = json_decode((string) ($row->meta_json ?? '[]'), true);
        $meta = is_array($meta) ? $meta : [];
        $modules = is_array($meta['modules'] ?? null) ? $meta['modules'] : [];

        $this->assertContains('core_free', $modules);
        $this->assertContains('career', $modules);

        $allowed = $manager->getAllowedModulesForAttempt(0, $attemptId);
        $this->assertContains('core_free', $allowed);
        $this->assertContains('career', $allowed);
    }

    public function test_sku_catalog_populates_modules_included_from_benefit_module_rules(): void
    {
        $benefitCode = 'CUSTOM_BENEFIT_FOR_SKU';

        DB::table('benefit_module_rules')->insert([
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'benefit_code' => $benefitCode,
                'module_code' => 'core_free',
                'access_tier' => 'free',
                'priority' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'benefit_code' => $benefitCode,
                'module_code' => 'relationships',
                'access_tier' => 'paid',
                'priority' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('skus')->insert([
            'sku' => 'SKU_RULES_DEMO',
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => $benefitCode,
            'scope' => 'attempt',
            'price_cents' => 9900,
            'currency' => 'USD',
            'is_active' => true,
            'meta_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var SkuCatalog $catalog */
        $catalog = app(SkuCatalog::class);
        $rows = $catalog->listActiveSkus('MBTI');
        $row = collect($rows)->firstWhere('sku', 'SKU_RULES_DEMO');

        $this->assertIsArray($row);
        $modulesIncluded = is_array($row['modules_included'] ?? null) ? $row['modules_included'] : [];
        $this->assertContains('core_free', $modulesIncluded);
        $this->assertContains('relationships', $modulesIncluded);
    }
}
