<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform11\Platform11HCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11ICloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11JCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11KCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11LCloseoutBuilder;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12StartGate;
use Tests\TestCase;

final class SeoPlatform1200StartGateTest extends TestCase
{
    public function test_closed_platform_11_allows_foundation_but_not_runtime_activation(): void
    {
        $receipt = $this->app->make(Platform12StartGate::class)->build(
            $this->platform11Closeout(),
            str_repeat('c', 40),
            str_repeat('d', 64),
            ['state' => 'NO_OBSERVATIONS', 'total_minutes' => null, 'observation_count' => 0],
        );

        $this->assertSame('READY_FOR_FOUNDATION_BUILD', $receipt['foundation_state']);
        $this->assertTrue($receipt['foundation_build_allowed']);
        $this->assertFalse($receipt['runtime_activation_allowed']);
        $this->assertSame('NIGHTLY_AND_12A_08_HOLD', $receipt['runtime_activation_state']);
        $this->assertSame('NO_OBSERVATIONS', $receipt['measurement_baseline_state']);
        $this->assertSame('CLOSED', $receipt['SEO-PLATFORM-11']);
        $this->assertSame('FOUNDATION_BUILD_ALLOWED', $receipt['SEO-PLATFORM-12']);
        $this->assertFalse($receipt['write_guards']['post12_agent_write_enabled']);
        $this->assertFalse($receipt['write_guards']['scheduler_enabled']);
        $this->assertSame('artifact_only', $receipt['write_guards']['L2']);
        foreach ($receipt['dependency_refs'] as $ref) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $ref['hash']);
        }
        $this->assertSame(
            $this->app->make(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash'),
            $receipt['receipt_hash'],
        );
    }

    public function test_nightly_green_does_not_authorize_12a_08_or_runtime_writes(): void
    {
        $receipt = $this->app->make(Platform12StartGate::class)->build(
            $this->platform11Closeout(),
            str_repeat('c', 40),
            str_repeat('d', 64),
            ['state' => 'OBSERVED', 'total_minutes' => 45, 'observation_count' => 3],
            'GREEN',
        );

        $this->assertTrue($receipt['foundation_build_allowed']);
        $this->assertFalse($receipt['runtime_activation_allowed']);
        $this->assertSame('12A_08_NOT_AUTHORIZED', $receipt['runtime_activation_state']);
        $this->assertSame('OBSERVED', $receipt['measurement_baseline_state']);
    }

    public function test_tampered_platform_11_receipt_fails_closed(): void
    {
        $platform11 = $this->platform11Closeout();
        $platform11['ready_for_12'] = false;
        $receipt = $this->app->make(Platform12StartGate::class)->build(
            $platform11,
            str_repeat('c', 40),
            str_repeat('d', 64),
            ['state' => 'NO_OBSERVATIONS', 'total_minutes' => null, 'observation_count' => 0],
        );

        $this->assertFalse($receipt['foundation_build_allowed']);
        $this->assertFalse($receipt['runtime_activation_allowed']);
        $this->assertSame('START_HOLD', $receipt['foundation_state']);
    }

    public function test_enabled_runtime_guard_blocks_foundation_receipt(): void
    {
        config()->set('seo_council.scheduler_enabled', true);
        $receipt = $this->app->make(Platform12StartGate::class)->build(
            $this->platform11Closeout(),
            str_repeat('c', 40),
            str_repeat('d', 64),
            ['state' => 'NO_OBSERVATIONS', 'total_minutes' => null, 'observation_count' => 0],
        );

        $this->assertFalse($receipt['foundation_build_allowed']);
        $this->assertTrue($receipt['write_guards']['scheduler_enabled']);
    }

    public function test_start_receipt_schema_is_closed_and_hash_addressed(): void
    {
        $schema = $this->app->make(Platform12ContractRegistry::class)->startReceiptSchema();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame('seo.platform12_start_receipt.v1', $schema['schema_id']);
        $this->assertSame(
            $this->app->make(SeoRegistryHasher::class)->hashWithout($schema, 'schema_hash'),
            $schema['schema_hash'],
        );
    }

    /** @return array<string, mixed> */
    private function platform11Closeout(): array
    {
        $sha = str_repeat('c', 40);
        $h = $this->app->make(Platform11HCloseoutBuilder::class)->build($sha, 'ci_candidate');
        $i = $this->app->make(Platform11ICloseoutBuilder::class)->build($sha, 'ci_candidate', $h);
        $j = $this->app->make(Platform11JCloseoutBuilder::class)->build($sha, 'ci_candidate', $i);
        $k = $this->app->make(Platform11KCloseoutBuilder::class)->build($sha, 'ci_candidate', $j);
        $k['environment'] = 'production_runtime';
        $k['closeout_state'] = 'CLOSED';
        $k['dependency_status'] = 'READY';
        $k['SEO-PLATFORM-11K'] = 'CLOSED';
        $k['ready_for_11L'] = true;

        return $this->app->make(Platform11LCloseoutBuilder::class)->build(
            $sha,
            'production_runtime',
            $h,
            $i,
            $j,
            $k,
        );
    }
}
