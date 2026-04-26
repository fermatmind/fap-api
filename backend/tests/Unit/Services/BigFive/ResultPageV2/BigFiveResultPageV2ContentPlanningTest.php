<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use Tests\TestCase;

final class BigFiveResultPageV2ContentPlanningTest extends TestCase
{
    private const MATRIX_REQUIRED_FIELDS = [
        'registry_key',
        'coverage_group',
        'target_module',
        'slot_key',
        'trigger_fields',
        'required_variants',
        'priority',
        'mutual_exclusion_group',
        'can_stack_with',
        'reading_modes',
        'scenario',
        'scope',
        'required_evidence_level',
        'safety_level',
        'shareable_policy',
        'fallback_policy',
        'missing_blocks',
        'estimated_block_count',
        'priority_tier',
    ];

    public function test_foundation_seed_manifest_resets_current_pack_to_staging_only(): void
    {
        $manifest = $this->loadJson('foundation_seed_manifest_v0_2.json');

        $this->assertSame('fap.big5.result_page_v2.foundation_seed_manifest.v0.2', $manifest['schema'] ?? null);
        $this->assertSame(72, data_get($manifest, 'asset_pack.asset_count'));
        $this->assertSame('foundation_seed', data_get($manifest, 'reset_classification.coverage_tier'));
        $this->assertFalse((bool) data_get($manifest, 'reset_classification.personalization_ready'));
        $this->assertFalse((bool) data_get($manifest, 'reset_classification.selector_ready'));
        $this->assertFalse((bool) data_get($manifest, 'reset_classification.runtime_ready'));
        $this->assertSame('staging_only', data_get($manifest, 'reset_classification.runtime_use'));
        $this->assertSame('not_allowed', data_get($manifest, 'reset_classification.composer_use'));
        $this->assertSame('not_allowed', data_get($manifest, 'reset_classification.frontend_use'));
        $this->assertSame('not_allowed_until_selector_metadata_exists', data_get($manifest, 'reset_classification.cms_import_use'));
        $this->assertSame([
            'slot_key',
            'trigger_fields',
            'priority',
            'mutual_exclusion_group',
            'can_stack_with',
            'reading_modes',
            'scenario',
            'scope',
            'required_evidence_level',
            'safety_level',
            'shareable_policy',
            'fallback_policy',
        ], data_get($manifest, 'selector_metadata_required_before_runtime'));
        $this->assertTrue((bool) data_get($manifest, 'runtime_guardrails.runtime_wrapper_must_not_read_this_pack'));
        $this->assertTrue((bool) data_get($manifest, 'runtime_guardrails.composer_must_not_select_this_pack'));
        $this->assertTrue((bool) data_get($manifest, 'runtime_guardrails.frontend_must_not_render_this_pack_directly'));
        $this->assertTrue((bool) data_get($manifest, 'runtime_guardrails.cms_import_requires_selector_ready_replacement'));
        $this->assertSame([
            'profile_signature_registry',
            'state_scope_registry',
            'facet_pattern_registry',
            'observation_feedback_registry',
            'triple_pattern_registry',
            'action_plan_registry',
        ], data_get($manifest, 'missing_registries'));
    }

    public function test_coverage_matrix_schema_and_required_fields_are_complete(): void
    {
        $matrix = $this->loadJson('personalization_coverage_matrix_v0_2.json');

        $this->assertSame('fap.big5.result_page_v2.personalization_coverage_matrix.v0.2', $matrix['schema'] ?? null);
        $this->assertSame(self::MATRIX_REQUIRED_FIELDS, $matrix['schema_fields'] ?? null);
        $this->assertSame('foundation_seed', data_get($matrix, 'foundation_seed_dependency.coverage_tier'));
        $this->assertFalse((bool) data_get($matrix, 'foundation_seed_dependency.personalization_ready'));
        $this->assertFalse((bool) data_get($matrix, 'foundation_seed_dependency.selector_ready'));
        $this->assertSame('staging_only', data_get($matrix, 'foundation_seed_dependency.runtime_use'));

        foreach ((array) ($matrix['entries'] ?? []) as $index => $entry) {
            foreach (self::MATRIX_REQUIRED_FIELDS as $requiredField) {
                $this->assertArrayHasKey($requiredField, $entry, "coverage entry {$index} missing {$requiredField}");
            }
        }
    }

    public function test_coverage_matrix_uses_known_registries_modules_and_priority_tiers(): void
    {
        $matrix = $this->loadJson('personalization_coverage_matrix_v0_2.json');
        $entries = (array) ($matrix['entries'] ?? []);
        $registryAllowlist = (array) data_get($matrix, 'allowlists.registry_keys');
        $priorityAllowlist = (array) data_get($matrix, 'allowlists.priority_tiers');

        $this->assertSame([
            'profile_signature_registry',
            'state_scope_registry',
            'domain_registry',
            'facet_pattern_registry',
            'coupling_registry',
            'triple_pattern_registry',
            'scenario_registry',
            'action_plan_registry',
            'observation_feedback_registry',
            'share_safety_registry',
            'boundary_registry',
            'method_registry',
        ], $registryAllowlist);
        $this->assertSame(BigFiveResultPageV2Contract::MODULE_KEYS, data_get($matrix, 'allowlists.target_modules'));

        foreach ($entries as $entry) {
            $this->assertContains($entry['registry_key'], $registryAllowlist);
            $this->assertContains($entry['target_module'], BigFiveResultPageV2Contract::MODULE_KEYS);
            $this->assertContains($entry['priority_tier'], $priorityAllowlist);
            $this->assertContains($entry['required_evidence_level'], data_get($matrix, 'allowlists.evidence_levels'));
            $this->assertContains($entry['safety_level'], data_get($matrix, 'allowlists.safety_levels'));
            $this->assertContains($entry['fallback_policy'], data_get($matrix, 'allowlists.fallback_policies'));

            foreach ((array) $entry['reading_modes'] as $readingMode) {
                $this->assertContains($readingMode, data_get($matrix, 'allowlists.reading_modes'));
            }
        }

        $coveredRegistries = array_values(array_unique(array_map(
            static fn (array $entry): string => (string) $entry['registry_key'],
            $entries
        )));
        sort($coveredRegistries);
        $expectedRegistries = $registryAllowlist;
        sort($expectedRegistries);
        $this->assertSame($expectedRegistries, $coveredRegistries);

        $this->assertContains('P0', array_column($entries, 'priority_tier'));
        $this->assertContains('P1', array_column($entries, 'priority_tier'));
        $this->assertContains('P2', array_column($entries, 'priority_tier'));

        $coveredModules = array_values(array_unique(array_map(
            static fn (array $entry): string => (string) $entry['target_module'],
            $entries
        )));
        sort($coveredModules);
        $expectedModules = BigFiveResultPageV2Contract::MODULE_KEYS;
        sort($expectedModules);
        $this->assertSame($expectedModules, $coveredModules);
    }

    public function test_coverage_matrix_has_required_p0_p1_p2_groups(): void
    {
        $entries = (array) $this->loadJson('personalization_coverage_matrix_v0_2.json')['entries'];
        $groups = array_column($entries, 'coverage_group');

        foreach ([
            'nine_interpretation_scopes',
            'five_domains_x_five_bands_x_three_slots',
            'thirty_facets_high_low_mismatch_reframe',
            'ten_pair_core_polarity_variants',
            'first_batch_non_type_trait_signatures',
            'share_transforms_forbidden_fields_safe_quote_pool',
            'five_scenarios_trait_coupling_facet_triggers',
            'forty_eight_hour_seven_day_thirty_day_paths',
            'module_coupling_facet_action_feedback',
            'high_value_three_domain_combinations',
            'advanced_scenario_variants',
            'retest_and_longitudinal_follow_up_variants',
        ] as $requiredGroup) {
            $this->assertContains($requiredGroup, $groups);
        }
    }

    public function test_planning_files_do_not_contain_body_copy_fixed_type_or_frontend_fallback(): void
    {
        foreach ([
            'foundation_seed_manifest_v0_2.json',
            'personalization_coverage_matrix_v0_2.json',
        ] as $filename) {
            $contents = (string) file_get_contents($this->path($filename));
            $decoded = $this->loadJson($filename);

            $this->assertFalse($this->containsKeyRecursive($decoded, 'body_zh'), "{$filename} must not include body_zh");
            $this->assertFalse($this->containsKeyRecursive($decoded, 'user_confirmed_type'), "{$filename} must not include user_confirmed_type");
            $this->assertFalse($this->containsAnyKeyRecursive($decoded, [
                'editor_notes',
                'qa_notes',
                'selection_guidance',
                'import_policy',
                'internal_metadata',
            ]), "{$filename} must not include public-payload forbidden metadata keys");
            $this->assertStringNotContainsString('frontend_fallback', $contents, "{$filename} must not allow frontend fallback");
            $this->assertStringNotContainsString('fixed_type', $contents, "{$filename} must not include fixed type language");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $filename): array
    {
        $json = file_get_contents($this->path($filename));
        $this->assertIsString($json, "Missing planning file {$filename}");
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, "Invalid JSON in {$filename}");

        return $decoded;
    }

    private function path(string $filename): string
    {
        return base_path('content_assets/big5/result_page_v2/'.$filename);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function containsKeyRecursive(array $payload, string $key): bool
    {
        foreach ($payload as $currentKey => $value) {
            if ($currentKey === $key) {
                return true;
            }
            if (is_array($value) && $this->containsKeyRecursive($value, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $payload
     * @param  array<int,string>  $keys
     */
    private function containsAnyKeyRecursive(array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->containsKeyRecursive($payload, $key)) {
                return true;
            }
        }

        return false;
    }
}
