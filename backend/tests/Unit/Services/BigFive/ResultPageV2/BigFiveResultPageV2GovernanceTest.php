<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use Tests\TestCase;

final class BigFiveResultPageV2GovernanceTest extends TestCase
{
    private const GOVERNANCE_DIR = 'content_assets/big5/result_page_v2/governance';

    private const CURRENT_RUNTIME_SECTIONS = [
        'hero_summary',
        'domains_overview',
        'domain_deep_dive',
        'facet_details',
        'core_portrait',
        'norms_comparison',
        'action_plan',
        'methodology_and_access',
    ];

    private const ALLOWED_SECTION_TARGETS = [
        'hero_summary',
        'domains_overview',
        'domain_deep_dive',
        'facet_details',
        'core_portrait',
        'norms_comparison',
        'action_plan',
        'methodology_and_access',
        'shell_trust_strip',
        'shell_tools',
        'lifecycle',
        'observation',
    ];

    public function test_governance_json_files_are_valid(): void
    {
        $this->assertIsArray($this->moduleMapping());
        $this->assertIsArray($this->runtimeDecision());
        $this->assertIsArray($this->antiTargetTerms());
    }

    public function test_module_to_section_mapping_covers_module_00_to_10(): void
    {
        $mapping = $this->moduleMapping();
        $modules = $this->modulesByKey($mapping);

        $this->assertSame(BigFiveResultPageV2Contract::MODULE_KEYS, array_keys($modules));
    }

    public function test_module_to_section_mapping_targets_current_8_section_skeleton(): void
    {
        $mapping = $this->moduleMapping();

        $this->assertSame(self::CURRENT_RUNTIME_SECTIONS, $mapping['runtime_sections'] ?? null);

        foreach ((array) ($mapping['modules'] ?? []) as $module) {
            $this->assertContains($module['primary_section'] ?? null, self::ALLOWED_SECTION_TARGETS, (string) ($module['module_key'] ?? ''));

            $secondarySurface = $module['secondary_surface'] ?? null;
            if ($secondarySurface !== null) {
                $this->assertContains($secondarySurface, self::ALLOWED_SECTION_TARGETS, (string) ($module['module_key'] ?? ''));
            }
        }
    }

    public function test_b5_b1_allowed_and_forbidden_module_decisions_are_explicit(): void
    {
        $modules = $this->modulesByKey($this->moduleMapping());

        $this->assertTrue((bool) $modules['module_01_hero']['allowed_in_b5_b1']);
        $this->assertTrue((bool) $modules['module_03_trait_deep_dive']['allowed_in_b5_b1']);
        $this->assertTrue((bool) $modules['module_04_coupling']['allowed_in_b5_b1']);
        $this->assertTrue((bool) $modules['module_05_facet_reframe']['allowed_in_b5_b1']);
        $this->assertTrue((bool) $modules['module_06_application_matrix']['allowed_in_b5_b1']);
        $this->assertFalse((bool) $modules['module_08_share_save']['allowed_in_b5_b1']);
        $this->assertFalse((bool) $modules['module_09_feedback_data_flywheel']['allowed_in_b5_b1']);
    }

    public function test_runtime_layer_decision_blocks_runtime_now(): void
    {
        $decision = $this->runtimeDecision();

        $this->assertFalse((bool) ($decision['runtime_wiring_allowed_now'] ?? true));
        $this->assertFalse((bool) ($decision['frontend_changes_allowed_now'] ?? true));
        $this->assertFalse((bool) ($decision['selector_runtime_allowed_now'] ?? true));
    }

    public function test_runtime_layer_classifies_sources_correctly(): void
    {
        $layers = $this->layersByKey($this->runtimeDecision());

        $this->assertSame('source_only', $layers['v2_formal_doc']['classification'] ?? null);
        $this->assertSame('source_only', $layers['longform_final_doc']['classification'] ?? null);
        $this->assertSame('staging_only', $layers['selector_ready_assets_v0_3_p0_full']['classification'] ?? null);
        $this->assertSame('staging_only', $layers['selector_qa_policy_pack']['classification'] ?? null);
        $this->assertSame('current_live_legacy_or_compatibility', $layers['v1_compiled_pack']['classification'] ?? null);
        $this->assertSame('must_not_be_long_term_content_owner', $layers['frontend_fallback']['classification'] ?? null);
        $this->assertSame('anti_target', $layers['compact_online_page']['classification'] ?? null);
    }

    public function test_source_authority_map_contains_required_decisions(): void
    {
        $markdown = $this->sourceAuthorityMarkdown();

        foreach ([
            'module master',
            'narrative / canonical body master',
            'runtime skeleton',
            'selector candidates / staging',
            'selector QA / policy pack',
            'anti-target',
            'must_not_be_long_term_content_owner',
            'B5-B1',
        ] as $requiredPhrase) {
            $this->assertStringContainsString($requiredPhrase, $markdown);
        }
    }

    public function test_anti_target_terms_cover_known_regressions(): void
    {
        $terms = array_column((array) ($this->antiTargetTerms()['terms'] ?? []), 'term');

        foreach ([
            'A compact overview of your Big Five profile and headline signals.',
            'Five-domain distribution with percentile-oriented context.',
            'Focused read on domain-level strengths and potential trade-offs.',
            'Facet-level signals arranged for quick interpretation and follow-up.',
            'Norms Comparison',
            'Methodology and Access',
            'Dominant trait structure and calibrated profile framing.',
            'N1 百分位',
            '优先关注成长面向',
            'all',
            'frontend_fallback',
            'internal_metadata',
            '[object Object]',
            'deferred_to_future',
            'policy_not_shipped',
        ] as $requiredTerm) {
            $this->assertContains($requiredTerm, $terms);
        }
    }

    public function test_governance_package_does_not_claim_runtime_ready(): void
    {
        $moduleMapping = json_encode($this->moduleMapping(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $runtimeDecision = $this->runtimeDecision();
        $antiTargetTerms = json_encode($this->antiTargetTerms(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($moduleMapping);
        $this->assertIsString($antiTargetTerms);

        $this->assertFalse((bool) ($runtimeDecision['runtime_wiring_allowed_now'] ?? true));
        $this->assertFalse((bool) ($runtimeDecision['frontend_changes_allowed_now'] ?? true));
        $this->assertFalse((bool) ($runtimeDecision['selector_runtime_allowed_now'] ?? true));

        $this->assertStringNotContainsString('"runtime_ready":true', $moduleMapping);
        $this->assertStringNotContainsString('"production_use_allowed":true', $moduleMapping);
        $this->assertStringNotContainsString('"runtime_ready":true', $antiTargetTerms);
        $this->assertStringNotContainsString('"production_use_allowed":true', $antiTargetTerms);
    }

    /**
     * @return array<string,mixed>
     */
    private function moduleMapping(): array
    {
        return $this->loadJson('big5_v2_module_to_section_mapping_v0_1.json');
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeDecision(): array
    {
        return $this->loadJson('big5_v2_runtime_layer_decision_v0_1.json');
    }

    /**
     * @return array<string,mixed>
     */
    private function antiTargetTerms(): array
    {
        return $this->loadJson('big5_v2_anti_target_render_terms_v0_1.json');
    }

    private function sourceAuthorityMarkdown(): string
    {
        $markdown = file_get_contents($this->path('big5_v2_source_authority_map_v0_1.md'));
        $this->assertIsString($markdown, 'Missing source authority map markdown');

        return $markdown;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function modulesByKey(array $mapping): array
    {
        $modules = [];
        foreach ((array) ($mapping['modules'] ?? []) as $module) {
            $moduleKey = (string) ($module['module_key'] ?? '');
            $this->assertNotSame('', $moduleKey);
            $modules[$moduleKey] = $module;
        }

        return $modules;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function layersByKey(array $decision): array
    {
        $layers = [];
        foreach ((array) ($decision['layers'] ?? []) as $layer) {
            $layerKey = (string) ($layer['layer_key'] ?? '');
            $this->assertNotSame('', $layerKey);
            $layers[$layerKey] = $layer;
        }

        return $layers;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $filename): array
    {
        $json = file_get_contents($this->path($filename));
        $this->assertIsString($json, "Missing governance file {$filename}");
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, "Invalid JSON in {$filename}");

        return $decoded;
    }

    private function path(string $filename): string
    {
        return base_path(self::GOVERNANCE_DIR.'/'.$filename);
    }
}
