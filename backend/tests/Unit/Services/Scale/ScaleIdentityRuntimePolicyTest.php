<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scale;

use App\Services\Scale\ScaleIdentityRuntimePolicy;
use Tests\TestCase;

final class ScaleIdentityRuntimePolicyTest extends TestCase
{
    public function test_defaults_map_to_legacy_runtime_modes(): void
    {
        config()->set('scale_identity.write_mode', 'legacy');
        config()->set('scale_identity.read_mode', 'legacy');
        config()->set('scale_identity.api_response_scale_code_mode', 'legacy');
        config()->set('scale_identity.accept_legacy_scale_code', true);
        config()->set('scale_identity.allow_demo_scales', true);

        $policy = app(ScaleIdentityRuntimePolicy::class);

        $this->assertSame('legacy', $policy->writeMode());
        $this->assertSame('legacy', $policy->readMode());
        $this->assertSame('legacy', $policy->apiResponseScaleCodeMode());
        $this->assertTrue($policy->acceptsLegacyScaleCode());
        $this->assertTrue($policy->allowsDemoScales());
        $this->assertFalse($policy->shouldWriteScaleIdentityColumns());
        $this->assertFalse($policy->shouldUseV2PrimaryScaleCode());
    }

    public function test_dual_write_mode_enables_identity_column_writes(): void
    {
        config()->set('scale_identity.write_mode', 'dual');

        $policy = app(ScaleIdentityRuntimePolicy::class);

        $this->assertSame('dual', $policy->writeMode());
        $this->assertTrue($policy->shouldWriteScaleIdentityColumns());
    }

    public function test_v2_response_mode_uses_v2_primary_scale_code(): void
    {
        config()->set('scale_identity.api_response_scale_code_mode', 'v2');

        $policy = app(ScaleIdentityRuntimePolicy::class);

        $this->assertSame('v2', $policy->apiResponseScaleCodeMode());
        $this->assertTrue($policy->shouldUseV2PrimaryScaleCode());
    }

    public function test_boolean_switches_reflect_legacy_acceptance_and_demo_access(): void
    {
        config()->set('scale_identity.accept_legacy_scale_code', false);
        config()->set('scale_identity.allow_demo_scales', false);

        $policy = app(ScaleIdentityRuntimePolicy::class);

        $this->assertFalse($policy->acceptsLegacyScaleCode());
        $this->assertFalse($policy->allowsDemoScales());
    }

    public function test_invalid_modes_fall_back_to_legacy(): void
    {
        config()->set('scale_identity.write_mode', 'unexpected');
        config()->set('scale_identity.read_mode', 'unexpected');
        config()->set('scale_identity.api_response_scale_code_mode', 'unexpected');

        $policy = app(ScaleIdentityRuntimePolicy::class);

        $this->assertSame('legacy', $policy->writeMode());
        $this->assertSame('legacy', $policy->readMode());
        $this->assertSame('legacy', $policy->apiResponseScaleCodeMode());
    }
}
