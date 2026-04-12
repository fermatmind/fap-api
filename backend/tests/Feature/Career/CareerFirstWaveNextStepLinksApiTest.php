<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerFirstWaveNextStepLinksService;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerFirstWaveNextStepLinksApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_a_first_wave_next_step_links_summary_for_supported_route_kinds_only(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/first-wave/jobs/accountants-and-auditors/next-step-links');

        $response->assertOk()
            ->assertJsonPath('summary_kind', 'career_first_wave_next_step_links')
            ->assertJsonPath('summary_version', CareerFirstWaveNextStepLinksService::SUMMARY_VERSION)
            ->assertJsonPath('scope', CareerFirstWaveNextStepLinksService::SCOPE)
            ->assertJsonPath('subject_kind', 'occupation')
            ->assertJsonPath('subject_identity.canonical_slug', 'accountants-and-auditors')
            ->assertJsonPath('counts.total', 3)
            ->assertJsonPath('counts.job_detail', 2)
            ->assertJsonPath('counts.family_hub', 1)
            ->assertJsonStructure([
                'summary_kind',
                'summary_version',
                'scope',
                'subject_kind',
                'subject_identity' => ['occupation_uuid', 'canonical_slug', 'canonical_title_en'],
                'counts' => ['total', 'job_detail', 'family_hub'],
                'next_step_links' => [[
                    'route_kind',
                    'canonical_path',
                    'canonical_slug',
                    'link_reason_code',
                ]],
            ])
            ->assertJsonMissingPath('recommended_action')
            ->assertJsonMissingPath('why_this_path');

        $links = collect($response->json('next_step_links'));

        $this->assertSame(
            ['career_family_hub', 'career_job_detail'],
            $links->pluck('route_kind')->unique()->sort()->values()->all()
        );
        $this->assertFalse($links->contains(static fn (array $row): bool => str_starts_with((string) ($row['canonical_path'] ?? ''), '/career/recommendations')));
        $this->assertFalse($links->contains(static fn (array $row): bool => (string) ($row['canonical_path'] ?? '') === '/career/jobs/accountants-and-auditors'));
    }

    public function test_it_returns_not_found_for_unknown_or_out_of_scope_occupation_slugs(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'non-first-wave-occupation-api',
        ]);

        $this->getJson('/api/v0.5/career/first-wave/jobs/unknown-occupation/next-step-links')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/career/first-wave/jobs/non-first-wave-occupation-api/next-step-links')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_it_excludes_undiscoverable_family_hubs_from_the_next_step_counts(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $blockedFamily = OccupationFamily::query()->create([
            'canonical_slug' => 'blocked-next-step-family-api',
            'title_en' => 'Blocked Next Step Family API',
            'title_zh' => '受限下一步家族 API',
        ]);

        $subject = Occupation::query()->where('canonical_slug', 'registered-nurses')->firstOrFail();
        $subject->update([
            'family_id' => $blockedFamily->id,
            'crosswalk_mode' => 'family_proxy',
        ]);

        $response = $this->getJson('/api/v0.5/career/first-wave/jobs/registered-nurses/next-step-links')
            ->assertOk()
            ->assertJsonPath('counts.family_hub', 0);

        $links = collect($response->json('next_step_links'));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_family_hub'));
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
