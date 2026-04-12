<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveNextStepLinksService;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerFirstWaveNextStepLinksServiceTest extends TestCase
{
    use RefreshDatabase;

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
