<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2CompatibilityTransformer;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BigFiveResultPageV2CompatibilityTransformerTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string}>
     */
    public static function validInputProvider(): iterable
    {
        yield 'canonical' => ['dry_run_canonical_input.json'];
        yield 'norm unavailable' => ['dry_run_norm_unavailable_input.json'];
        yield 'low quality' => ['dry_run_low_quality_input.json'];
        yield 'old v2 transform required' => ['dry_run_old_v2_transform_required_input.json'];
    }

    #[DataProvider('validInputProvider')]
    public function test_dry_run_transformer_outputs_valid_big5_result_page_v2_payload(string $fixture): void
    {
        $payload = $this->transformFixture($fixture);

        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload));
        $this->assertSame(BigFiveResultPageV2Contract::PAYLOAD_KEY, data_get($payload, 'big5_result_page_v2.payload_key'));
        $this->assertSame(BigFiveResultPageV2Contract::SCALE_CODE, data_get($payload, 'big5_result_page_v2.scale_code'));
    }

    public function test_canonical_sample_keeps_ocean_scores_and_minimal_module_coverage(): void
    {
        $payload = $this->transformFixture('dry_run_canonical_input.json');

        $this->assertSame(59, data_get($payload, 'big5_result_page_v2.projection_v2.domains.O.percentile'));
        $this->assertSame(32, data_get($payload, 'big5_result_page_v2.projection_v2.domains.C.percentile'));
        $this->assertSame(20, data_get($payload, 'big5_result_page_v2.projection_v2.domains.E.percentile'));
        $this->assertSame(55, data_get($payload, 'big5_result_page_v2.projection_v2.domains.A.percentile'));
        $this->assertSame(68, data_get($payload, 'big5_result_page_v2.projection_v2.domains.N.percentile'));
        $this->assertSame(BigFiveResultPageV2Contract::MODULE_KEYS, array_map(
            static fn (array $module): string => (string) $module['module_key'],
            data_get($payload, 'big5_result_page_v2.modules')
        ));
        $this->assertSame('compatibility_wrapper', data_get($payload, 'big5_result_page_v2.modules.6.blocks.0.content_source'));
        $this->assertSame('omit_block', data_get($payload, 'big5_result_page_v2.modules.6.blocks.0.fallback_policy'));
    }

    public function test_norm_unavailable_suppresses_percentile_and_normal_curve_fields(): void
    {
        $payload = $this->transformFixture('dry_run_norm_unavailable_input.json');

        $this->assertSame('norm_unavailable', data_get($payload, 'big5_result_page_v2.projection_v2.interpretation_scope'));
        $this->assertSame('MISSING', data_get($payload, 'big5_result_page_v2.projection_v2.norm_status'));
        $this->assertNull(data_get($payload, 'big5_result_page_v2.projection_v2.domains.O.score'));
        $this->assertNull(data_get($payload, 'big5_result_page_v2.projection_v2.domains.O.percentile'));
        $this->assertNull(data_get($payload, 'big5_result_page_v2.projection_v2.facets.N1.percentile'));
        $this->assertSame(['domain_bands', 'interpretation_scope', 'safety_flags'], data_get($payload, 'big5_result_page_v2.projection_v2.public_fields'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'normal_curve'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'show_normal_curve'));
    }

    public function test_low_quality_outputs_degraded_boundary_only_modules(): void
    {
        $payload = $this->transformFixture('dry_run_low_quality_input.json');
        $modules = data_get($payload, 'big5_result_page_v2.modules');

        $this->assertSame('low_quality', data_get($payload, 'big5_result_page_v2.projection_v2.interpretation_scope'));
        $this->assertSame([
            'module_00_trust_bar',
            'module_09_feedback_data_flywheel',
            'module_10_method_privacy',
        ], array_map(static fn (array $module): string => (string) $module['module_key'], $modules));
        foreach ($modules as $module) {
            foreach ((array) ($module['blocks'] ?? []) as $block) {
                $this->assertContains($block['safety_level'], ['boundary', 'degraded']);
            }
        }
    }

    public function test_minimal_low_quality_output_fixture_is_validator_clean_and_matches_transformer(): void
    {
        $expected = $this->loadFixture('dry_run_minimal_low_quality_output.payload.json');
        $actual = $this->transformFixture('dry_run_low_quality_input.json');

        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope($expected));
        $this->assertSame($expected, $actual);
    }

    public function test_old_v2_transform_required_blocks_include_source_authority_provenance(): void
    {
        $payload = $this->transformFixture('dry_run_old_v2_transform_required_input.json');

        $this->assertSame('transformed_old_v2_registry', data_get($payload, 'big5_result_page_v2.modules.3.blocks.0.content_source'));
        $this->assertSame([
            'old_v2_group' => 'atomic',
            'mapped_registry' => 'domain_registry',
            'reuse_status' => 'transform_required',
        ], data_get($payload, 'big5_result_page_v2.modules.3.blocks.0.source_authority'));
        $this->assertSame('transformed_old_v2_registry', data_get($payload, 'big5_result_page_v2.modules.4.blocks.0.content_source'));
        $this->assertSame('synergies', data_get($payload, 'big5_result_page_v2.modules.4.blocks.0.source_authority.old_v2_group'));
        $this->assertSame('facet_glossary', data_get($payload, 'big5_result_page_v2.modules.5.blocks.0.source_authority.old_v2_group'));
    }

    public function test_old_v2_direct_authority_without_transform_provenance_is_rejected(): void
    {
        $payload = $this->transformFixture('dry_run_old_v2_transform_required_input.json');
        data_set($payload, 'big5_result_page_v2.modules.3.blocks.0.registry_refs', ['old_v2_atomic:O.high']);
        data_set($payload, 'big5_result_page_v2.modules.3.blocks.0.source_authority', null);

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_03_trait_deep_dive.blocks.0 must not reference old_v2_atomic directly; use transformed_old_v2_registry with a mapped V2.0 registry', $errors);
        $this->assertContains('module_03_trait_deep_dive.blocks.0 transformed_old_v2_registry requires source_authority', $errors);
    }

    public function test_payload_does_not_expose_forbidden_public_or_share_score_fields(): void
    {
        $payload = $this->transformFixture('dry_run_old_v2_transform_required_input.json');

        $this->assertFalse($this->containsKeyRecursive($payload, 'user_confirmed_type'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'fixed_type'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'type_name'));
        foreach ((array) data_get($payload, 'big5_result_page_v2.modules') as $module) {
            foreach ((array) ($module['blocks'] ?? []) as $block) {
                if (($block['shareable'] ?? false) === true) {
                    $this->assertFalse($this->containsKeyRecursive((array) $block, 'raw_scores'));
                    $this->assertFalse($this->containsKeyRecursive((array) $block, 'domains'));
                    $this->assertFalse($this->containsKeyRecursive((array) $block, 'facets'));
                }
            }
        }
    }

    public function test_unavailable_modules_do_not_delegate_to_frontend_fallback(): void
    {
        $payload = $this->transformFixture('dry_run_canonical_input.json');

        foreach ((array) data_get($payload, 'big5_result_page_v2.modules') as $module) {
            foreach ((array) ($module['blocks'] ?? []) as $block) {
                $this->assertNotSame('frontend_fallback', $block['content_source'] ?? null);
                $this->assertNotSame('consumer_generated', $block['content_source'] ?? null);
                $this->assertNotSame('frontend_fallback', $block['fallback_policy'] ?? null);
                $this->assertNotSame('consumer_generated', $block['fallback_policy'] ?? null);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function transformFixture(string $filename): array
    {
        return app(BigFiveResultPageV2CompatibilityTransformer::class)->transform($this->loadFixture($filename));
    }

    /**
     * @return array<string,mixed>
     */
    private function loadFixture(string $filename): array
    {
        $path = base_path('tests/Fixtures/big5_result_page_v2/'.$filename);
        $json = file_get_contents($path);
        $this->assertIsString($json, "Fixture missing: {$filename}");
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, "Fixture invalid JSON: {$filename}");

        return $decoded;
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
}
