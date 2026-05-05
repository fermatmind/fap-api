<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2CouplingAssetGapPlanningTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/coupling_assets/planning/v0_1';

    private const EXPECTED_ROLES = [
        'coupling_core_explanation',
        'coupling_benefit_cost',
        'coupling_common_misread',
        'coupling_action_strategy',
        'coupling_scenario_bridge',
    ];

    public function test_summary_declares_planning_only_runtime_blocked_package(): void
    {
        $summary = $this->decodeJson('big5_coupling_asset_gap_summary_v0_1.json');

        $this->assertSame('B5-CONTENT-2B', $summary['package_key'] ?? null);
        $this->assertSame('planning_only', $summary['mode'] ?? null);
        $this->assertSame('B5-CONTENT-2B planning only', $summary['source_authority'] ?? null);
        $this->assertSame('not_runtime', $summary['runtime_use'] ?? null);
        $this->assertFalse((bool) ($summary['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($summary['ready_for_pilot'] ?? true));
        $this->assertFalse((bool) ($summary['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($summary['ready_for_production'] ?? true));
        $this->assertSame(38, $summary['unresolved_after_alias'] ?? null);
        $this->assertSame(190, $summary['expected_new_coupling_assets'] ?? null);
        $this->assertSame(5, $summary['expected_asset_roles_per_coupling'] ?? null);
        $this->assertTrue((bool) ($summary['no_body_generated'] ?? false));
        $this->assertTrue((bool) ($summary['ready_for_content_generation'] ?? false));
        $this->assertTrue((bool) ($summary['requires_content_owner_review'] ?? false));
    }

    public function test_plan_covers_thirty_eight_new_coupling_keys_without_body_copy(): void
    {
        $plan = $this->decodeJson('big5_coupling_asset_gap_plan_v0_1.json');
        $items = $plan['items'] ?? null;

        $this->assertSame('planning_only', $plan['mode'] ?? null);
        $this->assertSame('not_runtime', $plan['runtime_use'] ?? null);
        $this->assertFalse((bool) ($plan['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($plan['ready_for_pilot'] ?? true));
        $this->assertFalse((bool) ($plan['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($plan['ready_for_production'] ?? true));
        $this->assertTrue((bool) ($plan['no_body_generated'] ?? false));
        $this->assertSame(self::EXPECTED_ROLES, $plan['proposed_asset_roles'] ?? null);
        $this->assertIsArray($items);
        $this->assertCount(38, $items);

        $keys = [];
        $expectedAssetCount = 0;
        foreach ($items as $item) {
            $this->assertIsArray($item);
            $this->assertNotSame('', (string) ($item['coupling_key'] ?? ''));
            $this->assertIsArray($item['involved_traits'] ?? null);
            $this->assertCount(2, $item['involved_traits']);
            $this->assertIsArray($item['involved_bands'] ?? null);
            $this->assertNotSame('', (string) ($item['reason_new_asset_required'] ?? ''));
            $this->assertSame(self::EXPECTED_ROLES, $item['proposed_asset_roles'] ?? null);
            $this->assertSame(5, $item['expected_asset_count'] ?? null);
            $this->assertSame(5, $item['total_expected_new_assets_contribution'] ?? null);
            $this->assertSame('B5-CONTENT-2B planning only', $item['source_authority'] ?? null);
            $this->assertSame('not_runtime', $item['runtime_use'] ?? null);
            $this->assertFalse((bool) ($item['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($item['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($item['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($item['ready_for_production'] ?? true));
            $this->assertTrue((bool) ($item['requires_content_owner_review'] ?? false));

            $keys[] = (string) $item['coupling_key'];
            $expectedAssetCount += (int) $item['total_expected_new_assets_contribution'];
        }

        $this->assertSame(38, count(array_unique($keys)));
        $this->assertSame(190, $expectedAssetCount);
        $this->assertNotContains('c_low_x_e_low', $keys);
        $this->assertNotContains('e_low_x_n_high', $keys);
        $this->assertNotContains('o_mid_x_n_high', $keys);
        $this->assertStringNotContainsString('body_zh', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function test_csv_table_matches_plan_shape(): void
    {
        $csvPath = base_path(self::BASE_PATH.'/big5_coupling_asset_gap_table_v0_1.csv');
        $handle = fopen($csvPath, 'rb');
        $this->assertIsResource($handle);

        $header = fgetcsv($handle);
        $this->assertSame([
            'coupling_key',
            'involved_traits',
            'involved_bands',
            'reason_new_asset_required',
            'proposed_asset_roles',
            'expected_asset_count',
            'total_expected_new_assets_contribution',
            'source_authority',
            'runtime_use',
            'production_use_allowed',
            'ready_for_pilot',
            'ready_for_runtime',
            'ready_for_production',
            'requires_content_owner_review',
        ], $header);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        $this->assertCount(38, $rows);
        foreach ($rows as $row) {
            $this->assertCount(14, $row);
            $this->assertSame('5', $row[5]);
            $this->assertSame('5', $row[6]);
            $this->assertSame('B5-CONTENT-2B planning only', $row[7]);
            $this->assertSame('not_runtime', $row[8]);
            $this->assertSame('false', $row[9]);
            $this->assertSame('false', $row[10]);
            $this->assertSame('false', $row[11]);
            $this->assertSame('false', $row[12]);
            $this->assertSame('true', $row[13]);
        }

        $this->assertStringNotContainsString('body_zh', (string) file_get_contents($csvPath));
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertCount(4, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $fileName): array
    {
        $json = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
