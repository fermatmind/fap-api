<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerBatchLiveAcceptanceV2Auditor;
use App\Domain\Career\Audit\CareerBatchLiveAcceptanceV2Issue;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use PHPUnit\Framework\TestCase;

final class CareerBatchLiveAcceptanceV2AuditorTest extends TestCase
{
    public function test_arbitrary_batch_synthetic_pass(): void
    {
        $result = $this->auditor()->audit('batch-080', ['actuaries', 'actors'], ['en', 'zh'], $this->artifact(['actuaries', 'actors'], ['en', 'zh']), $this->artifact(['actuaries', 'actors'], ['en', 'zh']), $this->surfaces(['actuaries', 'actors'], ['en', 'zh']));

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertTrue($result->accepted);
        $this->assertSame(4, $result->expectedRows);
        $this->assertSame(4, $result->foundProjectionRows);
        $this->assertSame(4, $result->foundTruthRows);
        $this->assertSame('pass', $result->surfaceEquality);
        $this->assertFalse($result->writesDatabase);
    }

    public function test_missing_projection_and_truth_rows_are_reported(): void
    {
        $result = $this->auditor()->audit('batch-080', ['actuaries'], ['en'], ['items' => []], ['items' => []]);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame([
            CareerBatchLiveAcceptanceV2Issue::PROJECTION_ROW_MISSING => 1,
            CareerBatchLiveAcceptanceV2Issue::RELEASE_GATE_BLOCKED => 1,
            CareerBatchLiveAcceptanceV2Issue::TRUTH_ROW_MISSING => 1,
        ], $result->byReason());
    }

    public function test_release_gate_blocked_is_reported(): void
    {
        $result = $this->auditor()->audit('batch-080', ['actuaries'], ['en'], $this->artifact(['actuaries'], ['en'], releaseGatePass: false), $this->artifact(['actuaries'], ['en'], releaseGatePass: false));

        $this->assertSame(1, $result->releaseGateBlockedCount);
        $this->assertSame(1, $result->byReason()[CareerBatchLiveAcceptanceV2Issue::RELEASE_GATE_BLOCKED]);
    }

    public function test_surface_mismatch_rows_are_reported(): void
    {
        $result = $this->auditor()->audit('batch-080', ['actuaries'], ['en'], $this->artifact(['actuaries'], ['en']), $this->artifact(['actuaries'], ['en']), $this->surfaces(['actuaries'], ['en'], surfaceMatch: false));

        $this->assertSame('fail', $result->surfaceEquality);
        $this->assertSame(1, $result->mismatchCount);
        $this->assertSame(1, $result->byReason()[CareerBatchLiveAcceptanceV2Issue::SURFACE_MISMATCH]);
    }

    public function test_live_html_optional_missing_surface_is_unverified(): void
    {
        $result = $this->auditor()->audit('batch-080', ['actuaries'], ['en'], $this->artifact(['actuaries'], ['en']), $this->artifact(['actuaries'], ['en']), includeLiveHtml: true);

        $this->assertSame(CareerCanonicalEligibilityStatus::UNVERIFIED, $result->status);
        $this->assertSame('unverified', $result->surfaceEquality);
        $this->assertSame(1, $result->unverifiedSurfaceCount);
    }

    public function test_result_to_array_is_stable(): void
    {
        $result = $this->auditor()->audit('batch-080', ['actuaries'], ['en'], $this->artifact(['actuaries'], ['en']), $this->artifact(['actuaries'], ['en']), $this->surfaces(['actuaries'], ['en']))->toArray();

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "accepted": true,
    "batch_id": "batch-080",
    "expected_rows": 1,
    "found_projection_rows": 1,
    "found_truth_rows": 1,
    "release_gate": {
        "pass": 1,
        "blocked": 0
    },
    "surfaces": {
        "surface_equality": "pass",
        "mismatch_count": 0,
        "unverified_count": 0
    },
    "read_only": true,
    "writes_database": false,
    "by_reason": [],
    "rows": [
        {
            "canonical_slug": "actuaries",
            "locale": "en",
            "status": "pass",
            "projection_found": true,
            "truth_found": true,
            "release_gate_pass": true,
            "surface_status": "pass",
            "reasons": [],
            "evidence": [
                {
                    "slug": "actuaries",
                    "locale": "en"
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

    public function test_no_db_mutation_or_production_dependency_is_required(): void
    {
        $result = $this->auditor()->audit('batch-080', ['actuaries'], ['en'], $this->artifact(['actuaries'], ['en']), $this->artifact(['actuaries'], ['en']));

        $this->assertTrue($result->readOnly);
        $this->assertFalse($result->writesDatabase);
    }

    private function auditor(): CareerBatchLiveAcceptanceV2Auditor
    {
        return new CareerBatchLiveAcceptanceV2Auditor;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{items: list<array<string, mixed>>}
     */
    private function artifact(array $slugs, array $locales, bool $releaseGatePass = true): array
    {
        $items = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'runtime_publish_state' => 'published',
                    'release_gate_pass' => $releaseGatePass,
                ];
            }
        }

        return ['items' => $items];
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{items: list<array<string, mixed>>}
     */
    private function surfaces(array $slugs, array $locales, bool $surfaceMatch = true): array
    {
        $items = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'surface_match' => $surfaceMatch,
                    'canonical_self' => $surfaceMatch,
                    'robots_indexable' => $surfaceMatch,
                ];
            }
        }

        return ['items' => $items];
    }
}
