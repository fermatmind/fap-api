<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerFamilyHubBundleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFamilyHubBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_family_hub_with_publish_ready_visible_children_and_blocked_counts(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $bundle = app(CareerFamilyHubBundleBuilder::class)->buildBySlug('computer-and-information-technology');

        $this->assertNotNull($bundle);

        $payload = $bundle->toArray();
        $family = OccupationFamily::query()->where('canonical_slug', 'computer-and-information-technology')->firstOrFail();

        $this->assertSame('career_family_hub', $payload['bundle_kind']);
        $this->assertSame('career.protocol.family_hub.v1', $payload['bundle_version']);
        $this->assertSame($family->id, data_get($payload, 'family.family_uuid'));
        $this->assertSame('computer-and-information-technology', data_get($payload, 'family.canonical_slug'));
        $this->assertSame(1, data_get($payload, 'counts.visible_children_count'));
        $this->assertSame(1, data_get($payload, 'counts.publish_ready_count'));
        $this->assertSame(0, data_get($payload, 'counts.blocked_override_eligible_count'));
        $this->assertSame(0, data_get($payload, 'counts.blocked_not_safely_remediable_count'));
        $this->assertSame(0, data_get($payload, 'counts.blocked_total'));
        $this->assertCount(1, $payload['visible_children']);
        $this->assertSame('data-scientists', data_get($payload, 'visible_children.0.canonical_slug'));
        $this->assertSame('approved', data_get($payload, 'visible_children.0.trust_summary.reviewer_status'));
    }

    public function test_it_returns_an_empty_but_valid_bundle_when_a_family_has_no_visible_children(): void
    {
        OccupationFamily::query()->create([
            'canonical_slug' => 'empty-family',
            'title_en' => 'Empty Family',
            'title_zh' => '空家族',
        ]);

        $bundle = app(CareerFamilyHubBundleBuilder::class)->buildBySlug('empty-family');

        $this->assertNotNull($bundle);

        $payload = $bundle->toArray();

        $this->assertSame([], $payload['visible_children']);
        $this->assertSame(0, data_get($payload, 'counts.visible_children_count'));
        $this->assertSame(0, data_get($payload, 'counts.publish_ready_count'));
        $this->assertSame(0, data_get($payload, 'counts.blocked_override_eligible_count'));
        $this->assertSame(0, data_get($payload, 'counts.blocked_not_safely_remediable_count'));
        $this->assertSame(0, data_get($payload, 'counts.blocked_total'));
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
