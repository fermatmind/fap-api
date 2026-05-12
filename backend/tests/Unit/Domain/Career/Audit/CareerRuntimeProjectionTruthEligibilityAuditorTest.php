<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use App\Domain\Career\Audit\CareerRuntimeProjectionTruthEligibilityAuditor;
use App\Domain\Career\Audit\CareerRuntimeProjectionTruthEligibilityIssue;
use PHPUnit\Framework\TestCase;

final class CareerRuntimeProjectionTruthEligibilityAuditorTest extends TestCase
{
    public function test_projection_truth_expected_rows_pass(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en', 'zh'],
            projection: $this->projection([
                $this->projectionRow('actuaries', 'en'),
                $this->projectionRow('actuaries', 'zh'),
            ]),
            truth: $this->truth([
                $this->truthRow('actuaries', 'en'),
                $this->truthRow('actuaries', 'zh'),
            ]),
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(2, $result->expectedRows);
        $this->assertSame(2, $result->foundProjectionRows);
        $this->assertSame(2, $result->foundTruthRows);
        $this->assertSame(2, $result->foundPublished);
    }

    public function test_missing_projection_row_is_reported(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(1, $result->missingProjectionRows);
        $this->assertSame(CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_ROW_MISSING, $result->issues[0]->reason);
    }

    public function test_missing_truth_row_is_reported(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([]),
        );

        $this->assertSame(1, $result->missingTruthRows);
        $this->assertSame(CareerRuntimeProjectionTruthEligibilityIssue::TRUTH_ROW_MISSING, $result->issues[0]->reason);
    }

    public function test_projection_state_not_published_is_reported(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([
                $this->projectionRow('actuaries', 'en', ['runtime_publish_state' => null, 'projection_state' => 'published_candidate']),
            ]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        );

        $this->assertSame(CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_STATE_NOT_PUBLISHED, $result->issues[0]->reason);
        $this->assertSame(1, $result->notPublishedRows);
    }

    public function test_runtime_publish_state_not_published_is_reported(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en', ['runtime_publish_state' => 'published_candidate'])]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        );

        $this->assertSame(CareerRuntimeProjectionTruthEligibilityIssue::RUNTIME_PUBLISH_STATE_NOT_PUBLISHED, $result->issues[0]->reason);
        $this->assertSame(1, $result->notPublishedRows);
    }

    public function test_truth_not_published_is_reported_if_truth_state_exists(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en', ['projection_state' => 'published_candidate'])]),
        );

        $this->assertSame(CareerRuntimeProjectionTruthEligibilityIssue::TRUTH_STATE_NOT_PUBLISHED, $result->issues[0]->reason);
        $this->assertSame(1, $result->notPublishedRows);
    }

    public function test_invalid_canonical_public_type_is_reported(): void
    {
        $result = $this->auditor()->audit(
            planRows: [['canonical_slug' => 'actuaries', 'canonical_public_type' => 'public_alias_redirect']],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en', ['public_resolution_type' => 'public_alias_redirect'])]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        );

        $this->assertSame(CareerRuntimeProjectionTruthEligibilityIssue::CANONICAL_PUBLIC_TYPE_INVALID, $result->issues[0]->reason);
        $this->assertSame(1, $result->invalidPublicTypeRows);
    }

    public function test_missing_locale_row_is_reported(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en', 'zh'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        );

        $this->assertSame([
            CareerRuntimeProjectionTruthEligibilityIssue::LOCALE_ROW_MISSING => 1,
            CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_ROW_MISSING => 1,
            CareerRuntimeProjectionTruthEligibilityIssue::TRUTH_ROW_MISSING => 1,
        ], $result->byReason());
    }

    public function test_optional_ledger_missing_member_is_reported_when_ledger_input_is_supplied(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
            ledger: ['public_resolution' => ['rows' => [['source_slug' => 'actors']]]],
        );

        $this->assertSame(CareerRuntimeProjectionTruthEligibilityIssue::LEDGER_MEMBER_MISSING, $result->issues[0]->reason);
        $this->assertSame(1, $result->ledgerMissingRows);
        $this->assertFalse($result->rows[0]->ledgerMemberExists);
    }

    public function test_no_ledger_issue_is_produced_when_ledger_input_is_null(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
            ledger: null,
        );

        $this->assertSame([], $result->issues);
        $this->assertNull($result->rows[0]->ledgerMemberExists);
    }

    public function test_result_by_reason_counts_issues(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries', 'actors'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en', ['runtime_publish_state' => 'blocked'])]),
            truth: $this->truth([]),
        );

        $this->assertSame([
            CareerRuntimeProjectionTruthEligibilityIssue::LOCALE_ROW_MISSING => 1,
            CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_ROW_MISSING => 1,
            CareerRuntimeProjectionTruthEligibilityIssue::RUNTIME_PUBLISH_STATE_NOT_PUBLISHED => 1,
            CareerRuntimeProjectionTruthEligibilityIssue::TRUTH_ROW_MISSING => 2,
        ], $result->byReason());
    }

    public function test_row_to_array_is_stable(): void
    {
        $row = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
            ledger: ['public_resolution' => ['rows' => [['source_slug' => 'actuaries']]]],
        )->rows[0]->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "canonical_slug": "actuaries",
    "locale": "en",
    "ledger_member_exists": true,
    "projection_exists": true,
    "truth_exists": true,
    "projection_state": null,
    "runtime_publish_state": "published",
    "truth_state": "published",
    "canonical_public_type": "public_canonical_job",
    "runtime_status": {
        "layer": "runtime",
        "status": "pass",
        "reasons": [],
        "evidence": [
            {
                "slug": "actuaries",
                "locale": "en"
            },
            {
                "ledger_member_exists": true
            },
            {
                "runtime_publish_state": "published"
            },
            {
                "truth_state": "published"
            },
            {
                "canonical_public_type": "public_canonical_job"
            }
        ],
        "source": "runtime_projection_truth"
    },
    "evidence": [
        {
            "slug": "actuaries",
            "locale": "en"
        },
        {
            "ledger_member_exists": true
        },
        {
            "runtime_publish_state": "published"
        },
        {
            "truth_state": "published"
        },
        {
            "canonical_public_type": "public_canonical_job"
        }
    ],
    "issues": []
}
JSON,
            json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_result_to_array_is_stable(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        )->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "expected_rows": 1,
    "found_projection_rows": 1,
    "found_truth_rows": 1,
    "found_published": 1,
    "missing_projection_rows": 0,
    "missing_truth_rows": 0,
    "not_published_rows": 0,
    "invalid_public_type_rows": 0,
    "ledger_missing_rows": 0,
    "by_reason": [],
    "rows": [
        {
            "canonical_slug": "actuaries",
            "locale": "en",
            "ledger_member_exists": null,
            "projection_exists": true,
            "truth_exists": true,
            "projection_state": null,
            "runtime_publish_state": "published",
            "truth_state": "published",
            "canonical_public_type": "public_canonical_job",
            "runtime_status": {
                "layer": "runtime",
                "status": "pass",
                "reasons": [],
                "evidence": [
                    {
                        "slug": "actuaries",
                        "locale": "en"
                    },
                    {
                        "runtime_publish_state": "published"
                    },
                    {
                        "truth_state": "published"
                    },
                    {
                        "canonical_public_type": "public_canonical_job"
                    }
                ],
                "source": "runtime_projection_truth"
            },
            "evidence": [
                {
                    "slug": "actuaries",
                    "locale": "en"
                },
                {
                    "runtime_publish_state": "published"
                },
                {
                    "truth_state": "published"
                },
                {
                    "canonical_public_type": "public_canonical_job"
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

    public function test_runtime_layer_status_pass_and_blocked_are_audit_one_compatible(): void
    {
        $pass = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        )->rows[0]->runtimeStatus;

        $blocked = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([]),
            truth: $this->truth([]),
        )->rows[0]->runtimeStatus;

        $this->assertSame(CareerCanonicalEligibilityLayer::RUNTIME, $pass->layer);
        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $pass->status);
        $this->assertSame(CareerCanonicalEligibilityLayer::RUNTIME, $blocked->layer);
        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $blocked->status);
        $this->assertContains(CareerRuntimeProjectionTruthEligibilityIssue::PROJECTION_ROW_MISSING, $blocked->reasons);
    }

    public function test_audit_plan_consumes_audit_two_plan_rows(): void
    {
        $plan = new CareerPublicResolutionPlan(
            sourcePath: '__fixture__',
            checksum: null,
            rows: [CareerPublicResolutionPlanRow::fromRaw(['canonical_slug' => 'actuaries', 'canonical_public_type' => 'public_canonical_job'])],
        );

        $result = $this->auditor()->auditPlan(
            plan: $plan,
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame('actuaries', $result->rows[0]->canonicalSlug);
    }

    public function test_no_db_mutation_or_dependency(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            projection: $this->projection([$this->projectionRow('actuaries', 'en')]),
            truth: $this->truth([$this->truthRow('actuaries', 'en')]),
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
    }

    private function auditor(): CareerRuntimeProjectionTruthEligibilityAuditor
    {
        return new CareerRuntimeProjectionTruthEligibilityAuditor;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function projection(array $items): array
    {
        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'test',
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function truth(array $items): array
    {
        return [
            'truth_kind' => 'career_canonical_runtime_truth',
            'truth_version' => 'test',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function projectionRow(string $slug, string $locale, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => 'public_canonical_job',
            'runtime_publish_state' => 'published',
            'detail_route_enabled' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
            'canonical_self' => true,
            'robots_indexable' => true,
            'release_gate_pass' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function truthRow(string $slug, string $locale, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => 'public_canonical_job',
            'projection_state' => 'published',
            'route_exists' => true,
            'final_200' => true,
            'robots_indexable' => true,
            'canonical_self' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
            'release_gate_pass' => true,
            'fully_live' => true,
        ], $overrides);
    }
}
