<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerOccupationEntityInventoryAuditor;
use App\Domain\Career\Audit\CareerOccupationEntityInventoryIssue;
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
