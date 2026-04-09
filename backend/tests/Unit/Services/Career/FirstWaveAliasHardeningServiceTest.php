<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Import\FirstWaveAliasHardeningService;
use App\Models\CareerImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class FirstWaveAliasHardeningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_returns_approved_first_wave_aliases(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'software-developers',
            'crosswalk_mode' => 'exact',
        ]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b8-software-developers',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $resolved = app(FirstWaveAliasHardeningService::class)->resolveAliasPayloads(
            'software-developers',
            $chain['occupation'],
            $chain['family'],
            $importRun,
        );

        $this->assertTrue($resolved['in_scope']);
        $this->assertContains('程序员', $resolved['blocked_aliases']);
        $this->assertSame(
            ['software developer', 'software developers', 'application developer', '软件开发工程师', '软件工程师'],
            array_values(array_map(static fn (array $payload): string => (string) $payload['normalized'], $resolved['alias_payloads']))
        );
    }

    public function test_it_returns_no_alias_payloads_for_first_wave_entries_without_approved_alias_rows(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'marketing-managers',
            'crosswalk_mode' => 'exact',
        ]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b8-marketing-managers',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $resolved = app(FirstWaveAliasHardeningService::class)->resolveAliasPayloads(
            'marketing-managers',
            $chain['occupation'],
            $chain['family'],
            $importRun,
        );

        $this->assertTrue($resolved['in_scope']);
        $this->assertSame([], $resolved['alias_payloads']);
        $this->assertSame([], $resolved['blocked_aliases']);
    }

    public function test_it_leaves_non_first_wave_occupations_out_of_scope(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'cloud-platform-architect',
            'crosswalk_mode' => 'exact',
        ]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b8-cloud-platform-architect',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $resolved = app(FirstWaveAliasHardeningService::class)->resolveAliasPayloads(
            'cloud-platform-architect',
            $chain['occupation'],
            $chain['family'],
            $importRun,
        );

        $this->assertFalse($resolved['in_scope']);
        $this->assertSame([], $resolved['alias_payloads']);
    }
}
