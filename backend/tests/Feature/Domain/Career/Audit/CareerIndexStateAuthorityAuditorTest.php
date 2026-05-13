<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerIndexStateAuthorityAuditor;
use App\Domain\Career\Audit\CareerIndexStateAuthorityIssue;
use App\Domain\Career\Audit\CareerIndexStateRemediationPlan;
use App\Domain\Career\Audit\CareerIndexStateRemediationPlanRow;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use App\Domain\Career\IndexStateValue;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerIndexStateAuthorityAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexed_state_is_accepted(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::INDEXED, true);

        $result = (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries']);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(1, $result->indexedLikeCount);
        $this->assertSame(IndexStateValue::INDEXABLE, $result->rows[0]->publicIndexState);
    }

    public function test_indexable_state_is_accepted(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::INDEXABLE, true);

        $result = (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries']);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(IndexStateValue::INDEXABLE, $result->rows[0]->rawIndexState);
    }

    public function test_missing_index_state_blocks(): void
    {
        $this->createOccupation('actuaries');

        $result = (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries']);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->missingIndexStateCount);
        $this->assertSame(CareerIndexStateAuthorityIssue::INDEX_STATE_MISSING, $result->rows[0]->issues[0]->reason);
    }

    public function test_noindex_state_blocks(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::NOINDEX, false);

        $result = (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries']);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertContains(CareerIndexStateAuthorityIssue::EXPLICIT_NOINDEX_BLOCK, $result->rows[0]->indexStatus->reasons);
    }

    public function test_index_eligible_false_blocks_even_when_raw_state_is_indexable(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::INDEXABLE, false);

        $result = (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries']);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame([CareerIndexStateAuthorityIssue::INDEX_ELIGIBLE_FALSE], $result->rows[0]->indexStatus->reasons);
    }

    public function test_quarantine_and_rollback_reason_codes_block(): void
    {
        $quarantine = $this->createOccupation('quarantine-career');
        $rollback = $this->createOccupation('rollback-career');
        $this->createIndexState($quarantine, IndexStateValue::INDEXABLE, true, ['quarantine_on_failure']);
        $this->createIndexState($rollback, IndexStateValue::INDEXABLE, true, ['rollback_required']);

        $result = (new CareerIndexStateAuthorityAuditor)->auditSlugs([
            'quarantine-career',
            'rollback-career',
        ]);

        $this->assertSame([
            CareerIndexStateAuthorityIssue::QUARANTINE_BLOCK => 1,
            CareerIndexStateAuthorityIssue::ROLLBACK_BLOCK => 1,
        ], $result->byReason());
    }

    public function test_latest_index_state_wins(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::NOINDEX, false, [], now()->subDay());
        $this->createIndexState($occupation, IndexStateValue::INDEXABLE, true, [], now());

        $result = (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries']);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(IndexStateValue::INDEXABLE, $result->rows[0]->rawIndexState);
    }

    public function test_audit_plan_consumes_public_resolution_rows(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::INDEXABLE, true);
        $plan = new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-audit-5-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw(['canonical_slug' => 'actuaries']),
            ]
        );

        $result = (new CareerIndexStateAuthorityAuditor)->auditPlan($plan);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame('actuaries', $result->rows[0]->canonicalSlug);
    }

    public function test_index_layer_status_blocks_for_missing_state(): void
    {
        $this->createOccupation('actuaries');

        $status = (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries'])->rows[0]->indexStatus;

        $this->assertSame(CareerCanonicalEligibilityLayer::INDEX, $status->layer);
        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $status->status);
        $this->assertSame([CareerIndexStateAuthorityIssue::INDEX_STATE_MISSING], $status->reasons);
        $this->assertSame('index_states', $status->source);
    }

    public function test_result_to_array_is_stable(): void
    {
        $occupation = $this->createOccupation('actuaries', [
            'id' => '00000000-0000-4000-8000-000000000001',
        ]);
        $this->createIndexState($occupation, IndexStateValue::INDEXABLE, true, [], Carbon::parse('2026-05-12T00:00:00Z'), [
            'id' => '00000000-0000-4000-8000-000000000002',
        ]);

        $result = (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries'])->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "expected_count": 1,
    "indexed_like_count": 1,
    "missing_index_state_count": 0,
    "blocked_count": 0,
    "by_reason": [],
    "rows": [
        {
            "canonical_slug": "actuaries",
            "occupation_id": "00000000-0000-4000-8000-000000000001",
            "index_state_id": "00000000-0000-4000-8000-000000000002",
            "raw_index_state": "indexable",
            "public_index_state": "indexable",
            "index_eligible": true,
            "changed_at": "2026-05-12T00:00:00.000000Z",
            "index_status": {
                "layer": "index",
                "status": "pass",
                "reasons": [],
                "evidence": [
                    {
                        "canonical_slug": "actuaries"
                    },
                    {
                        "occupation_id": "00000000-0000-4000-8000-000000000001"
                    },
                    {
                        "index_state_id": "00000000-0000-4000-8000-000000000002",
                        "index_state": "indexable",
                        "index_eligible": true
                    }
                ],
                "source": "index_states"
            },
            "reason_codes": [],
            "evidence": [
                {
                    "canonical_slug": "actuaries"
                },
                {
                    "occupation_id": "00000000-0000-4000-8000-000000000001"
                },
                {
                    "index_state_id": "00000000-0000-4000-8000-000000000002",
                    "index_state": "indexable",
                    "index_eligible": true
                }
            ],
            "issues": []
        }
    ],
    "issues": [],
    "sidecars": []
}
JSON,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_auditor_does_not_mutate_database(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::INDEXABLE, true);
        $before = IndexState::query()->count();

        (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries']);

        $this->assertSame($before, IndexState::query()->count());
    }

    public function test_missing_expected_public_index_state_produces_remediation_plan(): void
    {
        $this->createOccupation('actuaries');
        $plan = $this->plan([
            ['canonical_slug' => 'actuaries', 'canonical_public_type' => 'public_canonical_job'],
        ]);

        $remediation = (new CareerIndexStateAuthorityAuditor)->planRemediation($plan);

        $this->assertSame(CareerIndexStateRemediationPlan::SCHEMA_VERSION, $remediation->schemaVersion);
        $this->assertSame(1, $remediation->createIndexStateCount());
        $this->assertSame(1, $remediation->toArray()['summary']['approval_required_count']);
        $this->assertSame('production_index_state_remediation_apply', $remediation->approvalGates[0]->gateId);
        $this->assertSame(CareerIndexStateRemediationPlanRow::EXPECTATION_EXPECTED_INDEXED, $remediation->rows[0]->expectation);
        $this->assertSame(CareerIndexStateRemediationPlanRow::ACTION_CREATE_INDEX_STATE, $remediation->rows[0]->action);
        $this->assertTrue($remediation->rows[0]->approvalRequired);
    }

    public function test_indexed_indexable_rows_do_not_need_remediation(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::INDEXABLE, true);
        $plan = $this->plan([
            ['canonical_slug' => 'actuaries', 'canonical_public_type' => 'public_canonical_job'],
        ]);

        $remediation = (new CareerIndexStateAuthorityAuditor)->planRemediation($plan);

        $this->assertSame(0, $remediation->createIndexStateCount());
        $this->assertSame([], $remediation->approvalGates);
        $this->assertSame(CareerIndexStateRemediationPlanRow::ACTION_NONE, $remediation->rows[0]->action);
        $this->assertFalse($remediation->rows[0]->approvalRequired);
    }

    public function test_non_public_missing_index_state_is_deferred_not_publish_blocking(): void
    {
        $this->createOccupation('restricted-career');
        $plan = $this->plan([
            [
                'canonical_slug' => 'restricted-career',
                'public_resolution_state' => 'blocked_until_governance_approval',
                'canonical_public_type' => 'governed_non_public',
            ],
        ]);

        $remediation = (new CareerIndexStateAuthorityAuditor)->planRemediation($plan);

        $this->assertSame(1, $remediation->deferredCount());
        $this->assertSame([], $remediation->approvalGates);
        $this->assertSame(CareerIndexStateRemediationPlanRow::EXPECTATION_GOVERNED_NON_PUBLIC, $remediation->rows[0]->expectation);
        $this->assertSame(CareerIndexStateRemediationPlanRow::ACTION_DEFER_GOVERNED_NON_PUBLIC, $remediation->rows[0]->action);
        $this->assertFalse($remediation->rows[0]->approvalRequired);
    }

    public function test_ready_for_pilot_without_public_type_is_deferred_until_promotion(): void
    {
        $this->createOccupation('pilot-career');
        $plan = $this->plan([
            ['canonical_slug' => 'pilot-career', 'public_resolution_state' => 'ready_for_pilot'],
        ]);

        $remediation = (new CareerIndexStateAuthorityAuditor)->planRemediation($plan);

        $this->assertSame(CareerIndexStateRemediationPlanRow::EXPECTATION_NOT_YET_PROMOTED, $remediation->rows[0]->expectation);
        $this->assertSame(CareerIndexStateRemediationPlanRow::ACTION_DEFER_UNTIL_RUNTIME_PROMOTION, $remediation->rows[0]->action);
        $this->assertFalse($remediation->rows[0]->approvalRequired);
    }

    public function test_existing_bad_index_state_produces_review_plan(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::NOINDEX, false);
        $plan = $this->plan([
            ['canonical_slug' => 'actuaries', 'canonical_public_type' => 'public_canonical_job'],
        ]);

        $remediation = (new CareerIndexStateAuthorityAuditor)->planRemediation($plan);

        $this->assertSame(1, $remediation->reviewExistingIndexStateCount());
        $this->assertSame(CareerIndexStateRemediationPlanRow::ACTION_REVIEW_EXISTING_INDEX_STATE, $remediation->rows[0]->action);
        $this->assertTrue($remediation->rows[0]->approvalRequired);
    }

    public function test_remediation_planning_does_not_mutate_database(): void
    {
        $this->createOccupation('actuaries');
        $plan = $this->plan([
            ['canonical_slug' => 'actuaries', 'canonical_public_type' => 'public_canonical_job'],
        ]);
        $before = IndexState::query()->count();

        (new CareerIndexStateAuthorityAuditor)->planRemediation($plan);

        $this->assertSame($before, IndexState::query()->count());
    }

    public function test_no_baseline_projection_or_surface_logic_is_invoked(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::INDEXABLE, true);

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new CareerIndexStateAuthorityAuditor)->auditSlugs(['actuaries']);

        $queries = json_encode(DB::getQueryLog(), JSON_UNESCAPED_SLASHES);

        $this->assertIsString($queries);
        $this->assertStringContainsString('index_states', $queries);
        $this->assertStringNotContainsString('career_jobs', $queries);
        $this->assertStringNotContainsString('career_job_display_assets', $queries);
        $this->assertStringNotContainsString('runtime', $queries);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOccupation(string $slug, array $attributes = []): Occupation
    {
        $payload = array_merge([
            'id' => (string) Str::uuid(),
            'family_id' => $this->occupationFamily()->id,
            'canonical_slug' => $slug,
            'entity_level' => 'occupation',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct',
            'canonical_title_en' => Str::title(str_replace('-', ' ', $slug)),
            'canonical_title_zh' => Str::title(str_replace('-', ' ', $slug)),
            'search_h1_zh' => Str::title(str_replace('-', ' ', $slug)),
        ], $attributes);

        /** @var Occupation $occupation */
        $occupation = Occupation::query()->create($payload);

        return $occupation;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function plan(array $rows): CareerPublicResolutionPlan
    {
        return new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-index-remediation-plan.json',
            checksum: null,
            rows: array_map(
                static fn (array $row): CareerPublicResolutionPlanRow => CareerPublicResolutionPlanRow::fromRaw($row),
                $rows
            )
        );
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, mixed>  $attributes
     */
    private function createIndexState(
        Occupation $occupation,
        string $state,
        bool $eligible,
        array $reasonCodes = [],
        ?Carbon $changedAt = null,
        array $attributes = [],
    ): IndexState {
        /** @var IndexState $indexState */
        $indexState = IndexState::query()->create(array_merge([
            'occupation_id' => $occupation->id,
            'index_state' => $state,
            'index_eligible' => $eligible,
            'canonical_path' => '/career/jobs/'.$occupation->canonical_slug,
            'canonical_target' => '/career/jobs/'.$occupation->canonical_slug,
            'reason_codes' => $reasonCodes,
            'changed_at' => $changedAt ?? now(),
        ], $attributes));

        return $indexState;
    }

    private function occupationFamily(): OccupationFamily
    {
        /** @var OccupationFamily $family */
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'audit-5-family'],
            [
                'id' => '00000000-0000-4000-8000-000000000500',
                'title_en' => 'Audit 5 Family',
                'title_zh' => 'Audit 5 Family',
            ]
        );

        return $family;
    }
}
