<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveOccupationCompanionLinksService;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerFirstWaveOccupationCompanionLinksServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_machine_safe_companion_links_for_a_first_wave_occupation(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $summary = app(CareerFirstWaveOccupationCompanionLinksService::class)->buildBySlug('accountants-and-auditors')?->toArray();

        $this->assertIsArray($summary);
        $this->assertSame('career_first_wave_companion_links', $summary['summary_kind']);
        $this->assertSame(CareerFirstWaveOccupationCompanionLinksService::SUMMARY_VERSION, $summary['summary_version']);
        $this->assertSame(CareerFirstWaveOccupationCompanionLinksService::SCOPE, $summary['scope']);
        $this->assertSame('occupation', $summary['subject_kind']);
        $this->assertSame('accountants-and-auditors', data_get($summary, 'subject_identity.canonical_slug'));
        $this->assertSame(3, data_get($summary, 'counts.total'));
        $this->assertSame(2, data_get($summary, 'counts.job_detail'));
        $this->assertSame(1, data_get($summary, 'counts.family_hub'));

        $links = collect($summary['companion_links']);
        $familyLink = $links->firstWhere('route_kind', 'career_family_hub');
        $jobLinks = $links->where('route_kind', 'career_job_detail')->values();

        $this->assertSame('/career/family/business-and-financial-37ec69bd', $familyLink['canonical_path']);
        $this->assertSame('business-and-financial-37ec69bd', $familyLink['canonical_slug']);
        $this->assertSame('family_hub_companion', $familyLink['link_reason_code']);

        $this->assertCount(2, $jobLinks);
        $this->assertSame(
            ['human-resources-specialists', 'project-management-specialists'],
            $jobLinks->pluck('canonical_slug')->sort()->values()->all()
        );
        $this->assertTrue($jobLinks->every(static fn (array $row): bool => ($row['link_reason_code'] ?? null) === 'same_family_job_companion'));
        $this->assertFalse($links->contains(static fn (array $row): bool => str_starts_with((string) ($row['canonical_path'] ?? ''), '/career/recommendations')));
        $this->assertFalse($links->contains(static fn (array $row): bool => str_starts_with((string) ($row['canonical_path'] ?? ''), '/career/search')));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'software-developers'));
    }

    public function test_it_excludes_self_and_undiscoverable_family_routes(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $blockedFamily = OccupationFamily::query()->create([
            'canonical_slug' => 'blocked-companion-family',
            'title_en' => 'Blocked Companion Family',
            'title_zh' => '受限 companion 家族',
        ]);

        $subject = Occupation::query()->where('canonical_slug', 'registered-nurses')->firstOrFail();
        $subject->update([
            'family_id' => $blockedFamily->id,
            'crosswalk_mode' => 'family_proxy',
        ]);

        $summary = app(CareerFirstWaveOccupationCompanionLinksService::class)->buildBySlug('registered-nurses')?->toArray();

        $this->assertIsArray($summary);

        $links = collect($summary['companion_links']);
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'registered-nurses'));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'blocked-companion-family'));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'software-developers'));
        $this->assertSame(0, data_get($summary, 'counts.family_hub'));
    }

    public function test_it_returns_null_for_unknown_or_out_of_scope_occupation_slugs(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'non-first-wave-occupation-companion',
        ]);

        $service = app(CareerFirstWaveOccupationCompanionLinksService::class);

        $this->assertNull($service->buildBySlug('unknown-occupation'));
        $this->assertNull($service->buildBySlug('non-first-wave-occupation-companion'));
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
