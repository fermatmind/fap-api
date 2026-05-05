<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteMatrixLookup;
use Tests\TestCase;

final class BigFiveResultPageV2RouteDrivenGoldenCasesTest extends TestCase
{
    private const CASES_PATH = 'content_assets/big5/result_page_v2/qa/route_driven_golden_cases/v0_1/big5_route_driven_golden_cases_v0_1.json';

    private const SUMMARY_PATH = 'content_assets/big5/result_page_v2/qa/route_driven_golden_cases/v0_1/big5_route_driven_golden_cases_summary_v0_1.json';

    public function test_route_driven_golden_cases_cover_profile_families_and_variants(): void
    {
        $cases = $this->cases();

        $this->assertCount(16, $cases);
        $this->assertCount(8, array_filter(
            $cases,
            static fn (array $case): bool => ($case['golden_group'] ?? null) === 'canonical_profile_family',
        ));

        $profileFamilies = array_values(array_unique(array_map(
            static fn (array $case): string => (string) $case['profile_family'],
            array_filter($cases, static fn (array $case): bool => ($case['golden_group'] ?? null) === 'canonical_profile_family'),
        )));
        sort($profileFamilies);

        $this->assertSame([
            'complex_explorer_low_structure',
            'connective_coordinator',
            'orderly_supporter',
            'overloaded_internalizer',
            'quiet_deep_worker',
            'sensitive_independent_thinker',
            'sharp_exploratory_driver',
            'vigilant_perfectionist',
        ], $profileFamilies);

        foreach ([
            'norm_unavailable',
            'degraded_quality',
            'low_profile_confidence',
            'unresolved_suppression',
            'surface_safety',
            'coupling_alias_resolution',
            'supplemental_coupling_resolution',
            'metadata_non_leak',
        ] as $variantGroup) {
            $this->assertContains($variantGroup, array_column($cases, 'golden_group'), $variantGroup);
        }
    }

    public function test_route_driven_golden_cases_are_staging_only_refs_not_body_assets(): void
    {
        foreach ($this->cases() as $case) {
            $this->assertSame('route_driven_selector_golden_case', $case['case_type'] ?? null);
            $this->assertSame('staging_only', $case['runtime_use'] ?? null);
            $this->assertFalse((bool) ($case['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($case['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($case['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($case['ready_for_production'] ?? true));
            $this->assertIsArray($case['expected_trait_refs'] ?? null);
            $this->assertIsArray($case['expected_coupling_refs'] ?? null);
            $this->assertIsArray($case['expected_suppressed_refs'] ?? null);
            $this->assertIsArray($case['metadata_non_leak_terms'] ?? null);

            $encoded = json_encode($case, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('body_zh', $encoded);
            $this->assertStringNotContainsString('完整报告', $encoded);
            $this->assertStringNotContainsString('固定人格类型', (string) ($case['notes'] ?? ''));
            $this->assertStringNotContainsString('你就是', $encoded);
        }
    }

    public function test_route_keys_exist_and_expected_refs_have_registry_shape(): void
    {
        $lookup = new BigFiveV2RouteMatrixLookup();

        foreach ($this->cases() as $case) {
            $combinationKey = (string) ($case['combination_key'] ?? '');
            $this->assertNotSame('', $combinationKey);
            $this->assertNotNull($lookup->lookup($combinationKey), $combinationKey);

            foreach ([...($case['expected_trait_refs'] ?? []), ...($case['expected_coupling_refs'] ?? [])] as $ref) {
                $this->assertMatchesRegularExpression('/^[a-z_]+:[a-z0-9_.]+$/', (string) $ref);
            }

            foreach ((array) ($case['metadata_non_leak_terms'] ?? []) as $term) {
                $this->assertContains($term, [
                    'source_reference',
                    'selector_basis',
                    'internal_metadata',
                    'production_use_allowed',
                    'runtime_use',
                    'review_status',
                    'qa_notes',
                    '[object Object]',
                ]);
            }
        }
    }

    public function test_route_driven_golden_summary_matches_cases(): void
    {
        $summary = $this->decodeJson(self::SUMMARY_PATH);
        $cases = $this->cases();

        $this->assertSame(count($cases), $summary['case_count'] ?? null);
        $this->assertSame(8, $summary['canonical_profile_family_case_count'] ?? null);
        $this->assertSame(8, $summary['variant_case_count'] ?? null);
        $this->assertTrue((bool) ($summary['no_body_generated'] ?? false));
        $this->assertSame('staging_only', $summary['runtime_use'] ?? null);
        $this->assertFalse((bool) ($summary['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($summary['production_go'] ?? true));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function cases(): array
    {
        $cases = $this->decodeJson(self::CASES_PATH);
        $this->assertTrue(array_is_list($cases));

        return array_values(array_filter($cases, 'is_array'));
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(string $relativePath): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($relativePath)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
