<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\Career2786FullAuditArtifactBuilder;
use App\Domain\Career\Audit\CareerCanonicalEligibilityAuditRow;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityLayerStatus;
use App\Domain\Career\Audit\CareerCanonicalEligibilityReport;
use App\Domain\Career\Audit\CareerCanonicalEligibilityScope;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use PHPUnit\Framework\TestCase;

final class Career2786FullAuditArtifactBuilderTest extends TestCase
{
    public function test_synthetic_2786_like_summary_can_pass_without_real_fixture(): void
    {
        $artifact = $this->builder()->build($this->report([$this->row('actuaries'), $this->row('actors')]), totalExpected: 2);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $artifact->status);
        $this->assertTrue($artifact->readyForExpansion);
        $this->assertSame(2, $artifact->totalExpected);
        $this->assertSame(2, $artifact->eligibleCount);
        $this->assertSame(0, $artifact->blockedCount);
    }

    public function test_blocked_rows_are_summarized_by_reason_and_layer(): void
    {
        $artifact = $this->builder()->build($this->report([
            $this->row('actuaries'),
            $this->row('actors', CareerCanonicalEligibilityStatus::BLOCKED, ['surface_mismatch']),
        ]), totalExpected: 2)->toArray();

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $artifact['status']);
        $this->assertFalse($artifact['ready_for_expansion']);
        $this->assertSame(['surface_mismatch' => 1], $artifact['by_reason']);
        $this->assertSame(2, $artifact['by_layer']['entity']['pass']);
    }

    public function test_artifact_to_array_is_stable(): void
    {
        $artifact = $this->builder()->build($this->report([$this->row('actuaries')]), totalExpected: 1)->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "artifact_kind": "career_2786_canonical_eligibility_audit_report",
    "artifact_version": "career.2786_canonical_eligibility_audit_report.v1",
    "status": "pass",
    "total_expected": 1,
    "audited_count": 1,
    "eligible_count": 1,
    "blocked_count": 0,
    "ready_for_expansion": true,
    "by_reason": [],
    "by_layer": {
        "baseline": {
            "pass": 1
        },
        "entity": {
            "pass": 1
        },
        "index": {
            "pass": 1
        },
        "runtime": {
            "pass": 1
        },
        "safety": {
            "pass": 1
        },
        "seo_geo": {
            "pass": 1
        },
        "surface": {
            "pass": 1
        }
    },
    "sections": [
        {
            "section": "canonical_eligibility",
            "status": "pass",
            "summary": {
                "status": "pass",
                "scope": "slugs",
                "expected_occupations": 1,
                "audited_occupations": 1,
                "eligible_count": 1,
                "blocked_count": 0,
                "by_reason": [],
                "rows": [
                    {
                        "slug": "actuaries",
                        "locale": "en",
                        "source_scope": "slugs",
                        "entity_status": {
                            "layer": "entity",
                            "status": "pass",
                            "reasons": [],
                            "evidence": [
                                {
                                    "slug": "actuaries"
                                }
                            ],
                            "source": "unit_test"
                        },
                        "baseline_status": {
                            "layer": "baseline",
                            "status": "pass",
                            "reasons": [],
                            "evidence": [
                                {
                                    "slug": "actuaries"
                                }
                            ],
                            "source": "unit_test"
                        },
                        "index_status": {
                            "layer": "index",
                            "status": "pass",
                            "reasons": [],
                            "evidence": [
                                {
                                    "slug": "actuaries"
                                }
                            ],
                            "source": "unit_test"
                        },
                        "runtime_status": {
                            "layer": "runtime",
                            "status": "pass",
                            "reasons": [],
                            "evidence": [
                                {
                                    "slug": "actuaries"
                                }
                            ],
                            "source": "unit_test"
                        },
                        "seo_geo_status": {
                            "layer": "seo_geo",
                            "status": "pass",
                            "reasons": [],
                            "evidence": [
                                {
                                    "slug": "actuaries"
                                }
                            ],
                            "source": "unit_test"
                        },
                        "surface_status": {
                            "layer": "surface",
                            "status": "pass",
                            "reasons": [],
                            "evidence": [
                                {
                                    "slug": "actuaries"
                                }
                            ],
                            "source": "unit_test"
                        },
                        "safety_status": {
                            "layer": "safety",
                            "status": "pass",
                            "reasons": [],
                            "evidence": [
                                {
                                    "read_only": true
                                }
                            ],
                            "source": "unit_test"
                        },
                        "overall_status": "pass",
                        "severity": "info",
                        "reasons": [],
                        "evidence": [
                            {
                                "slug": "actuaries"
                            }
                        ],
                        "sidecars": []
                    }
                ],
                "sidecars": []
            }
        }
    ],
    "sidecars": [],
    "read_only": true,
    "writes_database": false
}
JSON,
            json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function builder(): Career2786FullAuditArtifactBuilder
    {
        return new Career2786FullAuditArtifactBuilder;
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRow>  $rows
     */
    private function report(array $rows): CareerCanonicalEligibilityReport
    {
        $blocked = count(array_filter($rows, static fn (CareerCanonicalEligibilityAuditRow $row): bool => $row->overallStatus !== CareerCanonicalEligibilityStatus::PASS));

        return new CareerCanonicalEligibilityReport(
            status: $blocked === 0 ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            scope: CareerCanonicalEligibilityScope::SLUGS,
            expectedOccupations: count($rows),
            auditedOccupations: count($rows),
            eligibleCount: count($rows) - $blocked,
            blockedCount: $blocked,
            byReason: CareerCanonicalEligibilityReport::byReasonFromRows($rows),
            rows: $rows,
            sidecars: [],
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    private function row(string $slug, string $status = CareerCanonicalEligibilityStatus::PASS, array $reasons = []): CareerCanonicalEligibilityAuditRow
    {
        $layer = static fn (string $layer, array $evidence): CareerCanonicalEligibilityLayerStatus => new CareerCanonicalEligibilityLayerStatus($layer, CareerCanonicalEligibilityStatus::PASS, [], $evidence, 'unit_test');

        return new CareerCanonicalEligibilityAuditRow(
            slug: $slug,
            locale: 'en',
            sourceScope: CareerCanonicalEligibilityScope::SLUGS,
            entityStatus: $layer(CareerCanonicalEligibilityLayer::ENTITY, [['slug' => $slug]]),
            baselineStatus: $layer(CareerCanonicalEligibilityLayer::BASELINE, [['slug' => $slug]]),
            indexStatus: $layer(CareerCanonicalEligibilityLayer::INDEX, [['slug' => $slug]]),
            runtimeStatus: $layer(CareerCanonicalEligibilityLayer::RUNTIME, [['slug' => $slug]]),
            seoGeoStatus: $layer(CareerCanonicalEligibilityLayer::SEO_GEO, [['slug' => $slug]]),
            surfaceStatus: $layer(CareerCanonicalEligibilityLayer::SURFACE, [['slug' => $slug]]),
            safetyStatus: $layer(CareerCanonicalEligibilityLayer::SAFETY, [['read_only' => true]]),
            overallStatus: $status,
            severity: $status === CareerCanonicalEligibilityStatus::PASS ? CareerCanonicalEligibilitySeverity::INFO : CareerCanonicalEligibilitySeverity::HIGH,
            reasons: $reasons,
            evidence: [['slug' => $slug]],
            sidecars: [],
        );
    }
}
