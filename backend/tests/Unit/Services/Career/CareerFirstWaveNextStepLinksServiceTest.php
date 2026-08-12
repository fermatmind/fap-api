<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveNextStepLinksService;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\UsesPublishedCareerRuntimeProjection;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerFirstWaveNextStepLinksServiceTest extends TestCase
{
    use RefreshDatabase, UsesPublishedCareerRuntimeProjection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUsesPublishedCareerRuntimeProjection();
        Cache::flush();
    }

    public function test_it_builds_machine_safe_next_step_links_for_a_first_wave_occupation(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $summary = app(CareerFirstWaveNextStepLinksService::class)->buildBySlug('accountants-and-auditors')?->toArray();

        $this->assertIsArray($summary);
        $this->assertSame('career_first_wave_next_step_links', $summary['summary_kind']);
        $this->assertSame(CareerFirstWaveNextStepLinksService::SUMMARY_VERSION, $summary['summary_version']);
        $this->assertSame(CareerFirstWaveNextStepLinksService::SCOPE, $summary['scope']);
        $this->assertSame('occupation', $summary['subject_kind']);
        $this->assertSame('accountants-and-auditors', data_get($summary, 'subject_identity.canonical_slug'));
        $this->assertSame(3, data_get($summary, 'counts.total'));
        $this->assertSame(2, data_get($summary, 'counts.job_detail'));
        $this->assertSame(1, data_get($summary, 'counts.family_hub'));

        $links = collect($summary['next_step_links']);

        $familyLink = $links->firstWhere('route_kind', 'career_family_hub');
        $siblingLink = $links->firstWhere('route_kind', 'career_job_detail');

        $this->assertSame('/career/family/business-and-financial-37ec69bd', $familyLink['canonical_path']);
        $this->assertSame('business-and-financial-37ec69bd', $familyLink['canonical_slug']);
        $this->assertSame('family_hub_discoverable', $familyLink['link_reason_code']);

        $this->assertContains($siblingLink['canonical_slug'], ['human-resources-specialists', 'project-management-specialists']);
        $this->assertSame('same_family_sibling_discoverable', $siblingLink['link_reason_code']);
    }

    public function test_it_excludes_self_transition_targets_and_undiscoverable_routes(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $blockedFamily = OccupationFamily::query()->create([
            'canonical_slug' => 'blocked-next-step-family',
            'title_en' => 'Blocked Next Step Family',
            'title_zh' => '受限下一步家族',
        ]);

        $subject = Occupation::query()->where('canonical_slug', 'registered-nurses')->firstOrFail();
        $subject->update([
            'family_id' => $blockedFamily->id,
            'crosswalk_mode' => 'family_proxy',
        ]);

        $summary = app(CareerFirstWaveNextStepLinksService::class)->buildBySlug('registered-nurses')?->toArray();

        $this->assertIsArray($summary);

        $links = collect($summary['next_step_links']);

        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'registered-nurses'));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'blocked-next-step-family'));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'software-developers'));
        $this->assertSame(0, data_get($summary, 'counts.family_hub'));
    }

    public function test_it_returns_null_for_unknown_or_out_of_scope_occupation_slugs(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'non-first-wave-occupation',
        ]);

        $service = app(CareerFirstWaveNextStepLinksService::class);

        $this->assertNull($service->buildBySlug('unknown-occupation'));
        $this->assertNull($service->buildBySlug('non-first-wave-occupation'));
    }

    public function test_it_reuses_a_slug_and_locale_scoped_persistent_payload(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $first = app(CareerFirstWaveNextStepLinksService::class)
            ->buildBySlug('accountants-and-auditors', 'en')?->toArray();
        $this->assertIsArray($first);

        Occupation::query()
            ->where('canonical_slug', 'accountants-and-auditors')
            ->update(['canonical_title_en' => 'Changed after cache warm']);

        $second = app(CareerFirstWaveNextStepLinksService::class)
            ->buildBySlug('accountants-and-auditors', 'en-US')?->toArray();

        $this->assertSame($first, $second);
        $this->assertTrue(Cache::has(
            CareerFirstWaveNextStepLinksService::CACHE_KEY_PREFIX.':accountants-and-auditors:en:active'
        ));
    }

    public function test_it_negative_caches_unknown_slugs_per_locale(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $this->assertNull(app(CareerFirstWaveNextStepLinksService::class)->buildBySlug('unknown-occupation', 'en'));

        CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'unknown-occupation']);

        $this->assertNull(app(CareerFirstWaveNextStepLinksService::class)->buildBySlug('unknown-occupation', 'en'));
        $this->assertTrue(Cache::has(
            CareerFirstWaveNextStepLinksService::CACHE_KEY_PREFIX.':unknown-occupation:en:negative'
        ));
    }

    public function test_read_only_build_uses_cold_authority_without_writing_active_lkg_or_negative_cache(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        Cache::flush();

        $summary = app(CareerFirstWaveNextStepLinksService::class)
            ->buildBySlug('accountants-and-auditors', 'en', allowCacheWrites: false)?->toArray();

        $this->assertIsArray($summary);
        foreach (['active', 'lkg', 'negative'] as $suffix) {
            $this->assertFalse(Cache::has(
                CareerFirstWaveNextStepLinksService::CACHE_KEY_PREFIX.':accountants-and-auditors:en:'.$suffix
            ));
        }

        $this->assertNull(app(CareerFirstWaveNextStepLinksService::class)
            ->buildBySlug('unknown-occupation', 'en', allowCacheWrites: false));
        $this->assertFalse(Cache::has(
            CareerFirstWaveNextStepLinksService::CACHE_KEY_PREFIX.':unknown-occupation:en:negative'
        ));
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
