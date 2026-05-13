<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerOccupationEntityInventoryAuditor;
use App\Domain\Career\Audit\CareerOccupationEntityInventoryIssue;
use App\Domain\Career\Audit\CareerOccupationEntityRemediationPlan;
use App\Domain\Career\Audit\CareerOccupationEntityRemediationPlanRow;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerOccupationEntityInventoryAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_slugs_reports_all_found_occupations(): void
    {
        $this->createOccupation('actuaries');
        $this->createOccupation('financial-analysts');

        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs([
            'actuaries',
            'financial-analysts',
        ]);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(2, $result->expectedCount);
        $this->assertSame(2, $result->foundCount);
        $this->assertSame(0, $result->missingCount);
        $this->assertSame([], $result->issues);
    }

    public function test_audit_slugs_reports_missing_occupations(): void
    {
        $this->createOccupation('actuaries');

        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs([
            'actuaries',
            'missing-career',
        ]);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(2, $result->expectedCount);
        $this->assertSame(1, $result->foundCount);
        $this->assertSame(1, $result->missingCount);
        $this->assertSame(CareerOccupationEntityInventoryIssue::OCCUPATION_MISSING, $result->rows[1]->issues[0]->reason);
    }

    public function test_duplicate_input_slug_is_detected(): void
    {
        $this->createOccupation('actuaries');

        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs([
            'Actuaries',
            'actuaries',
        ]);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->duplicateInputCount);
        $this->assertTrue($result->rows[0]->duplicateInputSlug);
        $this->assertSame(CareerOccupationEntityInventoryIssue::CANONICAL_SLUG_DUPLICATE_IN_INPUT, $result->rows[0]->issues[0]->reason);
    }

    public function test_entity_layer_status_passes_for_found_occupation(): void
    {
        $occupation = $this->createOccupation('actuaries');

        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs(['actuaries']);
        $status = $result->rows[0]->entityStatus;

        $this->assertSame(CareerCanonicalEligibilityLayer::ENTITY, $status->layer);
        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $status->status);
        $this->assertSame([], $status->reasons);
        $this->assertSame([['occupation_id' => $occupation->id]], $status->evidence);
        $this->assertSame('occupations', $status->source);
    }

    public function test_entity_layer_status_blocks_for_missing_occupation(): void
    {
        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs(['missing-career']);
        $status = $result->rows[0]->entityStatus;

        $this->assertSame(CareerCanonicalEligibilityLayer::ENTITY, $status->layer);
        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $status->status);
        $this->assertSame([CareerOccupationEntityInventoryIssue::OCCUPATION_MISSING], $status->reasons);
        $this->assertSame([['canonical_slug' => 'missing-career']], $status->evidence);
    }

    public function test_result_by_reason_counts_occupation_missing(): void
    {
        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs([
            'missing-one',
            'missing-two',
        ]);

        $this->assertSame([
            CareerOccupationEntityInventoryIssue::OCCUPATION_MISSING => 2,
        ], $result->byReason());
    }

    public function test_missing_entity_fields_are_reported(): void
    {
        $this->createOccupation('actuaries', ['canonical_title_zh' => '']);

        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs(['actuaries']);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->missingEntityFieldCount);
        $this->assertSame(['canonical_title_zh'], $result->rows[0]->missingEntityFields);
        $this->assertSame(CareerOccupationEntityInventoryIssue::ENTITY_FIELD_MISSING, $result->rows[0]->issues[0]->reason);
    }

    public function test_audit_plan_consumes_public_resolution_plan_rows(): void
    {
        $this->createOccupation('actuaries');
        $plan = new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-audit-3-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw(['canonical_slug' => 'actuaries']),
                CareerPublicResolutionPlanRow::fromRaw(['canonical_slug' => 'missing-career']),
            ]
        );

        $result = (new CareerOccupationEntityInventoryAuditor)->auditPlan($plan);

        $this->assertSame(2, $result->expectedCount);
        $this->assertSame('plan', $result->rows[0]->sourceScope);
        $this->assertSame(1, $result->foundCount);
        $this->assertSame(1, $result->missingCount);
    }

    public function test_empty_input_is_handled_explicitly(): void
    {
        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs([]);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(0, $result->expectedCount);
        $this->assertSame([
            CareerOccupationEntityInventoryIssue::INPUT_SLUG_MISSING => 1,
        ], $result->byReason());
    }

    public function test_no_index_baseline_or_projection_logic_is_invoked(): void
    {
        $this->createOccupation('actuaries');

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new CareerOccupationEntityInventoryAuditor)->auditSlugs(['actuaries']);

        $queries = json_encode(DB::getQueryLog(), JSON_UNESCAPED_SLASHES);

        $this->assertIsString($queries);
        $this->assertStringContainsString('occupations', $queries);
        $this->assertStringNotContainsString('index_states', $queries);
        $this->assertStringNotContainsString('career_job_display_assets', $queries);
        $this->assertStringNotContainsString('occupation_truth_metrics', $queries);
    }

    public function test_missing_occupation_with_planner_source_produces_remediation_plan(): void
    {
        $plan = new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-entity-remediation-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw([
                    'canonical_slug' => 'actuaries',
                    'title_en' => 'Actuaries',
                    'title_zh' => '精算师',
                    'family' => 'Business',
                    'source_code' => '15-2011.00',
                ]),
            ]
        );

        $remediation = (new CareerOccupationEntityInventoryAuditor)->planRemediation($plan);

        $this->assertSame(CareerOccupationEntityRemediationPlan::SCHEMA_VERSION, $remediation->schemaVersion);
        $this->assertSame(1, $remediation->createOccupationCount());
        $this->assertCount(1, $remediation->approvalGates);
        $this->assertSame(
            CareerOccupationEntityRemediationPlanRow::ACTION_CREATE_OCCUPATION,
            $remediation->rows[0]->action
        );
        $this->assertSame(CareerOccupationEntityRemediationPlanRow::SOURCE_AVAILABLE, $remediation->rows[0]->sourceStatus);
        $this->assertTrue($remediation->rows[0]->approvalRequired);
        $this->assertSame('Actuaries', $remediation->rows[0]->plannerTitleEn);
        $this->assertSame(['occupation_missing'], $remediation->rows[0]->reasons);
    }

    public function test_missing_occupation_without_planner_source_requires_source_review_not_apply(): void
    {
        $plan = new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-entity-remediation-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw(['canonical_slug' => 'missing-career']),
            ]
        );

        $remediation = (new CareerOccupationEntityInventoryAuditor)->planRemediation($plan);

        $this->assertSame(
            CareerOccupationEntityRemediationPlanRow::ACTION_REVIEW_MISSING_SOURCE,
            $remediation->rows[0]->action
        );
        $this->assertSame(CareerOccupationEntityRemediationPlanRow::SOURCE_MISSING, $remediation->rows[0]->sourceStatus);
        $this->assertFalse($remediation->rows[0]->approvalRequired);
        $this->assertSame([], $remediation->approvalGates);
    }

    public function test_missing_entity_fields_produce_repair_plan(): void
    {
        $occupation = $this->createOccupation('actuaries', ['canonical_title_zh' => '']);
        $plan = new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-entity-remediation-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw([
                    'canonical_slug' => 'actuaries',
                    'title_en' => 'Actuaries',
                    'title_zh' => '精算师',
                ]),
            ]
        );

        $remediation = (new CareerOccupationEntityInventoryAuditor)->planRemediation($plan);

        $this->assertSame(
            CareerOccupationEntityRemediationPlanRow::ACTION_REPAIR_ENTITY_FIELDS,
            $remediation->rows[0]->action
        );
        $this->assertTrue($remediation->rows[0]->approvalRequired);
        $this->assertSame($occupation->id, $remediation->rows[0]->occupationId);
        $this->assertSame(['canonical_title_zh'], $remediation->rows[0]->missingEntityFields);
        $this->assertSame(1, $remediation->repairEntityFieldsCount());
    }

    public function test_entity_present_has_no_remediation_action(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $plan = new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-entity-remediation-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw([
                    'canonical_slug' => 'actuaries',
                    'title_en' => 'Actuaries',
                ]),
            ]
        );

        $remediation = (new CareerOccupationEntityInventoryAuditor)->planRemediation($plan);

        $this->assertSame(CareerOccupationEntityRemediationPlanRow::ACTION_NONE, $remediation->rows[0]->action);
        $this->assertFalse($remediation->rows[0]->approvalRequired);
        $this->assertTrue($remediation->rows[0]->occupationExists);
        $this->assertSame($occupation->id, $remediation->rows[0]->occupationId);
        $this->assertSame([], $remediation->approvalGates);
    }

    public function test_remediation_plan_to_array_is_stable(): void
    {
        $plan = new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-entity-remediation-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw([
                    'row_number' => 12,
                    'canonical_slug' => 'actuaries',
                    'title_en' => 'Actuaries',
                    'title_zh' => '精算师',
                    'family' => 'Business',
                    'source_code' => '15-2011.00',
                ]),
            ]
        );

        $payload = (new CareerOccupationEntityInventoryAuditor)->planRemediation($plan)->toArray();

        $this->assertSame('career_occupation_entity_remediation_plan.v1', $payload['schema_version']);
        $this->assertSame([
            'expected_count' => 1,
            'create_occupation_count' => 1,
            'repair_entity_fields_count' => 0,
            'review_count' => 0,
            'approval_required_count' => 1,
            'by_action' => ['create_occupation' => 1],
            'by_source_status' => ['planner_source_available' => 1],
        ], $payload['summary']);
        $this->assertSame('actuaries', $payload['rows'][0]['canonical_slug']);
        $this->assertSame('create_occupation', $payload['rows'][0]['action']);
        $this->assertSame('I explicitly approve production occupation entity remediation apply for Career 2786 using reviewed plan <PLAN_PATH>.', $payload['approval_gates'][0]['approval_phrase_template']);
    }

    public function test_remediation_planning_does_not_mutate_database(): void
    {
        $this->createOccupation('actuaries', ['canonical_title_zh' => '']);
        $before = Occupation::query()->count();
        $plan = new CareerPublicResolutionPlan(
            sourcePath: 'synthetic-entity-remediation-plan.json',
            checksum: null,
            rows: [
                CareerPublicResolutionPlanRow::fromRaw(['canonical_slug' => 'actuaries', 'title_en' => 'Actuaries']),
                CareerPublicResolutionPlanRow::fromRaw(['canonical_slug' => 'missing-career', 'title_en' => 'Missing Career']),
            ]
        );

        (new CareerOccupationEntityInventoryAuditor)->planRemediation($plan);

        $this->assertSame($before, Occupation::query()->count());
    }

    public function test_result_to_array_is_stable(): void
    {
        $this->createOccupation('actuaries', [
            'id' => '00000000-0000-4000-8000-000000000001',
        ]);

        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs(['actuaries'])->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "expected_count": 1,
    "found_count": 1,
    "missing_count": 0,
    "duplicate_input_count": 0,
    "duplicate_entity_count": 0,
    "missing_entity_field_count": 0,
    "by_reason": [],
    "rows": [
        {
            "canonical_slug": "actuaries",
            "occupation_exists": true,
            "occupation_id": "00000000-0000-4000-8000-000000000001",
            "duplicate_input_slug": false,
            "duplicate_entity_slug": false,
            "entity_status": {
                "layer": "entity",
                "status": "pass",
                "reasons": [],
                "evidence": [
                    {
                        "occupation_id": "00000000-0000-4000-8000-000000000001"
                    }
                ],
                "source": "occupations"
            },
            "missing_entity_fields": [],
            "source_scope": "slugs",
            "evidence": [
                {
                    "occupation_id": "00000000-0000-4000-8000-000000000001"
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
        $this->createOccupation('actuaries');
        $before = Occupation::query()->count();

        (new CareerOccupationEntityInventoryAuditor)->auditSlugs(['actuaries', 'missing-career']);

        $this->assertSame($before, Occupation::query()->count());
    }

    public function test_duplicate_entity_slug_detection_is_defensive_under_unique_schema(): void
    {
        $this->createOccupation('actuaries');

        $result = (new CareerOccupationEntityInventoryAuditor)->auditSlugs(['actuaries']);

        $this->assertSame(0, $result->duplicateEntityCount);
        $this->assertFalse($result->rows[0]->duplicateEntitySlug);
        $this->assertSame([], array_filter(
            $result->issues,
            static fn (CareerOccupationEntityInventoryIssue $issue): bool => $issue->reason === CareerOccupationEntityInventoryIssue::CANONICAL_SLUG_DUPLICATE_IN_ENTITIES
        ));
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

    private function occupationFamily(): OccupationFamily
    {
        /** @var OccupationFamily $family */
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'audit-3-family'],
            [
                'id' => '00000000-0000-4000-8000-000000000100',
                'title_en' => 'Audit 3 Family',
                'title_zh' => 'Audit 3 Family',
            ]
        );

        return $family;
    }
}
