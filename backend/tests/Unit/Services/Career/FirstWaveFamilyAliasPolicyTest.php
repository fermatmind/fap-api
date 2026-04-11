<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Import\FirstWaveFamilyAliasPolicy;
use App\Models\CareerImportRun;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FirstWaveFamilyAliasPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_curated_family_target_aliases_for_supported_families(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'computer-and-information-technology',
            'title_en' => 'Computer and Information Technology',
            'title_zh' => '计算机与信息技术',
        ]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b25-family-policy',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $resolved = app(FirstWaveFamilyAliasPolicy::class)->resolveFamilyAliasPayloads($family, $importRun);

        $this->assertTrue($resolved['in_scope']);
        $this->assertContains('tech', $resolved['blocked_aliases']);
        $this->assertSame(
            [
                'computer and information technology careers',
                'information technology careers',
                '信息技术职业',
            ],
            array_values(array_map(static fn (array $payload): string => (string) $payload['normalized'], $resolved['alias_payloads']))
        );
        $this->assertSame(
            ['family', 'family', 'family'],
            array_values(array_map(static fn (array $payload): string => (string) $payload['target_kind'], $resolved['alias_payloads']))
        );
    }

    public function test_it_does_not_promote_out_of_scope_families(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'custom-platform-roles',
            'title_en' => 'Custom Platform Roles',
            'title_zh' => '自定义平台岗位',
        ]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b25-family-policy-custom',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $resolved = app(FirstWaveFamilyAliasPolicy::class)->resolveFamilyAliasPayloads($family, $importRun);

        $this->assertFalse($resolved['in_scope']);
        $this->assertSame([], $resolved['alias_payloads']);
        $this->assertSame([], $resolved['blocked_aliases']);
    }
}
