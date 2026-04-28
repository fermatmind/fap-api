<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetContract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetValidator;
use Tests\TestCase;

final class BigFiveResultPageV2SelectorAssetImportV03Test extends TestCase
{
    private const RELATIVE_DIR = 'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full';

    private const EXPECTED_REGISTRY_COUNTS = [
        'action_plan_registry' => 25,
        'boundary_registry' => 5,
        'coupling_registry' => 50,
        'domain_registry' => 50,
        'facet_pattern_registry' => 60,
        'method_registry' => 6,
        'observation_feedback_registry' => 25,
        'profile_signature_registry' => 20,
        'scenario_registry' => 40,
        'share_safety_registry' => 10,
        'state_scope_registry' => 14,
        'triple_pattern_registry' => 20,
    ];

    private const EXPECTED_MODULE_COUNTS = [
        'module_00_trust_bar' => 1,
        'module_01_hero' => 45,
        'module_02_quick_understanding' => 24,
        'module_03_trait_deep_dive' => 26,
        'module_04_coupling' => 52,
        'module_05_facet_reframe' => 61,
        'module_06_application_matrix' => 57,
        'module_07_collaboration_manual' => 8,
        'module_08_share_save' => 12,
        'module_09_feedback_data_flywheel' => 26,
        'module_10_method_privacy' => 13,
    ];

    public function test_v0_3_assets_json_is_valid(): void
    {
        $assets = $this->assets();

        $this->assertCount(325, $assets);
        foreach ($assets as $asset) {
            foreach (BigFiveResultPageV2SelectorAssetContract::REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $asset);
            }
        }
    }

    public function test_v0_3_jsonl_matches_json(): void
    {
        $this->assertSame($this->assets(), $this->jsonlAssets());
    }

    public function test_v0_3_manifest_hash_matches_assets(): void
    {
        $manifest = $this->manifest();

        $this->assertSame(325, $manifest['asset_count'] ?? null);
        $this->assertSame('b69af54089dacd09d62c7b3ee9b37fbff012e231e6d00be752a376a4598e27bd', hash_file('sha256', $this->path('assets.json')));
        $this->assertSame(hash_file('sha256', $this->path('assets.json')), $manifest['sha256_json'] ?? null);
    }

    public function test_v0_3_all_assets_pass_selector_validator(): void
    {
        $validator = new BigFiveResultPageV2SelectorAssetValidator;

        $this->assertSame([], $validator->validateAssetSet($this->assets()));
    }

    public function test_v0_3_registry_counts_match_manifest(): void
    {
        $manifest = $this->manifest();

        $this->assertSame(self::EXPECTED_REGISTRY_COUNTS, $manifest['registry_counts'] ?? null);
        $this->assertSame(self::EXPECTED_REGISTRY_COUNTS, $this->countBy($this->assets(), 'registry_key'));
    }

    public function test_v0_3_module_counts_match_coverage_summary(): void
    {
        $coverageSummary = $this->coverageSummary();

        $this->assertSame(self::EXPECTED_MODULE_COUNTS, $coverageSummary['module_counts'] ?? null);
        $this->assertSame(self::EXPECTED_MODULE_COUNTS, $this->countBy($this->assets(), 'module_key'));
    }

    public function test_v0_3_assets_are_staging_only(): void
    {
        $manifest = $this->manifest();

        $this->assertFalse($manifest['runtime_ready'] ?? true);
        $this->assertSame('staging_only', $manifest['runtime_use'] ?? null);
    }

    public function test_v0_3_assets_align_with_coverage_matrix_and_contract(): void
    {
        $matrix = $this->coverageMatrix();
        $matrixRegistryKeys = $matrix['allowlists']['registry_keys'] ?? [];
        $matrixModules = $matrix['allowlists']['target_modules'] ?? [];

        foreach ($this->assets() as $asset) {
            $assetKey = (string) $asset['asset_key'];
            $this->assertContains($asset['registry_key'], $matrixRegistryKeys, $assetKey);
            $this->assertContains($asset['module_key'], $matrixModules, $assetKey);
            $this->assertContains($asset['module_key'], BigFiveResultPageV2Contract::MODULE_KEYS, $assetKey);
            $this->assertContains($asset['block_kind'], BigFiveResultPageV2Contract::BLOCK_KINDS, $assetKey);

            foreach ($asset['reading_modes'] as $readingMode) {
                $this->assertContains($readingMode, BigFiveResultPageV2SelectorAssetContract::READING_MODES, $assetKey);
            }

            foreach (($asset['trigger']['reading_mode'] ?? []) as $triggerReadingMode) {
                $this->assertContains($triggerReadingMode, $asset['reading_modes'], $assetKey);
            }

            $triggerScenarios = $asset['trigger']['scenario'] ?? [];
            $scenario = $asset['scenario'] ?: 'unspecified';
            if ($triggerScenarios !== []) {
                $this->assertContains($scenario, $triggerScenarios, $assetKey);
            }
        }
    }

    public function test_v0_3_public_payload_has_no_internal_metadata(): void
    {
        foreach ($this->assets() as $asset) {
            $publicPayload = $asset['public_payload'];
            $this->assertForbiddenKeysAbsent($publicPayload, [
                'internal_metadata',
                'editor_notes',
                'qa_notes',
                'selection_guidance',
                'import_policy',
            ], (string) $asset['asset_key']);
        }
    }

    public function test_v0_3_no_fixed_type_or_user_confirmed_type(): void
    {
        foreach ($this->assets() as $asset) {
            $publicText = json_encode($asset['public_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertIsString($publicText);
            $this->assertStringNotContainsString('user_confirmed_type', $publicText, (string) $asset['asset_key']);
            $this->assertStringNotContainsString('fixed_type', $publicText, (string) $asset['asset_key']);
            $this->assertStringNotContainsString('固定人格类型', $publicText, (string) $asset['asset_key']);
            $this->assertStringNotContainsString('你就是某一型', $publicText, (string) $asset['asset_key']);
            $this->assertDoesNotMatchRegularExpression('/(临床诊断|医学诊断|诊断为)/u', $publicText, (string) $asset['asset_key']);
        }
    }

    public function test_v0_3_shareable_assets_are_safe(): void
    {
        foreach ($this->assets() as $asset) {
            if (($asset['shareable'] ?? false) !== true) {
                continue;
            }

            $this->assertContains($asset['shareable_policy'], [
                'share_safe_behavioral_only',
                'required_for_every_shareable_true_block',
            ], (string) $asset['asset_key']);
            $this->assertForbiddenKeysAbsent($asset, BigFiveResultPageV2Contract::SHARE_FORBIDDEN_SCORE_FIELDS, (string) $asset['asset_key']);
            $publicText = json_encode($asset['public_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertIsString($publicText);
            $this->assertDoesNotMatchRegularExpression('/(raw_sensitive_score|raw score|sensitive percentile)/i', $publicText, (string) $asset['asset_key']);
        }
    }

    public function test_v0_3_facet_assets_have_support_metadata(): void
    {
        foreach ($this->assets() as $asset) {
            if (($asset['registry_key'] ?? null) !== 'facet_pattern_registry') {
                continue;
            }

            $facetSupport = $asset['trigger']['facet_support'] ?? null;
            $this->assertIsArray($facetSupport, (string) $asset['asset_key']);
            $this->assertIsInt($facetSupport['item_count'] ?? null, (string) $asset['asset_key']);
            $this->assertContains($facetSupport['confidence'] ?? null, ['low', 'medium', 'high'], (string) $asset['asset_key']);
            $this->assertArrayHasKey('inference_only', $facetSupport, (string) $asset['asset_key']);
            $this->assertNotSame('independent_measurement', $facetSupport['claim_strength'] ?? null, (string) $asset['asset_key']);
        }
    }

    public function test_v0_3_generates_or_validates_import_coverage_report(): void
    {
        $report = $this->importCoverageReport();

        $this->assertSame(325, $report['total_assets'] ?? null);
        $this->assertSame(self::EXPECTED_REGISTRY_COUNTS, $report['registry_counts_actual'] ?? null);
        $this->assertSame(self::EXPECTED_REGISTRY_COUNTS, $report['registry_counts_expected'] ?? null);
        $this->assertSame(self::EXPECTED_MODULE_COUNTS, $report['module_counts_actual'] ?? null);
        $this->assertSame(self::EXPECTED_MODULE_COUNTS, $report['module_counts_expected'] ?? null);
        $this->assertSame('pass', $report['validation_status'] ?? null);
        $this->assertSame(0, $report['validation_error_count'] ?? null);
        $this->assertContains('module_00_trust_bar only 1 asset', $report['warnings'] ?? []);
        $this->assertContains('module_07_collaboration_manual only 8 assets', $report['warnings'] ?? []);
        $this->assertContains('shareable assets only 3', $report['warnings'] ?? []);
        $this->assertContains('balanced_profile only 4', $report['warnings'] ?? []);
        $this->assertContains('norm_unavailable only 2', $report['warnings'] ?? []);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function assets(): array
    {
        return $this->decodeJsonFile('assets.json');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function jsonlAssets(): array
    {
        $lines = file($this->path('assets.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function manifest(): array
    {
        return $this->decodeJsonFile('manifest.json');
    }

    /**
     * @return array<string,mixed>
     */
    private function coverageSummary(): array
    {
        return $this->decodeJsonFile('coverage_summary.json');
    }

    /**
     * @return array<string,mixed>
     */
    private function coverageMatrix(): array
    {
        $decoded = json_decode(
            file_get_contents(base_path('content_assets/big5/result_page_v2/personalization_coverage_matrix_v0_2.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function importCoverageReport(): array
    {
        return $this->decodeJsonFile('import_coverage_report.json');
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJsonFile(string $filename): array
    {
        $decoded = json_decode(file_get_contents($this->path($filename)) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function path(string $filename): string
    {
        return base_path(self::RELATIVE_DIR.'/'.$filename);
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,int>
     */
    private function countBy(array $assets, string $field): array
    {
        $counts = [];
        foreach ($assets as $asset) {
            $key = (string) ($asset[$field] ?? 'unspecified');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $forbiddenKeys
     */
    private function assertForbiddenKeysAbsent(array $payload, array $forbiddenKeys, string $context): void
    {
        foreach ($payload as $key => $value) {
            $this->assertNotContains((string) $key, $forbiddenKeys, $context);
            if (is_array($value)) {
                $this->assertForbiddenKeysAbsent($value, $forbiddenKeys, $context.'.'.$key);
            }
        }
    }
}
