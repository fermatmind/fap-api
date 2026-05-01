<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerTrustFreshnessAuthorityService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerTrustFreshnessAuthorityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_first_wave_job_detail_only_internal_trust_freshness_authority(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $authority = app(CareerTrustFreshnessAuthorityService::class)->build()->toArray();
        $members = collect($authority['members'])->keyBy('canonical_slug');
        $registeredNurses = $members->get('registered-nurses');

        $this->assertSame('career_trust_freshness_authority', $authority['authority_kind']);
        $this->assertSame('career.trust_freshness.v1', $authority['authority_version']);
        $this->assertSame('career_first_wave_10', $authority['scope']);
        $this->assertCount(10, $authority['members']);

        $this->assertIsArray($registeredNurses);
        $this->assertSame('career_job_detail', $registeredNurses['member_kind']);
        $this->assertArrayHasKey('reviewer_status', $registeredNurses);
        $this->assertArrayHasKey('reviewed_at', $registeredNurses);
        $this->assertArrayHasKey('last_substantive_update_at', $registeredNurses);
        $this->assertArrayHasKey('next_review_due_at', $registeredNurses);
        $this->assertArrayHasKey('truth_last_reviewed_at', $registeredNurses);
        $this->assertArrayHasKey('review_due_known', $registeredNurses);
        $this->assertArrayHasKey('review_freshness_basis', $registeredNurses);
        $this->assertArrayHasKey('review_staleness_state', $registeredNurses);
        $this->assertArrayHasKey('signals', $registeredNurses);

        $this->assertCount(0, array_filter(
            $authority['members'],
            static fn (array $member): bool => ($member['member_kind'] ?? null) === 'career_family_hub'
        ));
        $this->assertArrayNotHasKey('compiled_at', $registeredNurses);
        $this->assertArrayNotHasKey('retrieved_at', $registeredNurses);
        $this->assertArrayNotHasKey('review_expired', $registeredNurses);
        $this->assertArrayNotHasKey('trust_expired', $registeredNurses);
    }

    public function test_it_marks_future_due_dates_as_review_scheduled(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $subject = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $subject->trustManifests()->orderByDesc('reviewed_at')->orderByDesc('created_at')->firstOrFail()->update([
            'reviewed_at' => now()->subDay(),
            'next_review_due_at' => now()->addDays(5),
        ]);

        $authority = app(CareerTrustFreshnessAuthorityService::class)->build()->toArray();
        $row = collect($authority['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertIsArray($row);
        $this->assertTrue($row['review_due_known']);
        $this->assertSame('review_scheduled', $row['review_staleness_state']);
        $this->assertSame('trust_manifest_next_review_due_at', $row['review_freshness_basis']);
    }

    public function test_it_uses_the_compiled_snapshot_trust_manifest_instead_of_newer_draft_rows(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $subject = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $compiledManifest = $subject->trustManifests()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('created_at')
            ->firstOrFail();
        $compiledManifest->update([
            'reviewed_at' => now()->subDay(),
            'next_review_due_at' => now()->subMinute(),
        ]);
        $subject->trustManifests()->create([
            'content_version' => 'draft-future-content',
            'data_version' => 'draft-future-data',
            'logic_version' => 'draft-future-logic',
            'locale_context' => [],
            'methodology' => [],
            'reviewer_status' => 'approved',
            'reviewed_at' => now(),
            'ai_assistance' => [],
            'quality' => [],
            'last_substantive_update_at' => now(),
            'next_review_due_at' => now()->addDays(30),
            'created_at' => now()->addMinute(),
        ]);

        $authority = app(CareerTrustFreshnessAuthorityService::class)->build()->toArray();
        $row = collect($authority['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertIsArray($row);
        $this->assertSame('review_due', $row['review_staleness_state']);
    }

    public function test_it_marks_past_or_current_due_dates_as_review_due(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $subject = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $subject->trustManifests()->orderByDesc('reviewed_at')->orderByDesc('created_at')->firstOrFail()->update([
            'reviewed_at' => now()->subDay(),
            'next_review_due_at' => now()->subMinute(),
        ]);

        $authority = app(CareerTrustFreshnessAuthorityService::class)->build()->toArray();
        $row = collect($authority['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertIsArray($row);
        $this->assertTrue($row['review_due_known']);
        $this->assertSame('review_due', $row['review_staleness_state']);
    }

    public function test_it_marks_missing_due_dates_as_unknown_due_date(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $authority = app(CareerTrustFreshnessAuthorityService::class)->build()->toArray();
        $row = collect($authority['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertIsArray($row);
        $this->assertFalse($row['review_due_known']);
        $this->assertSame('unknown_due_date', $row['review_staleness_state']);
    }

    public function test_it_marks_missing_review_timestamp_with_non_completed_status_as_review_unreviewed(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $subject = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $subject->trustManifests()->orderByDesc('reviewed_at')->orderByDesc('created_at')->firstOrFail()->update([
            'reviewer_status' => 'pending',
            'reviewed_at' => null,
            'next_review_due_at' => null,
        ]);

        $authority = app(CareerTrustFreshnessAuthorityService::class)->build()->toArray();
        $row = collect($authority['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertIsArray($row);
        $this->assertSame('review_unreviewed', $row['review_staleness_state']);
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
