<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2AssetPackageLoader;
use Tests\TestCase;

final class BigFiveResultPageV2SupplementalCouplingAssetsTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/coupling_assets/supplemental/v0_1';

    private const REQUIRED_FIELDS = [
        'asset_id',
        'asset_key',
        'coupling_key',
        'involved_traits',
        'involved_bands',
        'module_key',
        'section_key',
        'slot_key',
        'body_zh',
        'benefit_zh',
        'cost_zh',
        'common_misread_zh',
        'action_zh',
        'selection_priority',
        'dedupe_group',
    ];

    private const EXPECTED_ROLES = [
        'coupling_core_explanation',
        'coupling_benefit_cost',
        'coupling_common_misread',
        'coupling_action_strategy',
        'coupling_scenario_bridge',
    ];

    public function test_manifest_declares_supplemental_coupling_assets_as_staging_only(): void
    {
        $manifest = $this->decodeJson('big5_supplemental_coupling_assets_manifest_v0_1.json');

        $this->assertSame('B5-CONTENT-2B', $manifest['package_key'] ?? null);
        $this->assertSame(190, $manifest['asset_count'] ?? null);
        $this->assertSame(190, $manifest['expected_asset_count'] ?? null);
        $this->assertSame(38, $manifest['coupling_key_count'] ?? null);
        $this->assertSame('coupling', $manifest['asset_type'] ?? null);
        $this->assertSame('module_04_coupling', $manifest['module_key'] ?? null);
        $this->assertSame('core_portrait', $manifest['section_key'] ?? null);
        $this->assertSame('staging_only', $manifest['runtime_use'] ?? null);
        $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
        $this->assertTrue((bool) ($manifest['ready_for_asset_review'] ?? false));
        $this->assertFalse((bool) ($manifest['ready_for_pilot'] ?? true));
        $this->assertFalse((bool) ($manifest['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($manifest['ready_for_production'] ?? true));
        $this->assertSame(self::EXPECTED_ROLES, $manifest['included_asset_roles'] ?? null);
    }

    public function test_assets_cover_thirty_eight_coupling_keys_with_five_roles_each(): void
    {
        $assets = $this->assets();
        $this->assertCount(190, $assets);

        $rolesByCouplingKey = [];
        foreach ($assets as $asset) {
            $couplingKey = (string) $asset['coupling_key'];
            $role = str_replace('primary_coupling.', 'coupling_', (string) $asset['slot_key']);
            $rolesByCouplingKey[$couplingKey][$role] = true;
        }

        $this->assertCount(38, $rolesByCouplingKey);
        foreach ($rolesByCouplingKey as $couplingKey => $roles) {
            $roleList = array_keys($roles);
            sort($roleList);
            $expectedRoles = self::EXPECTED_ROLES;
            sort($expectedRoles);

            $this->assertSame($expectedRoles, $roleList, $couplingKey);
        }
    }

    public function test_json_jsonl_and_csv_parse_and_have_matching_asset_counts(): void
    {
        $jsonAssets = $this->assets();
        $jsonlAssets = $this->jsonlAssets();
        $csvRows = $this->csvRows('big5_supplemental_coupling_assets_main_v0_1.csv');

        $this->assertCount(190, $jsonAssets);
        $this->assertCount(190, $jsonlAssets);
        $this->assertCount(190, $csvRows);
        $this->assertSame(
            array_column($jsonAssets, 'asset_key'),
            array_column($jsonlAssets, 'asset_key')
        );
        $this->assertSame(
            array_column($jsonAssets, 'asset_key'),
            array_column($csvRows, 'asset_key')
        );
    }

    public function test_assets_have_no_duplicates_or_missing_required_fields(): void
    {
        $assets = $this->assets();

        $assetIds = [];
        $assetKeys = [];
        $bodyTexts = [];

        foreach ($assets as $index => $asset) {
            foreach (self::REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $asset, "asset {$index} missing {$field}");
                $this->assertNotSame('', $asset[$field], "asset {$index} empty {$field}");
            }

            $assetIds[] = (string) $asset['asset_id'];
            $assetKeys[] = (string) $asset['asset_key'];
            $bodyTexts[] = (string) $asset['body_zh'];
        }

        $this->assertSameSize($assetIds, array_unique($assetIds), 'duplicate asset_id detected');
        $this->assertSameSize($assetKeys, array_unique($assetKeys), 'duplicate asset_key detected');
        $this->assertSameSize($bodyTexts, array_unique($bodyTexts), 'duplicate body_zh detected');
    }

    public function test_assets_remain_staging_only_and_do_not_allow_fallback(): void
    {
        foreach ($this->assets() as $asset) {
            $this->assertSame('staging_only', $asset['runtime_use'] ?? null, (string) $asset['asset_key']);
            $this->assertFalse((bool) ($asset['production_use_allowed'] ?? true), (string) $asset['asset_key']);
            $this->assertFalse((bool) ($asset['ready_for_pilot'] ?? true), (string) $asset['asset_key']);
            $this->assertFalse((bool) ($asset['fallback_allowed'] ?? true), (string) $asset['asset_key']);
            $this->assertSame('asset_review_ready', $asset['qa_status'] ?? null, (string) $asset['asset_key']);
            $this->assertSame(['result_page', 'pdf'], $asset['render_surface'] ?? null, (string) $asset['asset_key']);
            $this->assertSame('BIG5_OCEAN', data_get($asset, 'applies_to.scale_code.0'), (string) $asset['asset_key']);
            $this->assertTrue((bool) data_get($asset, 'source_trace.staging_boundary'), (string) $asset['asset_key']);
            $this->assertTrue((bool) data_get($asset, 'source_trace.not_runtime'), (string) $asset['asset_key']);
            $this->assertTrue((bool) data_get($asset, 'source_trace.not_selector'), (string) $asset['asset_key']);
            $this->assertFalse((bool) data_get($asset, 'source_trace.production_use_allowed', true), (string) $asset['asset_key']);
        }
    }

    public function test_user_visible_copy_avoids_forbidden_public_claims(): void
    {
        foreach ($this->assets() as $asset) {
            $copy = implode("\n", array_map(
                static fn (string $field): string => (string) ($asset[$field] ?? ''),
                ['title_zh', 'summary_zh', 'body_zh', 'short_body_zh', 'benefit_zh', 'cost_zh', 'common_misread_zh', 'action_zh']
            ));

            $this->assertStringNotContainsString('frontend_fallback', $copy, (string) $asset['asset_key']);
            $this->assertStringNotContainsString('[object Object]', $copy, (string) $asset['asset_key']);
            $this->assertStringNotContainsString('production_use_allowed', $copy, (string) $asset['asset_key']);
            $this->assertStringNotContainsString('runtime_use', $copy, (string) $asset['asset_key']);
            $this->assertStringNotContainsString('固定人格类型', $copy, (string) $asset['asset_key']);
            $this->assertStringNotContainsString('你就是某一型', $copy, (string) $asset['asset_key']);
            $this->assertDoesNotMatchRegularExpression('/(临床诊断|医学诊断|诊断为|招聘筛选)/u', $copy, (string) $asset['asset_key']);
        }
    }

    public function test_sha256sums_are_reproducible_and_loader_inventories_package(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertCount(12, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
        }

        $inventory = app(BigFiveV2AssetPackageLoader::class)->inventory();
        $packages = collect($inventory->packages)->keyBy('relativePath');
        $package = $packages->get(self::BASE_PATH);

        $this->assertNotNull($package);
        $this->assertSame(13, $package->fileCount);
        $this->assertContains('staging_only', $package->runtimeUses);
        $this->assertFalse($package->productionUseAllowed);
        $this->assertFalse($package->readyForRuntime);
        $this->assertFalse($package->readyForProduction);
        $this->assertSame([], $package->errors);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function assets(): array
    {
        $decoded = $this->decodeJson('big5_supplemental_coupling_assets_v0_1.json');
        $items = $decoded['items'] ?? null;

        $this->assertIsArray($items);

        return array_values($items);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function jsonlAssets(): array
    {
        $lines = file(base_path(self::BASE_PATH.'/big5_supplemental_coupling_assets_v0_1.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines
        ));
    }

    /**
     * @return list<array<string,string>>
     */
    private function csvRows(string $fileName): array
    {
        $handle = fopen(base_path(self::BASE_PATH.'/'.$fileName), 'rb');
        $this->assertIsResource($handle);

        $header = fgetcsv($handle);
        $this->assertIsArray($header);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, $row);
        }
        fclose($handle);

        return $rows;
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
