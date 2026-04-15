<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveLaunchReadinessAuditService;
use App\Domain\Career\Publish\CareerFirstWaveLaunchReadinessAuditV2Service;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveLaunchReadinessAuditV2ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_trust_freshness_evidence_without_changing_counts(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $v1 = app(CareerFirstWaveLaunchReadinessAuditService::class)->build()->toArray();
        $v2 = app(CareerFirstWaveLaunchReadinessAuditV2Service::class)->build()->toArray();

        $this->assertSame('career_first_wave_launch_readiness_audit', $v2['summary_kind']);
        $this->assertSame(CareerFirstWaveLaunchReadinessAuditV2Service::SUMMARY_VERSION, $v2['summary_version']);
        $this->assertSame($v1['scope'], $v2['scope']);
        $this->assertSame($v1['counts'], $v2['counts']);

        $registeredNurses = collect($v2['members'])->keyBy('canonical_slug')->get('registered-nurses');

        $this->assertIsArray($registeredNurses);
        $this->assertIsArray($registeredNurses['trust_freshness']);
        $this->assertSame([
            'reviewed_at',
            'next_review_due_at',
            'review_due_known',
            'review_staleness_state',
            'review_freshness_basis',
        ], array_keys($registeredNurses['trust_freshness']));
        $this->assertContains(
            $registeredNurses['trust_freshness']['review_staleness_state'],
            ['unknown_due_date', 'review_scheduled', 'review_due', 'review_unreviewed']
        );
        $this->assertCount(0, array_filter(
            $v2['members'],
            static fn (array $member): bool => ($member['member_kind'] ?? null) === 'career_family_hub'
        ));
        $this->assertArrayNotHasKey('review_expired', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('trust_expired', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('review_stale', $registeredNurses['trust_freshness']);
    }

    public function test_it_preserves_candidate_and_blocked_classification_when_freshness_changes(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $candidate->update([
            'crosswalk_mode' => 'direct_match',
        ]);
        $candidate->trustManifests()->orderByDesc('reviewed_at')->orderByDesc('created_at')->firstOrFail()->update([
            'reviewed_at' => now()->subDay(),
            'next_review_due_at' => now()->subMinute(),
        ]);

        $v2 = app(CareerFirstWaveLaunchReadinessAuditV2Service::class)->build()->toArray();
        $row = collect($v2['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertSame(5, $v2['counts']['launch_ready']);
        $this->assertSame(1, $v2['counts']['candidate_review']);
        $this->assertSame(0, $v2['counts']['hold']);
        $this->assertSame(4, $v2['counts']['blocked']);

        $this->assertIsArray($row);
        $this->assertSame('candidate', $row['launch_tier']);
        $this->assertSame('review_due', data_get($row, 'trust_freshness.review_staleness_state'));
        $this->assertContains('not_publish_ready', $row['blockers']);
        $this->assertFalse(in_array('review_due', $row['blockers'], true));
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
