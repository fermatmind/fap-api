<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2CouplingResolver;
use Tests\TestCase;

final class BigFiveResultPageV2CouplingSelectorTest extends TestCase
{
    private BigFiveV2CouplingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new BigFiveV2CouplingResolver();
    }

    public function test_canonical_twelve_coupling_keys_are_resolvable(): void
    {
        $this->assertSame([
            'a_low_x_n_high',
            'a_mid_x_n_high',
            'c_high_x_n_high',
            'c_low_x_n_high',
            'e_high_x_a_high',
            'e_high_x_n_high',
            'e_low_x_c_low',
            'n_high_x_e_low',
            'n_high_x_o_mid_high',
            'o_high_x_a_low',
            'o_high_x_c_low',
            'o_low_x_c_high',
        ], $this->resolver->canonicalKeys());

        foreach ($this->resolver->canonicalKeys() as $couplingKey) {
            $resolution = $this->resolver->resolve($couplingKey, 'result_page', 'coupling_core_explanation');

            $this->assertSame('canonical_exact', $resolution->decisionType, $couplingKey);
            $this->assertSame($couplingKey, $resolution->resolvedKey);
            $this->assertSame('B5-CONTENT-2', $resolution->sourcePackage);
            $this->assertTrue($resolution->selectable);
            $this->assertNull($resolution->suppressionReason);
        }
    }

    public function test_approved_aliases_resolve_to_canonical_coupling_keys(): void
    {
        $this->assertSame([
            'c_low_x_e_low' => 'e_low_x_c_low',
            'e_low_x_n_high' => 'n_high_x_e_low',
            'o_mid_x_n_high' => 'n_high_x_o_mid_high',
        ], $this->resolver->approvedAliasMap());

        foreach ($this->resolver->approvedAliasMap() as $source => $target) {
            $resolution = $this->resolver->resolve($source, 'result_page', 'coupling_benefit_cost');

            $this->assertSame('approved_alias', $resolution->decisionType, $source);
            $this->assertSame($target, $resolution->resolvedKey);
            $this->assertSame('B5-CONTENT-2', $resolution->sourcePackage);
            $this->assertTrue($resolution->selectable);
            $this->assertNull($resolution->suppressionReason);
        }
    }

    public function test_supplemental_thirty_eight_coupling_keys_are_resolvable_only_from_supplemental_inventory(): void
    {
        $this->assertCount(38, $this->resolver->supplementalKeys());

        foreach ($this->resolver->supplementalKeys() as $couplingKey) {
            $resolution = $this->resolver->resolve($couplingKey, 'result_page', 'coupling_common_misread');

            $this->assertSame('supplemental_exact', $resolution->decisionType, $couplingKey);
            $this->assertSame($couplingKey, $resolution->resolvedKey);
            $this->assertSame('B5-CONTENT-2B', $resolution->sourcePackage);
            $this->assertTrue($resolution->selectable);
            $this->assertNull($resolution->suppressionReason);
        }
    }

    public function test_unknown_key_and_missing_role_are_suppressed(): void
    {
        $unknown = $this->resolver->resolve('x_high_x_y_low', 'result_page');

        $this->assertSame('unknown', $unknown->decisionType);
        $this->assertFalse($unknown->selectable);
        $this->assertNull($unknown->resolvedKey);
        $this->assertSame('unknown_coupling_key', $unknown->suppressionReason);

        $missingRole = $this->resolver->resolve('a_high_x_n_high', 'result_page', 'coupling_missing_role');

        $this->assertSame('unknown', $missingRole->decisionType);
        $this->assertFalse($missingRole->selectable);
        $this->assertNull($missingRole->resolvedKey);
        $this->assertSame('asset_role_missing', $missingRole->suppressionReason);
    }

    public function test_unsafe_surfaces_are_suppressed_for_routing4(): void
    {
        foreach (['pdf', 'share', 'share_card', 'history', 'compare'] as $surface) {
            $resolution = $this->resolver->resolve('n_high_x_e_low', $surface, 'coupling_core_explanation');

            $this->assertSame('unsafe_surface_suppressed', $resolution->decisionType, $surface);
            $this->assertFalse($resolution->selectable, $surface);
            $this->assertNull($resolution->resolvedKey, $surface);
            $this->assertSame('surface_not_enabled_for_routing4', $resolution->suppressionReason, $surface);
        }
    }

    public function test_resolution_output_is_refs_only_without_body_copy_or_public_payload(): void
    {
        $encoded = json_encode(
            $this->resolver->resolve('a_high_x_n_high', 'result_page')->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        foreach ([
            'body_zh',
            'summary_zh',
            'public_payload',
            'internal_metadata',
            'source_reference',
            'selector_basis',
            'frontend_fallback',
            '[object Object]',
        ] as $forbiddenTerm) {
            $this->assertStringNotContainsString($forbiddenTerm, $encoded);
        }
    }
}
