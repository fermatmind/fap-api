<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\Domain\Career\Publish\CareerRolloutReportAuthoritySigner;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerFullReleaseLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/private/career_canonical_rollout_batch_executions'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/career_canonical_rollout_batch_executions'));

        parent::tearDown();
    }

    public function test_it_builds_internal_full_342_release_ledger_with_expected_scope_and_count_boundaries(): void
    {
        $ledger = app(CareerFullReleaseLedgerService::class)->build()->toArray();

        $this->assertSame('career_full_release_ledger', $ledger['ledger_kind'] ?? null);
        $this->assertSame('career.release_ledger.full_342.v1', $ledger['ledger_version'] ?? null);
        $this->assertSame('career_all_342', $ledger['scope'] ?? null);

        $this->assertSame(342, (int) data_get($ledger, 'counts.tracking_counts.expected_total_occupations'));
        $this->assertSame(342, (int) data_get($ledger, 'counts.tracking_counts.tracked_total_occupations'));
        $this->assertSame(0, (int) data_get($ledger, 'counts.tracking_counts.missing_occupations'));
        $this->assertTrue((bool) data_get($ledger, 'counts.tracking_counts.tracking_complete'));
        $this->assertIsBool(data_get($ledger, 'counts.tracking_counts.first_wave_audit_available'));

        $this->assertCount(342, (array) ($ledger['members'] ?? []));
        $this->assertArrayHasKey('public_detail_indexable', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('public_detail_conservative', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('explorer_only', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('family_handoff', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('review_needed', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('blocked', data_get($ledger, 'counts.release_counts', []));

        $this->assertNotSame(
            (int) data_get($ledger, 'counts.tracking_counts.tracked_total_occupations'),
            (int) data_get($ledger, 'counts.release_counts.public_detail_indexable', 0)
            + (int) data_get($ledger, 'counts.release_counts.public_detail_conservative', 0)
        );

        $this->assertSame(
            (int) data_get($ledger, 'counts.tracking_counts.tracked_total_occupations'),
            array_sum((array) data_get($ledger, 'counts.release_counts', []))
        );
    }

    public function test_it_keeps_family_handoff_separate_from_blocked_and_forbids_weak_truth_fields(): void
    {
        $ledger = app(CareerFullReleaseLedgerService::class)->build()->toArray();
        $members = collect((array) ($ledger['members'] ?? []));

        $familyMembers = $members->where('release_cohort', 'family_handoff')->values();
        $this->assertNotEmpty($familyMembers);

        foreach ($familyMembers as $member) {
            $this->assertNotSame('blocked', $member['release_cohort'] ?? null);
        }

        $sample = (array) $members->first();
        $this->assertArrayNotHasKey('demand_signal', $sample);
        $this->assertArrayNotHasKey('novelty_score', $sample);
        $this->assertArrayNotHasKey('canonical_conflict', $sample);
    }

    public function test_explicit_rollout_batch_slugs_can_override_tracked_review_handoff_for_current_batch_only(): void
    {
        $service = app(CareerFullReleaseLedgerService::class);
        $baseLedger = $service->build()->toArray();
        $baseMember = collect((array) ($baseLedger['members'] ?? []))
            ->first(static fn (array $member): bool => in_array($member['release_cohort'] ?? '', ['review_needed', 'family_handoff'], true));

        $this->assertIsArray($baseMember);
        $slug = (string) $baseMember['canonical_slug'];

        $this->materializeIndexedOccupation($slug);

        $strictLedger = $service->build()->toArray();
        $strictMember = collect((array) ($strictLedger['members'] ?? []))
            ->firstWhere('canonical_slug', $slug);

        $this->assertContains($strictMember['release_cohort'] ?? null, ['review_needed', 'family_handoff']);
        $this->assertArrayNotHasKey('explicit_rollout_batch', (array) ($strictMember['evidence_refs'] ?? []));

        $batchLedger = $service->build([$slug], trustedRolloutAuthority: true)->toArray();
        $batchMember = collect((array) ($batchLedger['members'] ?? []))
            ->firstWhere('canonical_slug', $slug);

        $this->assertSame('public_detail_indexable', $batchMember['release_cohort'] ?? null);
        $this->assertSame('indexed', $batchMember['current_index_state'] ?? null);
        $this->assertSame('indexable', $batchMember['public_index_state'] ?? null);
        $this->assertSame([], $batchMember['blocker_reasons'] ?? null);
        $this->assertSame(
            'current_explicit_batch_only',
            data_get($batchMember, 'evidence_refs.explicit_rollout_batch.scope')
        );
    }

    public function test_caller_supplied_rollout_batch_slugs_are_ignored_without_trusted_authority(): void
    {
        $service = app(CareerFullReleaseLedgerService::class);
        $baseLedger = $service->build()->toArray();
        $baseMember = collect((array) ($baseLedger['members'] ?? []))
            ->first(static fn (array $member): bool => in_array($member['release_cohort'] ?? '', ['review_needed', 'family_handoff'], true));

        $this->assertIsArray($baseMember);
        $slug = (string) $baseMember['canonical_slug'];

        $this->materializeIndexedOccupation($slug);

        $ledger = $service->build([$slug])->toArray();
        $member = collect((array) ($ledger['members'] ?? []))
            ->firstWhere('canonical_slug', $slug);

        $this->assertIsArray($member);
        $this->assertContains($member['release_cohort'] ?? null, ['review_needed', 'family_handoff']);
        $this->assertArrayNotHasKey('explicit_rollout_batch', (array) ($member['evidence_refs'] ?? []));
    }

    public function test_explicit_rollout_batch_candidate_state_projects_as_conservative_candidate_for_tracked_members(): void
    {
        $service = app(CareerFullReleaseLedgerService::class);
        $baseLedger = $service->build()->toArray();
        $baseMember = collect((array) ($baseLedger['members'] ?? []))
            ->first(static fn (array $member): bool => in_array($member['release_cohort'] ?? '', ['review_needed', 'family_handoff'], true));

        $this->assertIsArray($baseMember);
        $slug = (string) $baseMember['canonical_slug'];

        $this->materializeIndexedOccupation($slug, 'promotion_candidate');

        $batchLedger = $service->build([$slug], trustedRolloutAuthority: true)->toArray();
        $batchMember = collect((array) ($batchLedger['members'] ?? []))
            ->firstWhere('canonical_slug', $slug);

        $this->assertSame('public_detail_conservative', $batchMember['release_cohort'] ?? null);
        $this->assertSame('promotion_candidate', $batchMember['current_index_state'] ?? null);
        $this->assertSame('trust_limited', $batchMember['public_index_state'] ?? null);
        $this->assertSame(
            'current_explicit_batch_only',
            data_get($batchMember, 'evidence_refs.explicit_rollout_batch.scope')
        );
    }

    public function test_explicit_rollout_batch_indexed_tracked_members_project_as_published(): void
    {
        $ledgerService = app(CareerFullReleaseLedgerService::class);
        $baseLedger = $ledgerService->build()->toArray();
        $baseMember = collect((array) ($baseLedger['members'] ?? []))
            ->first(static fn (array $member): bool => in_array($member['release_cohort'] ?? '', ['review_needed', 'family_handoff'], true));

        $this->assertIsArray($baseMember);
        $slug = (string) $baseMember['canonical_slug'];

        $this->materializeIndexedOccupation($slug);

        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray(
            $ledgerService->build([$slug], trustedRolloutAuthority: true)->toArray()
        );
        $rows = array_values(array_filter(
            (array) ($projection['items'] ?? []),
            static fn (array $item): bool => ($item['slug'] ?? null) === $slug,
        ));

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED, $row['runtime_publish_state']);
            $this->assertTrue($row['detail_route_enabled']);
            $this->assertTrue($row['release_gate_pass']);
        }
    }

    public function test_stale_first_wave_blocked_override_member_stays_blocked_without_explicit_batch_authority(): void
    {
        $slug = 'financial-analysts';
        $this->materializeIndexedOccupation($slug);

        $ledger = app(CareerFullReleaseLedgerService::class)->build()->toArray();
        $member = collect((array) ($ledger['members'] ?? []))->firstWhere('canonical_slug', $slug);

        $this->assertIsArray($member);
        $this->assertSame('blocked', $member['release_cohort'] ?? null);
        $this->assertContains('blocked_governance', $member['blocker_reasons'] ?? []);
        $this->assertContains('first_wave_readiness_blocked_override_eligible', $member['blocker_reasons'] ?? []);
        $this->assertArrayNotHasKey('explicit_rollout_batch', (array) ($member['evidence_refs'] ?? []));
    }

    public function test_explicit_rollout_batch_authority_overrides_stale_blocked_override_for_indexed_member(): void
    {
        $slug = 'financial-analysts';
        $this->materializeIndexedOccupation($slug);

        $ledger = app(CareerFullReleaseLedgerService::class)->build([$slug], trustedRolloutAuthority: true)->toArray();
        $member = collect((array) ($ledger['members'] ?? []))->firstWhere('canonical_slug', $slug);

        $this->assertIsArray($member);
        $this->assertSame('public_detail_indexable', $member['release_cohort'] ?? null);
        $this->assertSame('indexed', $member['current_index_state'] ?? null);
        $this->assertSame('indexable', $member['public_index_state'] ?? null);
        $this->assertSame([], $member['blocker_reasons'] ?? null);
        $this->assertSame(
            'current_explicit_batch_only',
            data_get($member, 'evidence_refs.explicit_rollout_batch.scope')
        );
    }

    public function test_explicit_rollout_batch_authority_keeps_stale_blocked_override_candidate_conservative(): void
    {
        $slug = 'financial-analysts';
        $this->materializeIndexedOccupation($slug, 'promotion_candidate');

        $ledger = app(CareerFullReleaseLedgerService::class)->build([$slug], trustedRolloutAuthority: true)->toArray();
        $member = collect((array) ($ledger['members'] ?? []))->firstWhere('canonical_slug', $slug);

        $this->assertIsArray($member);
        $this->assertSame('public_detail_conservative', $member['release_cohort'] ?? null);
        $this->assertSame('promotion_candidate', $member['current_index_state'] ?? null);
        $this->assertSame('trust_limited', $member['public_index_state'] ?? null);
        $this->assertContains('public_index_not_indexable', $member['blocker_reasons'] ?? []);
        $this->assertNotContains('blocked_governance', $member['blocker_reasons'] ?? []);
        $this->assertNotContains('first_wave_readiness_blocked_override_eligible', $member['blocker_reasons'] ?? []);
    }

    public function test_verified_rollout_batch_execution_is_included_in_default_projection_authority(): void
    {
        $baseLedger = app(CareerFullReleaseLedgerService::class)->build()->toArray();
        $baseMember = collect((array) ($baseLedger['members'] ?? []))
            ->first(static fn (array $member): bool => in_array($member['release_cohort'] ?? '', ['review_needed', 'family_handoff'], true));

        $this->assertIsArray($baseMember);
        $slug = (string) $baseMember['canonical_slug'];

        $this->materializeIndexedOccupation($slug);
        $this->writeRolloutExecutionReport('verified-batch.json', [
            'status' => 'promoted_success',
            'batch_id' => 'career_80_delta_canonical_001',
            'promoted_slugs' => [$slug],
            'promoted_locale_rows' => 2,
            'dry_run' => false,
            'writes_database' => true,
            'write_verified' => true,
            'persistence_check' => [
                'expected' => 2,
                'found_published' => 2,
                'not_published_count' => 0,
            ],
            'post_promotion_validation' => [
                'status' => 'pass',
            ],
            'release_gate' => [
                'release_gate_pass_count' => 2,
                'release_gate_blocked_count' => 0,
            ],
            'rollback_required' => false,
            'quarantine_required' => false,
        ], sign: true);

        $projected = app(CareerFullReleaseLedgerProjectionService::class)->build();
        $ledger = $projected[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? [];
        $member = collect((array) ($ledger['members'] ?? []))->firstWhere('canonical_slug', $slug);

        $this->assertIsArray($member);
        $this->assertSame('public_detail_indexable', $member['release_cohort'] ?? null);
        $this->assertSame(
            'current_explicit_batch_only',
            data_get($member, 'evidence_refs.explicit_rollout_batch.scope')
        );

        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($ledger);
        $rows = array_values(array_filter(
            (array) ($projection['items'] ?? []),
            static fn (array $item): bool => ($item['slug'] ?? null) === $slug,
        ));

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED, $row['runtime_publish_state']);
            $this->assertTrue($row['detail_route_enabled']);
            $this->assertTrue($row['release_gate_pass']);
        }
    }

    public function test_unverified_rollout_batch_execution_is_ignored_by_default_projection_authority(): void
    {
        $baseLedger = app(CareerFullReleaseLedgerService::class)->build()->toArray();
        $baseMember = collect((array) ($baseLedger['members'] ?? []))
            ->first(static fn (array $member): bool => in_array($member['release_cohort'] ?? '', ['review_needed', 'family_handoff'], true));

        $this->assertIsArray($baseMember);
        $slug = (string) $baseMember['canonical_slug'];

        $this->materializeIndexedOccupation($slug);
        $this->writeRolloutExecutionReport('unverified-batch.json', [
            'status' => 'planned',
            'batch_id' => 'career_80_delta_canonical_001',
            'promoted_slugs' => [$slug],
            'promoted_locale_rows' => 2,
            'dry_run' => true,
            'writes_database' => false,
            'write_verified' => false,
            'release_gate' => [
                'release_gate_pass_count' => 2,
                'release_gate_blocked_count' => 0,
            ],
            'rollback_required' => false,
            'quarantine_required' => false,
        ]);

        $projected = app(CareerFullReleaseLedgerProjectionService::class)->build();
        $ledger = $projected[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? [];
        $member = collect((array) ($ledger['members'] ?? []))->firstWhere('canonical_slug', $slug);

        $this->assertIsArray($member);
        $this->assertContains($member['release_cohort'] ?? null, ['review_needed', 'family_handoff']);
        $this->assertArrayNotHasKey('explicit_rollout_batch', (array) ($member['evidence_refs'] ?? []));
    }

    public function test_unsigned_successful_rollout_batch_execution_is_ignored_by_default_projection_authority(): void
    {
        $baseLedger = app(CareerFullReleaseLedgerService::class)->build()->toArray();
        $baseMember = collect((array) ($baseLedger['members'] ?? []))
            ->first(static fn (array $member): bool => in_array($member['release_cohort'] ?? '', ['review_needed', 'family_handoff'], true));

        $this->assertIsArray($baseMember);
        $slug = (string) $baseMember['canonical_slug'];

        $this->materializeIndexedOccupation($slug);
        $this->writeRolloutExecutionReport('unsigned-success.json', [
            'status' => 'promoted_success',
            'batch_id' => 'career_80_delta_canonical_001',
            'promoted_slugs' => [$slug],
            'promoted_locale_rows' => 2,
            'dry_run' => false,
            'writes_database' => true,
            'write_verified' => true,
            'persistence_check' => [
                'expected' => 2,
                'found_published' => 2,
                'not_published_count' => 0,
            ],
            'post_promotion_validation' => [
                'status' => 'pass',
            ],
            'release_gate' => [
                'release_gate_pass_count' => 2,
                'release_gate_blocked_count' => 0,
            ],
            'rollback_required' => false,
            'quarantine_required' => false,
        ]);

        $projected = app(CareerFullReleaseLedgerProjectionService::class)->build();
        $ledger = $projected[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? [];
        $member = collect((array) ($ledger['members'] ?? []))->firstWhere('canonical_slug', $slug);

        $this->assertIsArray($member);
        $this->assertContains($member['release_cohort'] ?? null, ['review_needed', 'family_handoff']);
        $this->assertArrayNotHasKey('explicit_rollout_batch', (array) ($member['evidence_refs'] ?? []));
    }

    public function test_stale_signed_rollout_batch_execution_is_ignored_by_default_projection_authority(): void
    {
        $baseLedger = app(CareerFullReleaseLedgerService::class)->build()->toArray();
        $baseMember = collect((array) ($baseLedger['members'] ?? []))
            ->first(static fn (array $member): bool => in_array($member['release_cohort'] ?? '', ['review_needed', 'family_handoff'], true));

        $this->assertIsArray($baseMember);
        $slug = (string) $baseMember['canonical_slug'];

        $this->materializeIndexedOccupation($slug);
        $this->writeRolloutExecutionReport('stale-signed-success.json', [
            'status' => 'promoted_success',
            'batch_id' => 'career_80_delta_canonical_001',
            'promoted_slugs' => [$slug],
            'promoted_locale_rows' => 2,
            'dry_run' => false,
            'writes_database' => true,
            'write_verified' => true,
            'persistence_check' => [
                'expected' => 2,
                'found_published' => 2,
                'not_published_count' => 0,
            ],
            'post_promotion_validation' => [
                'status' => 'pass',
            ],
            'release_gate' => [
                'release_gate_pass_count' => 2,
                'release_gate_blocked_count' => 0,
            ],
            'rollback_required' => false,
            'quarantine_required' => false,
        ], sign: true, signedAt: new DateTimeImmutable('-30 days', new DateTimeZone('UTC')), expiresAt: new DateTimeImmutable('-16 days', new DateTimeZone('UTC')));

        $projected = app(CareerFullReleaseLedgerProjectionService::class)->build();
        $ledger = $projected[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? [];
        $member = collect((array) ($ledger['members'] ?? []))->firstWhere('canonical_slug', $slug);

        $this->assertIsArray($member);
        $this->assertContains($member['release_cohort'] ?? null, ['review_needed', 'family_handoff']);
        $this->assertArrayNotHasKey('explicit_rollout_batch', (array) ($member['evidence_refs'] ?? []));
    }

    private function materializeIndexedOccupation(string $slug, string $indexState = 'indexed'): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'test-family-'.$indexState,
            'title_en' => 'Test Family',
            'title_zh' => '测试族',
        ]);

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'global_standard',
            'canonical_title_en' => ucwords(str_replace('-', ' ', $slug)),
            'canonical_title_zh' => $slug,
            'search_h1_zh' => $slug,
        ]);

        IndexState::query()->create([
            'occupation_id' => $occupation->id,
            'index_state' => $indexState,
            'index_eligible' => true,
            'canonical_path' => '/career/jobs/'.$slug,
            'canonical_target' => null,
            'reason_codes' => [
                'canonical_rollout_batch_promotion',
                'batch_id:batch-001-test',
            ],
            'changed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeRolloutExecutionReport(
        string $filename,
        array $payload,
        bool $sign = false,
        ?DateTimeImmutable $signedAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): void {
        $dir = storage_path('app/private/career_canonical_rollout_batch_executions');
        File::ensureDirectoryExists($dir);
        if ($sign) {
            $payload['authority'] = app(CareerRolloutReportAuthoritySigner::class)->sign($payload, $signedAt, $expiresAt);
        }

        File::put(
            $dir.DIRECTORY_SEPARATOR.$filename,
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}
