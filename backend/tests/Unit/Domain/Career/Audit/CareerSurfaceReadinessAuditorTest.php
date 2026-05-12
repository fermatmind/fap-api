<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonicalEligibilityLayer;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerSurfaceReadinessAuditor;
use App\Domain\Career\Audit\CareerSurfaceReadinessIssue;
use PHPUnit\Framework\TestCase;

final class CareerSurfaceReadinessAuditorTest extends TestCase
{
    public function test_api_artifact_canonical_and_noindex_pass(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->api([$this->apiRow('actuaries', 'en')]));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(CareerCanonicalEligibilityLayer::SURFACE, $result->rows[0]->surfaceStatus->layer);
    }

    public function test_api_canonical_mismatch_blocks(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->api([
            $this->apiRow('actuaries', 'en', ['api_canonical_path' => '/en/career/jobs/actors']),
        ]));

        $this->assertSame(CareerSurfaceReadinessIssue::API_CANONICAL_NOT_SELF, $result->issues[0]->reason);
    }

    public function test_api_noindex_blocks(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->api([
            $this->apiRow('actuaries', 'en', ['api_indexable' => false]),
        ]));

        $this->assertSame(CareerSurfaceReadinessIssue::API_NOINDEX_PRESENT, $result->issues[0]->reason);
    }

    public function test_live_html_synthetic_canonical_noindex_and_cta_pass(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            apiArtifact: $this->api([$this->apiRow('actuaries', 'en')]),
            includeLiveHtml: true,
            baseUrl: 'https://fermatmind.com',
            liveHtmlByKey: [
                'actuaries|en' => $this->html('/en/career/jobs/actuaries'),
            ],
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertTrue($result->rows[0]->liveHtmlVerified);
        $this->assertTrue($result->rows[0]->ctaPresent);
    }

    public function test_live_html_mismatch_reports_real_surface_mismatch(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            apiArtifact: $this->api([$this->apiRow('actuaries', 'en')]),
            includeLiveHtml: true,
            baseUrl: 'https://fermatmind.com',
            liveHtmlByKey: [
                'actuaries|en' => $this->html('/en/career/jobs/actors'),
            ],
        );

        $this->assertSame(1, $result->surfaceMismatchRows);
        $this->assertContains(CareerSurfaceReadinessIssue::REAL_SURFACE_MISMATCH, array_keys($result->byReason()));
    }

    public function test_missing_live_verifier_reports_unverified_not_pass(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            apiArtifact: $this->api([$this->apiRow('actuaries', 'en')]),
            includeLiveHtml: true,
            baseUrl: 'https://fermatmind.com',
            liveHtmlByKey: [],
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::UNVERIFIED, $result->status);
        $this->assertSame(CareerCanonicalEligibilityStatus::UNVERIFIED, $result->rows[0]->surfaceStatus->status);
        $this->assertSame(CareerSurfaceReadinessIssue::SURFACE_VERIFIER_MISSING, $result->issues[0]->reason);
    }

    public function test_live_html_without_base_url_reports_validator_context_missing(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            apiArtifact: $this->api([$this->apiRow('actuaries', 'en')]),
            includeLiveHtml: true,
            baseUrl: null,
            liveHtmlByKey: ['actuaries|en' => $this->html('/en/career/jobs/actuaries')],
        );

        $this->assertSame(CareerCanonicalEligibilityStatus::UNVERIFIED, $result->status);
        $this->assertSame(CareerSurfaceReadinessIssue::VALIDATOR_CONTEXT_MISSING, $result->issues[0]->reason);
    }

    public function test_live_html_noindex_and_missing_cta_are_reported(): void
    {
        $result = $this->auditor()->audit(
            planRows: ['actuaries'],
            locales: ['en'],
            apiArtifact: $this->api([$this->apiRow('actuaries', 'en')]),
            includeLiveHtml: true,
            baseUrl: 'https://fermatmind.com',
            liveHtmlByKey: [
                'actuaries|en' => '<html><head><link rel="canonical" href="https://fermatmind.com/en/career/jobs/actuaries"><meta name="robots" content="noindex,follow"></head><body></body></html>',
            ],
        );

        $this->assertSame([
            CareerSurfaceReadinessIssue::CTA_MISSING_OR_UNATTRIBUTED => 1,
            CareerSurfaceReadinessIssue::LIVE_NOINDEX_PRESENT => 1,
        ], $result->byReason());
    }

    public function test_result_to_array_is_stable(): void
    {
        $result = $this->auditor()->audit(['actuaries'], ['en'], $this->api([$this->apiRow('actuaries', 'en')]))->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "expected_rows": 1,
    "ready_rows": 1,
    "blocked_rows": 0,
    "unverified_rows": 0,
    "surface_mismatch_rows": 0,
    "by_reason": [],
    "rows": [
        {
            "canonical_slug": "actuaries",
            "locale": "en",
            "api_canonical_path": "/en/career/jobs/actuaries",
            "api_indexable": true,
            "live_html_requested": false,
            "live_html_verified": false,
            "live_canonical_path": null,
            "live_robots_policy": null,
            "cta_present": null,
            "surface_status": {
                "layer": "surface",
                "status": "pass",
                "reasons": [],
                "evidence": [
                    {
                        "slug": "actuaries",
                        "locale": "en"
                    },
                    {
                        "api_canonical_path": "/en/career/jobs/actuaries",
                        "api_indexable": true
                    },
                    {
                        "live_html_requested": false
                    },
                    {
                        "live_canonical_path": null
                    },
                    {
                        "live_robots_policy": null
                    },
                    {
                        "cta_present": null
                    }
                ],
                "source": "surface_artifacts"
            },
            "evidence": [
                {
                    "slug": "actuaries",
                    "locale": "en"
                },
                {
                    "api_canonical_path": "/en/career/jobs/actuaries",
                    "api_indexable": true
                },
                {
                    "live_html_requested": false
                },
                {
                    "live_canonical_path": null
                },
                {
                    "live_robots_policy": null
                },
                {
                    "cta_present": null
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

    public function test_no_db_mutation_or_live_fetch_is_required(): void
    {
        $result = $this->auditor()->auditSlugs(['actuaries'], ['en'], $this->api([$this->apiRow('actuaries', 'en')]));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
    }

    private function auditor(): CareerSurfaceReadinessAuditor
    {
        return new CareerSurfaceReadinessAuditor;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function api(array $items): array
    {
        return ['items' => $items];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function apiRow(string $slug, string $locale, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'locale' => $locale,
            'api_canonical_path' => '/'.$locale.'/career/jobs/'.$slug,
            'api_indexable' => true,
        ], $overrides);
    }

    private function html(string $canonicalPath): string
    {
        return '<html><head><link rel="canonical" href="https://fermatmind.com'.$canonicalPath.'"><meta name="robots" content="index,follow"></head><body><a data-career-cta="primary" href="/en/career/jobs">Explore</a></body></html>';
    }
}
