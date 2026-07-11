<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFive124RuntimeContentIntegrityRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_state_seed_repairs_exactly_twenty_two_assets_and_preserves_114_canonical_topology(): void
    {
        $base = '../generated/big-five-124-publish-import-dryrun/big_five_124_merged_v1_seed.json';
        $promotion = '../generated/big-five-114-indexability-publish-gate/big_five_93_indexability_promotion_v1_seed.json';
        $repair = '../generated/big-five-124-runtime-content-integrity-repair/big_five_22_runtime_integrity_patch_v1_seed.json';

        $this->artisan('personality-public-assets:import', ['--source' => $base, '--allow-indexable' => true, '--write' => true])
            ->expectsOutputToContain('assets_found=124')
            ->expectsOutputToContain('errors_count=0')
            ->assertExitCode(0);
        $this->artisan('personality-public-assets:import', ['--source' => $promotion, '--allow-indexable' => true, '--write' => true])
            ->expectsOutputToContain('assets_found=93')
            ->expectsOutputToContain('errors_count=0')
            ->assertExitCode(0);

        $this->artisan('personality-public-assets:import', ['--source' => $repair, '--allow-indexable' => true])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('assets_found=22')
            ->expectsOutputToContain('valid_count=22')
            ->expectsOutputToContain('will_update=22')
            ->expectsOutputToContain('will_skip=0')
            ->expectsOutputToContain('indexable_count=22')
            ->expectsOutputToContain('errors_count=0')
            ->assertExitCode(0);

        $this->artisan('personality-public-assets:import', ['--source' => $repair, '--allow-indexable' => true, '--write' => true])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('will_update=22')
            ->expectsOutputToContain('will_skip=0')
            ->expectsOutputToContain('discoverability_cache_keys_flushed=')
            ->assertExitCode(0);

        $this->assertSame(124, PersonalityPublicContentAsset::query()->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)->count());
        $this->assertSame(114, PersonalityPublicContentAsset::query()->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)->where('index_eligible', true)->count());

        $repaired = PersonalityPublicContentAsset::query()
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('locale', 'zh-CN')
            ->whereIn('entity_type', [PersonalityPublicContentAsset::ENTITY_DOMAIN, PersonalityPublicContentAsset::ENTITY_POLARITY])
            ->where('index_eligible', true)
            ->get();
        $this->assertCount(20, $repaired);
        foreach ($repaired as $asset) {
            $this->assertGreaterThanOrEqual(7, count($asset->internal_links_json ?? []));
            $this->assertNotEmpty(data_get($asset->hreflang_json, 'en'));
            $this->assertNotEmpty(data_get($asset->hreflang_json, 'zh-CN'));
        }

        $hubs = PersonalityPublicContentAsset::query()
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_HUB)
            ->get();
        $this->assertCount(2, $hubs);
        foreach ($hubs as $hub) {
            $this->assertGreaterThanOrEqual(7, count($hub->internal_links_json ?? []));
        }
    }
}
