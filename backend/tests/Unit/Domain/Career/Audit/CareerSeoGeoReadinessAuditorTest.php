<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilitySeverity;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use App\Domain\Career\Audit\CareerSeoGeoReadinessAuditor;
use App\Domain\Career\Audit\CareerSeoGeoReadinessIssue;
use PHPUnit\Framework\TestCase;

final class CareerSeoGeoReadinessAuditorTest extends TestCase
{
    public function test_canonical_self_and_static_seo_geo_readiness_pass(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            seoGeo: $this->artifact([$this->row('actuaries', 'en')]),
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(1, $result->readyRows);
        $this->assertSame(CareerCanonicalEligibilityLayer::SEO_GEO, $result->rows[0]->seoGeoStatus->layer);
    }

    public function test_canonical_not_self_is_reported(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([
            $this->row('actuaries', 'en', ['canonical_path' => '/en/career/jobs/actors']),
        ]));

        $this->assertSame(CareerSeoGeoReadinessIssue::CANONICAL_NOT_SELF, $result->issues[0]->reason);
    }

    public function test_robots_noindex_is_reported(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([
            $this->row('actuaries', 'en', ['robots_policy' => 'noindex,follow', 'robots_indexable' => false]),
        ]));

        $this->assertSame(CareerSeoGeoReadinessIssue::ROBOTS_NOINDEX, $result->issues[0]->reason);
    }

    public function test_sitemap_llms_and_llms_full_expected_not_ready_policy_is_distinguished_from_missing_sources(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([
            $this->row('actuaries', 'en', [
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ]),
        ]));

        $this->assertSame([
            CareerSeoGeoReadinessIssue::LLMS_EXPECTED_NOT_READY => 1,
            CareerSeoGeoReadinessIssue::LLMS_FULL_EXPECTED_NOT_READY => 1,
            CareerSeoGeoReadinessIssue::SITEMAP_EXPECTED_NOT_READY => 1,
        ], $result->byReason());
        $this->assertSame(0, $result->sitemapMissingRows);
        $this->assertSame(0, $result->llmsMissingRows);
        $this->assertSame(0, $result->llmsFullMissingRows);
        $this->assertSame(CareerCanonicalEligibilitySeverity::MEDIUM, $result->issues[0]->severity);
    }

    public function test_sitemap_llms_and_llms_full_missing_are_reported_when_sources_are_absent(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([
            $this->row('actuaries', 'en', [
                'sitemap_eligible' => null,
                'llms_eligible' => null,
                'llms_full_eligible' => null,
            ]),
        ]));

        $this->assertSame([
            CareerSeoGeoReadinessIssue::LLMS_FULL_MISSING => 1,
            CareerSeoGeoReadinessIssue::LLMS_MISSING => 1,
            CareerSeoGeoReadinessIssue::SITEMAP_MISSING => 1,
        ], $result->byReason());
        $this->assertSame(1, $result->sitemapMissingRows);
        $this->assertSame(1, $result->llmsMissingRows);
        $this->assertSame(1, $result->llmsFullMissingRows);
    }

    public function test_structured_data_dataset_search_and_citation_missing_are_reported(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([
            $this->row('actuaries', 'en', [
                'structured_data_ready' => false,
                'dataset_eligible' => false,
                'search_eligible' => false,
                'citation_metadata_ready' => false,
            ]),
        ]));

        $this->assertSame([
            CareerSeoGeoReadinessIssue::CITATION_METADATA_MISSING => 1,
            CareerSeoGeoReadinessIssue::DATASET_MISSING => 1,
            CareerSeoGeoReadinessIssue::SEARCH_MISSING => 1,
            CareerSeoGeoReadinessIssue::STRUCTURED_DATA_MISSING => 1,
        ], $result->byReason());
    }

    public function test_structured_data_and_citation_artifact_strings_are_accepted_as_sources(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([
            $this->row('actuaries', 'en', [
                'structured_data_ready' => null,
                'structured_data_json' => '{"@type":"Occupation"}',
                'citation_metadata_ready' => null,
                'citation_metadata' => 'career source citations available',
            ]),
        ]));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertArrayNotHasKey(CareerSeoGeoReadinessIssue::STRUCTURED_DATA_MISSING, $result->byReason());
        $this->assertArrayNotHasKey(CareerSeoGeoReadinessIssue::CITATION_METADATA_MISSING, $result->byReason());
    }

    public function test_missing_artifact_row_reports_all_static_readiness_issues(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([]));

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(9, count($result->issues));
    }

    public function test_audit_plan_consumes_public_resolution_plan_rows(): void
    {
        $plan = new CareerPublicResolutionPlan(
            sourcePath: '__fixture__',
            checksum: null,
            rows: [CareerPublicResolutionPlanRow::fromRaw(['canonical_slug' => 'actuaries'])],
        );

        $result = $this->auditor()->auditPlan($plan, ['en'], $this->artifact([$this->row('actuaries', 'en')]));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame('actuaries', $result->rows[0]->canonicalSlug);
    }

    public function test_row_to_array_is_stable(): void
    {
        $row = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([$this->row('actuaries', 'en')]))->rows[0]->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "canonical_slug": "actuaries",
    "locale": "en",
    "canonical_path": "/en/career/jobs/actuaries",
    "canonical_self": true,
    "robots_policy": "index,follow",
    "robots_indexable": true,
    "sitemap_eligible": true,
    "llms_eligible": true,
    "llms_full_eligible": true,
    "structured_data_ready": true,
    "dataset_eligible": true,
    "search_eligible": true,
    "citation_metadata_ready": true,
    "seo_geo_status": {
        "layer": "seo_geo",
        "status": "pass",
        "reasons": [],
        "evidence": [
            {
                "slug": "actuaries",
                "locale": "en"
            },
            {
                "canonical_path": "/en/career/jobs/actuaries",
                "canonical_self": true
            },
            {
                "robots_policy": "index,follow",
                "robots_indexable": true
            },
            {
                "sitemap_eligible": true
            },
            {
                "llms_eligible": true
            },
            {
                "llms_full_eligible": true
            },
            {
                "structured_data_ready": true
            },
            {
                "dataset_eligible": true
            },
            {
                "search_eligible": true
            },
            {
                "citation_metadata_ready": true
            }
        ],
        "source": "seo_geo_artifacts"
    },
    "evidence": [
        {
            "slug": "actuaries",
            "locale": "en"
        },
        {
            "canonical_path": "/en/career/jobs/actuaries",
            "canonical_self": true
        },
        {
            "robots_policy": "index,follow",
            "robots_indexable": true
        },
        {
            "sitemap_eligible": true
        },
        {
            "llms_eligible": true
        },
        {
            "llms_full_eligible": true
        },
        {
            "structured_data_ready": true
        },
        {
            "dataset_eligible": true
        },
        {
            "search_eligible": true
        },
        {
            "citation_metadata_ready": true
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
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([$this->row('actuaries', 'en')]))->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "expected_rows": 1,
    "ready_rows": 1,
    "blocked_rows": 0,
    "sitemap_missing_rows": 0,
    "llms_missing_rows": 0,
    "llms_full_missing_rows": 0,
    "structured_data_missing_rows": 0,
    "dataset_missing_rows": 0,
    "search_missing_rows": 0,
    "citation_metadata_missing_rows": 0,
    "by_reason": [],
    "rows": [
        {
            "canonical_slug": "actuaries",
            "locale": "en",
            "canonical_path": "/en/career/jobs/actuaries",
            "canonical_self": true,
            "robots_policy": "index,follow",
            "robots_indexable": true,
            "sitemap_eligible": true,
            "llms_eligible": true,
            "llms_full_eligible": true,
            "structured_data_ready": true,
            "dataset_eligible": true,
            "search_eligible": true,
            "citation_metadata_ready": true,
            "seo_geo_status": {
                "layer": "seo_geo",
                "status": "pass",
                "reasons": [],
                "evidence": [
                    {
                        "slug": "actuaries",
                        "locale": "en"
                    },
                    {
                        "canonical_path": "/en/career/jobs/actuaries",
                        "canonical_self": true
                    },
                    {
                        "robots_policy": "index,follow",
                        "robots_indexable": true
                    },
                    {
                        "sitemap_eligible": true
                    },
                    {
                        "llms_eligible": true
                    },
                    {
                        "llms_full_eligible": true
                    },
                    {
                        "structured_data_ready": true
                    },
                    {
                        "dataset_eligible": true
                    },
                    {
                        "search_eligible": true
                    },
                    {
                        "citation_metadata_ready": true
                    }
                ],
                "source": "seo_geo_artifacts"
            },
            "evidence": [
                {
                    "slug": "actuaries",
                    "locale": "en"
                },
                {
                    "canonical_path": "/en/career/jobs/actuaries",
                    "canonical_self": true
                },
                {
                    "robots_policy": "index,follow",
                    "robots_indexable": true
                },
                {
                    "sitemap_eligible": true
                },
                {
                    "llms_eligible": true
                },
                {
                    "llms_full_eligible": true
                },
                {
                    "structured_data_ready": true
                },
                {
                    "dataset_eligible": true
                },
                {
                    "search_eligible": true
                },
                {
                    "citation_metadata_ready": true
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

    public function test_seo_geo_layer_status_blocks_when_readiness_fails(): void
    {
        $status = $this->auditor()->audit(['actuaries'], ['en'], $this->artifact([
            $this->row('actuaries', 'en', ['robots_indexable' => false]),
        ]))->rows[0]->seoGeoStatus;

        $this->assertSame(CareerCanonicalEligibilityLayer::SEO_GEO, $status->layer);
        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $status->status);
        $this->assertContains(CareerSeoGeoReadinessIssue::ROBOTS_NOINDEX, $status->reasons);
    }

    public function test_no_db_mutation_or_live_html_fetch(): void
    {
        $result = $this->auditor()->auditSlugs(['actuaries'], ['en'], $this->artifact([$this->row('actuaries', 'en')]));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
    }

    private function auditor(): CareerSeoGeoReadinessAuditor
    {
        return new CareerSeoGeoReadinessAuditor;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function artifact(array $items): array
    {
        return [
            'seo_geo_kind' => 'career_static_seo_geo_readiness',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(string $slug, string $locale, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'locale' => $locale,
            'canonical_path' => '/'.$locale.'/career/jobs/'.$slug,
            'canonical_self' => true,
            'robots_policy' => 'index,follow',
            'robots_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'llms_full_eligible' => true,
            'structured_data_ready' => true,
            'dataset_eligible' => true,
            'search_eligible' => true,
            'citation_metadata_ready' => true,
        ], $overrides);
    }
}
